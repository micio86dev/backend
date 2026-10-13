<?php

declare(strict_types=1);

/**
 * The refusal paths of the four catalogue content controllers (roles,
 * competencies, BARS indicators, default questions) that a happy-path CRUD test
 * never reaches:
 *
 *  - a write whose revision stopped being an open draft between validation and
 *    the row lock is a clean 409, writes nothing, and names the right cause;
 *  - an update/delete with no open draft is a 404, never a silent clone;
 *  - a write that passes validation but loses to a database constraint maps to
 *    the same stable 422 code as the sequential path, while an UNRECOGNISED
 *    database error is not swallowed.
 *
 * The race is made deterministic instead of timing-dependent: a query hook
 * flips the revision row exactly when the controller asks to lock it, which is
 * the state a concurrent publish or discard leaves behind (the real two-process
 * proof lives in DiscardRaceWithConcurrentWriteTest).
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function catalogueSuperadminToken(): string
{
    return auth('api')->login(User::factory()->create(['organization_id' => null, 'is_superadmin' => true]));
}

/**
 * @return array{draft: FrameworkCatalogRevision, role: Role, competency: Competency, indicator: BarsIndicator, question: FrameworkDefaultQuestion}
 */
function catalogueDraftWithOneOfEach(): array
{
    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    $role = Role::factory()->create(['revision_id' => $draft->id]);
    $competency = Competency::factory()->create(['revision_id' => $draft->id]);
    $indicator = BarsIndicator::factory()->create([
        'revision_id' => $draft->id,
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'position' => 0,
    ]);
    $question = FrameworkDefaultQuestion::factory()->create(['competency_id' => $competency->id, 'position' => 0]);

    return compact('draft', 'role', 'competency', 'indicator', 'question');
}

/**
 * Runs `$mutate` once, at the moment the next write asks to lock the revision.
 */
function whenRevisionIsLocked(int $revisionId, Closure $mutate): void
{
    $armed = true;

    DB::beforeExecuting(function (string $query, array $bindings) use (&$armed, $revisionId, $mutate): void {
        if ($armed && str_contains($query, '"framework_catalog_revisions"') && str_contains($query, 'for update') && $bindings === [$revisionId]) {
            $armed = false;
            $mutate();
        }
    });
}

/**
 * Runs `$mutate` once, right before a statement starting with `$statementPrefix`.
 */
function beforeStatement(string $statementPrefix, Closure $mutate): void
{
    $armed = true;

    DB::beforeExecuting(function (string $query) use (&$armed, $statementPrefix, $mutate): void {
        if ($armed && str_starts_with($query, $statementPrefix)) {
            $armed = false;
            $mutate($query);
        }
    });
}

function publishRevisionBehindTheBack(int $revisionId): void
{
    DB::table('framework_catalog_revisions')->where('id', $revisionId)->update(['state' => 'published', 'published_at' => now()]);
}

/**
 * @return array<string, array{string, string, Closure(array<string, mixed>): array<string, mixed>}>
 */
function catalogueMutations(): array
{
    return [
        'create role' => ['post', '/api/catalogue/roles', fn () => ['code' => 'RACEROLE', 'name' => ['en' => 'R', 'it' => 'R']]],
        'update role' => ['patch', '/api/catalogue/roles/{role}', fn () => ['name' => ['en' => 'Renamed']]],
        'delete role' => ['delete', '/api/catalogue/roles/{role}', fn () => []],
        'replace role competencies' => ['put', '/api/catalogue/roles/{role}/competencies', fn (array $f) => ['competency_ids' => [$f['competency']->id]]],
        'create competency' => ['post', '/api/catalogue/competencies', fn () => ['code' => 'RACECOMP', 'type' => 'standard', 'name' => ['en' => 'C', 'it' => 'C'], 'definition' => ['en' => 'D', 'it' => 'D']]],
        'update competency' => ['patch', '/api/catalogue/competencies/{competency}', fn () => ['name' => ['en' => 'Renamed']]],
        'delete competency' => ['delete', '/api/catalogue/competencies/{competency}', fn () => []],
        'create indicator' => ['post', '/api/catalogue/bars-indicators', fn (array $f) => [
            'competency_id' => $f['competency']->id, 'role_id' => $f['role']->id, 'position' => 1,
            'text' => ['en' => 'T', 'it' => 'T'], 'anchor_5' => ['en' => 'a', 'it' => 'a'], 'anchor_3' => ['en' => 'b', 'it' => 'b'], 'anchor_1' => ['en' => 'c', 'it' => 'c'],
        ]],
        'update indicator' => ['patch', '/api/catalogue/bars-indicators/{indicator}', fn () => ['text' => ['en' => 'Renamed']]],
        'delete indicator' => ['delete', '/api/catalogue/bars-indicators/{indicator}', fn () => []],
        'create default question' => ['post', '/api/catalogue/default-questions', fn (array $f) => [
            'competency_id' => $f['competency']->id, 'position' => 1, 'text' => ['en' => 'Q?', 'it' => 'D?'],
        ]],
        'update default question' => ['patch', '/api/catalogue/default-questions/{question}', fn () => ['text' => ['en' => 'Renamed?', 'it' => 'Rinominata?']]],
        'delete default question' => ['delete', '/api/catalogue/default-questions/{question}', fn () => []],
    ];
}

