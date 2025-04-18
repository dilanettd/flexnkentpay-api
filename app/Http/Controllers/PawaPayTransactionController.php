<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\MomoTransaction;
use App\Models\OrderPayment;
use App\Enums\PawaPayTransactionStatus;
use App\Enums\ProviderType;
use App\Services\PawaPaySignatureService;

class PawaPayTransactionController extends Controller
{
    protected $signatureService;

    /**
     * Constructor
     */
    public function __construct(PawaPaySignatureService $signatureService = null)
    {
        // Si le service n'est pas injecté, créez une instance par défaut
        $this->signatureService = $signatureService ?: new PawaPaySignatureService();
    }

    /**
     * Initie un paiement via PawaPay.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initiatePayment(Request $request)
    {
        $request->validate([
            'order_payment_id' => 'required|exists:order_payments,id',
            'phone_number' => 'required|string',
        ]);

        $user = Auth::user();
        $orderPayment = OrderPayment::findOrFail($request->order_payment_id);

        if ($orderPayment->status === 'success') {
            return response()->json([
                'message' => 'Ce paiement a déjà été traité avec succès.'
            ], 400);
        }

        // Vérifier si c'est le premier paiement mais que la commande est déjà confirmée
        $order = $orderPayment->order;
        if ($orderPayment->installment_number == 1 && $order->is_confirmed) {
            return response()->json([
                'message' => 'Cette commande est déjà confirmée.'
            ], 400);
        }

        // Les montants sont déjà dans le bon format
        $amount = $orderPayment->amount_paid;
        $feePercentage = config('services.pawapay.fee_percentage', 1.5);
        $fees = $amount * ($feePercentage / 100);

        // Générer un ID de transaction unique
        $transactionId = 'momo_' . Str::uuid();

        // Créer la transaction MoMo
        $transaction = MomoTransaction::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'provider_transaction_id' => null, // Sera mis à jour après la réponse de PawaPay
            'phone_number' => $request->phone_number,
            'amount' => $amount,
            'fees' => $fees,
            'status' => PawaPayTransactionStatus::PENDING->value,
            'provider_type' => ProviderType::PAWAPAY->value,
        ]);

        // Lier la transaction au paiement de la commande
        $orderPayment->momo_transaction_id = $transaction->id;
        $orderPayment->save();

        try {
            // Appel à l'API PawaPay avec signature
            $pawaPayResponse = $this->callPawaPayApi('deposits', [
                'payer' => [
                    'type' => 'MSISDN',
                    'address' => [
                        'value' => $request->phone_number
                    ],
                ],
                'amount' => $amount,
                'currency' => 'XAF', // À adapter selon votre contexte
                'externalId' => $transactionId,
                'metadata' => [
                    'user_id' => $user->id,
                    'order_payment_id' => $orderPayment->id,
                    'order_id' => $order->id,
                    'installment_number' => $orderPayment->installment_number
                ],
                'statementDescription' => 'Paiement commande #' . $orderPayment->order_id . ' (Versement ' . $orderPayment->installment_number . '/' . $order->installment_count . ')',
            ]);

            // Convertir le statut en minuscules pour correspondre à nos enums
            if (isset($pawaPayResponse['status'])) {
                $pawaPayResponse['status'] = strtolower($pawaPayResponse['status']);
            }

            if (isset($pawaPayResponse['status']) && ($pawaPayResponse['status'] === PawaPayTransactionStatus::ACCEPTED->value || isset($pawaPayResponse['depositId']))) {
                // Mettre à jour la transaction avec l'ID PawaPay si disponible
                if (isset($pawaPayResponse['depositId'])) {
                    $transaction->provider_transaction_id = $pawaPayResponse['depositId'];
                }

                // Mettre à jour le statut si disponible
                if (isset($pawaPayResponse['status']) && $pawaPayResponse['status'] === PawaPayTransactionStatus::ACCEPTED->value) {
                    $transaction->status = PawaPayTransactionStatus::ACCEPTED->value;
                }

                $transaction->save();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Paiement initié avec succès. Veuillez confirmer sur votre téléphone.',
                    'transaction' => $transaction,
                    'is_first_payment' => $orderPayment->installment_number == 1
                ]);
            } else if (isset($pawaPayResponse['status']) && $pawaPayResponse['status'] === PawaPayTransactionStatus::DUPLICATE_IGNORED->value) {
                // Cas où la transaction est ignorée comme un duplicata
                $transaction->status = PawaPayTransactionStatus::DUPLICATE_IGNORED->value;
                $transaction->save();

                return response()->json([
                    'status' => 'warning',
                    'message' => 'Ce paiement semble déjà avoir été initié. Veuillez vérifier votre téléphone ou essayer plus tard.',
                    'transaction' => $transaction
                ], 200);
            } else if (isset($pawaPayResponse['status']) && $pawaPayResponse['status'] === PawaPayTransactionStatus::REJECTED->value) {
                // Cas où la transaction est rejetée par PawaPay
                $transaction->status = PawaPayTransactionStatus::REJECTED->value;
                $transaction->save();

                $rejectionReason = $pawaPayResponse['rejectionReason'] ?? 'Raison inconnue';

                return response()->json([
                    'status' => 'error',
                    'message' => 'Le paiement a été rejeté: ' . $rejectionReason,
                    'transaction' => $transaction
                ], 400);
            } else {
                // En cas d'erreur générique
                $transaction->status = PawaPayTransactionStatus::FAILED->value;
                $transaction->save();

                Log::error('Réponse inattendue de PawaPay', [
                    'response' => $pawaPayResponse,
                    'transaction_id' => $transactionId
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Erreur lors de l\'initiation du paiement',
                ], 400);
            }
        } catch (\Exception $e) {
            // Log l'erreur
            Log::error('Exception lors de l\'appel à l\'API PawaPay', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
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
     * Récupère les transactions PawaPay de l'utilisateur connecté.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserTransactions()
    {
        $user = Auth::user();

        $transactions = MomoTransaction::where('user_id', $user->id)
            ->where('provider_type', ProviderType::PAWAPAY->value)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Appelle l'API PawaPay avec signature.
     *
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws \Exception
     */
    private function callPawaPayApi($endpoint, $data)
    {
        Log::info("Appel à l'API PawaPay", [
            'endpoint' => $endpoint,
            'external_id' => $data['externalId'] ?? null
        ]);

        // Utiliser le service de signature pour envoyer la requête
        $result = $this->signatureService->signAndSendRequest($endpoint, $data);

        if (!$result['success']) {
            Log::error("Erreur lors de l'appel à l'API PawaPay", [
                'error' => $result['error'],
                'details' => $result['details'] ?? null,
                'external_id' => $data['externalId'] ?? null
            ]);

            throw new \Exception('Erreur d\'API PawaPay: ' . ($result['error'] ?? 'Erreur inconnue'));
        }

        // Logger la réponse (sans données sensibles)
        Log::info("Réponse de l'API PawaPay", [
            'external_id' => $data['externalId'] ?? null,
            'response_status' => $result['data']['status'] ?? null
        ]);

        return $result['data'];
    }
}