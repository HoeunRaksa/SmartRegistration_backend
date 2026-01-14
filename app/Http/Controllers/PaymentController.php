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

            $itemsRaw = json_encode([
                [
                    'name' => 'Registration Fee',
                    'quantity' => 1,
                    'price' => $amount
                ]
            ], JSON_UNESCAPED_SLASHES);

            // BASE64 FIRST (IMPORTANT)
            $itemsEncoded    = base64_encode($itemsRaw);
            $callbackEncoded = base64_encode(config('payway.callback'));
            $returnEncoded   = base64_encode(config('payway.return'));

            $reqTime = now()->format('YmdHis');

            // ✅ EXACT ABA HASH ORDER
            $hashString =
                $reqTime .
                config('payway.merchant_id') .
                $tranId .
                $amount .
                $itemsEncoded .
                ($registration->first_name ?? '') .
                ($registration->last_name ?? '') .
                ($registration->personal_email ?? '') .
                $phone .
                'purchase' .
                'abapay_khqr' .
                $callbackEncoded .
                $returnEncoded .
                'USD';

            $hash = base64_encode(
                hash_hmac(
                    'sha512',
                    $hashString,
                    config('payway.api_key'),
                    true
                )
            );

            $payload = [
                'req_time'       => $reqTime,
                'merchant_id'    => config('payway.merchant_id'),
                'tran_id'        => $tranId,
                'amount'         => $amount,
                'items'          => $itemsEncoded,
                'first_name'     => $registration->first_name ?? '',
                'last_name'      => $registration->last_name ?? '',
                'email'          => $registration->personal_email ?? '',
                'phone'          => $phone,
                'purchase_type'  => 'purchase',
                'payment_option' => 'abapay_khqr',
                'callback_url'   => $callbackEncoded,
                'return_url'     => $returnEncoded,
                'currency'       => 'USD',
                'hash'           => $hash,
            ];

            Log::info('PayWay Payload', $payload);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post(
                'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/purchase',
                $payload
            );

            if (!$response->successful()) {
                Log::error('PayWay Error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json($response->json(), 403);
            }

            DB::table('payment_transactions')->insert([
                'tran_id' => $tranId,
                'status' => 'PENDING',
                'amount' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('registrations')
                ->where('id', $registration->id)
                ->update([
                    'payment_tran_id' => $tranId,
                    'payment_status' => 'PENDING',
                    'payment_amount' => $amount,
                ]);

            DB::commit();

            return response()->json([
                'tran_id' => $tranId,
                'status' => 'PENDING',
                'data' => $response->json()
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['error' => 'failed'], 500);
        }
    }

    public function paymentCallback(Request $request)
    {
        DB::table('payment_transactions')
            ->where('tran_id', $request->tran_id)
            ->update([
                'status' => $request->payment_status_code == 0 ? 'PAID' : 'FAILED',
                'updated_at' => now()
            ]);

        DB::table('registrations')
            ->where('payment_tran_id', $request->tran_id)
            ->update([
                'payment_status' => $request->payment_status_code == 0 ? 'PAID' : 'FAILED',
                'payment_date' => now()
            ]);

        return response()->json(['ack' => 'ok']);
    }

    public function checkPaymentStatus($tranId)
    {
        $tx = DB::table('payment_transactions')->where('tran_id', $tranId)->first();

        return response()->json([
            'tran_id' => $tranId,
            'status' => [
                'code' => $tx?->status === 'PAID' ? '0' : '1',
                'message' => $tx?->status ?? 'PENDING',
                'lang' => 'en'
            ]
        ]);
    }

    public function getRegistrationPayment($registrationId)
    {
        $data = DB::table('registrations')
            ->leftJoin('payment_transactions', 'registrations.payment_tran_id', '=', 'payment_transactions.tran_id')
            ->where('registrations.id', $registrationId)
            ->first();

        return response()->json(['data' => $data]);
    }
}
