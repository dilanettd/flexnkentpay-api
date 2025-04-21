<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PawaPayWebhook;
use App\Models\MomoTransaction;
use App\Models\User;
use App\Models\OrderPayment;
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
     * Initiates a payment via PawaPay.
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

        $order = $orderPayment->order;
        if ($orderPayment->installment_number == 1 && $order->is_confirmed) {
            return response()->json([
                'message' => 'Cette commande est déjà confirmée.'
            ], 400);
        }

        $orderPayment->calculatePenaltyFees();

        $amount = $orderPayment->amount_paid + $orderPayment->penalty_fees;
        $fees = 0;

        $transactionId = (string) Str::uuid();

        $transaction = MomoTransaction::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'provider_transaction_id' => null,
            'phone_number' => $request->phone_number,
            'amount' => $amount,
            'fees' => $fees,
            'status' => PawaPay::STATUS_PENDING,
            'provider_type' => PawaPay::PROVIDER_TYPE,
        ]);

        $orderPayment->momo_transaction_id = $transaction->id;
        $orderPayment->save();

        try {
            $metadata = [
                'user_id' => $user->id,
                'order_payment_id' => $orderPayment->id,
                'order_id' => $order->id,
                'installment_number' => $orderPayment->installment_number,
                'penalty_fees' => $orderPayment->penalty_fees
            ];

            $description = 'Paiement commande #' . $orderPayment->order_id . $transactionId . ' (Versement ' . $orderPayment->installment_number . '/' . $order->installment_count . ')';

            $pawaPayResponse = $this->pawaPayModel->deposit(
                $request->phone_number,
                $amount,
                $transactionId,
                $metadata,
                $description
            );

            if ($pawaPayResponse['success']) {
                $responseData = $pawaPayResponse['data'];

                if (isset($responseData['depositId'])) {
                    $transaction->provider_transaction_id = $responseData['depositId'];
                }

                if (isset($responseData['status']) && $responseData['status'] === PawaPay::STATUS_ACCEPTED) {
                    $transaction->status = 'success';
                    $transaction->save();

                    // Utilisation de markAsPaid qui déclenche les événements appropriés
                    $orderPayment->markAsPaid($transaction->id);

                    // Mettre à jour les statistiques d'utilisation
                    ProviderUsage::updateDepositUsage('pawapay', $amount);
                } else {
                    $transaction->save();
                }

                return response()->json([
                    'status' => 'success',
                    'transaction' => $transaction,
                    'payment_info' => [
                        'order_id' => $order->id,
                        'payment_id' => $orderPayment->id,
                        'installment_number' => $orderPayment->installment_number,
                        'amount' => $orderPayment->amount_paid,
                        'penalty_fees' => $orderPayment->penalty_fees,
                        'total_amount' => $amount,
                    ]
                ]);
            } else {
                $transaction->status = PawaPay::STATUS_FAILED;
                $transaction->save();

                $errorMessage = $pawaPayResponse['message'] ?? 'Erreur lors de l\'initiation du paiement';

                return response()->json([
                    'status' => 'error',
                    'message' => $errorMessage,
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'appel à l\'API PawaPay', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'trace' => $e->getTraceAsString()
            ]);

            $transaction->status = PawaPay::STATUS_FAILED;
            $transaction->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la communication avec le service de paiement',
            ], 500);
        }
    }

    /**
     * Retrieves PawaPay transactions for the connected user.
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
     * Checks the status of an existing deposit transaction.
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

                if (isset($responseData['status'])) {
                    $previousStatus = $transaction->status;
                    $newStatus = strtolower($responseData['status']);
                    $transaction->status = $newStatus;
                    $transaction->save();

                    // Si le statut devient "completed" et qu'il était en attente ou accepté
                    if (
                        $newStatus === PawaPay::STATUS_COMPLETED &&
                        ($previousStatus === PawaPay::STATUS_PENDING || $previousStatus === PawaPay::STATUS_ACCEPTED)
                    ) {

                        $orderPayment = OrderPayment::where('momo_transaction_id', $transaction->id)->first();

                        if ($orderPayment && $orderPayment->status !== 'success') {
                            // Utiliser markAsPaid pour traiter le paiement et envoyer les emails
                            $orderPayment->markAsPaid($transaction->id);

                            // Mettre à jour les statistiques d'utilisation
                            ProviderUsage::updateDepositUsage('pawapay', $transaction->amount);
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
     * Endpoint to receive PawaPay webhooks.
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

        if (!$this->verifyWebhookSignature($request)) {
            Log::error("[PawaPay handleWebhook] Invalid webhook signature", [
                'event_type' => $eventType
            ]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $validEventTypes = [PawaPay::TYPE_DEPOSIT, PawaPay::TYPE_PAYOUT, PawaPay::TYPE_REFUND];
        if (!in_array(strtolower($eventType), $validEventTypes)) {
            Log::error("[PawaPay handleWebhook] Unknown event type {$eventType}", [
                'data' => $data
            ]);
            return response()->json(['message' => 'Invalid event type'], 400);
        }

        $result = $this->processWebhookData(strtolower($eventType), $data);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 500);
        }

        return response()->json(['message' => 'Webhook processed successfully']);
    }

    /**
     * Verifies the webhook signature for security.
     *
     * @param Request $request
     * @return bool
     */
    private function verifyWebhookSignature(Request $request)
    {
        $webhookSecret = config('services.pawapay.webhook_secret');

        if (empty($webhookSecret)) {
            return true;
        }

        $signature = $request->header('X-PawaPay-Signature');
        $timestamp = $request->header('X-PawaPay-Timestamp');

        if (empty($signature)) {
            Log::warning('Signature de webhook manquante');
            return false;
        }

        if (!empty($timestamp)) {
            $timestampDate = \DateTime::createFromFormat('c', $timestamp);
            if ($timestampDate) {
                $now = new \DateTime();
                $diff = $now->getTimestamp() - $timestampDate->getTimestamp();

                if ($diff > 300) {
                    Log::warning('Timestamp de webhook trop ancien', [
                        'timestamp' => $timestamp,
                        'diff' => $diff
                    ]);
                    return false;
                }
            }
        }

        $payload = $request->getContent();

        return $this->signatureService->verifyIncomingSignature(
            $payload,
            $signature,
            $timestamp,
            $request->method(),
            $request->path()
        );
    }

    /**
     * Processes PawaPay webhook data.
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

            if (in_array($status, [PawaPay::STATUS_FAILED, PawaPay::STATUS_REJECTED])) {
                $orderPayment = OrderPayment::where('momo_transaction_id', $momoTransaction->id)->first();

                if ($orderPayment && $orderPayment->installment_number == 1) {
                    $order = $orderPayment->order;

                    if (!$order->is_confirmed) {
                        Log::info("[PawaPay processWebhookData] Suppression de la commande non confirmée", [
                            'order_id' => $order->id,
                            'status' => $status
                        ]);

                        $order->orderPayments()->delete();
                        $order->delete();
                    }
                }
            }

            // Gestion des paiements réussis
            if (
                $status == PawaPay::STATUS_COMPLETED &&
                ($currentStatus == PawaPay::STATUS_PENDING || $currentStatus == PawaPay::STATUS_ACCEPTED)
            ) {

                $orderPayment = OrderPayment::where('momo_transaction_id', $momoTransaction->id)->first();

                if ($orderPayment && $orderPayment->status !== 'success') {
                    // Utiliser markAsPaid pour traiter le paiement et envoyer les emails
                    $orderPayment->markAsPaid($momoTransaction->id);

                    // Mettre à jour les statistiques d'utilisation
                    ProviderUsage::updateDepositUsage('pawapay', $amount);

                    Log::info("[PawaPay processWebhookData] Payment marked as paid", [
                        'order_payment_id' => $orderPayment->id,
                        'transaction_id' => $transactionId
                    ]);
                }
            }

            // Si le statut est accepté, marquer la transaction comme réussie
            if ($status == PawaPay::STATUS_ACCEPTED) {
                $momoTransaction->status = "success";
            } else {
                $momoTransaction->status = $status;
            }

            $momoTransaction->updated_at = now();
            $momoTransaction->save();

            Log::info("[PawaPay processWebhookData] Transaction status updated", [
                'transaction_id' => $transactionId,
                'status' => $status
            ]);

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
     * Sends a notification about the transaction.
     *
     * @param MomoTransaction $momoTransaction
     * @param string $status
     * @return void
     */
    private function sendTransactionNotification(MomoTransaction $momoTransaction, string $status): void
    {
        try {
            $user = User::find($momoTransaction->user_id);

            if ($user) {
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