function catalogueUrl(string $template, array $fixture): string
{
    return strtr($template, [
        '{role}' => (string) $fixture['role']->id,
        '{competency}' => (string) $fixture['competency']->id,
        '{indicator}' => (string) $fixture['indicator']->id,
        '{question}' => (string) $fixture['question']->id,
    ]);
}

dataset('catalogue mutations', fn () => array_map(fn (array $m) => $m, catalogueMutations()));

dataset('catalogue updates and deletes', fn () => array_filter(
    catalogueMutations(),
    fn (string $label) => str_starts_with($label, 'update') || str_starts_with($label, 'delete') || str_starts_with($label, 'replace'),
    ARRAY_FILTER_USE_KEY,
));

// ─── 409: lost the race against a publish ───────────────────────────────────

test('a write that finds its revision published at lock time is a 409 and changes nothing', function (string $method, string $url, Closure $payload): void {
    $token = catalogueSuperadminToken();
    $fixture = catalogueDraftWithOneOfEach();
    $before = [
        'roles' => DB::table('framework_roles')->count(),
        'competencies' => DB::table('framework_competencies')->count(),
        'indicators' => DB::table('framework_bars_indicators')->count(),
        'questions' => DB::table('framework_default_questions')->count(),
    ];
    whenRevisionIsLocked($fixture['draft']->id, fn () => publishRevisionBehindTheBack($fixture['draft']->id));

    $this->withToken($token)->json($method, catalogueUrl($url, $fixture), $payload($fixture))
        ->assertStatus(409)
        ->assertJsonPath('error', 'revision_published_during_write');

    expect([
        'roles' => DB::table('framework_roles')->count(),
        'competencies' => DB::table('framework_competencies')->count(),
        'indicators' => DB::table('framework_bars_indicators')->count(),
        'questions' => DB::table('framework_default_questions')->count(),
    ])->toBe($before);
})->with('catalogue mutations');

test('a write that finds its draft discarded at lock time says discarded, not published', function (string $url, array $payload): void {
    $token = catalogueSuperadminToken();
    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    whenRevisionIsLocked($draft->id, fn () => DB::table('framework_catalog_revisions')->where('id', $draft->id)->delete());

    $this->withToken($token)->postJson($url, $payload)
        ->assertStatus(409)
        ->assertJsonPath('error', 'revision_discarded_during_write');
})->with([
    'role' => ['/api/catalogue/roles', ['code' => 'GONE', 'name' => ['en' => 'R', 'it' => 'R']]],
    'competency' => ['/api/catalogue/competencies', ['code' => 'GONE', 'type' => 'standard', 'name' => ['en' => 'C', 'it' => 'C'], 'definition' => ['en' => 'D', 'it' => 'D']]],
]);

// ─── 404: nothing to update without an open draft ───────────────────────────

test('updating or deleting with no open draft is a 404 and never opens one', function (string $method, string $url, Closure $payload): void {
    $token = catalogueSuperadminToken();
    $fixture = catalogueDraftWithOneOfEach();
    publishRevisionBehindTheBack($fixture['draft']->id);

    // With no draft, no competency can validate as "in the draft", so the replace-set
    // request is sent empty to get past validation and reach the controller's own check.
    $body = $method === 'put' ? ['competency_ids' => []] : $payload($fixture);

    $this->withToken($token)->json($method, catalogueUrl($url, $fixture), $body)->assertNotFound();

    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();
})->with('catalogue updates and deletes');

