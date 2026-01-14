<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class PaymentController extends Controller
{
    public function generateQr(Request $request)
    {
        Log::info('🔥 generateQr hit');

        $request->validate([
            'registration_id' => 'required|exists:registrations,id'
        ]);

        DB::beginTransaction();

        try {
            $registration = DB::table('registrations')
                ->join('majors', 'registrations.major_id', '=', 'majors.id')
                ->where('registrations.id', $request->registration_id)
                ->select('registrations.*', 'majors.registration_fee')
                ->first();

            if (!$registration || $registration->payment_status === 'PAID') {
                return response()->json(['error' => 'Invalid registration'], 400);
            }

            /* ================= REQUIRED FIELDS ================= */

            $reqTime    = now()->utc()->format('YmdHis');
            $merchantId = config('payway.merchant_id');
            $tranId     = 'REG-' . $registration->id . '-' . time();
            $amount     = number_format($registration->registration_fee, 2, '.', '');
            $currency   = 'USD';

            /* ================= OPTIONAL PAYER INFO ================= */

            $firstName = trim($registration->first_name ?? '');
            $lastName  = trim($registration->last_name ?? '');
            $email     = trim($registration->personal_email ?? '');

            // 🔴 MUST be digits only
            $phone = preg_replace('/\D/', '', $registration->phone_number ?? '');

            /* ================= BASE64 FIELDS ================= */

            $items = base64_encode(json_encode([
                [
                    'name'     => 'Registration Fee',
                    'quantity' => 1,
                    'price'    => $amount
                ]
            ], JSON_UNESCAPED_SLASHES));

            $callbackUrl     = base64_encode(config('payway.callback'));
            $returnDeeplink  = '';
            $customFields    = '';
            $returnParams    = '';
            $payout           = '';
            $lifetime         = 6;
            $qrImageTemplate  = 'template3_color';
            $purchaseType     = 'purchase';
            $paymentOption    = 'abapay_khqr';

            /* ================= HASH STRING (EXACT ORDER) ================= */

            $hashString =
                $reqTime .
                $merchantId .
                $tranId .
                $amount .
                $items .
                $firstName .
                $lastName .
                $email .
                $phone .
                $purchaseType .
                $paymentOption .
                $callbackUrl .
                $returnDeeplink .
                $currency .
                $customFields .
                $returnParams .
                $payout .
                $lifetime .
                $qrImageTemplate;

            $hash = base64_encode(
                hash_hmac(
                    'sha512',
                    $hashString,
                    config('payway.api_key'), // ✅ HMAC API KEY
                    true
                )
            );

            /* ================= PAYLOAD ================= */

            $payload = [
                'req_time'          => $reqTime,
                'merchant_id'       => $merchantId,
                'tran_id'           => $tranId,
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'email'             => $email,
                'phone'             => $phone,
                'amount'            => $amount, // 🔴 STRING, NOT FLOAT
                'purchase_type'     => $purchaseType,
                'payment_option'    => $paymentOption,
                'items'             => $items,
                'currency'          => $currency,
                'callback_url'      => $callbackUrl,
                'return_deeplink'   => '',
                'custom_fields'     => '',
                'return_params'     => '',
                'payout'            => '',
                'lifetime'          => $lifetime,
                'qr_image_template' => $qrImageTemplate,
                'hash'              => $hash,
            ];

            /* ================= CALL ABA QR API ================= */

            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post(
                'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/generate-qr',
                $payload
            );

            if (!$response->successful()) {
                Log::error('ABA QR Error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'hash_string_length' => strlen($hashString)
                ]);
                return response()->json($response->json(), 403);
            }

            DB::commit();

            return response()->json([
                'tran_id' => $tranId,
                'qr'      => $response->json()
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['error' => 'failed'], 500);
        }
    }
    public function checkPaymentStatus($tranId)
    {
        try {
            $tx = DB::table('payment_transactions')
                ->where('tran_id', $tranId)
                ->first();
            if (!$tx) {
                return response()->json([
                    'tran_id' => $tranId,
                    'status' => [
                        'code' => '1',
                        'message' => 'PENDING',
                        'lang' => 'en'
                    ]
                ]);
            }

            return response()->json([
                'tran_id' => $tranId,
                'status' => [
                    'code' => $tx->status === 'PAID' ? '0' : '1',
                    'message' => $tx->status,
                    'lang' => 'en'
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('checkPaymentStatus error', [
                'tran_id' => $tranId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Internal error'
            ], 500);
        }
    }
    public function paymentCallback(Request $request)
    {
        Log::info('ABA CALLBACK RECEIVED', [
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            'parsed' => $request->all(),
        ]);

        if (!$request->filled('tran_id')) {
            return response()->json(['error' => 'Missing tran_id'], 400);
        }

        DB::beginTransaction();

        try {
            $status = ((int) $request->payment_status_code === 0) ? 'PAID' : 'FAILED';

            // 1️⃣ Update payment transaction
            DB::table('payment_transactions')
                ->where('tran_id', $request->tran_id)
                ->update([
                    'status' => $status,
                    'updated_at' => now(),
                ]);

            // 2️⃣ Find registration
            $registration = DB::table('registrations')
                ->where('payment_tran_id', $request->tran_id)
                ->first();

            if (!$registration) {
                DB::rollBack();
                return response()->json(['error' => 'Registration not found'], 404);
            }

            // 3️⃣ Update registration
            DB::table('registrations')
                ->where('payment_tran_id', $request->tran_id)
                ->update([
                    'payment_status' => $status,
                    'payment_date' => now(),
                ]);

            // 4️⃣ 🔥 Change user role AFTER successful payment
            if ($status === 'PAID') {
                User::where('email', $registration->personal_email)
                    ->where('role', 'register')
                    ->update([
                        'role' => 'student',
                    ]);
            }

            DB::commit();

            // ABA requires HTTP 200
            return response()->json(['ack' => 'ok']);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('PAYMENT CALLBACK ERROR', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
