<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $resetUrl;

    /**
     * Create a new message instance.
     *
     * @param  mixed  $user
     * @param  string  $resetUrl
     * @return void
     */
    public function __construct($user, $resetUrl)
    {
        $this->user = $user;
        $this->resetUrl = $resetUrl;
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

        return $this->subject(__('email.reset_subject'))
            ->markdown('emails.reset')
            ->with([
                'name' => $this->user->name,
                'resetUrl' => $this->resetUrl
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