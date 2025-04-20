<?php

namespace App\Listeners;

use App\Events\OrderPaymentSuccessful;
use Illuminate\Support\Facades\Mail;
use App\Mail\RegularPaymentSuccess;
use App\Mail\FinalPaymentSuccess;
use App\Services\InvoiceService;

class SendOrderPaymentEmail
{
    protected $invoiceService;

    /**
     * Create the event listener.
     *
     * @param InvoiceService $invoiceService
     * @return void
     */
    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Handle the event.
     *
     * @param  OrderPaymentSuccessful  $event
     * @return void
     */
    public function handle(OrderPaymentSuccessful $event)
    {
        if ($event->isLastPayment) {
            // Générer la facture PDF
            $invoicePath = $this->invoiceService->generateInvoice($event->order);

            // Envoyer l'email avec la facture en pièce jointe
            Mail::to($event->user->email)->send(
                new FinalPaymentSuccess($event->order, $event->payment, $invoicePath)
            );
        } else {
            // Envoyer l'email standard pour un paiement intermédiaire
            Mail::to($event->user->email)->send(
                new RegularPaymentSuccess($event->order, $event->payment)
            );
        }
    }
}