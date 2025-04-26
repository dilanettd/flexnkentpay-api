<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PawaPaySignatureService
{
    // Ce service a été vidé de sa logique comme demandé
    // La vérification de signature pour les webhooks a été retirée du contrôleur

    public function __construct()
    {
        // Constructeur vide
    }

    /**
     * Cette fonction est délibérément laissée vide car 
     * la vérification de signature n'est plus nécessaire.
     */
    public function verifyIncomingSignature($payload, $signature, $timestamp, $method, $path)
    {
        // Toujours retourner true pour que les webhooks soient acceptés sans vérification
        return true;
    }
}