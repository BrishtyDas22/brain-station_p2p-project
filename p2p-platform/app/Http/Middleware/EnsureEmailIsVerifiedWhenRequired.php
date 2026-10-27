<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the email-verification requirement only when it is switched on.
 *
 * Laravel's `EnsureEmailIsVerified` middleware is hard-wired: once it is on
 * a route, an unverified user is always redirected to the verification
 * notice. That is the right behaviour for production and the wrong behaviour
 * for the local development / demo flow, where a registration should go
 * straight to the login page.
 *
 * Rather than removing the `verified` middleware from the route groups —
 * which would quietly delete the production safeguard along with it — this
 * middleware decides at request time whether the requirement applies. The
 * switch lives in `config/p2p-auth.php`.
 *
 * When the requirement is enabled the behaviour is EXACTLY Laravel's own:
 * the user is redirected to the verification notice, or refused with a 403
 * for an JSON request. When it is disabled the request passes straight
 * through and no verification screen is ever presented.
 */
final class EnsureEmailIsVerifiedWhenRequired
{
    /**
     * The framework middleware this one delegates to when the requirement
     * is enabled. Delegating rather than re-implementing keeps the
     * production behaviour byte-for-byte identical to the framework's.
     */
    private EnsureEmailIsVerified $verifier;

    public function __construct()
    {
        $this->verifier = new EnsureEmailIsVerified;
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        if (! (bool) config('p2p-auth.require_email_verification', false)) {
            return $next($request);
        }

        /** @var Response $response */
        $response = $this->verifier->handle($request, $next, $redirectToRoute);

        return $response;
    }
}
