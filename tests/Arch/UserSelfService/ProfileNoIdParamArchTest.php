<?php

declare(strict_types=1);

/**
 * ProfileNoIdParamArchTest (user-profile-self-service, design.md D1).
 *
 * Structural guard: no route under `api/profile*` may ever declare a
 * parameter — the resource is singular and self-resolving, exactly like
 * `api/organization` (RouteIsolationTest.php's route-inspection pattern).
 * A future author adding `Route::patch('/profile/{user}', ...)` would
 * silently introduce an IDOR surface; this test fails loudly instead.
 *
 * REQ: Singular Self-Resolving Profile Resource — "No id-taking variant
 * exists"
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */
test('no route under api/profile* declares a parameter', function (): void {
    $routes = app('router')->getRoutes();

    $profileRoutes = [];
    foreach ($routes as $route) {
        if (str_starts_with($route->uri(), 'api/profile')) {
            $profileRoutes[] = $route;
        }
    }

    // Asserted first, deliberately: before Phase 3 registers the routes,
    // this is empty and the assertion below would otherwise pass VACUOUSLY
    // (an empty loop finds no violation). Requiring at least 3 routes
    // (GET/PATCH /profile, PUT /profile/password) turns "not registered yet"
    // into a real, visible RED instead of a silent no-op.
    expect(count($profileRoutes))->toBeGreaterThanOrEqual(3);

    foreach ($profileRoutes as $route) {
        expect($route->parameterNames())
            ->toBe([], "Route {$route->uri()} must not declare any parameter (no id-taking variant).");
    }
});
