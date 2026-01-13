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

    /**
     * PayWay callback - Updates both payment_transactions AND registrations
     */
    public function paymentCallback(Request $request)
    {
        Log::info('PayWay callback received:', $request->all());

        $tranId = $request->input('tran_id');
        $statusCode = data_get($request->all(), 'status.code', null);
        $statusMsg  = data_get($request->all(), 'status.message', '');

        $newStatus = ($statusCode === "0" || strtolower($statusMsg) === 'success') ? 'PAID' : 'FAILED';

        DB::beginTransaction();
        
        try {
            // Update payment_transactions table
            DB::table('payment_transactions')->updateOrInsert(
                ['tran_id' => $tranId],
                [
                    'status' => $newStatus,
                    'amount' => $request->input('amount', 0),
                    'updated_at' => now()
                ]
            );

            // Update registration payment status
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

            Log::info('Payment status updated successfully', [
                'tran_id' => $tranId,
                'status' => $newStatus
            ]);

            return response()->json(['ack' => 'received'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment callback error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Generate QR code for registration payment
     */
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
                return response()->json([
                    'error' => 'Registration fee already paid'
                ], 400);
            }

            // Generate unique transaction ID
            $tranId = 'REG-' . $registration->id . '-' . time();
            $amount = $registration->payment_amount ?? $registration->registration_fee;

            // Prepare PayWay data
            $data = [
                'req_time' => now()->format('YmdHis'),
                'merchant_id' => env('PAYWAY_MERCHANT_ID', 'your_merchant_id'),
                'tran_id' => $tranId,
                'amount' => number_format($amount, 2, '.', ''),
                'items' => "Registration Fee - {$registration->major_name}",
                'first_name' => $registration->first_name ?? '',
                'last_name' => $registration->last_name ?? '',
                'email' => $registration->personal_email ?? '',
                'phone' => $registration->phone_number ?? '',
                'purchase_type' => 'Registration Fee',
                'payment_option' => 'khqr',
                'callback_url' => url('/api/payment/callback'),
                'return_deeplink' => url('/payment-success'),
                'currency' => 'USD',
                'custom_fields' => json_encode([
                    'registration_id' => $registration->id,
                    'department_id' => $registration->department_id,
                    'major_id' => $registration->major_id
                ]),
                'return_params' => $tranId,
                'payout' => '',
                'lifetime' => '300', // 5 minutes
                'qr_image_template' => 'standard'
            ];

            // Generate hash
            $fields = [
                'req_time', 'merchant_id', 'tran_id', 'amount', 'items',
                'first_name', 'last_name', 'email', 'phone', 'purchase_type',
                'payment_option', 'callback_url', 'return_deeplink', 'currency',
                'custom_fields', 'return_params', 'payout', 'lifetime', 'qr_image_template'
            ];

            $concat = '';
            foreach ($fields as $f) {
                $concat .= $data[$f] ?? '';
            }
            $data['hash'] = base64_encode(hash_hmac('sha512', $concat, self::API_KEY, true));

            // Call PayWay API
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post(self::PAYWAY_SANDBOX_URL . 'generate-qr', $data);

            if (!$response->successful()) {
                Log::error("PayWay QR Generation Failed:", $response->json() ?? ['body' => $response->body()]);
                return response()->json($response->json() ?? ['error' => 'API call failed'], Response::HTTP_BAD_REQUEST);
            }

            $result = $response->json();

            // Create payment transaction record
            DB::table('payment_transactions')->insert([
                'tran_id' => $tranId,
                'status' => 'PENDING',
                'amount' => $amount,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Link transaction to registration
            DB::table('registrations')
                ->where('id', $registration->id)
                ->update([
                    'payment_tran_id' => $tranId,
                    'payment_status' => 'PENDING',
                    'updated_at' => now()
                ]);

            DB::commit();

            $result['tran_id'] = $tranId;
            $result['registration_id'] = $registration->id;
            $result['amount'] = $amount;

            return response()->json($result);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Generate QR error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to generate QR code'], 500);
        }
    }

    /**
     * Check payment status (polling endpoint)
     */
    public function checkPaymentStatus($tranId)
    {
        $transaction = DB::table('payment_transactions')
            ->where('tran_id', $tranId)
            ->first();

        $status = $transaction->status ?? 'PENDING';

        // Also return registration info
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
            'registration_id' => $registration->id ?? null
        ]);
    }

    /**
     * Get payment history for a registration
     */
    public function getRegistrationPayment($registrationId)
    {
        $registration = DB::table('registrations')
            ->leftJoin('payment_transactions', 'registrations.payment_tran_id', '=', 'payment_transactions.tran_id')
            ->where('registrations.id', $registrationId)
            ->select(
                'registrations.id',
                'registrations.payment_status',
                'registrations.payment_amount',
                'registrations.payment_date',
                'registrations.payment_tran_id',
                'payment_transactions.status as transaction_status',
                'payment_transactions.created_at as transaction_created'
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