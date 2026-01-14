<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            // ===== Required fields =====
            $reqTime    = now()->utc()->format('YmdHis');
            $merchantId = config('payway.merchant_id');
            $tranId     = 'REG-' . $registration->id . '-' . time();
            $amount     = number_format($registration->registration_fee, 2, '.', '');
            $currency   = 'USD';

            // ===== Optional payer info =====
            $firstName = $registration->first_name ?? '';
            $lastName  = $registration->last_name ?? '';
            $email     = $registration->personal_email ?? '';
            $phone     = $registration->phone_number ?? '';

            // ===== Base64 fields =====
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

            // =====================================================
            // 🔐 HASH STRING (EXACT ORDER FROM ABA DOC)
            // =====================================================
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
                $returnDeeplink .   // "" ← NOT null
                $currency .
                $customFields .     // ""
                $returnParams .     // ""
                $payout .           // ""
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

            // ===== Payload =====
            $payload = [
                'req_time'          => $reqTime,
                'merchant_id'       => $merchantId,
                'tran_id'           => $tranId,
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'email'             => $email,
                'phone'             => $phone,
                'amount'            => (float) $amount,
                'purchase_type'     => $purchaseType,
                'payment_option'    => $paymentOption,
                'items'             => $items,
                'currency'          => $currency,
                'callback_url'      => $callbackUrl,
                'return_deeplink'   => '',   // ← empty string
                'custom_fields'     => '',
                'return_params'     => '',
                'payout'            => '',
                'lifetime'          => $lifetime,
                'qr_image_template' => $qrImageTemplate,
                'hash'              => $hash,
            ];


            // ===== Call ABA QR API =====
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
}
