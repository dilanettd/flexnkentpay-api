<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PawaPayApiService
{
    private $api_token;
    private $baseUrl;

    public function __construct()
    {
        $this->api_token = config('services.pawapay.api_token');
        $this->baseUrl = config('services.pawapay.base_url', 'https://api.pawapay.io');
    }

    /**
     * Envoie une requête à l'API PawaPay
     */
    public function sendRequest($endpoint, $data, $method = 'POST')
    {
        $payload = json_encode($data);
        $externalId = $data['depositId'] ?? $data['payoutId'] ?? $data['refundId'] ?? null;

        $fullUrl = $this->baseUrl . '/' . ltrim($endpoint, '/');

        // Log de la requête
        Log::info("Requête envoyée à l'API PawaPay", [
            'method' => $method,
            'endpoint' => $endpoint,
            'url' => $fullUrl,
            'external_id' => $externalId,
            'payload' => $this->sanitizeResponse($data)
        ]);

        try {
            $headers = ['Content-Type' => 'application/json'];

            $request = Http::withHeaders($headers);
            $request = $request->withToken($this->api_token, 'Bearer');

            $startTime = microtime(true);
            $response = $request->withBody($payload, 'application/json')
                ->send($method, $fullUrl);
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2); // Temps en millisecondes

            // Log de la réponse
            if ($response->successful()) {
                Log::info("Réponse reçue de l'API PawaPay", [
                    'status_code' => $response->status(),
                    'execution_time_ms' => $executionTime,
                    'endpoint' => $endpoint,
                    'url' => $fullUrl,
                    'external_id' => $externalId,
                    'response' => $this->sanitizeResponse($response->json())
                ]);

                return [
                    'success' => true,
                    'data' => $response->json(),
                    'execution_time_ms' => $executionTime
                ];
            } else {
                Log::error("Erreur lors de l'appel à l'API PawaPay", [
                    'status_code' => $response->status(),
                    'execution_time_ms' => $executionTime,
                    'response' => $this->sanitizeResponse($response->json()),
                    'endpoint' => $endpoint,
                    'url' => $fullUrl,
                    'external_id' => $externalId
                ]);

                return [
                    'success' => false,
                    'error' => 'Erreur API: ' . $response->status(),
                    'details' => $this->sanitizeResponse($response->json()),
                    'status_code' => $response->status(),
                    'execution_time_ms' => $executionTime
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'appel à l\'API PawaPay', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
                'url' => $fullUrl,
                'external_id' => $externalId,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Masque les informations sensibles dans la réponse
     */
    private function sanitizeResponse($response)
    {
        if (!is_array($response)) {
            return ['response' => 'non-array response'];
        }

        $sensitiveKeys = ['token', 'password', 'secret', 'key', 'auth', 'credential', 'private', 'apiKey'];

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