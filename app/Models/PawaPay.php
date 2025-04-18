<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\PawaPaySignatureService;

class PawaPay extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_DUPLICATE_IGNORED = 'duplicate_ignored';

    const TYPE_DEPOSIT = 'deposit';
    const TYPE_PAYOUT = 'payout';
    const TYPE_REFUND = 'refund';

    const CORRESPONDENT_MTN_CMR = 'MTN_MOMO_CMR';
    const CORRESPONDENT_ORANGE_CMR = 'ORANGE_CMR';

    const PROVIDER_TYPE = 'pawapay';

    protected $signatureService;
    protected $baseUrl;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->signatureService = new PawaPaySignatureService();
        $this->baseUrl = config('services.pawapay.base_url', 'https://api.sandbox.pawapay.io');
    }

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
            $phoneResult = $this->formatPhoneNumber($phoneNumber);
            $formattedPhoneNumber = $phoneResult['phoneNumber'];
            $detectedCorrespondent = $phoneResult['correspondent'];

            $useCorrespondent = $correspondent ?: $detectedCorrespondent;

            $formattedAmount = $this->formatAmount($amount);

            $formattedMetadata = [];
            foreach ($metadata as $key => $value) {
                $formattedMetadata[] = [
                    'fieldName' => $key,
                    'fieldValue' => (string) $value,
                    'isPII' => false
                ];
            }

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
                'statementDescription' => $description ?: "Payment #" . substr($transactionId, -8),
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

            $response = $this->callPawaPayApi('deposits', $payload);

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

    public function checkDepositStatus(string $depositId)
    {
        try {
            $response = $this->callPawaPayApi("deposits/{$depositId}", [], 'GET');

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
            $phoneResult = $this->formatPhoneNumber($phoneNumber);
            $formattedPhoneNumber = $phoneResult['phoneNumber'];
            $detectedCorrespondent = $phoneResult['correspondent'];

            $useCorrespondent = $correspondent ?: $detectedCorrespondent;

            $formattedAmount = $this->formatAmount($amount);

            $formattedMetadata = [];
            foreach ($metadata as $key => $value) {
                $formattedMetadata[] = [
                    'fieldName' => $key,
                    'fieldValue' => (string) $value,
                    'isPII' => false
                ];
            }

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

            $response = $this->callPawaPayApi('payouts', $payload);

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

    public function checkPayoutStatus(string $payoutId)
    {
        try {
            $response = $this->callPawaPayApi("payouts/{$payoutId}", [], 'GET');

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

    public function refund(string $depositId, float $amount, string $transactionId, array $metadata = [], string $reason = '')
    {
        try {
            $formattedAmount = $this->formatAmount($amount);

            $formattedMetadata = [];
            foreach ($metadata as $key => $value) {
                $formattedMetadata[] = [
                    'fieldName' => $key,
                    'fieldValue' => (string) $value,
                    'isPII' => false
                ];
            }

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

            $response = $this->callPawaPayApi('refunds', $payload);

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

    public function checkRefundStatus(string $refundId)
    {
        try {
            $response = $this->callPawaPayApi("refunds/{$refundId}", [], 'GET');

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

    protected function formatPhoneNumber(string $phoneNumber)
    {
        $phoneNumber = preg_replace('/[\s\-\(\)]/', '', $phoneNumber);
        $phoneNumber = ltrim($phoneNumber, '+');

        if (preg_match('/^6\d{8}$/', $phoneNumber)) {
            $phoneNumber = '237' . $phoneNumber;
        }

        $correspondent = null;

        $mtnPrefixes = [
            '23767',
            '237680',
            '237681',
            '237682',
            '237683',
            '237684',
            '237650',
            '237651',
            '237652',
            '237653',
            '237654'
        ];

        $orangePrefixes = [
            '23769',
            '237655',
            '237656',
            '237657',
            '237658',
            '237659',
            '237685',
            '237686',
            '237687',
            '237688',
            '237689',
            '237640'
        ];

        foreach ($mtnPrefixes as $prefix) {
            if (strpos($phoneNumber, $prefix) === 0) {
                $correspondent = self::CORRESPONDENT_MTN_CMR;
                break;
            }
        }

        if (!$correspondent) {
            foreach ($orangePrefixes as $prefix) {
                if (strpos($phoneNumber, $prefix) === 0) {
                    $correspondent = self::CORRESPONDENT_ORANGE_CMR;
                    break;
                }
            }
        }

        if (!$correspondent && strlen($phoneNumber) >= 4) {
            $firstDigit = substr($phoneNumber, 3, 1);
            if ($firstDigit === '6') {
                $correspondent = self::CORRESPONDENT_MTN_CMR;
            } else if ($firstDigit === '9') {
                $correspondent = self::CORRESPONDENT_ORANGE_CMR;
            }
        }

        if (!$correspondent) {
            $correspondent = self::CORRESPONDENT_MTN_CMR;
        }

        return [
            'phoneNumber' => $phoneNumber,
            'correspondent' => $correspondent
        ];
    }

    protected function formatAmount(float $amount)
    {
        if (floor($amount) == $amount) {
            return (string) intval($amount);
        }

        return number_format($amount, 0, '', '');
    }

    protected function callPawaPayApi($endpoint, array $data = [], string $method = 'POST')
    {
        return $this->signatureService->signAndSendRequest($endpoint, $data, $method);
    }
}