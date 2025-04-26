<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Fee;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    protected $pawaPayController;

    /**
     * Constructor
     */
    public function __construct(PawaPayController $pawaPayController)
    {
        $this->pawaPayController = $pawaPayController;
    }

    /**
     * Create a new order
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'quantity' => 'required|integer|min:1',
            'installment_count' => 'required|integer|min:1',
            'payment_frequency' => 'required|string|in:daily,weekly,monthly',
            'reminder_type' => 'required|string|in:call,sms,email',
            'product_id' => 'required|exists:products,id',
            'phone_number' => 'required|string|min:9|max:15',
        ]);

        $product = Product::findOrFail($validatedData['product_id']);
        $productPrice = $product->price * $validatedData['quantity'];
        $orderFeePercentage = Fee::getActivePercentage('order');
        $orderFees = ceil($productPrice * ($orderFeePercentage / 100));
        $totalCost = $productPrice + $orderFees;
        $penaltyPercentage = Fee::getActivePercentage('penalty');
        $installmentAmount = ceil($totalCost / $validatedData['installment_count']);

        $order = Order::create([
            'user_id' => Auth::id(),
            'seller_id' => $product->shop->seller_id,
            'product_id' => $validatedData['product_id'],
            'quantity' => $validatedData['quantity'],
            'total_cost' => $totalCost,
            'fees' => $orderFees,
            'remaining_amount' => $totalCost,
            'installment_amount' => $installmentAmount,
            'installment_count' => $validatedData['installment_count'],
            'remaining_installments' => $validatedData['installment_count'],
            'payment_frequency' => $validatedData['payment_frequency'],
            'reminder_type' => $validatedData['reminder_type'],
            'penalty_percentage' => $penaltyPercentage,
            'is_confirmed' => false,
        ]);

        // Create payments for the order
        $this->createOrderPayments($order);

        // Get the first payment
        $firstPayment = $order->orderPayments()->orderBy('installment_number', 'asc')->first();

        if ($firstPayment) {
            $paymentRequest = new Request([
                'order_payment_id' => $firstPayment->id,
                'phone_number' => $validatedData['phone_number']
            ]);

            $paymentResponse = $this->pawaPayController->initiatePayment($paymentRequest);
            $paymentData = json_decode($paymentResponse->getContent(), true);

            if (isset($paymentData['status']) && $paymentData['status'] === 'success') {
                return response()->json([
                    'message' => 'Order created. Please complete the payment on your phone to confirm the order.',
                    'order' => $order->load('orderPayments'),
                    'payment_info' => $paymentData
                ]);
            } else {
                // If payment failed, delete the order
                $order->orderPayments()->delete();
                $order->delete();

                return response()->json([
                    'message' => 'Error initiating payment: ' . ($paymentData['message'] ?? 'An error occurred')
                ], 400);
            }
        }

        return response()->json([
            'message' => 'Order created but no installment could be configured.',
            'order' => $order->load('orderPayments')
        ]);
    }


    /**
     * Récupère toutes les commandes avec pagination et recherche.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllOrders(Request $request)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $query = Order::query();

        if ($request->has('keyword') && !empty($request->keyword)) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->whereHas('user', function ($query) use ($keyword) {
                    $query->where('name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                })
                    ->orWhereHas('product', function ($query) use ($keyword) {
                        $query->where('name', 'like', "%{$keyword}%");
                    })
                    ->orWhere('id', 'like', "%{$keyword}%");
            });
        }

        if ($request->has('status')) {
            if ($request->status === 'completed') {
                $query->where('is_completed', true);
            } elseif ($request->status === 'confirmed') {
                $query->where('is_confirmed', true)
                    ->where('is_completed', false);
            } elseif ($request->status === 'pending') {
                $query->where('is_confirmed', false);
            }
        }

        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->has('min_amount') && is_numeric($request->min_amount)) {
            $query->where('total_cost', '>=', $request->min_amount);
        }

        if ($request->has('max_amount') && is_numeric($request->max_amount)) {
            $query->where('total_cost', '<=', $request->max_amount);
        }

        $sortField = $request->sort_field ?? 'created_at';
        $sortDirection = $request->sort_direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        $perPage = $request->per_page ?? 15;
        $orders = $query->with(['user', 'product', 'seller.user', 'seller.shop', 'orderPayments'])
            ->paginate($perPage);

        return response()->json($orders);
    }


    /**
     * Retry payment to confirm a pending order
     */
    public function retryConfirmationPayment(Request $request, $orderId)
    {
        $request->validate([
            'phone_number' => 'required|string|min:9|max:15',
        ]);

        $user = Auth::user();
        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->where('is_confirmed', false)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found or already confirmed'
            ], 404);
        }

        // Get the first payment that is still pending
        $firstPayment = $order->orderPayments()
            ->where('installment_number', 1)
            ->where('status', 'pending')
            ->first();

        if (!$firstPayment) {
            return response()->json([
                'message' => 'No pending payment found for this order'
            ], 404);
        }

        // Use PawaPayController to initiate payment
        $paymentRequest = new Request([
            'order_payment_id' => $firstPayment->id,
            'phone_number' => $request->phone_number
        ]);

        return $this->pawaPayController->initiatePayment($paymentRequest);
    }

    /**
     * Get orders for the authenticated user
     */
    public function getUserOrders()
    {
        $user = Auth::user();
        $orders = Order::where('user_id', $user->id)
            ->with(['user', 'product.images', 'orderPayments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }

    /**
     * Get orders for the authenticated seller
     */
    public function getSellerOrders()
    {
        $seller = Auth::user()->seller;

        if (!$seller) {
            return response()->json(['message' => 'You are not a seller.'], 403);
        }

        $orders = Order::where('seller_id', $seller->id)
            ->with(['user', 'product.images', 'orderPayments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }

    /**
     * Cancel an order along with its associated order payments
     */
    public function cancelOrder(Request $request, $orderId)
    {
        $order = Order::where('id', $orderId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found or unauthorized access.'], 404);
        }

        $hasSuccessfulPayment = $order->orderPayments()->where('status', 'success')->exists();

        if ($hasSuccessfulPayment) {
            return response()->json(['message' => 'Cannot cancel an order with successful payments.'], 400);
        }

        // Delete payments and order
        $order->orderPayments()->delete();
        $order->delete();

        return response()->json(['message' => 'Order and associated payments successfully deleted.']);
    }

    /**
     * Get order details
     */
    public function getOrderDetails($id)
    {
        $user = Auth::user();

        $order = Order::where(function ($query) use ($user) {
            $query->where('user_id', $user->id);

            if ($user->seller) {
                $query->orWhere('seller_id', $user->seller->id);
            }
        })
            ->where('id', $id)
            ->with(['user', 'product.images', 'orderPayments.momoTransaction'])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found or unauthorized access.'], 404);
        }

        return response()->json($order);
    }

    /**
     * Get unconfirmed orders for the authenticated user
     */
    public function getUnconfirmedOrders()
    {
        $user = Auth::user();

        $unconfirmedOrders = Order::where('user_id', $user->id)
            ->where('is_confirmed', false)
            ->with([
                'product.images',
                'orderPayments' => function ($query) {
                    $query->where('installment_number', 1);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($unconfirmedOrders);
    }

    /**
     * Creates OrderPayments for a given Order
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
        $installmentAmount = ceil($order->total_cost / $order->installment_count);

        for ($i = 1; $i <= $order->installment_count; $i++) {
            $dueDate = $startDate->copy()->add($interval)->toDateTimeString();

            OrderPayment::create([
                'order_id' => $order->id,
                'amount_paid' => $installmentAmount,
                'penalty_fees' => 0,
                'due_date' => $dueDate,
                'payment_date' => null,
                'status' => 'pending',
                'is_late' => false,
                'installment_number' => $i,
            ]);

            $startDate->add($interval);
        }
    }
}