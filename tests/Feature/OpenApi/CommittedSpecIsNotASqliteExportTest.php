<?php

declare(strict_types=1);

/**
 * The committed `openapi.json` must be a POSTGRES export.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * `php artisan scramble:export` reads `api/.env`, and the committed `.env`
 * defaults to `DB_CONNECTION=sqlite`. SQLite and Postgres introspect JSON and
 * integer columns differently, so an export produced against SQLite is not a
 * lesser export — it is a WRONG one. It types `SessionReviewResource.id`,
 * `participant_id` and `question_index` as `string` instead of `integer`, and
 * flattens `AvatarTemplateResource.config`/`persona`.
 *
 * That matters beyond this repo: `frontend` and `backoffice` each generate
 * their typed TypeScript client from this exact file. A SQLite export ships
 * both apps a contract in which a numeric id is a string.
 *
 * It has already happened once. The Taskfile documented the trap in prose
 * (`openapi:sync`'s comment) and prose did not stop it — a SQLite-generated
 * spec was committed and released, and CI only caught it after the merge to
 * `main`, because the drift check regenerates and diffs rather than
 * inspecting content. This test inspects content, so it fails in any
 * environment, on any branch, the moment such a file is committed.
 *
 * WHY THESE FIELDS
 * ----------------
 * They are the exact fields the divergence touches. This is deliberately a
 * canary, not an exhaustive schema assertion: a full snapshot would fail on
 * every legitimate API change and would be deleted within a month.
 */

use Illuminate\Support\Arr;

/**
 * @return array<string, mixed>
 */
function committedOpenApiSpec(): array
{
    $path = base_path('openapi.json');

    expect(file_exists($path))->toBeTrue('openapi.json is not committed — run `task openapi:sync`.');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

test('numeric session identifiers are typed integer, not string', function (string $pointer): void {
    $type = Arr::get(committedOpenApiSpec(), $pointer);

    // A SQLite export types every one of these `string`. Postgres types them
    // `integer`. There is no third answer, so the assertion can be exact.
    expect($type)->toBe(
        'integer',
        "{$pointer} is \"".json_encode($type).'". A `string` here means openapi.json was exported against '
        .'SQLite. Re-export against Postgres: see `task openapi:sync` in the wrapper Taskfile.'
    );
})->with([
    'components.schemas.SessionReviewResource.properties.id.type',
    'components.schemas.SessionReviewResource.properties.participant_id.type',
    'components.schemas.SessionReviewResource.properties.question_index.type',
    'components.schemas.SessionSummaryResource.properties.id.type',
    'components.schemas.SessionSummaryResource.properties.question_index.type',
]);

test('nullable session columns are typed as a nullable union, not a bare string', function (string $pointer): void {
    // `ended_reason` and `provider_session_ref` are nullable in the schema.
    // Under SQLite they come back as a bare `string`, which tells a generated
    // client the field is always present — so the client never null-checks a
    // field that is null for every session that has not ended.
    expect(Arr::get(committedOpenApiSpec(), $pointer))
        ->toBe(['string', 'null'], "{$pointer} lost its nullability — openapi.json looks like a SQLite export.");
})->with([
    'components.schemas.SessionReviewResource.properties.ended_reason.type',
    'components.schemas.SessionReviewResource.properties.provider_session_ref.type',
    'components.schemas.SessionSummaryResource.properties.ended_reason.type',
]);

test('info.version matches the VERSION file', function (): void {
    // CI regenerates the spec and diffs it, and `info.version` is GENERATED
    // from VERSION — so bumping the version without re-exporting turns `main`
    // red AFTER the release is already tagged and deployed. That is exactly
    // how v0.36.0 shipped. Failing here fails on the release branch instead.
    $version = trim((string) file_get_contents(base_path('VERSION')));

    expect(Arr::get(committedOpenApiSpec(), 'info.version'))->toBe(
        $version,
        'openapi.json was exported before the version bump. Re-export after bumping VERSION.'
    );
});
