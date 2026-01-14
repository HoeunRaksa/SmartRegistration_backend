<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class PaymentController extends Controller
{
    /**
     * Generate ABA PayWay QR Code
     */
    public function generateQr(Request $request)
    {
        Log::info('🔥 generateQr hit');

        $request->validate([
            'registration_id' => 'required|exists:registrations,id'
        ]);

        DB::beginTransaction();

        try {
            /* =====================================================
               1️⃣ Load registration + fee
            ===================================================== */
            $registration = DB::table('registrations')
                ->join('majors', 'registrations.major_id', '=', 'majors.id')
                ->where('registrations.id', $request->registration_id)
                ->select('registrations.*', 'majors.registration_fee')
                ->first();

            if (!$registration) {
                return response()->json(['error' => 'Registration not found'], 404);
            }

            if ($registration->payment_status === 'PAID') {
                return response()->json(['error' => 'Already paid'], 400);
            }

            /* =====================================================
               2️⃣ Prepare core values
            ===================================================== */
            $tranId = 'REG-' . $registration->id . '-' . time();

            $amount = number_format(
                $registration->payment_amount ?? $registration->registration_fee,
                2,
                '.',
                ''
            );

            // Normalize Cambodia phone → 855XXXXXXXX
            $phone = preg_replace('/\D/', '', $registration->phone_number ?? '');
            if (str_starts_with($phone, '0')) {
                $phone = '855' . substr($phone, 1);
            }

            if (strlen($phone) < 11) {
                return response()->json(['error' => 'Invalid phone number'], 422);
            }

            /* =====================================================
               3️⃣ RAW items (NOT encoded yet)
            ===================================================== */
            $itemsRaw = json_encode([
                [
                    'name'     => 'Registration Fee',
                    'quantity' => 1,
                    'price'    => $amount,
                ]
            ], JSON_UNESCAPED_SLASHES);

            $callbackUrl = config('payway.callback');
            $returnUrl   = config('payway.return');

            /* =====================================================
               4️⃣ Base payload (RAW values only)
            ===================================================== */
            $paymentData = [
                'req_time'          => now()->format('YmdHis'),
                'merchant_id'       => config('payway.merchant_id'),
                'tran_id'           => $tranId,
                'amount'            => $amount,
                'items'             => null, // encoded AFTER hash
                'first_name'        => $registration->first_name ?? '',
                'last_name'         => $registration->last_name ?? '',
                'email'             => $registration->personal_email ?? '',
                'phone'             => $phone,
                'purchase_type'     => 'purchase',
                'payment_option'    => 'abapay_khqr',
                'callback_url'      => null, // encoded AFTER hash
                'return_url'        => null, // encoded AFTER hash
                'currency'          => 'USD',
                'lifetime'          => '300',
                'qr_image_template' => 'template3_color',
            ];

            /* =====================================================
               5️⃣ HASH STRING — EXACT ABA ORDER (RAW ONLY)
            ===================================================== */
            $hashString =
                $paymentData['req_time'] .
                $paymentData['merchant_id'] .
                $paymentData['tran_id'] .
                $paymentData['amount'] .
                $itemsRaw .
                $paymentData['first_name'] .
                $paymentData['last_name'] .
                $paymentData['email'] .
                $paymentData['phone'] .
                $paymentData['purchase_type'] .
                $paymentData['payment_option'] .
                $callbackUrl .
                $returnUrl .
                $paymentData['currency'] .
                $paymentData['lifetime'] .
                $paymentData['qr_image_template'];

            /* =====================================================
               6️⃣ Generate HASH
            ===================================================== */
            $paymentData['hash'] = base64_encode(
                hash_hmac(
                    'sha512',
                    $hashString,
                    config('payway.api_key'),
                    true
                )
            );

            /* =====================================================
               7️⃣ Encode AFTER hashing (CRITICAL)
            ===================================================== */
            $paymentData['items']        = base64_encode($itemsRaw);
            $paymentData['callback_url'] = base64_encode($callbackUrl);
            $paymentData['return_url']   = base64_encode($returnUrl);

            Log::info('PayWay Request', [
                'tran_id' => $tranId,
                'amount'  => $amount
            ]);

            /* =====================================================
               8️⃣ Call PayWay API
            ===================================================== */
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post(
                rtrim(config('payway.base_url'), '/') . '/generate-qr',
                $paymentData
            );

            if (!$response->successful()) {
                Log::error('PayWay Error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return response()->json([
                    'error'   => 'Payment gateway error',
                    'details'=> $response->json(),
                ], $response->status());
            }

            $result = $response->json();

            /* =====================================================
               9️⃣ Save DB
            ===================================================== */
            DB::table('payment_transactions')->insert([
                'tran_id'     => $tranId,
                'status'      => 'PENDING',
                'amount'      => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('registrations')
                ->where('id', $registration->id)
                ->update([
                    'payment_tran_id' => $tranId,
                    'payment_status'  => 'PENDING',
                    'payment_amount'  => $amount,
                    'updated_at'      => now(),
                ]);

            DB::commit();

            /* =====================================================
               🔟 Return to frontend
            ===================================================== */
            return response()->json([
                'qr_image_url' => $result['qr_image_url'] ?? null,
                'qr_string'    => $result['qr_string'] ?? null,
                'tran_id'      => $tranId,
                'amount'       => $amount,
                'status'       => 'PENDING',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Generate QR Failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to generate QR',
            ], 500);
        }
    }

    /* =====================================================
       CALLBACK
    ===================================================== */
    public function paymentCallback(Request $request)
    {
        Log::info('PayWay Callback', $request->all());

        $tranId = $request->input('tran_id');
        $code   = $request->input('status.code');
        $msg    = strtolower($request->input('status.message', ''));

        $newStatus = ($code === '0' || $msg === 'success') ? 'PAID' : 'FAILED';

        DB::beginTransaction();

        try {
            DB::table('payment_transactions')
                ->where('tran_id', $tranId)
                ->update([
                    'status'      => $newStatus,
                    'updated_at' => now(),
                ]);

            if ($newStatus === 'PAID') {
                DB::table('registrations')
                    ->where('payment_tran_id', $tranId)
                    ->update([
                        'payment_status' => 'PAID',
                        'payment_date'   => now(),
                    ]);
            }

            DB::commit();
            return response()->json(['ack' => 'ok']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'callback failed'], 500);
        }
    }

    /* =====================================================
       CHECK STATUS
    ===================================================== */
    public function checkPaymentStatus($tranId)
    {
        $tx = DB::table('payment_transactions')->where('tran_id', $tranId)->first();

        return response()->json([
            'tran_id' => $tranId,
            'status'  => [
                'code'    => $tx?->status === 'PAID' ? '0' : '1',
                'message' => $tx?->status ?? 'PENDING',
                'lang'    => 'en',
            ]
        ]);
    }

    /* =====================================================
       GET REGISTRATION PAYMENT
    ===================================================== */
    public function getRegistrationPayment($registrationId)
    {
        $data = DB::table('registrations')
            ->leftJoin(
                'payment_transactions',
                'registrations.payment_tran_id',
                '=',
                'payment_transactions.tran_id'
            )
            ->where('registrations.id', $registrationId)
            ->first();

        return response()->json(['data' => $data]);
    }
}
