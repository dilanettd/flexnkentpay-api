<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\App;

class RegularPaymentSuccess extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $payment;
    public $user;
    public $nextPayment;

    /**
     * Create a new message instance.
     *
     * @param  Order  $order
     * @param  OrderPayment  $payment
     * @return void
     */
    public function __construct(Order $order, OrderPayment $payment)
    {
        $this->order = $order;
        $this->payment = $payment;
        $this->user = $order->user;

        // Trouver le prochain paiement prévu
        $this->nextPayment = $order->orderPayments()
            ->where('status', 'pending')
            ->orderBy('installment_number', 'asc')
            ->first();
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

        return $this->subject(__('email.regular_payment_subject', ['orderNumber' => $this->order->id]))
            ->markdown('emails.regular-payment-success')
            ->with([
                'userName' => $this->user->name,
                'order' => $this->order,
                'payment' => $this->payment,
                'product' => $this->order->product,
                'nextPayment' => $this->nextPayment,
                'remainingPayments' => $this->order->remaining_installments
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