<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class AccountConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $verificationUrl;

    /**
     * Create a new message instance.
     *
     * @param  mixed  $user
     * @param  string  $verificationUrl
     * @return void
     */
    public function __construct($user, $verificationUrl)
    {
        $this->user = $user;
        $this->verificationUrl = $verificationUrl;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // Get user's preferred language or use app default
        $locale = $this->getUserLocale();

        // Set locale for this email
        App::setLocale($locale);

        return $this->subject(__('email.confirmation_subject'))
            ->markdown('emails.confirmation')
            ->with([
                'name' => $this->user->name,
                'verificationUrl' => $this->verificationUrl
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