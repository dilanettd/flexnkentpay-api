<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\OrderPayment;

class OrderPaymentSuccessful
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $payment;
    public $user;
    public $isLastPayment;

    /**
     * Create a new event instance.
     *
     * @param  Order  $order
     * @param  OrderPayment  $payment
     * @param  bool  $isLastPayment
     * @return void
     */
    public function __construct(Order $order, OrderPayment $payment, bool $isLastPayment)
    {
        $this->order = $order;
        $this->payment = $payment;
        $this->user = $order->user;
        $this->isLastPayment = $isLastPayment;
    }
}