<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PawaPaySignatureService
{
    private $privateKeyPath;
    private $api_token;

    public function __construct()
    {
        $this->privateKeyPath = storage_path(config('services.pawapay.private_key_path', 'app/keys/private_key.pem'));
        $this->api_token = config('services.pawapay.api_token');
    }

    public function signAndSendRequest($endpoint, $data, $method = 'POST')
    {
        $timestamp = date('c');
        $payload = json_encode($data);

        try {
            $headers = ['Content-Type' => 'application/json'];
            $baseUrl = config('services.pawapay.base_url', 'https://api.sandbox.pawapay.io');
            $fullUrl = $baseUrl . '/' . ltrim($endpoint, '/');

            $request = Http::withHeaders($headers);
            $request = $request->withBasicAuth($this->api_token, '');

            $response = $request->withBody($payload, 'application/json')
                ->send($method, $fullUrl);

            if (!$response->successful()) {
                Log::error("Erreur lors de l'appel à l'API PawaPay", [
                    'status_code' => $response->status(),
                    'response' => $this->sanitizeResponse($response->json()),
                    'endpoint' => $endpoint,
                    'external_id' => $data['depositId'] ?? $data['payoutId'] ?? $data['refundId'] ?? null
                ]);

                return [
                    'success' => false,
                    'error' => 'Erreur API: ' . $response->status(),
                    'details' => $this->sanitizeResponse($response->json()),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => true,
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'appel à l\'API PawaPay', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function verifyIncomingSignature($payload, $signature, $timestamp, $method, $path)
    {
        $pawaPayPublicKeyPath = storage_path(config('services.pawapay.pawapay_public_key_path', 'app/keys/pawapay_public_key.pem'));

        if (!file_exists($pawaPayPublicKeyPath)) {
            Log::error("Clé publique PawaPay non trouvée", [
                'path' => $pawaPayPublicKeyPath
            ]);
            return false;
        }

        try {
            $publicKey = file_get_contents($pawaPayPublicKeyPath);
            $stringToVerify = $method . "\n" .
                $path . "\n" .
                $timestamp . "\n" .
                $payload;

            $decodedSignature = base64_decode($signature);
            $result = openssl_verify($stringToVerify, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);

            if ($result === 1) {
                return true;
            } else if ($result === 0) {
                Log::warning("Signature PawaPay invalide");
                return false;
            } else {
                Log::error("Erreur lors de la vérification de la signature: " . openssl_error_string());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception lors de la vérification de la signature", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function sanitizeResponse($response)
    {
        if (!is_array($response)) {
            return ['response' => 'non-array response'];
        }

        $sensitiveKeys = ['token', 'password', 'secret', 'key', 'auth', 'credential', 'private'];

        $sanitize = function ($data) use (&$sanitize, $sensitiveKeys) {
            if (!is_array($data)) {
                return $data;
            }

            $result = [];
            foreach ($data as $key => $value) {
                $isSensitive = false;
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if (stripos($key, $sensitiveKey) !== false) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive) {
                    $result[$key] = '[MASKED]';
                } else if (is_array($value)) {
                    $result[$key] = $sanitize($value);
                } else {
                    $result[$key] = $value;
                }
            }

            return $result;
        };

        return $sanitize($response);
    }
}