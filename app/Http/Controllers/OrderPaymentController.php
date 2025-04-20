<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\Auth;

class OrderPaymentController extends Controller
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
     * Initie le paiement d'un versement en utilisant le PawaPayController.
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

        // Préparer une requête pour le PawaPayController
        $paymentRequest = new Request([
            'order_payment_id' => $nextPayment->id,
            'phone_number' => $request->phone_number
        ]);

        // Déléguer l'initialisation du paiement au PawaPayController
        return $this->pawaPayController->initiatePayment($paymentRequest);
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