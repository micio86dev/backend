<?php

declare(strict_types=1);

/**
 * Platform settings — the knobs that belong to BEAI, not to a tenant.
 *
 * WHY A NEW STORE RATHER THAN A COLUMN ON `organizations`
 * ------------------------------------------------------
 * "How many questions may an operator author per competency" is not a client's
 * decision. It is a property of the assessment METHOD: a `standard` interview
 * opens with at most one predefined question because the adaptivity is the
 * product, and a tenant able to raise that to twenty would turn a BARS
 * interview into a questionnaire while still calling it a BARS interview.
 *
 * So it sits above every organization, and only the superadmin — who belongs
 * to none — may change it.
 *
 * WHY IT IS A SETTING AT ALL RATHER THAN A CONSTANT
 * ------------------------------------------------
 * It was `private const MAX_PER_COMPETENCY = ['standard' => 1, 'potential' => 4]`
 * inside a FormRequest, so changing it meant a release. The default is
 * unchanged (1 / 4); what changes is who can move it and how fast.
 */

use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Support\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

// `Feature/Superadmin` is not registered for RefreshDatabase in tests/Pest.php,
// so this file declares it — otherwise its factory rows outlive the run against
// the real test database and resurface as "email has already been taken"
// somewhere unrelated on the second execution.
uses(RefreshDatabase::class);

test('the defaults are 1 for standard and 4 for potential', function (): void {
    // Unchanged from the constant this replaces. A settings store whose
    // defaults differ from the behaviour it inherits is a silent migration of
    // the product's rules.
    $settings = app(PlatformSettings::class);

    expect($settings->maxQuestionsPerCompetency('standard'))->toBe(1);
    expect($settings->maxQuestionsPerCompetency('potential'))->toBe(4);
});

test('a superadmin reads the settings', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $response = $this->withToken($token)->getJson('/api/admin/settings');

    $response->assertOk();
    $response->assertJsonPath('data.max_questions_per_competency.standard', 1);
    $response->assertJsonPath('data.max_questions_per_competency.potential', 4);
});

test('a superadmin changes the standard cap and it takes effect immediately', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $this->withToken($token)
        ->patchJson('/api/admin/settings', [
            'max_questions_per_competency' => ['standard' => 3],
        ])
        ->assertOk()
        ->assertJsonPath('data.max_questions_per_competency.standard', 3);

    // Read back through the service the validator actually uses — asserting
    // only the response would prove the endpoint echoes its own input.
    expect(app(PlatformSettings::class)->maxQuestionsPerCompetency('standard'))->toBe(3);

    // The untouched half keeps its default rather than being reset by a
    // partial write.
    expect(app(PlatformSettings::class)->maxQuestionsPerCompetency('potential'))->toBe(4);
});

test('every tenant role is refused, read and write alike', function (string $role): void {
    // Not a 404. These endpoints hold no record whose existence needs
    // protecting — they are a capability, and the caller is authenticated, so
    // 403 says the true thing (SuperadminController's own doctrine).
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, $role);

    $this->withToken($token)->getJson('/api/admin/settings')->assertForbidden();

    $this->withToken($token)
        ->patchJson('/api/admin/settings', ['max_questions_per_competency' => ['standard' => 9]])
        ->assertForbidden();
})->with(['admin', 'operator', 'viewer']);

test('the cap is refused below 1 — zero would silently disable authoring', function (): void {
    // A cap of 0 does not mean "unlimited" and does not mean "off": it means
    // every save fails with a message about a maximum, which is the least
    // explicable state this knob could be left in.
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $this->withToken($token)
        ->patchJson('/api/admin/settings', ['max_questions_per_competency' => ['standard' => 0]])
        ->assertStatus(422);
});

test('the setting is a single row per key, not a row per write', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    foreach ([2, 3, 5] as $value) {
        $this->withToken($token)
            ->patchJson('/api/admin/settings', ['max_questions_per_competency' => ['standard' => $value]])
            ->assertOk();
    }

    expect(PlatformSetting::count())->toBe(1);
    expect(app(PlatformSettings::class)->maxQuestionsPerCompetency('standard'))->toBe(5);
});

