<?php

declare(strict_types=1);

/**
 * Changing the interview intro is a platform mutation, so it is audited through
 * `PlatformAuditWriter` atomically with the write, exactly like the currency
 * catalogue: an unaudited change must not exist, and the cap knob stays unaudited
 * (unchanged behaviour).
 */

use App\Models\Organization;
use App\Support\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PSA_ACTION = 'platform_settings.interview_intro_phrase.updated';

/** @return list<object> */
function psaRows(): array
{
    return DB::table('audit_logs')->where('action', PSA_ACTION)->orderBy('id')->get()->all();
}

/** @return array<string, mixed> */
function psaJson(?string $value): array
{
    return $value === null ? [] : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
}

function psaToken(): string
{
    return authTokenForRole(Organization::factory()->create(), 'platform');
}

test('setting, replacing and clearing the intro each write one platform audit row with before and after', function (): void {
    $token = psaToken();

    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_intro_phrase' => ['en' => 'One.']])->assertOk();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_intro_phrase' => ['en' => 'Two.', 'it' => 'Due.']])->assertOk();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_intro_phrase' => null])->assertOk();

    $rows = psaRows();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->organization_id)->toBeNull()
        ->and($rows[0]->subject_type)->toBe('PlatformSetting')
        ->and($rows[0]->subject_id)->toBeNull()
        ->and($rows[0]->actor_id)->not->toBeNull()
        ->and(psaJson($rows[0]->before))->toBe(['interview_intro_phrase' => []])
        ->and(psaJson($rows[0]->after))->toBe(['interview_intro_phrase' => ['en' => 'One.']])
        ->and(psaJson($rows[1]->before))->toBe(['interview_intro_phrase' => ['en' => 'One.']])
        ->and(psaJson($rows[1]->after))->toBe(['interview_intro_phrase' => ['en' => 'Two.', 'it' => 'Due.']])
        ->and(psaJson($rows[2]->after))->toBe(['interview_intro_phrase' => []]);
});

test('an intro that looks like JSON is stored as text, not decoded by the audit writer', function (): void {
    $this->withToken(psaToken())->patchJson('/api/admin/settings', ['interview_intro_phrase' => ['en' => '[1, 2]']])->assertOk();

    expect(psaJson(psaRows()[0]->after))->toBe(['interview_intro_phrase' => ['en' => '[1, 2]']]);
});

test('a PATCH that changes nothing writes no audit row', function (): void {
    $token = psaToken();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_intro_phrase' => ['en' => 'Same.']])->assertOk();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_intro_phrase' => ['en' => 'Same.']])->assertOk();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_intro_phrase' => null])->assertOk();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_intro_phrase' => null])->assertOk();

    expect(psaRows())->toHaveCount(2);
});

test('the caps knob is not audited', function (): void {
    $this->withToken(psaToken())->patchJson('/api/admin/settings', ['max_questions_per_competency' => ['standard' => 3]])->assertOk();

    expect(DB::table('audit_logs')->count())->toBe(0);
});

test('a refused or forbidden PATCH writes no audit row', function (): void {
    $this->withToken(psaToken())->patchJson('/api/admin/settings', ['interview_intro_phrase' => ['it' => 'Solo italiano.']])->assertStatus(422);
    $this->withToken(authTokenForRole(Organization::factory()->create(), 'admin'))
        ->patchJson('/api/admin/settings', ['interview_intro_phrase' => ['en' => 'Hi.']])->assertForbidden();

    expect(DB::table('audit_logs')->count())->toBe(0);
});

test('a failing audit write rolls the whole PATCH back and fails loudly', function (): void {
    DB::unprepared('CREATE FUNCTION psa_refuse_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'audit refused\'; END $$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER psa_refuse_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION psa_refuse_audit()');

    $this->withToken(psaToken())
        ->patchJson('/api/admin/settings', [
            'max_questions_per_competency' => ['standard' => 5],
            'interview_intro_phrase' => ['en' => 'Never stored.'],
        ])
        ->assertStatus(500);

    expect(app(PlatformSettings::class)->interviewIntroPhraseMap())->toBe([])
        ->and(app(PlatformSettings::class)->maxQuestionsPerCompetency('standard'))->toBe(1);
});

test('setting, replacing and clearing the closing phrase each write one platform audit row', function (): void {
    DB::table('platform_settings')->where('key', PlatformSettings::INTERVIEW_CLOSING_KEY)->delete();
    $token = psaToken();
    $action = 'platform_settings.interview_closing_phrase.updated';

    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_closing_phrase' => ['en' => 'One.']])->assertOk();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_closing_phrase' => ['en' => 'One.']])->assertOk();
    $this->withToken($token)->patchJson('/api/admin/settings', ['interview_closing_phrase' => null])->assertOk();

    $rows = DB::table('audit_logs')->where('action', $action)->orderBy('id')->get()->all();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->subject_type)->toBe('PlatformSetting')
        ->and(psaJson($rows[0]->before))->toBe(['interview_closing_phrase' => []])
        ->and(psaJson($rows[0]->after))->toBe(['interview_closing_phrase' => ['en' => 'One.']])
        ->and(psaJson($rows[1]->after))->toBe(['interview_closing_phrase' => []])
        ->and(psaRows())->toHaveCount(0);
});
