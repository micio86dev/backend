<?php

declare(strict_types=1);

/**
 * RED/GREEN — 10.3/11.1/11.2 (framework-catalogue-authoring PR3, D3): a 6th
 * role, a 4th indicator for a pair, a blank `it` locale value → 422 at the
 * FormRequest layer.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function catalogueTwinSuperadminToken(): string
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return auth('api')->login($user);
}

test('a sixth role is refused with HTTP 422', function (): void {
    $token = catalogueTwinSuperadminToken();

    foreach (['R1', 'R2', 'R3', 'R4', 'R5'] as $code) {
        $this->withToken($token)->postJson('/api/catalogue/roles', [
            'code' => $code,
            'name' => ['en' => "Role {$code}"],
        ])->assertCreated();
    }

    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'R6',
        'name' => ['en' => 'Role 6'],
    ])->assertStatus(422);
});

test('a fourth indicator for a pair is refused with HTTP 422', function (): void {
    $token = catalogueTwinSuperadminToken();

    $this->withToken($token)->postJson('/api/catalogue/roles', ['code' => 'IND', 'name' => ['en' => 'Indicator Role']])->assertCreated();
    $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'INDC', 'type' => 'standard', 'name' => ['en' => 'x'], 'definition' => ['en' => 'x'],
    ])->assertCreated();

    $draftId = FrameworkCatalogRevision::where('state', 'draft')->value('id');
    $roleId = DB::table('framework_roles')->where('revision_id', $draftId)->where('code', 'IND')->value('id');
    $competencyId = DB::table('framework_competencies')->where('revision_id', $draftId)->where('code', 'INDC')->value('id');

    $payload = fn (int $position) => [
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'position' => $position,
        'text' => ['en' => "text {$position}"],
        'anchor_5' => ['en' => "a5 {$position}"],
        'anchor_3' => ['en' => "a3 {$position}"],
        'anchor_1' => ['en' => "a1 {$position}"],
    ];

    for ($i = 0; $i < 3; $i++) {
        $this->withToken($token)->postJson('/api/catalogue/bars-indicators', $payload($i))->assertCreated();
    }

    $this->withToken($token)->postJson('/api/catalogue/bars-indicators', $payload(3))->assertStatus(422);
});

test('a blank it locale value is refused with HTTP 422', function (): void {
    $token = catalogueTwinSuperadminToken();

    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'BLANKIT',
        'name' => ['en' => 'Blank IT', 'it' => '   '],
    ])->assertStatus(422);
});

/**
 * Z2 (R3-responsibilities-missing-en, REQUIRED BEFORE ARCHIVE): `responsibilities`
 * is an optional map on `StoreRoleRequest` (`localeMapRules('responsibilities',
 * required: false)`), but WHEN present it must still carry `en` — the same
 * "en mandatory whenever the map is present" invariant every other locale map
 * in this change enforces (`CompetencyNormalizer::normalizeLocaleMap()`,
 * `StoreDefaultQuestionRequest`'s own `text.it` override).
 */
test('Z2: responsibilities with an it value and no en key is refused with HTTP 422', function (): void {
    $token = catalogueTwinSuperadminToken();

    $response = $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'NOEN',
        'name' => ['en' => 'No English Responsibilities'],
        'responsibilities' => ['it' => 'Solo italiano'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['responsibilities.en']);
});

test('Z2: responsibilities omitted entirely is still accepted — the field stays optional', function (): void {
    $token = catalogueTwinSuperadminToken();

    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'NORESP',
        'name' => ['en' => 'No Responsibilities At All'],
    ])->assertCreated();
});

/**
 * Z3 (R1-001, REQUIRED BEFORE ARCHIVE): `ValidatesLocaleMaps` validated the
 * parent locale map only as `array` — a key beyond the known locale set
 * (`en`/`it`) was never refused and stored straight into the JSON column.
 */
test('Z3: a locale map key outside the known-locale set is refused with HTTP 422', function (): void {
    $token = catalogueTwinSuperadminToken();

    $response = $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'UNKLOC',
        'name' => ['en' => 'Unknown Locale', 'fr' => 'Locale francaise non supportee'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['name']);
});
