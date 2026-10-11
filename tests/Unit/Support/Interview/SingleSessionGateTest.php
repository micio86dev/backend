<?php

declare(strict_types=1);

/**
 * SingleSessionGate — the only reader of the single-session flag and canary
 * list (tavus-single-session-interview, API-01, design N1).
 */

use App\Models\Project;
use App\Support\Interview\SingleSessionGate;

function gateProject(int $id = 7): Project
{
    $project = new Project;
    $project->id = $id;

    return $project;
}

function gateApplies(string $provider, int $projectId = 7): bool
{
    return (new SingleSessionGate)->applies(gateProject($projectId), $provider);
}

beforeEach(function (): void {
    config([
        'interview.tavus.single_session' => false,
        'interview.tavus.single_session_projects' => [],
    ]);
});

test('is false by default', function (): void {
    expect(gateApplies('tavus'))->toBeFalse();
});

test('is true for tavus with the flag on', function (): void {
    config(['interview.tavus.single_session' => true]);

    expect(gateApplies('tavus'))->toBeTrue();
});

test('is true for a canary project id with the flag off', function (): void {
    config(['interview.tavus.single_session_projects' => [7, 9]]);

    expect(gateApplies('tavus', 7))->toBeTrue()
        ->and(gateApplies('tavus', 8))->toBeFalse();
});

test('is always false for heygen and mock', function (string $provider): void {
    config([
        'interview.tavus.single_session' => true,
        'interview.tavus.single_session_projects' => [7],
    ]);

    expect(gateApplies($provider))->toBeFalse();
})->with(['heygen', 'mock']);
