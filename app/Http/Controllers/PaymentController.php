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

    // Callback from PayWay
    public function paymentCallback(Request $request)
    {
        $tranId = $request->input('tran_id');
        $statusCode = data_get($request->all(), 'status.code', null);
        $statusMsg  = data_get($request->all(), 'status.message', '');

        $newStatus = ($statusCode === "0" || strtolower($statusMsg) === 'success') ? 'PAID' : 'FAILED';

        DB::table('payment_transactions')->updateOrInsert(
            ['tran_id' => $tranId],
            [
                'status' => $newStatus,
                'amount' => $request->input('amount', 0),
                'updated_at' => now()
            ]
        );

        return response()->json(['ack' => 'received'], 200);
    }

    // Generate QR
    public function generateQr(Request $request)
    {
        $data = $request->all();
        $data['req_time'] = now()->format('YmdHis');
        $data['amount'] = number_format($data['amount'], 2, '.', '');

        $fields = [
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
            'currency',
            'custom_fields',
            'return_params',
            'payout',
            'lifetime',
            'qr_image_template'
        ];

        $concat = '';
        foreach ($fields as $f) {
            $concat .= $data[$f] ?? '';
        }
        $data['hash'] = base64_encode(hash_hmac('sha512', $concat, self::API_KEY, true));

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post(self::PAYWAY_SANDBOX_URL . 'generate-qr', $data);

        if (!$response->successful()) {
            Log::error("PayWay QR Generation Failed:", $response->json() ?? ['body' => $response->body()]);
            return response()->json($response->json() ?? ['error' => 'API call failed'], Response::HTTP_BAD_REQUEST);
        }

        $result = $response->json();

        DB::table('payment_transactions')->updateOrInsert(
            ['tran_id' => $data['tran_id']],
            ['status' => 'PENDING', 'amount' => $data['amount'], 'created_at' => now()]
        );
        $result['tran_id'] = $data['tran_id'];

        return response()->json($result);
    }

    // Polling endpoint
    public function checkPaymentStatus($tranId)
    {
        $status = DB::table('payment_transactions')
            ->where('tran_id', $tranId)
            ->value('status') ?? 'PENDING';

        return response()->json([
            'tran_id' => $tranId,
            'status' => [
                'code' => $status === 'PAID' ? '0' : '1',
                'message' => $status,
                'lang' => 'en',
            ]
        ]);
    }
}
