<?php

declare(strict_types=1);

/**
 * The admin read surface stays enumerated, and no route serves per-question
 * audio or raw snapshot BYTES (C11 PR A3, task 9.6).
 *
 * Audio storage does not exist and stays gated by open product decision #2.
 *
 * SUPERSEDED IN PART: this guard previously asserted that InterviewSnapshot
 * proctoring artifacts are "never exposed via this API". The session review
 * change (openspec/changes/interview-review-and-template-portability) exposes
 * them deliberately, to the BACKOFFICE ONLY, as short-lived signed URLs — the
 * API still never serves the bytes, and `s3_key` never leaves the server.
 * That narrower ban is what this test now enforces, and it is the part that
 * was load-bearing: the original concern was an unauthenticated or long-lived
 * path to a webcam frame, not the existence of a reviewed strip.
 *
 * Retention is untouched. Making evidence visible does not extend how long it
 * is kept, and this surface must never become the argument for keeping it
 * longer.
 *
 * REQ: Downloadable Artifacts Are Limited to Transcript and Evaluation
 *      Admin session review
 */

use Illuminate\Support\Facades\Route;

test('the admin participant route surface is exactly the enumerated set', function (): void {
    $adminParticipantUris = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/participants') || $route->uri() === 'api/dashboard/metrics')
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($adminParticipantUris->all())->toEqualCanonicalizing([
        'api/participants',
        'api/participants/{id}',
        'api/participants/{id}/transcript',
        'api/participants/{id}/evaluation',
        'api/participants/{id}/transcript/download',
        'api/participants/{id}/evaluation/download',
        'api/participants/{participant}/sessions',
        // (participant-error-recovery) POST /api/participants/{id}/recover is a
        // WRITE, not part of the admin READ surface this test otherwise
        // enumerates — it shares the URI prefix only, so it is listed here
        // rather than filtered out, to keep the enumeration exhaustive.
        'api/participants/{id}/recover',
        'api/dashboard/metrics',
    ]);
});

test('no admin route serves audio, and none serves snapshot bytes', function (): void {
    $uris = collect(Route::getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'api/participants')
            || str_starts_with($uri, 'api/interview-sessions'))
        ->values();

    foreach ($uris as $uri) {
        // Audio has no storage and no route, full stop.
        expect($uri)->not->toContain('audio');

        // Snapshots reach the operator as signed URLs inside the review
        // payload. A route whose PATH is a snapshot would be the API serving
        // the bytes itself — which is the thing that must not exist.
        expect($uri)->not->toContain('snapshot');
    }
});
