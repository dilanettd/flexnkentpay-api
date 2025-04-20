<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Services\PawaPayApiService;
use App\Services\PawaPayFormatterService;

class PawaPay extends Model
{
    // Constants de statut
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_DUPLICATE_IGNORED = 'duplicate_ignored';

    // Constants de type de transaction
    const TYPE_DEPOSIT = 'deposit';
    const TYPE_PAYOUT = 'payout';
    const TYPE_REFUND = 'refund';

    // Correspondants (maintenant utilisées depuis le FormatterService)
    const CORRESPONDENT_MTN_CMR = 'MTN_MOMO_CMR';
    const CORRESPONDENT_ORANGE_CMR = 'ORANGE_CMR';

    const PROVIDER_TYPE = 'pawapay';

    protected $apiService;
    protected $formatterService;

    /**
     * Constructeur avec injection des services
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->apiService = new PawaPayApiService();
        $this->formatterService = new PawaPayFormatterService();
    }

    /**
     * Initie un dépôt via PawaPay
     */
    public function deposit(
        string $phoneNumber,
        float $amount,
        string $transactionId,
        array $metadata = [],
        string $description = '',
        string $country = 'CMR',
        string $currency = 'XAF',
        ?string $correspondent = null
    ) {
        try {
            $phoneResult = $this->formatterService->formatPhoneNumber($phoneNumber);
            $formattedPhoneNumber = $phoneResult['phoneNumber'];
            $detectedCorrespondent = $phoneResult['correspondent'];

            $useCorrespondent = $correspondent ?: $detectedCorrespondent;
            $formattedAmount = $this->formatterService->formatAmount($amount);
            $formattedMetadata = $this->formatterService->formatMetadata($metadata);

            $payload = [
                'depositId' => $transactionId,
                'amount' => $formattedAmount,
                'currency' => $currency,
                'country' => $country,
                'correspondent' => $useCorrespondent,
                'payer' => [
                    'type' => 'MSISDN',
                    'address' => [
                        'value' => $formattedPhoneNumber
                    ]
                ],
                'customerTimestamp' => now()->toIso8601String(),
                'statementDescription' => "Payment " . substr($transactionId, -8),
            ];

            if (!empty($formattedMetadata)) {
                $payload['metadata'] = $formattedMetadata;
            }

            Log::info('PawaPay deposit initiated', [
                'external_id' => $transactionId,
                'phone_number' => $formattedPhoneNumber,
                'amount' => $formattedAmount,
                'correspondent' => $useCorrespondent
            ]);

            $response = $this->apiService->sendRequest('deposits', $payload);

            if ($response['success']) {
                $responseData = $response['data'];

                if (isset($responseData['status'])) {
                    $responseData['status'] = strtolower($responseData['status']);
                }

                return [
                    'success' => true,
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response['error'] ?? 'Unknown error',
                'details' => $response['details'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception during PawaPay deposit initiation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transactionId
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Vérifie le statut d'un dépôt
     */
    public function checkDepositStatus(string $depositId)
    {
        try {
            $response = $this->apiService->sendRequest("deposits/{$depositId}", [], 'GET');

            if ($response['success']) {
                $responseData = $response['data'];

                if (is_array($responseData) && !empty($responseData)) {
                    $depositData = $responseData[0];

                    if (isset($depositData['status'])) {
                        $depositData['status'] = strtolower($depositData['status']);
                    }

                    if (isset($depositData['suspiciousActivityReport']) && !empty($depositData['suspiciousActivityReport'])) {
                        Log::warning('PawaPay reported suspicious activity for deposit', [
                            'deposit_id' => $depositId,
                            'report' => $depositData['suspiciousActivityReport']
                        ]);
                    }

                    if (isset($depositData['status']) && strtolower($depositData['status']) === self::STATUS_FAILED) {
                        if (isset($depositData['failureReason'])) {
                            $failureCode = $depositData['failureReason']['failureCode'] ?? 'UNKNOWN';
                            $failureMessage = $depositData['failureReason']['failureMessage'] ?? 'Unknown error';

                            Log::error('PawaPay deposit failed', [
                                'deposit_id' => $depositId,
                                'failure_code' => $failureCode,
                                'failure_message' => $failureMessage
                            ]);
                        }
                    }

                    return [
                        'success' => true,
                        'data' => $depositData
                    ];
                }

                return [
                    'success' => true,
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response['error'] ?? 'Unknown error',
                'details' => $response['details'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception during PawaPay deposit status check', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'deposit_id' => $depositId
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Initie un paiement via PawaPay
     */
    public function payout(
        string $phoneNumber,
        float $amount,
        string $transactionId,
        array $metadata = [],
        string $description = '',
        string $country = 'CMR',
        string $currency = 'XAF',
        ?string $correspondent = null
    ) {
        try {
            $phoneResult = $this->formatterService->formatPhoneNumber($phoneNumber);
            $formattedPhoneNumber = $phoneResult['phoneNumber'];
            $detectedCorrespondent = $phoneResult['correspondent'];

            $useCorrespondent = $correspondent ?: $detectedCorrespondent;
            $formattedAmount = $this->formatterService->formatAmount($amount);
            $formattedMetadata = $this->formatterService->formatMetadata($metadata);

            $payload = [
                'payoutId' => $transactionId,
                'amount' => $formattedAmount,
                'currency' => $currency,
                'country' => $country,
                'correspondent' => $useCorrespondent,
                'recipient' => [
                    'type' => 'MSISDN',
                    'address' => [
                        'value' => $formattedPhoneNumber
                    ]
                ],
                'customerTimestamp' => now()->toIso8601String(),
                'statementDescription' => $description ?: "Payout #" . substr($transactionId, -8),
            ];

            if (!empty($formattedMetadata)) {
                $payload['metadata'] = $formattedMetadata;
            }

            Log::info('PawaPay payout initiated', [
                'external_id' => $transactionId,
                'phone_number' => $formattedPhoneNumber,
                'amount' => $formattedAmount,
                'correspondent' => $useCorrespondent
            ]);

            $response = $this->apiService->sendRequest('payouts', $payload);

            if ($response['success']) {
                $responseData = $response['data'];

                if (isset($responseData['status'])) {
                    $responseData['status'] = strtolower($responseData['status']);
                }

                return [
                    'success' => true,
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response['error'] ?? 'Unknown error',
                'details' => $response['details'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception during PawaPay payout initiation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transactionId
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Vérifie le statut d'un paiement
     */
    public function checkPayoutStatus(string $payoutId)
    {
        try {
            $response = $this->apiService->sendRequest("payouts/{$payoutId}", [], 'GET');

            if ($response['success']) {
                $responseData = $response['data'];

                if (is_array($responseData) && !empty($responseData)) {
                    $payoutData = $responseData[0];

                    if (isset($payoutData['status'])) {
                        $payoutData['status'] = strtolower($payoutData['status']);
                    }

                    return [
                        'success' => true,
                        'data' => $payoutData
                    ];
                }

                return [
                    'success' => true,
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response['error'] ?? 'Unknown error',
                'details' => $response['details'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception during PawaPay payout status check', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payout_id' => $payoutId
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Initie un remboursement via PawaPay
     */
    public function refund(string $depositId, float $amount, string $transactionId, array $metadata = [], string $reason = '')
    {
        try {
            $formattedAmount = $this->formatterService->formatAmount($amount);
            $formattedMetadata = $this->formatterService->formatMetadata($metadata);

            $payload = [
                'refundId' => $transactionId,
                'depositId' => $depositId,
                'amount' => $formattedAmount,
                'reason' => $reason ?: 'Customer request',
            ];

            if (!empty($formattedMetadata)) {
                $payload['metadata'] = $formattedMetadata;
            }

            Log::info('PawaPay refund initiated', [
                'external_id' => $transactionId,
                'deposit_id' => $depositId,
                'amount' => $formattedAmount
            ]);

            $response = $this->apiService->sendRequest('refunds', $payload);

            if ($response['success']) {
                $responseData = $response['data'];

                if (isset($responseData['status'])) {
                    $responseData['status'] = strtolower($responseData['status']);
                }

                return [
                    'success' => true,
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response['error'] ?? 'Unknown error',
                'details' => $response['details'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception during PawaPay refund initiation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transactionId,
                'deposit_id' => $depositId
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Vérifie le statut d'un remboursement
     */
    public function checkRefundStatus(string $refundId)
    {
        try {
            $response = $this->apiService->sendRequest("refunds/{$refundId}", [], 'GET');

            if ($response['success']) {
                $responseData = $response['data'];

                if (is_array($responseData) && !empty($responseData)) {
                    $refundData = $responseData[0];

                    if (isset($refundData['status'])) {
                        $refundData['status'] = strtolower($refundData['status']);
                    }

                    return [
                        'success' => true,
                        'data' => $refundData
                    ];
                }

                return [
                    'success' => true,
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response['error'] ?? 'Unknown error',
                'details' => $response['details'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception during PawaPay refund status check', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'refund_id' => $refundId
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }
}