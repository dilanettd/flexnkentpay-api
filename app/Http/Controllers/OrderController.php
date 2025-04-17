<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    /**
     * Create a new order
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'quantity' => 'required|integer|min:1',
            'installment_count' => 'required|integer|min:1',
            'installment_amount' => 'required|integer|min:1',
            'total_amount' => 'required|integer|min:1',
            'payment_frequency' => 'required|string|in:daily,weekly,monthly',
            'reminder_type' => 'required|string|in:call,sms,email',
            'product_id' => 'required|exists:products,id',
        ]);

        $product = Product::findOrFail($validatedData['product_id']);

        $totalCost = $product->price * $validatedData['quantity'];
        $penaltyPercentage = env('PENALTY_PERCENTAGE', 1.5);
        $totalCost = $validatedData['total_amount'];

        $order = Order::create([
            'user_id' => Auth::id(),
            'seller_id' => $product->shop->seller_id,
            'product_id' => $validatedData['product_id'],
            'quantity' => $validatedData['quantity'],
            'total_cost' => $totalCost,
            'remaining_amount' => $totalCost,
            'installment_count' => $validatedData['installment_count'],
            'remaining_installments' => $validatedData['installment_count'],
            'installment_amount' => $validatedData['installment_amount'],
            'payment_frequency' => $validatedData['payment_frequency'],
            'reminder_type' => $validatedData['reminder_type'],
            'penalty_percentage' => $penaltyPercentage,
        ]);

        $this->createOrderPayments($order);

        return $order->load('orderPayments');
    }


    /**
     * Creates OrderPayments for a given Order.
     *
     * @param \App\Models\Order $order
     * @return void
     */
    private function createOrderPayments(Order $order)
    {
        $startDate = now();

        $frequencyMapping = [
            'daily' => '1 day',
            'weekly' => '1 week',
            'monthly' => '1 month',
        ];

        $interval = $frequencyMapping[$order->payment_frequency] ?? '1 day';

        for ($i = 1; $i <= $order->installment_count; $i++) {
            $dueDate = $startDate->copy()->add($interval)->toDateTimeString();

            OrderPayment::create([
                'order_id' => $order->id,
                'amount_paid' => $order->installment_amount,
                'due_date' => $dueDate,
                'payment_date' => null,
                'status' => 'pending',
                'is_late' => false,
                'installment_number' => $i,
            ]);

            $startDate->add($interval);
        }
    }

    public function getUserOrders()
    {
        $user = Auth::user();
        $orders = Order::where('user_id', $user->id)
            ->with(['user', 'product.images', 'orderPayments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $orders;
    }

    public function getSellerOrders()
    {
        $seller = Auth::user()->seller;
        $orders = Order::where('seller_id', $seller->id)
            ->with(['user', 'product.images', 'orderPayments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $orders;
    }

    /**
     * Delete an order along with its associated order payments.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelOrder(Request $request, $id)
    {
        $order = Order::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found or unauthorized access.'], 404);
        }

        $hasSuccessfulPayment = $order->orderPayments()->where('status', 'success')->exists();

        if ($hasSuccessfulPayment) {
            return response()->json(['message' => 'Cannot cancel an order with successful payments.'], 400);
        }


        $order->orderPayments()->delete();

        $order->delete();

        return response()->json(['message' => 'Order and associated payments successfully deleted.']);
    }


}
