<?php

use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ResolveBranch;
use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetRequestContext;
use App\Exceptions\DomainConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: [
            'stripe/*',
        ]);
        $middleware->statefulApi();
        $middleware->append([
            SetRequestContext::class,
            SecurityHeaders::class,
        ]);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveTenant::class,
        );

        $middleware->prependToPriorityList(
            ResolveTenant::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        );

        $middleware->prependToPriorityList(
            ResolveTenant::class,
            EnsureAccountAccess::class,
        );

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'branch' => ResolveBranch::class,
            'account-access' => EnsureAccountAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (DomainConflictException $e, Request $request) => $request->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => $e->domainCode], 409) : null);
        $exceptions->render(fn (DomainException $e, Request $request) => $request->is('api/*') ? response()->json(['message' => $e->getMessage()], 409) : null);
        $exceptions->render(fn (\App\Exceptions\BillingVerificationUnavailableException $e, Request $request) => $request->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'billing_verification_unavailable'], 503) : null);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
