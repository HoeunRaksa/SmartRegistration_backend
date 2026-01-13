<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    private const API_KEY = 'bf2e45817599c11dcba44490cad0823a4fd0ee8c';
    private const PAYWAY_SANDBOX_URL = 'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/';
    public function generateQr(Request $request)
    {
        $validated = $request->validate([
            'registration_id' => 'required|exists:registrations,id'
        ]);

        DB::beginTransaction();

        try {
            // Get registration with major fee
            $registration = DB::table('registrations')
                ->join('majors', 'registrations.major_id', '=', 'majors.id')
                ->where('registrations.id', $validated['registration_id'])
                ->select('registrations.*', 'majors.registration_fee', 'majors.major_name')
                ->first();

            if (!$registration) {
                return response()->json(['error' => 'Registration not found'], 404);
            }

            if ($registration->payment_status === 'PAID') {
                return response()->json(['error' => 'Registration fee already paid'], 400);
            }

            // Generate unique transaction ID
            $tranId = 'REG-' . $registration->id . '-' . time();
            $amount = $registration->payment_amount ?? $registration->registration_fee;
            $rawPhone = $registration->phone_number ?? '';

            // Remove all non-numeric characters
            $phone = preg_replace('/\D/', '', $rawPhone);

            // Convert Cambodia local format to international
            // 012345678 → 85512345678
            if (strlen($phone) === 9 && str_starts_with($phone, '0')) {
                $phone = '855' . substr($phone, 1);
            }

            // Safety fallback (ABA REQUIRES phone)
            if (empty($phone)) {
                return response()->json([
                    'error' => 'Invalid phone number for payment'
                ], 422);
            }


            // Prepare PayWay data - EXACT structure they need
            $paymentData = [
                'req_time' => now()->format('YmdHis'),
                'merchant_id' => config('payway.merchant_id'),
                'tran_id' => $tranId,
                'amount' => number_format($amount, 2, '.', ''),

                'items' => base64_encode(json_encode([
                    [
                        'name' => 'Registration Fee',
                        'quantity' => 1,
                        'price' => $amount
                    ]
                ])),

                'first_name' => $registration->first_name ?? '',
                'last_name'  => $registration->last_name ?? '',
                'email'      => $registration->personal_email ?? '',
                'phone'      => $phone,

                'purchase_type'  => 'purchase',
                'payment_option' => 'abapay_khqr',

                // ✅ REQUIRED
                'callback_url' => base64_encode(config('payway.callback')),
                'return_url'   => base64_encode(config('payway.return')),

                // ❌ optional (can keep or remove)
                // 'return_deeplink' => base64_encode(config('payway.return')),

                'currency' => 'USD',
                'lifetime' => '300',
                'qr_image_template' => 'template3_color'
            ];




            // Generate hash - EXACT order matters
            $hashFields = [
                'req_time',
                'merchant_id',
                'tran_id',
                'amount',
                'items',
                'first_name',
                'last_name',
                'email',
                'phone',
                'purchase_type',
                'payment_option',
                'callback_url',
                'return_deeplink',
                'return_url',
                'currency',
                'lifetime',
                'qr_image_template'

            ];

            $hashString = '';
            foreach ($hashFields as $field) {
                $hashString .= $paymentData[$field] ?? '';
            }

            $paymentData['hash'] = base64_encode(
                hash_hmac(
                    'sha512',
                    $hashString,
                    config('payway.api_key'),
                    true
                )
            );


            Log::info('PayWay Request:', [
                'tran_id' => $tranId,
                'amount' => $amount,
                'data' => $paymentData
            ]);

            // Call PayWay API
            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post(
                config('payway.base_url') . 'generate-qr',
                $paymentData
            );
            if (!$response->successful()) {
                Log::error('PayWay API Error:', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'json' => $response->json()
                ]);

                return response()->json([
                    'error' => 'Payment gateway error',
                    'details' => $response->json() ?? $response->body()
                ], $response->status());
            }

            $result = $response->json();

            Log::info('PayWay Response:', $result);

            // Create payment transaction
            DB::table('payment_transactions')->insert([
                'tran_id' => $tranId,
                'status' => 'PENDING',
                'amount' => $amount,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Link to registration
            DB::table('registrations')
                ->where('id', $registration->id)
                ->update([
                    'payment_tran_id' => $tranId,
                    'payment_status' => 'PENDING',
                    'payment_amount' => $amount,
                    'updated_at' => now()
                ]);

            DB::commit();

            // Return PayWay response with our data
            return response()->json([
                'qr_image_url' => $result['qr_image_url'] ?? null,
                'qr_string' => $result['qr_string'] ?? null,
                'tran_id' => $tranId,
                'registration_id' => $registration->id,
                'amount' => $amount,
                'status' => 'PENDING'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Generate QR Error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to generate QR code',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PayWay callback - Updates payment status
     */
    public function paymentCallback(Request $request)
    {
        Log::info('PayWay Callback:', $request->all());

        $tranId = $request->input('tran_id');
        $statusCode = $request->input('status.code');
        $statusMsg = $request->input('status.message', '');

        $newStatus = ($statusCode === "0" || strtolower($statusMsg) === 'success') ? 'PAID' : 'FAILED';

        DB::beginTransaction();

        try {
            // Update payment transaction
            DB::table('payment_transactions')->updateOrInsert(
                ['tran_id' => $tranId],
                [
                    'status' => $newStatus,
                    'amount' => $request->input('amount', 0),
                    'updated_at' => now()
                ]
            );

            // Update registration
            if ($newStatus === 'PAID') {
                DB::table('registrations')
                    ->where('payment_tran_id', $tranId)
                    ->update([
                        'payment_status' => 'PAID',
                        'payment_date' => now(),
                        'updated_at' => now()
                    ]);
            } else {
                DB::table('registrations')
                    ->where('payment_tran_id', $tranId)
                    ->update([
                        'payment_status' => 'FAILED',
                        'updated_at' => now()
                    ]);
            }

            DB::commit();

            Log::info('Payment updated:', [
                'tran_id' => $tranId,
                'status' => $newStatus
            ]);

            return response()->json(['ack' => 'received'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Callback Error:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Check payment status
     */
    public function checkPaymentStatus($tranId)
    {
        $transaction = DB::table('payment_transactions')
            ->where('tran_id', $tranId)
            ->first();

        $status = $transaction->status ?? 'PENDING';

        $registration = DB::table('registrations')
            ->where('payment_tran_id', $tranId)
            ->first();

        return response()->json([
            'tran_id' => $tranId,
            'status' => [
                'code' => $status === 'PAID' ? '0' : '1',
                'message' => $status,
                'lang' => 'en',
            ],
            'payment_date' => $registration->payment_date ?? null,
            'registration_id' => $registration->id ?? null,
            'amount' => $transaction->amount ?? null
        ]);
    }

    /**
     * Get payment info for registration
     */
    public function getRegistrationPayment($registrationId)
    {
        $registration = DB::table('registrations')
            ->leftJoin('payment_transactions', 'registrations.payment_tran_id', '=', 'payment_transactions.tran_id')
            ->leftJoin('majors', 'registrations.major_id', '=', 'majors.id')
            ->where('registrations.id', $registrationId)
            ->select(
                'registrations.id',
                'registrations.payment_status',
                'registrations.payment_amount',
                'registrations.payment_date',
                'registrations.payment_tran_id',
                'payment_transactions.status as transaction_status',
                'payment_transactions.created_at as transaction_created',
                'majors.registration_fee',
                'majors.major_name'
            )
            ->first();

        if (!$registration) {
            return response()->json(['error' => 'Registration not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $registration
        ]);
    }
}
