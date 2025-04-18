<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PawaPayWebhook;
use App\Models\MomoTransaction;
use App\Models\User;
use App\Models\OrderPayment;
use App\Models\Order;
use App\Models\ProviderUsage;
use App\Models\PawaPay;
use App\Utils\Constants;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\PawaPaySignatureService;

class PawaPayController extends Controller
{
    protected $pawaPayModel;
    protected $signatureService;

    /**
     * Constructor
     */
    public function __construct(PawaPay $pawaPayModel = null, PawaPaySignatureService $signatureService = null)
    {
        $this->pawaPayModel = $pawaPayModel ?: new PawaPay();
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

        // Calculer les frais de pénalité si le paiement est en retard
        $orderPayment->calculatePenaltyFees();

        // Montant total à payer (versement + pénalités)
        $amount = $orderPayment->amount_paid + $orderPayment->penalty_fees;
        $fees = 0;

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
            'status' => PawaPay::STATUS_PENDING,
            'provider_type' => PawaPay::PROVIDER_TYPE,
        ]);

        // Lier la transaction au paiement de la commande
        $orderPayment->momo_transaction_id = $transaction->id;
        $orderPayment->save();

        try {
            // Préparer les métadonnées pour PawaPay
            $metadata = [
                'user_id' => $user->id,
                'order_payment_id' => $orderPayment->id,
                'order_id' => $order->id,
                'installment_number' => $orderPayment->installment_number,
                'penalty_fees' => $orderPayment->penalty_fees
            ];

            // Description pour l'état de la transaction
            $description = 'Paiement commande #' . $orderPayment->order_id . ' (Versement ' . $orderPayment->installment_number . '/' . $order->installment_count . ')';

            // Appel au modèle PawaPay pour initier le dépôt
            $pawaPayResponse = $this->pawaPayModel->deposit(
                $request->phone_number,
                $amount,
                $transactionId,
                $metadata,
                $description
            );

            if ($pawaPayResponse['success']) {
                $responseData = $pawaPayResponse['data'];

                // Mettre à jour la transaction avec l'ID PawaPay si disponible
                if (isset($responseData['depositId'])) {
                    $transaction->provider_transaction_id = $responseData['depositId'];
                }

                // Mettre à jour le statut si disponible
                if (isset($responseData['status']) && $responseData['status'] === PawaPay::STATUS_ACCEPTED) {
                    $transaction->status = PawaPay::STATUS_ACCEPTED;
                }

                $transaction->save();

                // Préparer un message spécifique pour le premier paiement
                $message = $orderPayment->installment_number == 1
                    ? 'Paiement initié avec succès. Veuillez confirmer sur votre téléphone pour valider votre commande.'
                    : 'Paiement initié avec succès. Veuillez confirmer sur votre téléphone.';

                return response()->json([
                    'status' => 'success',
                    'message' => $message,
                    'transaction' => $transaction,
                    'payment_info' => [
                        'order_id' => $order->id,
                        'payment_id' => $orderPayment->id,
                        'installment_number' => $orderPayment->installment_number,
                        'amount' => $orderPayment->amount_paid,
                        'penalty_fees' => $orderPayment->penalty_fees,
                        'total_amount' => $amount,
                        'is_first_payment' => $orderPayment->installment_number == 1
                    ]
                ]);
            } else {
                // En cas d'erreur générique
                $transaction->status = PawaPay::STATUS_FAILED;
                $transaction->save();

                $errorMessage = $pawaPayResponse['message'] ?? 'Erreur lors de l\'initiation du paiement';

                return response()->json([
                    'status' => 'error',
                    'message' => $errorMessage,
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
            $transaction->status = PawaPay::STATUS_FAILED;
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
            ->where('provider_type', PawaPay::PROVIDER_TYPE)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Vérifie le statut d'une transaction de dépôt existante.
     *
     * @param string $providerTransactionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkDepositStatus($providerTransactionId)
    {
        $transaction = MomoTransaction::where('provider_transaction_id', $providerTransactionId)
            ->where('provider_type', PawaPay::PROVIDER_TYPE)
            ->first();

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction non trouvée'
            ], 404);
        }

        try {
            $statusResponse = $this->pawaPayModel->checkDepositStatus($providerTransactionId);

            if ($statusResponse['success']) {
                $responseData = $statusResponse['data'];

                // Mettre à jour le statut de la transaction dans notre base de données
                if (isset($responseData['status'])) {
                    $transaction->status = strtolower($responseData['status']);
                    $transaction->save();

                    // Si la transaction est complétée, mettre à jour le paiement associé
                    if ($transaction->status === PawaPay::STATUS_COMPLETED) {
                        $orderPayment = OrderPayment::where('momo_transaction_id', $transaction->id)->first();

                        if ($orderPayment) {
                            $this->updateOrderPaymentOnSuccess($orderPayment, $transaction);
                        }
                    }
                }

                return response()->json([
                    'status' => 'success',
                    'transaction' => $transaction,
                    'pawapay_status' => $responseData
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => $statusResponse['message'] ?? 'Erreur lors de la vérification du statut',
                'transaction' => $transaction
            ], 400);

        } catch (\Exception $e) {
            Log::error('Exception lors de la vérification du statut de la transaction', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->transaction_id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la communication avec le service de paiement',
                'transaction' => $transaction
            ], 500);
        }
    }

    /**
     * Endpoint pour recevoir les webhooks de PawaPay.
     * 
     * @param Request $request
     * @param string $eventType
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request, $eventType)
    {
        Log::info("[pawapay_transaction_webhook handleWebhook] /api/pawapay/{$eventType}/webhook endpoint called", [
            'data' => $request->all()
        ]);

        $data = $request->all();

        // Vérifier la signature du webhook (pour la sécurité en production)
        if (!$this->verifyWebhookSignature($request)) {
            Log::error("[PawaPay handleWebhook] Invalid webhook signature", [
                'event_type' => $eventType
            ]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // Validation du eventType
        $validEventTypes = [PawaPay::TYPE_DEPOSIT, PawaPay::TYPE_PAYOUT, PawaPay::TYPE_REFUND];
        if (!in_array(strtolower($eventType), $validEventTypes)) {
            Log::error("[PawaPay handleWebhook] Unknown event type {$eventType}", [
                'data' => $data
            ]);
            return response()->json(['message' => 'Invalid event type'], 400);
        }

        // Déléguer le traitement au processeur de webhook
        $result = $this->processWebhookData(strtolower($eventType), $data);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 500);
        }

        return response()->json(['message' => 'Webhook processed successfully']);
    }

    /**
     * Vérifie la signature du webhook pour la sécurité.
     *
     * @param Request $request
     * @return bool
     */
    private function verifyWebhookSignature(Request $request)
    {
        $webhookSecret = config('services.pawapay.webhook_secret');

        // Si aucun secret n'est configuré, on considère que la vérification est désactivée
        if (empty($webhookSecret)) {
            return true;
        }

        $signature = $request->header('X-PawaPay-Signature');
        $timestamp = $request->header('X-PawaPay-Timestamp');

        if (empty($signature)) {
            Log::warning('Signature de webhook manquante');
            return false;
        }

        // Vérifier que le timestamp n'est pas trop ancien (optionnel, pour éviter les attaques par rejeu)
        if (!empty($timestamp)) {
            $timestampDate = \DateTime::createFromFormat('c', $timestamp);
            if ($timestampDate) {
                $now = new \DateTime();
                $diff = $now->getTimestamp() - $timestampDate->getTimestamp();

                if ($diff > 300) { // 5 minutes
                    Log::warning('Timestamp de webhook trop ancien', [
                        'timestamp' => $timestamp,
                        'diff' => $diff
                    ]);
                    return false;
                }
            }
        }

        // Contenu du webhook
        $payload = $request->getContent();

        // Utiliser le service de signature pour vérifier le webhook
        return $this->signatureService->verifyIncomingSignature(
            $payload,
            $signature,
            $timestamp,
            $request->method(),
            $request->path()
        );
    }

    /**
     * Traite les données du webhook PawaPay.
     *
     * @param string $eventType
     * @param array $data
     * @return array
     */
    private function processWebhookData(string $eventType, array $data)
    {
        $transactionTypeMap = [
            PawaPay::TYPE_DEPOSIT => Constants::BALANCE_UPDATE_TOPUP,
            PawaPay::TYPE_PAYOUT => Constants::BALANCE_UPDATE_WITHDRAW,
            PawaPay::TYPE_REFUND => Constants::BALANCE_WITHDRAW_REFUND,
        ];

        $transactionType = $transactionTypeMap[$eventType];

        Log::info("[PawaPay processWebhookData] transaction_type {$transactionType}", [
            'data' => $data
        ]);

        $providerTransactionId = $data['depositId'] ?? $data['payoutId'] ?? $data['refundId'] ?? null;
        $status = strtolower($data['status'] ?? '');
        $amount = $data['amount'] ?? $data['requestedAmount'] ?? 0;
        $phoneNumber = $data['payer']['address']['value'] ?? $data['recipient']['address']['value'] ?? null;
        $currency = $data['currency'] ?? null;
        $country = $data['country'] ?? null;
        $correspondent = $data['correspondent'] ?? null;
        $description = $data['statementDescription'] ?? null;
        $customerTimestamp = $data['customerTimestamp'] ?? null;
        $createdTimestamp = $data['created'] ?? null;
        $receivedTimestamp = $data['receivedByRecipient'] ?? null;
        $failureReason = $data['failureReason']['failureMessage'] ?? null;
        $metadata = $data['metadata'] ?? null;
        $suspiciousActivityReport = $data['suspiciousActivityReport'] ?? null;

        if (!$providerTransactionId || !$status) {
            Log::error("[PawaPay processWebhookData] Invalid data for webhook", [
                'provider_transaction_id' => $providerTransactionId,
                'status' => $status
            ]);
            return ['error' => 'Invalid webhook data'];
        }

        try {
            // Enregistrer le webhook
            $webhook = PawaPayWebhook::create([
                'transaction_id' => $providerTransactionId,
                'transaction_type' => $eventType,
                'timestamp' => now(),
                'phone_number' => $phoneNumber,
                'amount' => $amount,
                'currency' => $currency,
                'country' => $country,
                'correspondent' => $correspondent,
                'status' => $status,
                'description' => $description,
                'customer_timestamp' => $customerTimestamp ? Carbon::parse($customerTimestamp) : null,
                'created_timestamp' => $createdTimestamp ? Carbon::parse($createdTimestamp) : null,
                'received_timestamp' => $receivedTimestamp ? Carbon::parse($receivedTimestamp) : null,
                'failure_reason' => $failureReason,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'suspicious_activity_report' => $suspiciousActivityReport ? json_encode($suspiciousActivityReport) : null,
            ]);

            // Rechercher la transaction correspondante
            $momoTransaction = MomoTransaction::where('provider_transaction_id', $providerTransactionId)
                ->where('provider_type', PawaPay::PROVIDER_TYPE)
                ->first();

            if (!$momoTransaction) {
                Log::info("[PawaPay processWebhookData] Momo transaction not found", [
                    'transaction_id' => $providerTransactionId,
                    'webhook_id' => $webhook->id
                ]);
                return ['error' => 'Transaction not found'];
            }

            $transactionId = $momoTransaction->transaction_id;
            $currentStatus = $momoTransaction->status;

            if ($status == PawaPay::STATUS_DUPLICATE_IGNORED) {
                return ['message' => 'Duplicate transaction ignored'];
            }

            // Si le statut est passé à FAILED ou REJECTED, et qu'il s'agit du premier paiement
            // pour une commande non confirmée, supprimer la commande
            if (in_array($status, [PawaPay::STATUS_FAILED, PawaPay::STATUS_REJECTED])) {
                // Vérifier si c'est le premier paiement d'une commande non confirmée
                $orderPayment = OrderPayment::where('momo_transaction_id', $momoTransaction->id)->first();

                if ($orderPayment && $orderPayment->installment_number == 1) {
                    $order = $orderPayment->order;

                    if (!$order->is_confirmed) {
                        // Si la commande n'est pas confirmée et que le premier paiement a échoué, supprimer
                        Log::info("[PawaPay processWebhookData] Suppression de la commande non confirmée", [
                            'order_id' => $order->id,
                            'status' => $status
                        ]);

                        // Enregistrer la suppression dans les logs
                        $order->orderPayments()->delete();
                        $order->delete();
                    }
                }
            }

            // Si le statut est passé à COMPLETED
            if (
                $status == PawaPay::STATUS_COMPLETED &&
                ($currentStatus == PawaPay::STATUS_PENDING || $currentStatus == PawaPay::STATUS_ACCEPTED)
            ) {
                $this->handleSuccessfulTransaction($momoTransaction, $amount);
            }

            // Mettre à jour le statut de la transaction
            $momoTransaction->status = $status;
            $momoTransaction->updated_at = now();
            $momoTransaction->save();

            Log::info("[PawaPay processWebhookData] Transaction status updated", [
                'transaction_id' => $transactionId,
                'status' => $status
            ]);

            // Envoyer notification
            $this->sendTransactionNotification($momoTransaction, $status);

            return ['message' => 'Webhook processed successfully'];

        } catch (\Exception $e) {
            Log::error("[PawaPay processWebhookData] Error processing webhook", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['error' => 'Error processing webhook: ' . $e->getMessage()];
        }
    }

    /**
     * Gère une transaction réussie.
     *
     * @param MomoTransaction $momoTransaction
     * @param float $amount
     * @return void
     */
    private function handleSuccessfulTransaction(MomoTransaction $momoTransaction, float $amount): void
    {
        // Mettre à jour le statut du paiement de la commande
        $orderPayment = OrderPayment::where('momo_transaction_id', $momoTransaction->id)->first();

        if ($orderPayment) {
            $orderPayment->status = 'success';
            $orderPayment->payment_date = now();
            $orderPayment->save();

            // Mettre à jour le statut de la commande
            $order = $orderPayment->order;
            $order->remaining_amount -= $orderPayment->amount_paid;
            $order->remaining_installments -= 1;

            // Vérifier si c'est le premier paiement (installment_number = 1)
            if ($orderPayment->installment_number == 1) {
                $order->is_confirmed = true;

                Log::info("[PawaPay handleSuccessfulTransaction] Order confirmed", [
                    'order_id' => $order->id,
                    'payment_id' => $orderPayment->id
                ]);
            }

            if ($order->remaining_amount <= 0) {
                $order->is_completed = true;
            }

            $order->save();

            // Enregistrer l'utilisation du fournisseur (montant déjà au bon format)
            ProviderUsage::updateDepositUsage('pawapay', $amount);

            Log::info("[PawaPay handleSuccessfulTransaction] Order payment updated", [
                'order_payment_id' => $orderPayment->id,
                'order_id' => $order->id,
                'status' => 'success',
                'is_confirmed' => $order->is_confirmed,
                'is_completed' => $order->is_completed
            ]);
        }
    }

    /**
     * Met à jour le paiement de commande quand une transaction est réussie.
     *
     * @param OrderPayment $orderPayment
     * @param MomoTransaction $transaction
     * @return void
     */
    private function updateOrderPaymentOnSuccess(OrderPayment $orderPayment, MomoTransaction $transaction)
    {
        $orderPayment->status = 'success';
        $orderPayment->payment_date = now();
        $orderPayment->save();

        $order = $orderPayment->order;
        $order->remaining_amount -= $orderPayment->amount_paid;
        $order->remaining_installments -= 1;

        if ($orderPayment->installment_number == 1) {
            $order->is_confirmed = true;
        }

        if ($order->remaining_installments <= 0 || $order->remaining_amount <= 0) {
            $order->is_completed = true;
        }

        $order->save();

        Log::info('Paiement mis à jour avec succès', [
            'order_id' => $order->id,
            'payment_id' => $orderPayment->id,
            'transaction_id' => $transaction->transaction_id
        ]);
    }

    /**
     * Envoie une notification concernant la transaction.
     *
     * @param MomoTransaction $momoTransaction
     * @param string $status
     * @return void
     */
    private function sendTransactionNotification(MomoTransaction $momoTransaction, string $status): void
    {
        // À implémenter selon votre système de notification
        // Vous pourriez envoyer un e-mail, un SMS, ou une notification push

        try {
            $user = User::find($momoTransaction->user_id);

            if ($user) {
                // Si vous avez configuré le système de notifications Laravel
                // $user->notify(new PaymentStatusNotification($momoTransaction, $status));

                // Pour l'instant, simplement logger
                Log::info("[PawaPay sendTransactionNotification] Notification envoyée", [
                    'user_id' => $momoTransaction->user_id,
                    'transaction_id' => $momoTransaction->transaction_id,
                    'status' => $status,
                    'amount' => $momoTransaction->amount,
                    'phone_number' => $momoTransaction->phone_number
                ]);
            }
        } catch (\Exception $e) {
            Log::error("[PawaPay sendTransactionNotification] Erreur d'envoi de notification", [
                'error' => $e->getMessage(),
                'user_id' => $momoTransaction->user_id,
                'transaction_id' => $momoTransaction->transaction_id
            ]);
        }
    }
}