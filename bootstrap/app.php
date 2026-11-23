<?php

use App\Http\Middleware\AuthenticateParticipant;
use App\Http\Middleware\EnsureGroupAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // These read the resolved {group} model, so they must run after route model binding.
        $middleware->appendToPriorityList(after: SubstituteBindings::class, append: EnsureGroupAdmin::class);
        $middleware->appendToPriorityList(after: SubstituteBindings::class, append: AuthenticateParticipant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
