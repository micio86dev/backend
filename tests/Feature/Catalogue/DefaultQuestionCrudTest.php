<?php

declare(strict_types=1);

/**
 * RED/GREEN — 14.1 (framework-catalogue-authoring PR4): superadmin CRUD over
 * `framework_default_questions`, scoped to the open draft revision exactly
 * like Competency/Role/BarsIndicator (design D3/D12, catalogue-authoring
 * spec — "Catalogue-Level Default Questions Per Competency"). A default
 * missing `it` is rejected — stricter than every other catalogue
 * FormRequest's `en`-mandatory/`it`-optional rule, because
 * `ApplyCompetencySelection` (PR5) copies this text verbatim into whatever
 * language the receiving project runs in.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function defaultQuestionSuperadminToken(): string
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return auth('api')->login($user);
}

function defaultQuestionOpenDraftCompetency(string $token, string $code): int
{
    test()->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => $code,
        'type' => 'standard',
        'name' => ['en' => 'x', 'it' => 'x'],
        'definition' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(201);

    return (int) DB::table('framework_competencies')->where('code', $code)->value('id');
}

test('a default question is authored in both locales, persists, and is orderable among its siblings', function (): void {
    $token = defaultQuestionSuperadminToken();
    $competencyId = defaultQuestionOpenDraftCompetency($token, 'DQCOMP1');

    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'Tell me about a time...', 'it' => 'Raccontami di una volta...'],
        'position' => 1,
    ])->assertStatus(201)
        ->assertJsonPath('data.competency_id', $competencyId)
        ->assertJsonPath('data.text.en', 'Tell me about a time...')
        ->assertJsonPath('data.text.it', 'Raccontami di una volta...')
        ->assertJsonPath('data.position', 1);

    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'What happened next?', 'it' => 'Cosa è successo dopo?'],
        'position' => 0,
    ])->assertStatus(201);

    $ordered = collect(
        $this->withToken($token)->getJson('/api/catalogue/default-questions')->assertOk()->json('data')
    )->where('competency_id', $competencyId)->sortBy('position')->values();

    expect($ordered->pluck('position')->all())->toBe([0, 1]);
    expect($ordered->first()['text']['en'])->toBe('What happened next?');
    expect($ordered->last()['text']['en'])->toBe('Tell me about a time...');
});

test('a default question missing the required it locale is rejected with 422', function (): void {
    $token = defaultQuestionSuperadminToken();
    $competencyId = defaultQuestionOpenDraftCompetency($token, 'DQCOMP2');

    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'Only English text.'],
        'position' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors(['text.it']);

    expect(DB::table('framework_default_questions')->where('competency_id', $competencyId)->exists())->toBeFalse();
});

test('a duplicate position for the same competency is rejected with 422, not a raw DB error', function (): void {
    $token = defaultQuestionSuperadminToken();
    $competencyId = defaultQuestionOpenDraftCompetency($token, 'DQCOMP5');

    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'first', 'it' => 'primo'],
        'position' => 0,
    ])->assertStatus(201);

    // A malformed, non-numeric competency_id must not reach the DB at all —
    // its own `integer`/`exists` rule fails, and the position-uniqueness
    // check degrades to a no-op rather than a raw QueryException.
    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => 'not-a-number',
        'text' => ['en' => 'second', 'it' => 'secondo'],
        'position' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors(['competency_id']);

    // The genuine duplicate: same competency, same position, valid id.
    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'second', 'it' => 'secondo'],
        'position' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors(['position']);

    $second = $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'second', 'it' => 'secondo'],
        'position' => 1,
    ])->assertStatus(201)->json('data.id');

    // PATCHing the second row onto the first row's occupied position is the
    // same conflict, now on the update side.
    $this->withToken($token)
        ->patchJson("/api/catalogue/default-questions/{$second}", ['position' => 0])
        ->assertStatus(422)->assertJsonValidationErrors(['position']);
});

test('a competency id absent from the open draft is rejected with 422', function (): void {
    $token = defaultQuestionSuperadminToken();
    // Opens a real draft (any edit does), so the exists-rule's `where
    // revision_id` scope is exercised against a genuine draft — the id
    // below simply never belongs to it.
    defaultQuestionOpenDraftCompetency($token, 'DQCOMPSCOPE');

    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => 999_999_999,
        'text' => ['en' => 'x', 'it' => 'x'],
        'position' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors(['competency_id']);
});

test('update() returns 200 with the fresh row, and destroy() returns 204', function (): void {
    $token = defaultQuestionSuperadminToken();
    $competencyId = defaultQuestionOpenDraftCompetency($token, 'DQCOMP6');

    $id = $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'original', 'it' => 'originale'],
        'position' => 0,
    ])->assertStatus(201)->json('data.id');

    $this->withToken($token)
        ->patchJson("/api/catalogue/default-questions/{$id}", [
            'text' => ['en' => 'updated', 'it' => 'aggiornato'],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.text.en', 'updated')
        ->assertJsonPath('data.text.it', 'aggiornato');

    // `text.it` is `required_with:text` — a PATCH naming `text` but not
    // `it` is refused, closing the exact gap the previous `sometimes`
    // implementation left open.
    $this->withToken($token)
        ->patchJson("/api/catalogue/default-questions/{$id}", ['text' => ['en' => 'only english']])
        ->assertStatus(422)->assertJsonValidationErrors(['text.it']);

    $this->withToken($token)
        ->deleteJson("/api/catalogue/default-questions/{$id}")
        ->assertStatus(204);

    expect(DB::table('framework_default_questions')->where('id', $id)->exists())->toBeFalse();
});

test('an org admin gets 403 on index() too', function (): void {
    $nonSuperadmin = User::factory()->create();
    $nonSuperadminToken = auth('api')->login($nonSuperadmin);

    $this->withToken($nonSuperadminToken)->getJson('/api/catalogue/default-questions')->assertStatus(403);
});

test('an org admin gets 403 on every default-question write action', function (): void {
    $token = defaultQuestionSuperadminToken();
    $competencyId = defaultQuestionOpenDraftCompetency($token, 'DQCOMP3');

    $questionId = DB::table('framework_default_questions')->insertGetId([
        'revision_id' => DB::table('framework_competencies')->where('id', $competencyId)->value('revision_id'),
        'competency_id' => $competencyId,
        'text' => json_encode(['en' => 'x', 'it' => 'x']),
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $nonSuperadmin = User::factory()->create();
    $nonSuperadminToken = auth('api')->login($nonSuperadmin);

    $this->withToken($nonSuperadminToken)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'x', 'it' => 'x'],
        'position' => 5,
    ])->assertStatus(403);

    $this->withToken($nonSuperadminToken)
        ->patchJson("/api/catalogue/default-questions/{$questionId}", ['text' => ['en' => 'y']])
        ->assertStatus(403);

    $this->withToken($nonSuperadminToken)
        ->deleteJson("/api/catalogue/default-questions/{$questionId}")
        ->assertStatus(403);
});

test('creating a default question bumps the draft content_version', function (): void {
    // Companion to `CatalogueControllerReusesFormRequestDraftTest`'s own
    // Role/Competency/BarsIndicator cases: `DiscardUnusedDraftRevision`
    // treats `content_version === 0` as "genuinely untouched, safe to
    // discard on a failed validation elsewhere". Without
    // `BumpsRevisionContentVersion` on `FrameworkDefaultQuestion`, a
    // superadmin who authored ONLY a default question (no role/competency/
    // indicator edit) would leave the draft looking untouched, and a
    // later, unrelated failed-validation request could discard it.
    $token = defaultQuestionSuperadminToken();
    $competencyId = defaultQuestionOpenDraftCompetency($token, 'DQCOMP4');

    $draft = FrameworkCatalogRevision::openDraft();
    expect($draft)->not->toBeNull();

    $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId,
        'text' => ['en' => 'x', 'it' => 'x'],
        'position' => 0,
    ])->assertStatus(201);

    expect($draft->fresh()->content_version)->toBeGreaterThan(0);
});

test('a published revision refuses default-question writes the same way it refuses anchors', function (): void {
    // 16.2: published-revision immutability holds for default questions the
    // same as for anchors — the CRUD surface 404s (never reaches a
    // published revision: every write is scoped to the open draft), and the
    // DB trigger refuses a raw, Eloquent-bypassing write naming a published
    // revision directly.
    $token = defaultQuestionSuperadminToken();

    $published = FrameworkCatalogRevision::factory()->draft()->create();
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $published->id, 'code' => 'DQPUBIMMUT', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $questionId = DB::table('framework_default_questions')->insertGetId([
        'revision_id' => $published->id, 'competency_id' => $competencyId,
        'text' => json_encode(['en' => 'x', 'it' => 'x']), 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    // Opens a real draft so the 404 below is genuinely produced by the
    // draft-scoped `findOrFail`, not by "no draft open at all" (H8).
    defaultQuestionOpenDraftCompetency($token, 'DQPUBIMMUTDRAFT');

    $this->withToken($token)
        ->patchJson("/api/catalogue/default-questions/{$questionId}", ['text' => ['en' => 'renamed', 'it' => 'rinominato']])
        ->assertStatus(404);

    $this->withToken($token)
        ->deleteJson("/api/catalogue/default-questions/{$questionId}")
        ->assertStatus(404);

    assertPostgresConstraintViolation(
        fn () => DB::table('framework_default_questions')->insert([
            'revision_id' => $published->id, 'competency_id' => $competencyId,
            'text' => json_encode(['en' => 'y', 'it' => 'y']), 'position' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]),
        '23514',
        'framework_catalog_published_content_immutable',
    );
});
