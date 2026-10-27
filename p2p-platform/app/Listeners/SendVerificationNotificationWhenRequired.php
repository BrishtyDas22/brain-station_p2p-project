<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;

/**
 * Sends the verification email only when verification is actually required.
 *
 * Laravel registers `SendEmailVerificationNotification` against the
 * `Registered` event automatically for every model that implements
 * `MustVerifyEmail`. In the local development / demo flow the verification
 * step is switched off, so a "please confirm your email" message would be
 * sent for a link the user never needs to follow.
 *
 * This listener replaces Laravel's default and delegates to it when — and
 * only when — `p2p-auth.require_email_verification` is enabled. With the
 * requirement on, the production behaviour is unchanged: the same email, from
 * the same framework class, is delivered.
 */
final class SendVerificationNotificationWhenRequired
{
    /**
     * The framework listener this one delegates to.
     */
    private SendEmailVerificationNotification $listener;

    public function __construct()
    {
        $this->listener = new SendEmailVerificationNotification;
    }

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        if (! (bool) config('p2p-auth.require_email_verification', false)) {
            return;
        }

        $this->listener->handle($event);
    }
}
