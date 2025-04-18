<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PawaPaySignatureService
{
    private $privateKeyPath;
    private $apiKey;
    private $apiSecret;

    public function __construct()
    {
        $this->privateKeyPath = storage_path(config('services.pawapay.private_key_path', 'app/keys/private_key.pem'));
        $this->apiKey = config('services.pawapay.api_key');
        $this->apiSecret = config('services.pawapay.api_secret');
    }

    /**
     * Signe et envoie une requête à l'API PawaPay.
     *
     * @param string $endpoint
     * @param array $data
     * @param string $method
     * @return array Response data
     */
    public function signAndSendRequest($endpoint, $data, $method = 'POST')
    {
        // Timestamp actuel en format ISO8601
        $timestamp = date('c');

        // Construire le payload à signer
        $payload = json_encode($data);

        // Clé d'API requise pour certains endpoints
        $apiKey = $this->apiKey;

        try {
            // Si le fichier de clé privée existe, utiliser la signature RSA
            if (file_exists($this->privateKeyPath)) {
                // Chaîne à signer (méthode + URI + timestamp + payload)
                $stringToSign = $method . "\n" .
                    $endpoint . "\n" .
                    $timestamp . "\n" .
                    $payload;

                // Charger la clé privée
                $privateKey = file_get_contents($this->privateKeyPath);

                // Signer la chaîne
                $signature = '';
                $result = openssl_sign($stringToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

                if (!$result) {
                    throw new \Exception('Erreur lors de la signature: ' . openssl_error_string());
                }

                // Encoder la signature en base64
                $encodedSignature = base64_encode($signature);

                // Préparer les entêtes de la requête avec signature RSA
                $headers = [
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $apiKey,
                    'X-Timestamp' => $timestamp,
                    'X-Signature' => $encodedSignature,
                ];
            } else {
                // Utiliser l'authentification HTTP Basic comme solution de repli
                Log::warning('Clé privée non trouvée, utilisation de l\'authentification HTTP Basic', [
                    'key_path' => $this->privateKeyPath
                ]);

                // Utiliser HTTP Basic au lieu de la signature RSA
                $headers = ['Content-Type' => 'application/json'];

                // La méthode withBasicAuth sera utilisée plus tard
            }

            // Envoyer la requête à PawaPay
            $baseUrl = config('services.pawapay.base_url', 'https://api.pawapay.com/v1');
            $fullUrl = $baseUrl . '/' . ltrim($endpoint, '/');

            // Préparation de la requête HTTP
            $request = Http::withHeaders($headers);

            // Ajouter l'authentification HTTP Basic si aucune signature RSA n'est utilisée
            if (!isset($headers['X-Signature'])) {
                $request = $request->withBasicAuth($apiKey, $this->apiSecret);
            }

            // Envoyer la requête
            $response = $request->withBody($payload, 'application/json')
                ->send($method, $fullUrl);

            // Gérer la réponse
            if (!$response->successful()) {
                // Logger l'erreur (sans données sensibles)
                Log::error("Erreur lors de l'appel à l'API PawaPay", [
                    'status_code' => $response->status(),
                    'response' => $this->sanitizeResponse($response->json()),
                    'endpoint' => $endpoint,
                    'external_id' => $data['externalId'] ?? null
                ]);

                return [
                    'success' => false,
                    'error' => 'Erreur API: ' . $response->status(),
                    'details' => $this->sanitizeResponse($response->json()),
                    'status_code' => $response->status()
                ];
            }

            // Réponse réussie
            return [
                'success' => true,
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            // Logger l'erreur
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

    /**
     * Vérifie la signature d'une requête entrante venant de PawaPay.
     *
     * @param string $payload Le contenu de la requête
     * @param string $signature La signature de la requête
     * @param string $timestamp Le timestamp de la requête
     * @param string $method La méthode HTTP
     * @param string $path Le chemin de la requête
     * @return bool
     */
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
            // Charger la clé publique
            $publicKey = file_get_contents($pawaPayPublicKeyPath);

            // Chaîne à vérifier (méthode + chemin + timestamp + payload)
            $stringToVerify = $method . "\n" .
                $path . "\n" .
                $timestamp . "\n" .
                $payload;

            // Décoder la signature
            $decodedSignature = base64_decode($signature);

            // Vérifier la signature
            $result = openssl_verify($stringToVerify, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);

            if ($result === 1) {
                return true; // Signature valide
            } else if ($result === 0) {
                Log::warning("Signature PawaPay invalide");
                return false; // Signature invalide
            } else {
                // Erreur lors de la vérification
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

    /**
     * Nettoie la réponse pour le logging (supprime les données sensibles).
     *
     * @param array|null $response
     * @return array
     */
    private function sanitizeResponse($response)
    {
        if (!is_array($response)) {
            return ['response' => 'non-array response'];
        }

        // Liste des clés à masquer
        $sensitiveKeys = ['token', 'password', 'secret', 'key', 'auth', 'credential', 'private'];

        // Fonction récursive pour nettoyer un tableau
        $sanitize = function ($data) use (&$sanitize, $sensitiveKeys) {
            if (!is_array($data)) {
                return $data;
            }

            $result = [];
            foreach ($data as $key => $value) {
                // Vérifier si la clé contient un mot sensible
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