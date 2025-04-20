<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\OrderPayment;

class FirstOrderPaymentSuccessful
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $payment;
    public $user;

    /**
     * Create a new event instance.
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
    }
}