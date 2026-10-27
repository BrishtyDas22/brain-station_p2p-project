<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\SendVerificationNotificationWhenRequired;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Owns the application's listener map and makes the verification email
 * conditional.
 *
 * Laravel attaches `SendEmailVerificationNotification` to the `Registered`
 * event for every model that implements `MustVerifyEmail`. That happens
 * inside the framework's own event service provider, in its `booted()`
 * callback, and it happens unconditionally.
 *
 * In the local development / demo flow the email-verification step is
 * switched off, so that message would ask a user to follow a link they
 * never need to follow. This provider removes the framework's listener and
 * replaces it with one that delegates to the very same framework class — but
 * only when `p2p-auth.require_email_verification` is enabled.
 *
 * With the requirement on, the email is delivered by Laravel's own class,
 * unchanged. With it off, no email is sent at all.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        Registered::class => [
            SendVerificationNotificationWhenRequired::class,
        ],
    ];

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->replaceFrameworkVerificationListener();
    }

    /**
     * Swap the framework's verification listener for the conditional one.
     *
     * The framework registers its listener in a `booted()` callback, so the
     * replacement has to happen after that. This provider queues a `booted`
     * callback of its own; because `booted()` callbacks run in the order
     * they were queued and the framework queued its one first, this callback
     * runs after the framework's listener has been attached.
     *
     * Only the listeners for the `Registered` event are touched, and the
     * application's own listener for that event is re-attached immediately
     * afterwards, so nothing else is lost.
     */
    private function replaceFrameworkVerificationListener(): void
    {
        $this->app->booted(function (): void {
            $dispatcher = $this->app->make(Dispatcher::class);

            // Only the framework's own listener is removed. The
            // application's listener for this event is preserved and
            // re-registered below, so any future listener on `Registered`
            // keeps working.
            $frameworkListener = SendEmailVerificationNotification::class;

            $this->removeListener($dispatcher, Registered::class, $frameworkListener);

            $dispatcher->listen(Registered::class, SendVerificationNotificationWhenRequired::class);
        });
    }

    /**
     * Remove one specific listener from one event.
     *
     * The dispatcher has no API for removing a single listener, so the
     * event's listener list is read, filtered and written back. Every other
     * listener on that event is left exactly where it was.
     */
    private function removeListener(Dispatcher $dispatcher, string $event, string $listener): void
    {
        if (! method_exists($dispatcher, 'getRawListeners')) {
            return;
        }

        $listeners = $dispatcher->getRawListeners();

        if (! isset($listeners[$event]) || ! is_array($listeners[$event])) {
            return;
        }

        /** @var array<int, mixed> $registered */
        $registered = $listeners[$event];

        $kept = array_values(array_filter(
            $registered,
            static fn (mixed $entry): bool => $entry !== $listener,
        ));

        if (count($kept) === count($registered)) {
            // The listener was never attached; nothing to do.
            return;
        }

        $dispatcher->forget($event);

        foreach ($kept as $entry) {
            $dispatcher->listen($event, $entry);
        }
    }
}
