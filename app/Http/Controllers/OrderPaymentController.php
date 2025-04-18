<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\MomoTransaction;
use App\Enums\PawaPayTransactionStatus;
use App\Enums\ProviderType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class OrderPaymentController extends Controller
{
    /**
     * Initie le paiement d'un versement.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initiatePayment(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'phone_number' => 'required|string|min:9|max:15',
        ]);

        $user = Auth::user();
        $order = Order::where('id', $request->order_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Commande non trouvée ou non autorisée'
            ], 404);
        }

        if ($order->is_completed) {
            return response()->json([
                'message' => 'Cette commande est déjà complètement payée'
            ], 400);
        }

        if (!$order->is_confirmed && $order->orderPayments()->where('installment_number', 1)->where('status', 'success')->count() === 0) {
            // Si la commande n'est pas confirmée, on doit payer le premier versement
            $nextPayment = $order->orderPayments()->where('installment_number', 1)->first();
        } else {
            // Sinon, on cherche le prochain versement à payer
            $nextPayment = $order->orderPayments()
                ->where('status', 'pending')
                ->orderBy('installment_number', 'asc')
                ->first();
        }

        if (!$nextPayment) {
            return response()->json([
                'message' => 'Aucun versement en attente trouvé pour cette commande'
            ], 404);
        }

        // Calculer les frais de pénalité si le paiement est en retard
        $nextPayment->calculatePenaltyFees();

        // Vérifier si une transaction en cours existe déjà
        $pendingTransaction = MomoTransaction::whereIn('status', [PawaPayTransactionStatus::ACCEPTED->value, PawaPayTransactionStatus::PENDING->value])
            ->whereHas('payment', function ($query) use ($nextPayment) {
                $query->where('id', $nextPayment->id);
            })
            ->first();

        if ($pendingTransaction && $pendingTransaction->status === PawaPayTransactionStatus::ACCEPTED->value) {
            return response()->json([
                'message' => 'Un paiement est déjà en cours de traitement pour ce versement. Veuillez vérifier votre téléphone ou réessayer plus tard.'
            ], 400);
        }

        // Générer un ID de transaction unique
        $transactionId = 'momo_' . Str::uuid();

        // Montant total à payer (versement + pénalités)
        $totalAmount = $nextPayment->amount_paid + $nextPayment->penalty_fees;

        // Créer la transaction MoMo
        $transaction = MomoTransaction::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'provider_transaction_id' => null,
            'phone_number' => $request->phone_number,
            'amount' => $totalAmount,
            'fees' => 0, // Les frais peuvent être ajustés selon votre politique
            'status' => PawaPayTransactionStatus::PENDING->value,
            'provider_type' => ProviderType::PAWAPAY->value,
        ]);

        // Lier la transaction au paiement
        $nextPayment->momo_transaction_id = $transaction->id;
        $nextPayment->save();

        try {
            // Appel au contrôleur PawaPayTransactionController pour initier le paiement
            $response = Http::post(route('initiate.pawapay.payment'), [
                'order_payment_id' => $nextPayment->id,
                'phone_number' => $request->phone_number,
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                if ($responseData['status'] === 'success') {
                    return response()->json([
                        'message' => 'Paiement initié avec succès. Veuillez compléter le paiement sur votre téléphone.',
                        'payment_info' => [
                            'order_id' => $order->id,
                            'payment_id' => $nextPayment->id,
                            'installment_number' => $nextPayment->installment_number,
                            'amount' => $nextPayment->amount_paid,
                            'penalty_fees' => $nextPayment->penalty_fees,
                            'total_amount' => $totalAmount,
                            'transaction' => $responseData['transaction'] ?? null
                        ]
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
            Log::error('Exception lors de l\'initiation du paiement', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'order_id' => $order->id,
                'payment_id' => $nextPayment->id,
                'trace' => $e->getTraceAsString()
            ]);

            // Mettre à jour le statut de la transaction
            $transaction->status = PawaPayTransactionStatus::FAILED->value;
            $transaction->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la communication avec le service de paiement',
            ], 500);
        }
    }

    /**
     * Récupère l'historique des paiements pour une commande.
     *
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderPayments($orderId)
    {
        $user = Auth::user();

        $order = Order::where(function ($query) use ($user) {
            $query->where('user_id', $user->id);

            if ($user->seller) {
                $query->orWhere('seller_id', $user->seller->id);
            }
        })
            ->where('id', $orderId)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Commande non trouvée ou non autorisée'], 404);
        }

        $payments = $order->orderPayments()
            ->with('momoTransaction')
            ->orderBy('installment_number', 'asc')
            ->get();

        return response()->json($payments);
    }

    /**
     * Récupère les détails d'un paiement spécifique.
     *
     * @param int $paymentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentDetails($paymentId)
    {
        $user = Auth::user();

        $payment = OrderPayment::with(['order', 'momoTransaction'])
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id);

                if ($user->seller) {
                    $query->orWhere('seller_id', $user->seller->id);
                }
            })
            ->where('id', $paymentId)
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Paiement non trouvé ou non autorisé'], 404);
        }

        return response()->json($payment);
    }

    /**
     * Récupère les prochains paiements en attente pour l'utilisateur.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPendingPayments()
    {
        $user = Auth::user();

        $pendingPayments = OrderPayment::with(['order.product'])
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('is_completed', false);
            })
            ->where('status', 'pending')
            ->orderBy('due_date', 'asc')
            ->get();

        foreach ($pendingPayments as $payment) {
            $payment->calculatePenaltyFees();
        }

        return response()->json($pendingPayments);
    }
}