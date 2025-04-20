<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

class FinalPaymentSuccess extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $payment;
    public $user;
    public $invoicePath;
    public $seller;

    /**
     * Create a new message instance.
     *
     * @param  Order  $order
     * @param  OrderPayment  $payment
     * @param  string  $invoicePath
     * @return void
     */
    public function __construct(Order $order, OrderPayment $payment, string $invoicePath)
    {
        $this->order = $order;
        $this->payment = $payment;
        $this->user = $order->user;
        $this->invoicePath = $invoicePath;

        // Charger le vendeur et les infos de la boutique
        $this->order->load(['seller.user', 'seller.shop']);
        $this->seller = $this->order->seller;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // Définir la locale en fonction de la préférence de l'utilisateur
        $locale = $this->getUserLocale();
        App::setLocale($locale);

        // Récupérer le chemin complet vers la facture
        $storagePath = Storage::path('public/' . $this->invoicePath);
        $fileName = 'facture_commande_' . $this->order->id . '.pdf';

        return $this->subject(__('email.final_payment_subject', ['orderNumber' => $this->order->id]))
            ->markdown('emails.final-payment-success')
            ->with([
                'userName' => $this->user->name,
                'order' => $this->order,
                'payment' => $this->payment,
                'product' => $this->order->product,
                'seller' => $this->seller,
                'sellerName' => $this->seller->user->name,
                'sellerPhone' => $this->seller->user->phone ?? 'Non disponible',
                'shop' => $this->seller->shop
            ])
            ->attach($storagePath, [
                'as' => $fileName,
                'mime' => 'application/pdf',
            ]);
    }

    /**
     * Get user locale preference
     * 
     * @return string
     */
    protected function getUserLocale()
    {
        // Try to get user preference, if available
        if ($this->user->preference && $this->user->preference->language) {
            return $this->user->preference->language;
        }

        // Default to application locale
        return config('app.locale');
    }
}