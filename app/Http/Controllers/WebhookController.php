<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    // fungsinya : cek data dimidtrans sama gak dgn yg ada didatabase
    public function midtransHandler(Request $request){
        $data = $request->all();
        $signatureKey = $data['signature_key'];

        // cocokin
        $orderId = $data['order_id'];
        $statusCode = $data['status_code'];
        $grossAmount = $data['gross_amount'];
        $serverKey = env('MIDTRANS_SERVER_KEY');
        $mySignatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        $transactionStatus = $data['transaction_status'];
        $type = $data['payment_type'];
        $fraudStatus = $data['fraud_status'];

        if($signatureKey !== $mySignatureKey){
            return response()->json([
                'status' => 'error',
                'message' => 'invalid signature'
            ], 400);
        }

        // 1-h23h2 -> OrderController@create
        $realOrderId = explode('-', $orderId);
        $order = Order::find($realOrderId[0]);

        if(!$order){
            return response()->json([
                'status' => 'error',
                'message' => 'order id not found'
            ], 404);
        }

        // kalau udh lunas bayar, tdk bisa bayar lagi
        if($order->status === 'success'){
            return response()->json([
                'status' => 'error',
                'message' => 'operation not permitted'
            ], 405);
        }

        // cek status pembayaran
        if($transactionStatus == 'capture'){
            if($fraudStatus =='challenge'){
                $order->status = 'challenge';
            }else if($fraudStatus =='accept'){
                $order->status = 'success';
            }
        }else if($transactionStatus == 'settlement'){
            $order->status = 'success';
        }else if($transactionStatus == 'cancel'
        || $transactionStatus == 'deny'
        || $transactionStatus == 'expire'
        ){
            $order->status = 'failure';
        }else if($transactionStatus == 'pending'){
            $order->status = 'pending';
        }

        $logsData = [
            'status' => $transactionStatus,
            'raw_response' => json_encode($data),
            'order_id' => $realOrderId[0],
            'payment_type' => $type
        ];

        PaymentLog::create($logsData);
        $order->save();

        if($order->status === 'success'){
            // memberikan akses premium -> service course
            createPremiumAccess([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id
            ]);
        }

        return response()->json('ok');
    }
}
