<?php

declare(strict_types=1);

/**
 * An operator reading in Italian gets an Italian report — where the text is
 * ours to translate, and only there.
 *
 * `indicator_text` is frozen at scoring time in the PROJECT's language. That is
 * right for EVIDENCE: an explanation of what a candidate said is a record of an
 * assessment conducted in one language, and re-languaging it would be inventing
 * words nobody said. An indicator NAME is neither evidence nor a guess — it is
 * catalogue data authored in both languages, and the operator reading the
 * report is not the candidate.
 *
 * The mechanism to pick a locale already existed and reached exactly three
 * framework-catalogue endpoints, because it was a private method each of them
 * remembered to call. Now it is middleware on the whole api group.
 */

use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role as FrameworkRole;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{token: string, participant: Participant}
 */
function reportLanguageFixture(): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));
    app(TenantResolver::class)->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // A real catalogue row, authored in both languages — the whole point is
    // that the report picks one of them rather than echoing a frozen string.
    // Created rather than looked up: the framework catalogue is imported by a
    // seeder this suite does not run, and a test that depends on seeded
    // reference data fails for a reason it is not about.
    $role = FrameworkRole::firstOrCreate(['code' => 'ICO'], FrameworkRole::factory()->raw(['code' => 'ICO']));
    $competency = Competency::firstOrCreate(['code' => 'COM'], Competency::factory()->raw(['code' => 'COM']));

    $indicator = new App\Models\BarsIndicator;
    $indicator->role_id = $role->id;
    $indicator->competency_id = $competency->id;
    $indicator->position = 1;
    $indicator->setTranslation('text', 'en', 'Get the point across clearly and concisely');
    $indicator->setTranslation('text', 'it', 'Trasmettere il messaggio in modo chiaro e conciso');
    $indicator->setTranslation('anchor_5', 'en', 'a');
    $indicator->setTranslation('anchor_3', 'en', 'b');
    $indicator->setTranslation('anchor_1', 'en', 'c');
    $indicator->save();

    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
        // The PROJECT is in English. The operator is not.
        'language' => 'en',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'status' => 'completato',
    ]);

    $evaluation = Evaluation::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'status' => 'completed',
    ]);

    $result = CompetencyResult::factory()->create([
        'organization_id' => $org->id,
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COM',
    ]);

    IndicatorScore::factory()->create([
        'organization_id' => $org->id,
        'competency_result_id' => $result->id,
        'position' => 1,
        'score' => 3,
        // Frozen in the project's language, exactly as scoring writes it.
        'indicator_text' => 'Get the point across clearly and concisely',
    ]);

    return ['token' => auth('api')->login($user), 'participant' => $participant];
}

test('an Italian reader gets the indicator name in Italian, on an English project', function (): void {
    ['token' => $token, 'participant' => $participant] = reportLanguageFixture();

    $response = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8'])
        ->getJson("/api/participants/{$participant->id}/evaluation");

    $response->assertOk();
    $response->assertJsonPath(
        'data.COM.behaviors.0.indicator',
        'Trasmettere il messaggio in modo chiaro e conciso'
    );
});

test('an English reader gets English, from the same stored row', function (): void {
    ['token' => $token, 'participant' => $participant] = reportLanguageFixture();

    $response = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])
        ->getJson("/api/participants/{$participant->id}/evaluation");

    $response->assertJsonPath(
        'data.COM.behaviors.0.indicator',
        'Get the point across clearly and concisely'
    );
});

test('an explicit ?locale= wins over the header', function (): void {
    ['token' => $token, 'participant' => $participant] = reportLanguageFixture();

    $response = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])
        ->getJson("/api/participants/{$participant->id}/evaluation?locale=it");

    $response->assertJsonPath(
        'data.COM.behaviors.0.indicator',
        'Trasmettere il messaggio in modo chiaro e conciso'
    );
});

test('an unsupported Accept-Language degrades instead of failing the request', function (): void {
    // Sent by every browser without anybody choosing it. Refusing a request
    // the caller did not know they were making would be the wrong trade —
    // unlike `?locale=`, which is a claim about what someone can read.
    ['token' => $token, 'participant' => $participant] = reportLanguageFixture();

    $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'ja-JP,ja;q=0.9'])
        ->getJson("/api/participants/{$participant->id}/evaluation")
        ->assertOk();
});

test('an unsupported ?locale= is refused, because it is a claim', function (): void {
    ['token' => $token, 'participant' => $participant] = reportLanguageFixture();

    $this->withToken($token)
        ->getJson("/api/participants/{$participant->id}/evaluation?locale=ja")
        ->assertStatus(422);
});
