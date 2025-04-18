<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Fee;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\MomoTransaction;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    const PAWAPAY_STATUS_PENDING = 'pending';
    const PAWAPAY_STATUS_ACCEPTED = 'accepted';
    const PAWAPAY_STATUS_COMPLETED = 'completed';
    const PAWAPAY_STATUS_FAILED = 'failed';

    const PROVIDER_TYPE_PAWAPAY = 'pawapay';

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

        // Créer les paiements pour la commande
        $this->createOrderPayments($order);

        // Récupérer le premier paiement
        $firstPayment = $order->orderPayments()->orderBy('installment_number', 'asc')->first();

        if ($firstPayment) {
            // Initier le paiement du premier versement via PawaPay
            $paymentResult = $this->initiateFirstPayment($order, $firstPayment, $validatedData['phone_number']);

            if ($paymentResult['status'] === 'success') {
                return response()->json([
                    'message' => 'Commande créée. Veuillez compléter le paiement sur votre téléphone pour confirmer la commande.',
                    'order' => $order->load('orderPayments'),
                    'payment_info' => $paymentResult['data']
                ]);
            } else {
                // Si le paiement a échoué, on supprime la commande
                $order->orderPayments()->delete();
                $order->delete();

                return response()->json([
                    'message' => 'Erreur lors de l\'initiation du paiement: ' . $paymentResult['message']
                ], 400);
            }
        }

        return response()->json([
            'message' => 'Commande créée mais aucun versement n\'a pu être configuré.',
            'order' => $order->load('orderPayments')
        ]);
    }

    /**
     * Réessayer le paiement pour confirmer une commande en attente.
     *
     * @param Request $request
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
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
                'message' => 'Commande non trouvée ou déjà confirmée'
            ], 404);
        }

        // Récupérer le premier paiement qui est toujours en attente
        $firstPayment = $order->orderPayments()
            ->where('installment_number', 1)
            ->where('status', 'pending')
            ->first();

        if (!$firstPayment) {
            return response()->json([
                'message' => 'Aucun paiement en attente trouvé pour cette commande'
            ], 404);
        }

        // Vérifier si une transaction en cours existe déjà
        $pendingTransaction = MomoTransaction::whereIn('status', [self::PAWAPAY_STATUS_ACCEPTED, self::PAWAPAY_STATUS_PENDING])
            ->whereHas('payment', function ($query) use ($firstPayment) {
                $query->where('id', $firstPayment->id);
            })
            ->first();

        if ($pendingTransaction && $pendingTransaction->status === self::PAWAPAY_STATUS_ACCEPTED) {
            return response()->json([
                'message' => 'Un paiement est déjà en cours de traitement pour cette commande. Veuillez vérifier votre téléphone ou réessayer plus tard.'
            ], 400);
        }

        // Créer une nouvelle transaction
        $transactionId = 'momo_' . Str::uuid();

        $transaction = MomoTransaction::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'provider_transaction_id' => null,
            'phone_number' => $request->phone_number,
            'amount' => $firstPayment->amount_paid,
            'fees' => 0,
            'status' => self::PAWAPAY_STATUS_PENDING,
            'provider_type' => self::PROVIDER_TYPE_PAWAPAY,
        ]);

        // Lier la transaction au paiement
        $firstPayment->momo_transaction_id = $transaction->id;
        $firstPayment->save();

        try {
            // Appel au contrôleur PawaPayTransactionController pour initier le paiement
            $response = Http::post(route('initiate.pawapay.payment'), [
                'order_payment_id' => $firstPayment->id,
                'phone_number' => $request->phone_number,
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                if ($responseData['status'] === 'success') {
                    return response()->json([
                        'message' => 'Paiement initié avec succès. Veuillez compléter le paiement sur votre téléphone pour confirmer la commande.',
                        'payment_info' => $responseData
                    ]);
                } else {
                    return response()->json([
                        'message' => $responseData['message'] ?? 'Erreur lors de l\'initiation du paiement',
                        'details' => $responseData
                    ], 400);
                }
            } else {
                throw new \Exception('Erreur lors de la communication avec le service de paiement: ' . $response->status());
            }
        } catch (\Exception $e) {
            // Log l'erreur
            Log::error('Exception lors de l\'appel à PawaPayTransactionController', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString()
            ]);

            // Mettre à jour le statut de la transaction
            $transaction->status = self::PAWAPAY_STATUS_FAILED;
            $transaction->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la communication avec le service de paiement',
            ], 500);
        }
    }

    /**
     * Initie le paiement du premier versement via PawaPay.
     *
     * @param Order $order
     * @param OrderPayment $payment
     * @param string $phoneNumber Le numéro de téléphone fourni lors de la création de la commande
     * @return array
     */
    private function initiateFirstPayment(Order $order, OrderPayment $payment, string $phoneNumber)
    {
        // Générer un ID de transaction unique
        $transactionId = 'momo_' . Str::uuid();

        // Créer la transaction MoMo
        $transaction = MomoTransaction::create([
            'user_id' => $order->user_id,
            'transaction_id' => $transactionId,
            'provider_transaction_id' => null, // Sera mis à jour après la réponse de PawaPay
            'phone_number' => $phoneNumber,
            'amount' => $payment->amount_paid,
            'fees' => 0, // Les frais peuvent être ajustés selon votre politique
            'status' => self::PAWAPAY_STATUS_PENDING,
            'provider_type' => self::PROVIDER_TYPE_PAWAPAY,
        ]);

        // Lier la transaction au paiement de la commande
        $payment->momo_transaction_id = $transaction->id;
        $payment->save();

        try {
            // Appel au contrôleur PawaPayTransactionController pour initier le paiement
            $response = Http::post(route('initiate.pawapay.payment'), [
                'order_payment_id' => $payment->id,
                'phone_number' => $phoneNumber,
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                if ($responseData['status'] === 'success') {
                    // Mettre à jour la transaction avec l'ID PawaPay
                    if (isset($responseData['transaction']['provider_transaction_id'])) {
                        $transaction->provider_transaction_id = $responseData['transaction']['provider_transaction_id'];
                        $transaction->save();
                    }

                    return [
                        'status' => 'success',
                        'message' => 'Paiement initié avec succès',
                        'data' => $responseData
                    ];
                }
            }

            // En cas d'échec
            $transaction->status = self::PAWAPAY_STATUS_FAILED;
            $transaction->save();

            return [
                'status' => 'error',
                'message' => $response->json()['message'] ?? 'Erreur lors de l\'initiation du paiement'
            ];
        } catch (\Exception $e) {
            // En cas d'exception
            $transaction->status = self::PAWAPAY_STATUS_FAILED;
            $transaction->save();

            // Logging de l'erreur pour le débogage
            Log::error('Exception lors de l\'initiation du premier paiement', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => 'Erreur lors de la communication avec le service de paiement: ' . $e->getMessage()
            ];
        }
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


    /**
     * Get orders for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
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
     * Get orders for the authenticated seller.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSellerOrders()
    {
        $seller = Auth::user()->seller;

        if (!$seller) {
            return response()->json(['message' => 'Vous n\'êtes pas un vendeur.'], 403);
        }

        $orders = Order::where('seller_id', $seller->id)
            ->with(['user', 'product.images', 'orderPayments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
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

        // Supprimer les transactions MoMo associées
        $orderPaymentIds = $order->orderPayments()->pluck('id')->toArray();
        MomoTransaction::whereIn('momo_transaction_id', $orderPaymentIds)->delete();

        // Supprimer les paiements et la commande
        $order->orderPayments()->delete();
        $order->delete();

        return response()->json(['message' => 'Order and associated payments successfully deleted.']);
    }

    /**
     * Get order details.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
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
            return response()->json(['message' => 'Commande non trouvée ou accès non autorisé.'], 404);
        }

        return response()->json($order);
    }

    /**
     * Get unconfirmed orders for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
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
}