<?php

declare(strict_types=1);

/**
 * Per-organization branding — a logo and a primary colour.
 *
 * Reopens product decision 9, which CLAUDE.md recorded as PARKED for want of a
 * written requirement. The requirement now exists and is recorded alongside
 * this change.
 *
 * `primary_color` is not an ordinary string field: it is interpolated into a
 * CSS custom property in two Nuxt apps. A value that is not a colour becomes a
 * stylesheet that silently does not apply, and one carrying `;` or `}` is a CSS
 * injection into every page an operator's candidates see. It is therefore
 * validated at the request AND constrained at the database, which is the only
 * layer the portability import path and any future writer also pass through.
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{user: User, token: string}
 */
function brandingUser(Organization $org, string $role): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatie = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatie);
    app(TenantResolver::class)->setOrgId($org->id);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

// ─── Defaults ─────────────────────────────────────────────────────────────────

test('branding is absent by default, and absent is a real state', function (): void {
    // Null is permanent, not a migration artefact waiting to be filled. An
    // organization that configures nothing renders in the Quint palette
    // DESIGN.md defines — the product has a brand of its own, and "no logo
    // configured" must never mean "no logo at all".
    $org = Organization::factory()->create();

    expect($org->fresh()->logo_path)->toBeNull()
        ->and($org->fresh()->primary_color)->toBeNull();
});

test('the settings response exposes both fields so the apps can theme themselves', function (): void {
    $org = Organization::factory()->create(['primary_color' => '#123456']);
    ['token' => $token] = brandingUser($org, 'admin');

    $response = $this->withToken($token)->getJson('/api/organization');

    $response->assertOk();
    $response->assertJsonPath('data.primary_color', '#123456');
    // The KEY must be present even when null — a UI cannot distinguish "not
    // configured" from "this build of the API does not support branding" if the
    // field is simply missing.
    $response->assertJsonStructure(['data' => ['primary_color', 'logo_url']]);
});

// ─── Writing ──────────────────────────────────────────────────────────────────

test('an admin can set the primary colour', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $response = $this->withToken($token)->patchJson('/api/organization', [
        'primary_color' => '#7C3AED',
    ]);

    $response->assertOk();
    expect($org->fresh()->primary_color)->toBe('#7C3AED');
});

test('an admin can clear the primary colour, returning to the product palette', function (): void {
    // Without a route back to null, choosing a colour would be a one-way door:
    // an operator could never restore the default they started from.
    $org = Organization::factory()->create(['primary_color' => '#123456']);
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)
        ->patchJson('/api/organization', ['primary_color' => null])
        ->assertOk();

    expect($org->fresh()->primary_color)->toBeNull();
});

test('a non-admin cannot change branding', function (): void {
    // What every candidate of an organization sees is an admin decision.
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'operator');

    $this->withToken($token)
        ->patchJson('/api/organization', ['primary_color' => '#123456'])
        ->assertForbidden();

    expect($org->fresh()->primary_color)->toBeNull();
});

// ─── The colour is CSS, and is treated as such ────────────────────────────────

test('a value that is not a hex colour is refused', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    // No empty string here, deliberately: Laravel's ConvertEmptyStringsToNull
    // middleware turns `""` into null before validation ever sees it, and null
    // is the LEGAL "clear this" value. Asserting `""` as invalid would be
    // asserting against the framework rather than against this rule — and it
    // is what made the first version of this test fail for the wrong reason.
    foreach (['red', '#abc', '#12345', '#1234567', 'rgb(1,2,3)', '#12345g'] as $bad) {
        $this->withToken($token)
            ->patchJson('/api/organization', ['primary_color' => $bad])
            ->assertUnprocessable();
    }

    expect($org->fresh()->primary_color)->toBeNull();
});

test('an empty string clears the colour rather than failing', function (): void {
    // The behaviour the case above deliberately leaves out. A form submitting
    // an emptied field means "no colour", and the middleware already expresses
    // that as null — so this is documented rather than fought.
    $org = Organization::factory()->create(['primary_color' => '#123456']);
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)
        ->patchJson('/api/organization', ['primary_color' => ''])
        ->assertOk();

    expect($org->fresh()->primary_color)->toBeNull();
});

