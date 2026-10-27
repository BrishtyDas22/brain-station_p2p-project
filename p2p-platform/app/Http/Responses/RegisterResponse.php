<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * The registration response for the local development / demo flow.
 *
 * Fortify's own `RegisterResponse` assumes the user was signed in during
 * registration — Fortify's `RegisteredUserController` calls
 * `guard->login($user)` before building the response — and redirects to the
 * home path. The local demo flow does not want that: registration ends at
 * the login page so the user logs in explicitly.
 *
 * This response:
 *
 *   - signs the new user back OUT, so no authenticated session survives a
 *     registration;
 *   - invalidates the registration session and regenerates the CSRF token,
 *     so the session identifier cannot be reused and a stale token cannot be
 *     replayed;
 *   - flashes the success message and redirects to the login page.
 *
 * When `p2p-auth.login_after_registration` is switched on, the Fortify
 * behaviour is restored exactly, so the production flow is not lost.
 */
final class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ((bool) config('p2p-auth.login_after_registration', false)) {
            return $request->wantsJson()
                ? new JsonResponse('', 201)
                : redirect()->intended(Fortify::redirects('register'));
        }

        // The guard signed the new user in during the registration request.
        // Sign them back out so registration never leaves an authenticated
        // session behind.
        if ($request->hasSession()) {
            /** @var StatefulGuard $guard */
            $guard = auth()->guard(config('fortify.guard', 'web'));

            $guard->logout();

            // Invalidate the registration session and mint a fresh CSRF
            // token. `invalidate()` rotates the session id, which is what
            // prevents session fixation across the registration boundary.
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $request->session()->flash(
                'status',
                (string) config('p2p-auth.registration_success_message'),
            );
        }

        return $request->wantsJson()
            ? new JsonResponse(['status' => 'Account created successfully. Please log in.'], 201)
            : redirect()->route('login');
    }
}
