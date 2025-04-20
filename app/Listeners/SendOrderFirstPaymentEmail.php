<?php

namespace App\Listeners;

use App\Events\FirstOrderPaymentSuccessful;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderFirstPaymentSuccess;

class SendOrderFirstPaymentEmail
{
    /**
     * Handle the event.
     *
     * @param  FirstOrderPaymentSuccessful  $event
     * @return void
     */
    public function handle(FirstOrderPaymentSuccessful $event)
    {
        Mail::to($event->user->email)->send(
            new OrderFirstPaymentSuccess($event->order, $event->payment)
        );
    }
}