test('listing before any revision exists is an empty list, not an error', function (string $path): void {
    $token = catalogueSuperadminToken();
    DB::table('framework_catalog_revisions')->delete();

    $this->withToken($token)->getJson($path)->assertOk()->assertExactJson(['data' => []]);
})->with([
    'indicators' => '/api/catalogue/bars-indicators',
    'default questions' => '/api/catalogue/default-questions',
]);

// ─── 422: lost the race against a database constraint ───────────────────────

test('a create that loses a uniqueness race maps to the same stable 422 code as the sequential path', function (string $table, string $url, Closure $payload, Closure $competing, string $code): void {
    $token = catalogueSuperadminToken();
    $fixture = catalogueDraftWithOneOfEach();
    beforeStatement('insert into "'.$table.'"', fn () => $competing($fixture));

    $this->withToken($token)->postJson($url, $payload($fixture))
        ->assertStatus(422)
        ->assertExactJson(['error' => $code]);
})->with([
    'role code' => [
        'framework_roles', '/api/catalogue/roles',
        fn () => ['code' => 'LOSER', 'name' => ['en' => 'R', 'it' => 'R']],
        fn (array $f) => Role::factory()->create(['revision_id' => $f['draft']->id, 'code' => 'LOSER']),
        'code_taken',
    ],
    'competency code' => [
        'framework_competencies', '/api/catalogue/competencies',
        fn () => ['code' => 'LOSER', 'type' => 'standard', 'name' => ['en' => 'C', 'it' => 'C'], 'definition' => ['en' => 'D', 'it' => 'D']],
        fn (array $f) => Competency::factory()->create(['revision_id' => $f['draft']->id, 'code' => 'LOSER']),
        'code_taken',
    ],
    'indicator position' => [
        'framework_bars_indicators', '/api/catalogue/bars-indicators',
        fn (array $f) => [
            'competency_id' => $f['competency']->id, 'role_id' => $f['role']->id, 'position' => 1,
            'text' => ['en' => 'T', 'it' => 'T'], 'anchor_5' => ['en' => 'a', 'it' => 'a'], 'anchor_3' => ['en' => 'b', 'it' => 'b'], 'anchor_1' => ['en' => 'c', 'it' => 'c'],
        ],
        fn (array $f) => BarsIndicator::factory()->create(['revision_id' => $f['draft']->id, 'role_id' => $f['role']->id, 'competency_id' => $f['competency']->id, 'position' => 1]),
        'position_taken_for_pair',
    ],
    'default question position' => [
        'framework_default_questions', '/api/catalogue/default-questions',
        fn (array $f) => ['competency_id' => $f['competency']->id, 'position' => 1, 'text' => ['en' => 'Q?', 'it' => 'D?']],
        fn (array $f) => FrameworkDefaultQuestion::factory()->create(['competency_id' => $f['competency']->id, 'position' => 1]),
        'position_taken_for_competency',
    ],
]);

test('an update that loses a uniqueness race maps to the same stable 422 code', function (string $table, string $url, Closure $payload, Closure $competing, string $code): void {
    $token = catalogueSuperadminToken();
    $fixture = catalogueDraftWithOneOfEach();
    beforeStatement('update "'.$table.'"', fn () => $competing($fixture));

    $this->withToken($token)->patchJson(catalogueUrl($url, $fixture), $payload($fixture))
        ->assertStatus(422)
        ->assertExactJson(['error' => $code]);
})->with([
    'role code' => [
        'framework_roles', '/api/catalogue/roles/{role}',
        fn () => ['code' => 'LOSER'],
        fn (array $f) => Role::factory()->create(['revision_id' => $f['draft']->id, 'code' => 'LOSER']),
        'code_taken',
    ],
    'competency code' => [
        'framework_competencies', '/api/catalogue/competencies/{competency}',
        fn () => ['code' => 'LOSER'],
        fn (array $f) => Competency::factory()->create(['revision_id' => $f['draft']->id, 'code' => 'LOSER']),
        'code_taken',
    ],
    'indicator position' => [
        'framework_bars_indicators', '/api/catalogue/bars-indicators/{indicator}',
        fn () => ['position' => 2],
        fn (array $f) => BarsIndicator::factory()->create(['revision_id' => $f['draft']->id, 'role_id' => $f['role']->id, 'competency_id' => $f['competency']->id, 'position' => 2]),
        'position_taken_for_pair',
    ],
    'default question position' => [
        'framework_default_questions', '/api/catalogue/default-questions/{question}',
        fn () => ['position' => 2],
        fn (array $f) => FrameworkDefaultQuestion::factory()->create(['competency_id' => $f['competency']->id, 'position' => 2]),
        'position_taken_for_competency',
    ],
]);

