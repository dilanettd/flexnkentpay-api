<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PawaPayWebhook;
use App\Models\MomoTransaction;
use App\Models\User;
use App\Models\OrderPayment;
use App\Models\Order;
use App\Models\ProviderUsage;
use App\Enums\PawaPayTransactionStatus;
use App\Enums\PawaPayTransactionType;
use App\Enums\TransactionTypes;
use App\Enums\ProviderType;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PawaPayController extends Controller
{
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

        // Conversion de l'eventType en PawaPayTransactionType
        try {
            $eventTypeEnum = PawaPayTransactionType::from(strtolower($eventType));
        } catch (\ValueError $e) {
            Log::error("[PawaPay handleWebhook] Unknown event type {$eventType}", [
                'data' => $data
            ]);
            return response()->json(['message' => 'Invalid event type'], 400);
        }

        // Déléguer le traitement au processeur de webhook
        $result = $this->processWebhookData($eventTypeEnum, $data);

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

        // La méthode de vérification dépend de la documentation de PawaPay
        // Voici un exemple avec HMAC SHA256 qui est courant pour les webhooks
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        // Vérification avec une comparaison de chaîne à durée constante pour éviter les attaques temporelles
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Signature de webhook invalide', [
                'expected' => $expectedSignature,
                'received' => $signature
            ]);
            return false;
        }

        return true;
    }

    /**
     * Traite les données du webhook PawaPay.
     *
     * @param PawaPayTransactionType $eventType
     * @param array $data
     * @return array
     */
    private function processWebhookData(PawaPayTransactionType $eventType, array $data)
    {
        $transactionTypeMap = [
            PawaPayTransactionType::DEPOSIT => TransactionTypes::TOPUP->value,
            PawaPayTransactionType::PAYOUT => TransactionTypes::WITHDRAWAL->value,
            PawaPayTransactionType::REFUND => TransactionTypes::REFUND_TRANSACTION->value,
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
                'transaction_type' => $eventType->value,
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
                ->where('provider_type', ProviderType::PAWAPAY->value)
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

            $transactionStatus = $status == PawaPayTransactionStatus::COMPLETED->value
                ? 'successful'
                : $status;

            if ($transactionStatus == PawaPayTransactionStatus::DUPLICATE_IGNORED->value) {
                return ['message' => 'Duplicate transaction ignored'];
            }

            // Si le statut est passé à FAILED ou REJECTED, et qu'il s'agit du premier paiement
            // pour une commande non confirmée, supprimer la commande
            if (in_array($status, [PawaPayTransactionStatus::FAILED->value, PawaPayTransactionStatus::REJECTED->value])) {
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

            // Si le statut est passé à SUCCESSFUL
            if (
                $transactionStatus == 'successful' && ($currentStatus == PawaPayTransactionStatus::PENDING->value
                    || $currentStatus == PawaPayTransactionStatus::ACCEPTED->value)
            ) {
                $this->handleSuccessfulTransaction($momoTransaction, $amount);
            }

            // Mettre à jour le statut de la transaction
            $momoTransaction->status = $transactionStatus;
            $momoTransaction->updated_at = now();
            $momoTransaction->save();

            Log::info("[PawaPay processWebhookData] Transaction status updated", [
                'transaction_id' => $transactionId,
                'status' => $status
            ]);

            // Envoyer notification
            $this->sendTransactionNotification($momoTransaction, $transactionStatus);

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