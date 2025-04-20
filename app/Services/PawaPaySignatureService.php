<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PawaPaySignatureService
{
    private $privateKeyPath;
    private $pawaPayPublicKeyPath;

    public function __construct()
    {
        $this->privateKeyPath = storage_path(config('services.pawapay.private_key_path', 'app/keys/private_key.pem'));
        $this->pawaPayPublicKeyPath = storage_path(config('services.pawapay.pawapay_public_key_path', 'app/keys/pawapay_public_key.pem'));
    }

    /**
     * Vérifie la signature d'une requête entrante de PawaPay
     */
    public function verifyIncomingSignature($payload, $signature, $timestamp, $method, $path)
    {
        if (!file_exists($this->pawaPayPublicKeyPath)) {
            Log::error("Clé publique PawaPay non trouvée", [
                'path' => $this->pawaPayPublicKeyPath
            ]);
            return false;
        }

        try {
            $publicKey = file_get_contents($this->pawaPayPublicKeyPath);
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
}