test('a database error that is not a known catalogue constraint is not disguised as a validation failure', function (string $method, string $statementPrefix, string $url, Closure $payload): void {
    $token = catalogueSuperadminToken();
    $fixture = catalogueDraftWithOneOfEach();
    beforeStatement($statementPrefix, function (string $query): void {
        throw new QueryException('pgsql', $query, [], new RuntimeException('some unrelated failure'));
    });

    $this->withToken($token)->json($method, catalogueUrl($url, $fixture), $payload($fixture))->assertStatus(500);
})->with(function (): array {
    $m = catalogueMutations();
    $case = fn (string $method, string $prefix, string $label): array => [$method, $prefix, $m[$label][1], $m[$label][2]];

    return [
        'create role' => $case('post', 'insert into "framework_roles"', 'create role'),
        'update role' => $case('patch', 'update "framework_roles"', 'update role'),
        'create competency' => $case('post', 'insert into "framework_competencies"', 'create competency'),
        'update competency' => $case('patch', 'update "framework_competencies"', 'update competency'),
        'create indicator' => $case('post', 'insert into "framework_bars_indicators"', 'create indicator'),
        'update indicator' => $case('patch', 'update "framework_bars_indicators"', 'update indicator'),
        'create default question' => $case('post', 'insert into "framework_default_questions"', 'create default question'),
        'update default question' => $case('patch', 'update "framework_default_questions"', 'update default question'),
    ];
});

test('a create that loses a race after it had to open a fresh draft discards that draft instead of orphaning it', function (): void {
    $token = catalogueSuperadminToken();
    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();

    $armed = true;
    DB::beforeExecuting(function (string $query, array $bindings) use (&$armed): void {
        // Only the controller's own insert carries the new code; the clone's inserts do not.
        if ($armed && str_starts_with($query, 'insert into "framework_competencies"') && in_array('LOSER2', $bindings, true)) {
            $armed = false;
            Competency::factory()->create(['revision_id' => FrameworkCatalogRevision::openDraft()->id, 'code' => 'LOSER2']);
        }
    });

    $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'LOSER2', 'type' => 'standard', 'name' => ['en' => 'C', 'it' => 'C'], 'definition' => ['en' => 'D', 'it' => 'D'],
    ])->assertStatus(422)->assertExactJson(['error' => 'code_taken']);

    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();
});

// ─── Revision lifecycle endpoints ───────────────────────────────────────────

test('discarding a draft that was published while the request waited is a 409 and the revision stays published', function (): void {
    $token = catalogueSuperadminToken();
    $fixture = catalogueDraftWithOneOfEach();
    whenRevisionIsLocked($fixture['draft']->id, fn () => publishRevisionBehindTheBack($fixture['draft']->id));

    $this->withToken($token)->deleteJson('/api/catalogue/revisions/draft')
        ->assertStatus(409)
        ->assertJsonPath('error', 'revision_published_during_write');
});

test('discarding or publishing with no open draft is a 404', function (string $method, string $url): void {
    $token = catalogueSuperadminToken();
    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();

    $this->withToken($token)->json($method, $url)->assertNotFound();
})->with([
    'discard' => ['delete', '/api/catalogue/revisions/draft'],
    'publish' => ['post', '/api/catalogue/revisions/publish'],
]);

test('publishing a draft that fails the sweep answers 422 with the violations and leaves it a draft', function (): void {
    $token = catalogueSuperadminToken();
    // A role-assigned competency with one BARS indicator instead of three cannot be published.
    ['draft' => $draft, 'role' => $role, 'competency' => $competency] = catalogueDraftWithOneOfEach();
    $role->competencies()->sync([$competency->id => ['position' => 0]]);

    $response = $this->withToken($token)->postJson('/api/catalogue/revisions/publish')->assertStatus(422);

    expect($response->json('violations'))->toBeArray()->not->toBeEmpty()
        ->and($draft->fresh()->state)->toBe('draft');
});
