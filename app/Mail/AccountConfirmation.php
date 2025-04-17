<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountConfirmation extends Mailable
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
        return $this->subject(__('Account Confirmation'))
            ->markdown('emails.confirmation')
            ->with(['userName' => $this->userName, 'link' => $this->link]);
    }
}

