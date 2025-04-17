<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $link;

    public function __construct($userName, $link)
    {
        $this->userName = $userName;
        $this->link = $link;
    }

    public function build()
    {
        return $this->subject(__('Password Reset'))
            ->markdown('emails.reset')
            ->with(['userName' => $this->userName, 'link' => $this->link]);
    }
}
