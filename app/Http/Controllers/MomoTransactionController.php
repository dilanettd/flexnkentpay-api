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

    /**
     * Récupère toutes les transactions avec pagination et recherche pour l'administration.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllTransactions(Request $request)
    {
        // Vérifier que l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $query = MomoTransaction::query();

        // Recherche par mot-clé
        if ($request->has('keyword') && !empty($request->keyword)) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('transaction_id', 'like', "%{$keyword}%")
                    ->orWhere('provider_transaction_id', 'like', "%{$keyword}%")
                    ->orWhere('phone_number', 'like', "%{$keyword}%")
                    ->orWhereHas('user', function ($query) use ($keyword) {
                        $query->where('name', 'like', "%{$keyword}%")
                            ->orWhere('email', 'like', "%{$keyword}%");
                    });
            });
        }

        // Filtrage par statut
        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        // Filtrage par type de fournisseur
        if ($request->has('provider_type') && !empty($request->provider_type)) {
            $query->where('provider_type', $request->provider_type);
        }

        // Filtrage par montant
        if ($request->has('min_amount') && is_numeric($request->min_amount)) {
            $query->where('amount', '>=', $request->min_amount);
        }

        if ($request->has('max_amount') && is_numeric($request->max_amount)) {
            $query->where('amount', '<=', $request->max_amount);
        }

        // Filtrage par date
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Tri
        $sortField = $request->sort_field ?? 'created_at';
        $sortDirection = $request->sort_direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->per_page ?? 15;
        $transactions = $query->with(['user', 'payment.order.product'])
            ->paginate($perPage);

        return response()->json($transactions);
    }
}
