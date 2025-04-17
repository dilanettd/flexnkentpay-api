<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\MomoTransaction;
use App\Models\OrderPayment;
use App\Models\Order;

class MomoTransactionController extends Controller
{
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'order_payment_id' => 'required|exists:order_payments,id',
            'phone_number' => 'required',
        ]);

        $user = Auth::user();
        $orderPayment = OrderPayment::findOrFail($validatedData['order_payment_id']);

        if ($orderPayment->status === 'success') {
            return response()->json([
                'message' => 'This payment has already been processed successfully.'
            ], 400);
        }

        $amount = $orderPayment->amount_paid;
        $feePercentage = config('app.momo_fee_percentage', 1.5);
        $fees = $amount * ($feePercentage / 100);

        $transactionId = 'txn-' . strtoupper(uniqid());

        $transaction = MomoTransaction::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'phone_number' => $validatedData['phone_number'],
            'amount' => $amount,
            'fees' => $fees,
            'status' => 'success',
        ]);

        $orderPayment->update([
            'momo_transaction_id' => $transaction->id,
            'payment_date' => now(),
            'status' => 'success',
        ]);

        $order = $orderPayment->order;
        $order->remaining_amount -= $amount;
        $order->remaining_installments -= 1;

        if ($order->remaining_amount <= 0) {
            $order->is_completed = true;
        }

        $order->save();

        return $transaction;
    }


    public function getUserTransactions()
    {
        $user = Auth::user();

        $transactions = MomoTransaction::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $transactions;
    }
}