test('a CSS injection attempt is refused', function (): void {
    // The actual threat, spelled out. This value lands inside a custom property
    // in two apps; a payload closing the declaration would append rules of its
    // own to every page an operator's candidates load.
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $payloads = [
        '#fff; } body { display:none } .x{color:#fff',
        '#123456;--x:y',
        'javascript:alert(1)',
        '#123456 }',
    ];

    foreach ($payloads as $payload) {
        $this->withToken($token)
            ->patchJson('/api/organization', ['primary_color' => $payload])
            ->assertUnprocessable();
    }

    expect($org->fresh()->primary_color)->toBeNull();
});

test('a trailing newline is trimmed away rather than rejected, and what is stored is clean', function (): void {
    // `"#123456\n"` is the payload that made the rule's anchors matter, and the
    // outcome is worth pinning because it is NOT what it first looks like.
    //
    // Laravel's TrimStrings middleware strips the newline before validation
    // ever runs, so the request succeeds and the STORED value is the clean
    // colour — safe, just not by the route one would guess. The rule still uses
    // `\A`/`\z` rather than `^`/`$`, because `$` matches before a final newline
    // and this rule must also hold on any path that does not pass through that
    // middleware.
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)
        ->patchJson('/api/organization', ['primary_color' => "#123456\n"])
        ->assertOk();

    expect($org->fresh()->primary_color)->toBe('#123456');
});

test('case is preserved but both cases are accepted', function (): void {
    // `#AABBCC` and `#aabbcc` are the same colour, and refusing one would be a
    // trap for an operator pasting from a brand document.
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)
        ->patchJson('/api/organization', ['primary_color' => '#aabbcc'])
        ->assertOk();

    expect($org->fresh()->primary_color)->toBe('#aabbcc');
});

test('the DATABASE refuses a malformed colour, not only the request', function (): void {
    // The constraint has to hold for the portability import path and any future
    // writer, not just for requests that happen to go through a FormRequest.
    // Read from the catalogue rather than provoked: a failed statement aborts
    // the surrounding transaction, and `RefreshDatabase` wraps each test in one.
    $check = DB::selectOne(
        "select pg_get_constraintdef(oid) as def
         from pg_constraint
         where conname = 'organizations_primary_color_hex'"
    );

    expect($check?->def)->toContain('[0-9a-f]{6}');
});

// ─── Logo upload ──────────────────────────────────────────────────────────────

function brandingImage(string $bytes, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** A real 1x1 PNG — the smallest thing `getimagesize()` will actually decode. */
function brandingRealPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

test('an admin can upload a logo, and the response carries a resolvable URL', function (): void {
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $response = $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ]);

    $response->assertOk();
    expect($response->json('data.logo_url'))->not->toBeNull();
    expect($org->fresh()->logo_path)->not->toBeNull();
});

test('a PHP script renamed to .png is refused, and never reaches the disk', function (): void {
    // `mimes:png` passes this — the browser's claim and the filename are both
    // attacker-controlled. The magic bytes are not.
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage('<?php system($_GET["c"]); ?>', 'logo.png'),
    ])->assertUnprocessable();

    expect($org->fresh()->logo_path)->toBeNull();
    // Nothing written: a rejected upload that still lands on disk is a file
    // an attacker can retry until something serves it.
    expect(Storage::allFiles())->toBe([]);
});

test('an SVG is refused', function (): void {
    // The obvious logo format, refused on purpose: SVG is XML, XML carries
    // <script>, and an inline SVG from our own origin runs with our privileges.
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg'),
    ])->assertUnprocessable();

    expect($org->fresh()->logo_path)->toBeNull();
});

test('a non-admin cannot upload a logo', function (): void {
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'operator');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertForbidden();

    expect($org->fresh()->logo_path)->toBeNull();
});

