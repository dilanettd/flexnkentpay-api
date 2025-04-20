<?php

namespace App\Listeners;

use App\Events\PasswordResetRequested;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPassword;

class SendPasswordResetEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param  PasswordResetRequested  $event
     * @return void
     */
    public function handle(PasswordResetRequested $event)
    {
        Mail::to($event->user->email)->send(
            new ResetPassword($event->user, $event->resetUrl)
        );
    }
}