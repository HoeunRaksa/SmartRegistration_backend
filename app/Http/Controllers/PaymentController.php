<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Generate ABA PayWay KHQR
     */
    public function generateQr(Request $request)
    {
        Log::info('🔥 generateQr hit');

        $request->validate([
            'registration_id' => 'required|exists:registrations,id'
        ]);

        DB::beginTransaction();

        try {
            /* =======================
               1️⃣ Load registration
            ======================= */
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

            /* =======================
               2️⃣ Core values
            ======================= */
            $tranId = 'REG-' . $registration->id . '-' . time();

            $amount = number_format(
                $registration->payment_amount ?? $registration->registration_fee,
                2,
                '.',
                ''
            );

            $phone = preg_replace('/\D/', '', $registration->phone_number ?? '');
            if (str_starts_with($phone, '0')) {
                $phone = '855' . substr($phone, 1);
            }

            if (strlen($phone) < 11) {
                return response()->json(['error' => 'Invalid phone'], 422);
            }

            /* =======================
               3️⃣ RAW ITEMS (NO FLAGS)
            ======================= */
            $itemsRaw = json_encode([
                [
                    'name'     => 'Registration Fee',
                    'quantity' => 1,
                    'price'    => $amount,
                ]
            ]);

            $callbackUrl = config('payway.callback');
            $returnUrl   = config('payway.return');

            /* =======================
               4️⃣ BASE PAYLOAD
            ======================= */
            $paymentData = [
                'req_time'       => now()->format('YmdHis'),
                'merchant_id'    => config('payway.merchant_id'),
                'tran_id'        => $tranId,
                'amount'         => $amount,
                'items'          => null,
                'first_name'     => $registration->first_name ?? '',
                'last_name'      => $registration->last_name ?? '',
                'email'          => $registration->personal_email ?? '',
                'phone'          => $phone,
                'purchase_type'  => 'purchase',
                'payment_option' => 'abapay_khqr',
                'callback_url'   => null,
                'return_url'     => null,
                'currency'       => 'USD',
                'lifetime'       => '300',
                'qr_image_template' => 'template3_color',
            ];

            /* =======================
               5️⃣ HASH STRING (ABA EXACT)
            ======================= */
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
                $paymentData['currency'];

            /* =======================
               6️⃣ HASH
            ======================= */
            $paymentData['hash'] = base64_encode(
                hash_hmac(
                    'sha512',
                    $hashString,
                    config('payway.api_key'),
                    true
                )
            );

            /* =======================
               7️⃣ BASE64 AFTER HASH
            ======================= */
            $paymentData['items']        = base64_encode($itemsRaw);
            $paymentData['callback_url'] = base64_encode($callbackUrl);
            $paymentData['return_url']   = base64_encode($returnUrl);

            Log::info('PayWay Payload Ready', [
                'tran_id' => $tranId,
                'amount'  => $amount
            ]);

            /* =======================
               8️⃣ CALL PAYWAY
            ======================= */
            $response = Http::post(
                rtrim(config('payway.base_url'), '/') . '/generate-qr',
                $paymentData
            );

            if (!$response->successful()) {
                Log::error('PayWay Error', [
                    'status' => $response->status(),
                    'body'   => $response->body()
                ]);

                return response()->json([
                    'error' => 'PayWay error',
                    'detail'=> $response->json()
                ], $response->status());
            }

            $result = $response->json();

            /* =======================
               9️⃣ SAVE DB
            ======================= */
            DB::table('payment_transactions')->insert([
                'tran_id'    => $tranId,
                'status'     => 'PENDING',
                'amount'     => $amount,
                'created_at'=> now(),
                'updated_at'=> now(),
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

            return response()->json([
                'qr_image_url' => $result['qr_image_url'] ?? null,
                'qr_string'    => $result['qr_string'] ?? null,
                'tran_id'      => $tranId,
                'amount'       => $amount,
                'status'       => 'PENDING',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Generate QR Failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Generate QR failed'], 500);
        }
    }
}
