<?php

declare(strict_types=1);

/**
 * No route serves per-question audio or snapshot binary content (C11 PR A3,
 * task 9.6). Only the transcript (text/plain) and evaluation report
 * (application/json) are downloadable — audio storage does not exist and is
 * gated by open product decision #2; InterviewSnapshot proctoring artifacts
 * are under the same gate and are never exposed via this API.
 *
 * REQ: Downloadable Artifacts Are Limited to Transcript and Evaluation
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use Illuminate\Support\Facades\Route;

test('the admin participant route surface has exactly the 7 spec\'d endpoints, no audio/snapshot route', function (): void {
    $adminParticipantUris = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/participants') || $route->uri() === 'api/dashboard/metrics')
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($adminParticipantUris)->toHaveCount(7);

    foreach ($adminParticipantUris as $uri) {
        expect($uri)->not->toContain('audio');
        expect($uri)->not->toContain('snapshot');
    }

    expect($adminParticipantUris->all())->toEqualCanonicalizing([
        'api/participants',
        'api/participants/{id}',
        'api/participants/{id}/transcript',
        'api/participants/{id}/evaluation',
        'api/participants/{id}/transcript/download',
        'api/participants/{id}/evaluation/download',
        'api/dashboard/metrics',
    ]);
});