test('replacing a logo deletes the old object', function (): void {
    // Otherwise every re-upload leaves a file behind, and an operator iterating
    // on their branding quietly fills the bucket.
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'first.png'),
    ])->assertOk();
    $first = $org->fresh()->logo_path;

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'second.png'),
    ])->assertOk();

    expect($org->fresh()->logo_path)->not->toBe($first);
    Storage::assertMissing($first);
    expect(Storage::allFiles())->toHaveCount(1);
});

test('an admin can remove the logo, returning to the product mark', function (): void {
    // Absent is a supported state, not a broken one — the Quint logo renders
    // when none is configured — so this is an action, not an undo.
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();
    $key = $org->fresh()->logo_path;

    $this->withToken($token)->deleteJson('/api/organization/logo')->assertOk();

    expect($org->fresh()->logo_path)->toBeNull();
    Storage::assertMissing($key);
});

test('one organization cannot touch another organization logo', function (): void {
    // There is no id in the route at all — the organization comes from the
    // authenticated user, so there is nothing to tamper with. Asserted anyway,
    // because that property is the reason the route has no parameter.
    Storage::fake();

    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create(['logo_path' => 'organization-logos/999/x.png']);
    ['token' => $token] = brandingUser($mine, 'admin');

    $this->withToken($token)->deleteJson('/api/organization/logo')->assertOk();

    expect($theirs->fresh()->logo_path)->toBe('organization-logos/999/x.png');
});

// ─── The candidate app needs the branding too ─────────────────────────────────

function brandingParticipant(Organization $org): Participant
{
    return TenantContextScope::runFor($org->id, function () use ($org): Participant {
        $project = Project::factory()->create(['organization_id' => $org->id, 'status' => 'active']);

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'brand-'.uniqid(),
            'display_name' => 'Branding Candidate',
            'status' => 'in_attesa',
        ]);
        $participant->save();

        return $participant;
    });
}

function brandingCandidateToken(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

test('the candidate session carries the branding, so the interview looks like the client', function (): void {
    // The candidate is NOT a user of the organization and cannot call
    // `/api/organization` — that endpoint is admin-authenticated. Branding
    // therefore rides along with the bootstrap the app already makes, rather
    // than needing a second endpoint and a second round trip before the page
    // can paint.
    Storage::fake();

    $org = Organization::factory()->create(['primary_color' => '#7C3AED']);
    ['token' => $adminToken] = brandingUser($org, 'admin');

    $this->withToken($adminToken)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $participant = brandingParticipant($org);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.brandingCandidateToken($participant),
    ])->getJson('/api/candidate/session');

    $response->assertOk();
    $response->assertJsonPath('data.branding.primary_color', '#7C3AED');
    expect($response->json('data.branding.logo_url'))->not->toBeNull();
});

test('the candidate session exposes branding and NOTHING else about the organization', function (): void {
    // The tenant boundary. A candidate is an outsider holding a short-lived
    // token; the organization's name, webhook configuration and every other
    // setting are none of their business. Only the two fields the page has to
    // paint with are exposed, asserted as an exact key set so a future field
    // added to `OrganizationResource` cannot leak here by accident.
    $org = Organization::factory()->create([
        'primary_color' => '#123456',
        'default_webhook_url' => 'https://secret.example.test/hook',
    ]);
    $participant = brandingParticipant($org);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.brandingCandidateToken($participant),
    ])->getJson('/api/candidate/session');

    $response->assertOk();
    expect(array_keys($response->json('data.branding')))
        ->toEqualCanonicalizing(['primary_color', 'logo_url']);
    expect(json_encode($response->json()))->not->toContain('secret.example.test');
});

test('an organization with no branding sends nulls, never a fabricated default', function (): void {
    // Null means "use the product palette", and the candidate app decides that
    // by NOT overriding its own tokens. Sending the Quint purple from here
    // would put a second copy of that constant on the wire, and the two would
    // drift.
    $org = Organization::factory()->create();
    $participant = brandingParticipant($org);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.brandingCandidateToken($participant),
    ])->getJson('/api/candidate/session');

    $response->assertOk();
    $response->assertJsonPath('data.branding.primary_color', null);
    $response->assertJsonPath('data.branding.logo_url', null);
});
