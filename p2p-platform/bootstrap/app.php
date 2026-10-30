<?php

use App\Http\Middleware\EnsureEmailIsVerifiedWhenRequired;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The administrator boundary. Registered as an alias so routes read
        // as `->middleware('admin')`.
        //
        // `verified` is the framework's own email-verification middleware.
        // It is replaced with a conditional wrapper so the LOCAL DEMO flow
        // can skip the verification detour WITHOUT the production
        // safeguard being deleted: flipping
        // `p2p-auth.require_email_verification` to true restores the
        // framework's exact behaviour. The Fortify verification routes stay
        // registered either way.
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'verified' => EnsureEmailIsVerifiedWhenRequired::class,
        ]);

        // Render terminates TLS at its edge and forwards plain HTTP to the
        // container, so Laravel sees the request as http:// and generates
        // http:// asset URLs. On an HTTPS page the browser then blocks them
        // as mixed content, and the app renders blank.
        //
        // The scheme is taken from X-Forwarded-Proto, which the proxy chain
        // OVERWRITES with the authoritative value - a client-supplied one is
        // replaced at the edge, so it cannot be spoofed. Only the scheme and
        // client-IP headers are trusted; X-Forwarded-Host is deliberately NOT
        // trusted, because that would allow Host header injection.
        //
        // The trusted proxy is the connecting peer (REMOTE_ADDR). The service
        // is reachable only through the platform's proxy chain - it has no
        // dedicated IP and no direct origin route - so every request that
        // reaches it has already passed through a proxy.
        //
        // Locally there is no proxy: the request comes from the loopback
        // interface with no X-Forwarded-* headers, so the scheme stays http
        // and local HTTP development is unaffected.
        $middleware->trustProxies(
            at: 'REMOTE_ADDR',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
