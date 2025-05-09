<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Events\UserRegistered;
use App\Events\PasswordResetRequested;
use App\Events\FirstOrderPaymentSuccessful;
use App\Events\OrderPaymentSuccessful;
use App\Listeners\SendAccountConfirmationEmail;
use App\Listeners\SendPasswordResetEmail;
use App\Listeners\SendOrderFirstPaymentEmail;
use App\Listeners\SendOrderPaymentEmail;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        UserRegistered::class => [
            SendAccountConfirmationEmail::class,
        ],
        PasswordResetRequested::class => [
            SendPasswordResetEmail::class,
        ],
        FirstOrderPaymentSuccessful::class => [
            SendOrderFirstPaymentEmail::class,
        ],
        OrderPaymentSuccessful::class => [
            SendOrderPaymentEmail::class,
        ],

    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