test('a payload naming no recognised cap is a 422, never a 500', function (): void {
    /**
     * The case the original tests missed, and it was not hypothetical.
     *
     * Laravel excludes unvalidated array keys by default, and a key carrying an
     * `array` rule plus dotted sub-rules has its PARENT dropped from
     * `validated()` and re-set only from the sub-keys that actually arrived. A
     * body naming none of them passed `required|array`, skipped both
     * `sometimes` rules, and left `validated()` empty — so the read was null,
     * and under strict_types that hit `setMaxQuestionsPerCompetency(array)` as
     * a TypeError.
     *
     * The endpoint answered 500 to a malformed request. The suite covered
     * defaults, reads, partial writes, RBAC, the floor and the upsert, and not
     * this.
     */
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $this->withToken($token)
        ->patchJson('/api/admin/settings', ['max_questions_per_competency' => ['foo' => 3]])
        ->assertStatus(422);
});

test('an unrecognised cap alongside a valid one is refused whole', function (): void {
    // Not partially applied. A request naming a key this endpoint does not
    // understand is a request whose author believed something untrue about it,
    // and honouring half of it hides that.
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $this->withToken($token)
        ->patchJson('/api/admin/settings', [
            'max_questions_per_competency' => ['standard' => 3, 'nonsense' => 9],
        ])
        ->assertStatus(422);

    expect(app(PlatformSettings::class)->maxQuestionsPerCompetency('standard'))->toBe(1);
});

test('a stored value that is not a map at all degrades to the defaults', function (): void {
    // A SCALAR, not JSON null: the column is `json NOT NULL` and Eloquent's
    // array cast turns a PHP null into SQL NULL, which the constraint rejects.
    // `"not-a-map"` is valid JSON, survives the constraint, and makes
    // `is_array($stored)` false — the state the guard actually defends.
    PlatformSetting::factory()->nonArray()->create();

    expect(app(PlatformSettings::class)->maxQuestionsPerCompetency('standard'))->toBe(1);
});

test('integer keys from json_decode are ignored, not read as caps', function (): void {
    // `{"0": 5}` decodes to an INTEGER key. The reader's `is_string($type)`
    // check exists for exactly this and nothing else.
    PlatformSetting::factory()->numericKeys()->create();

    $settings = app(PlatformSettings::class);

    expect($settings->maxQuestionsPerCompetency('standard'))->toBe(2);
    expect($settings->maxQuestionsPerCompetency('potential'))->toBe(4);
});

test('a stored cap below the floor is clamped on the way out', function (): void {
    // The floor of 1 is the invariant this class documents as load-bearing —
    // a cap of 0 makes every save fail with a message about a maximum — and it
    // used to live only at the HTTP boundary, where a test, the factory or a
    // hand-edited row could all walk straight past it.
    // Asserted on BOTH sides. Clamping only the read left the setter returning
    // 0 while the getter answered 1, so the PATCH response and the next GET
    // disagreed about the same setting.
    $returned = app(PlatformSettings::class)->setMaxQuestionsPerCompetency(['standard' => 0]);

    expect($returned['standard'])->toBe(1);
    expect(app(PlatformSettings::class)->maxQuestionsPerCompetency('standard'))->toBe(1);
});

test('a malformed stored row degrades to the defaults instead of crashing', function (): void {
    // The state the HTTP endpoint cannot produce, and the one every guard in
    // PlatformSettings was written for: a hand-edited row, a restore from an
    // older schema, a future setting that stores something else. Until there
    // was a factory, no mutation of those guards would have failed a test.
    PlatformSetting::factory()->malformed()->create();

    $settings = app(PlatformSettings::class);

    expect($settings->maxQuestionsPerCompetency('standard'))->toBe(1);
    expect($settings->maxQuestionsPerCompetency('potential'))->toBe(4);
});
