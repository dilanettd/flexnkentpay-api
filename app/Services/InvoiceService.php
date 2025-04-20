<?php

namespace App\Services;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    /**
     * Génère une facture PDF pour une commande.
     *
     * @param Order $order
     * @return string Chemin du fichier PDF généré
     */
    public function generateInvoice(Order $order)
    {
        // Charger le seller avec le shop pour avoir les informations du vendeur
        $order->load(['user', 'seller.user', 'seller.shop', 'product', 'orderPayments']);

        // Nom du fichier basé sur le numéro de commande et la date
        $filename = 'facture_commande_' . $order->id . '_' . now()->format('Y-m-d') . '.pdf';
        $storagePath = 'invoices/' . $filename;

        // Configurer le PDF
        $pdf = PDF::loadView('pdfs.invoice', [
            'order' => $order,
            'user' => $order->user,
            'seller' => $order->seller,
            'sellerName' => $order->seller->user->name,
            'sellerPhone' => $order->seller->user->phone,
            'shop' => $order->seller->shop,
            'product' => $order->product,
            'payments' => $order->orderPayments,
            'logoPath' => public_path('logo.png'),
            'date' => now()->format('d/m/Y')
        ]);

        // Stocker le PDF dans le dossier storage/app/public/invoices
        Storage::put('public/' . $storagePath, $pdf->output());

        // Retourner le chemin du fichier créé
        return $storagePath;
    }
}