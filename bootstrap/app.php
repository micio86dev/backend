<?php

use App\Exceptions\ParticipantTransitionException;
use App\Http\Middleware\CheckAbility;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TenantContext;
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
        // Apply security headers globally (task 7.7 / D29).
        $middleware->append(SecurityHeaders::class);

        // C2: Register TenantContext on the `api` middleware group AFTER auth:api.
        // TenantContext reads $request->user() which is only available after auth:api
        // has authenticated the bearer token and loaded the User from the DB.
        // IMPORTANT: TenantContext must never run before auth:api — it would receive null user.
        $middleware->appendToGroup('api', TenantContext::class);

        // C5: Register the 'ability' middleware alias for per-route M2M ability checks.
        // e.g. Route::middleware('ability:participants:read')
        $middleware->alias(['ability' => CheckAbility::class]);

        // C5: Insert CheckAbility IMMEDIATELY BEFORE SubstituteBindings in the priority list.
        // Without this, per-route ability middleware could execute AFTER SubstituteBindings
        // (which IS in the default priority list), creating a 404-vs-403 resource-existence
        // enumeration oracle. prependToPriorityList inserts without replacing the full list.
        // ⚠️  NOT appendToPriorityList — that would place CheckAbility AFTER SubstituteBindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, CheckAbility::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // C6: ParticipantTransitionException renders HTTP 422.
        // Registered here as a backstop for direct (non-HTTP) model writes.
        // The exception's own render() method handles the JSON response.
        $exceptions->render(function (ParticipantTransitionException $e, Request $request) {
            return $e->render($request);
        });

        // C8: CompositionException and AnchorTranslationMissingException → HTTP 422.
        // Mirrors ParticipantTransitionException registration pattern.
        // Machine-readable error codes (not localized — BEAI machine-facing response policy).
        $exceptions->render(function (\App\Exceptions\Conversation\CompositionException $e, Request $request) {
            return response()->json(['error' => 'composition_error'], \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (\App\Exceptions\Scoring\AnchorTranslationMissingException $e, Request $request) {
            return response()->json(['error' => 'anchor_translation_missing'], \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    })->create();
