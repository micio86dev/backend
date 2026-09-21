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
            ->assertUnprocessable()
            // A CODE, never prose. Without `messages()` this answered "The
            // primary color field format is invalid." — an English sentence
            // the backoffice can only print verbatim, into an Italian field
            // error, because `te()` has nothing to translate.
            ->assertJsonPath('errors.primary_color.0', 'primary_color_invalid');
    }

    expect($org->fresh()->primary_color)->toBeNull();
});

test('the logo upload answers with a code on every shape rejection too', function (): void {
    // The two magic-byte checks below the validator already throw codes. The
    // validator itself did not, so `logo` required/mimes/max came back as
    // English sentences under the SAME key — indistinguishable to the caller,
    // and untranslatable by the app that renders them.
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    // The MAPPING is what is under test, so each case asserts its own code.
    // A shape assertion (`/\A[a-z_]+\z/`) would pass if `required` answered
    // `logo_too_large`, which is the one thing a hand-written map gets wrong.
    $cases = [
        [[], 'logo_required'],
        [['logo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], 'logo_invalid_image'],
        // A non-file SCALAR — the only input that resolves `logo.file`. The
        // `uploaded` short-circuit never fires here, `required` passes, and
        // `file` fails normally. Without this case that rule's code could be
        // deleted and all 29 other tests would stay green.
        [['logo' => 'just-a-string'], 'logo_invalid_image'],
        [['logo' => UploadedFile::fake()->create('big.png', 4096, 'image/png')], 'logo_too_large'],
    ];

    foreach ($cases as [$payload, $code]) {
        $response = $this->withToken($token)
            ->postJson('/api/organization/logo', $payload)
            ->assertUnprocessable();

        expect($response->json('errors.logo.0'))->toBe($code);
    }
});

/**
 * The path production actually takes, and the one three valid uploads cannot
 * reach.
 *
 * There is no `php.ini` in `api/docker/`, so PHP's compiled
 * `upload_max_filesize=2M` fires before Laravel sees the size at all.
 * `isValid()` is then false, and Laravel's `file` rule DELEGATES to
 * `uploaded` — so `logo.file` is not the key that resolves, and a map without
 * `logo.uploaded` answers "The logo failed to upload." in English.
 */
test('a logo PHP itself refused for size reports too_large, not a sentence', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    file_put_contents($path, brandingRealPng());

    $response = $this->withToken($token)->post('/api/organization/logo', [
        'logo' => new UploadedFile($path, 'logo.png', 'image/png', UPLOAD_ERR_INI_SIZE, true),
    ], ['Accept' => 'application/json']);

    $response->assertUnprocessable();
    expect($response->json('errors.logo.0'))->toBe('logo_too_large');
});

test('a partial logo upload reports upload_failed, not too_large', function (): void {
    // The other arm of the same ternary. A broken transfer is not a size
    // problem, and "too large" would send the operator shrinking a file that
    // was never the trouble. Without this case the branch could carry any
    // string, English prose included, and the suite would stay green.
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    file_put_contents($path, brandingRealPng());

    $response = $this->withToken($token)->post('/api/organization/logo', [
        'logo' => new UploadedFile($path, 'logo.png', 'image/png', UPLOAD_ERR_PARTIAL, true),
    ], ['Accept' => 'application/json']);

    $response->assertUnprocessable();
    expect($response->json('errors.logo.0'))->toBe('logo_upload_failed');
});

test('every rule on this endpoint answers with a code, never a sentence', function (): void {
    // One case per declared rule. A rule with no code is a sentence waiting to
    // reach an operator, and it will be the one nobody exercised.
    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $cases = [
        ['name' => str_repeat('a', 256)],
        ['default_webhook_url' => 'not-a-url'],
        ['default_webhook_url' => 'https://example.test/'.str_repeat('a', 2048)],
        ['default_webhook_secret' => str_repeat('a', 1025)],
        ['default_webhook_events' => 'not-an-array'],
        ['default_webhook_events' => ['no_such_event']],
        ['primary_color' => 'red'],
    ];

    foreach ($cases as $payload) {
        $errors = $this->withToken($token)
            ->patchJson('/api/organization', $payload)
            ->assertUnprocessable()
            ->json('errors');

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                expect($message)->toMatch('/\A[a-z][a-z0-9_]*\z/', "{$field} answered with prose: {$message}");
            }
        }
    }
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

test('the settings resource returns an ABSOLUTE logo URL, resolvable from another origin', function (): void {
    // `Storage::url()` on the local disk returns a ROOT-RELATIVE path
    // (`/storage/...`, FilesystemAdapter::getLocalUrl). The backoffice is a
    // separate origin from this API — :3000 against :8000 locally, distinct
    // hosts on Railway — so the browser resolved that path against the
    // BACKOFFICE and 404'd. The file was stored correctly the whole time; the
    // URL was simply never reachable from the app that renders it.
    Storage::fake();
    config(['app.url' => 'http://api.test']);

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $response = $this->withToken($token)->getJson('/api/organization');

    $response->assertOk();
    expect($response->json('data.logo_url'))->toStartWith('http://api.test/');
});

test('the candidate session resource returns an ABSOLUTE logo URL too', function (): void {
    // Same defect, same fix: the candidate frontend is likewise a separate
    // origin from this API.
    Storage::fake();
    config(['app.url' => 'http://api.test']);

    $org = Organization::factory()->create();
    ['token' => $adminToken] = brandingUser($org, 'admin');

    $this->withToken($adminToken)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $participant = brandingParticipant($org);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.brandingCandidateToken($participant),
    ])->getJson('/api/candidate/session');

    $response->assertOk();
    expect($response->json('data.branding.logo_url'))->toStartWith('http://api.test/');
});

test('no logo configured still means null, never a partial URL', function (): void {
    Storage::fake();
    config(['app.url' => 'http://api.test']);

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->getJson('/api/organization')->assertJsonPath('data.logo_url', null);
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
            'email' => uniqid('cand-').'@example.test',
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
    // The tenant boundary, and it still holds — the set is exact so a field
    // added to `OrganizationResource` cannot leak here by accident. What
    // changed is WHICH fields are in it.
    //
    // `name` was deliberately excluded when this was written, on the reading
    // that a candidate is an outsider and the organization's name is none of
    // their business. That reasoning does not survive contact with the
    // product: the candidate is invited BY that organization, by name, in an
    // email this system sends and signs with it. Withholding on the page a
    // fact we already put in their inbox protected nothing and left the
    // interview looking like it came from nobody.
    //
    // Ratified 2026-09-02 by the product owner after the boundary was raised
    // explicitly. `webhook configuration and every other setting` stay out,
    // which is what this assertion is really guarding.
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
        ->toEqualCanonicalizing(['primary_color', 'logo_url', 'name']);
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

/**
 * The candidate sees WHOSE interview this is.
 *
 * The frontend renders the product's own wordmark plus the organization's
 * name; without this field it could only show the former, so a candidate
 * invited by Acme reached a page that named nobody. The same name already
 * signs the invitation email they arrived from (EmailBranding), so this
 * makes the page agree with the message rather than revealing anything new.
 */
test('the candidate session carries the organization name', function (): void {
    $org = Organization::factory()->create(['name' => 'Acme Selezione']);
    $participant = brandingParticipant($org);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.brandingCandidateToken($participant),
    ])->getJson('/api/candidate/session');

    $response->assertOk();
    $response->assertJsonPath('data.branding.name', 'Acme Selezione');
});

// ─── The logo URL is served by THIS API, never by the object store ───────────

/**
 * The third door the same defect came through.
 *
 * First it was a root-relative `/storage/...` path, resolved against the
 * WRONG ORIGIN by two Nuxt apps on separate hosts. That was fixed by
 * anchoring on `APP_URL` — which is correct, and which is also exactly what
 * the `local` disk needed and no more.
 *
 * On the `s3` disk the anchoring never fires, because `Storage::url()` already
 * returns something absolute: `AWS_ENDPOINT` + `/bucket/` + key. That host is
 * the R2 **S3 API endpoint**. A GET there without a SigV4 signature is a 401,
 * so the `<img>` never painted and the operator read it as "the upload did not
 * save" — while the row held the key correctly the whole time.
 *
 * And the bucket cannot be made public to fix it: it is the same bucket that
 * holds candidate proctoring snapshots (`SingleStorageDiskArchTest` makes the
 * single-disk rule structural). So the URL has to stop pointing at the store.
 */
test('the logo URL points at this API, never at the private object store', function (): void {
    Storage::fake();
    config(['app.url' => 'http://api.test']);

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $url = $this->withToken($token)->getJson('/api/organization')->json('data.logo_url');

    // Exact, not a prefix match. A prefix assertion passes for any absolute
    // URL this API could emit, including the storage endpoint once `AWS_URL`
    // is set to something that happens to share a host.
    //
    // The `?v=` query string is the cache-buster (`Organization::absoluteLogoUrl()`)
    // — the stored object's own filename, asserted against `logo_path` rather
    // than hardcoded so this test does not know the upload's UUID.
    $version = basename((string) $org->fresh()->logo_path);

    expect($url)->toBe("http://api.test/api/organizations/{$org->id}/logo?v={$version}");
});

test('the same stable URL reaches the email and the candidate app', function (): void {
    // A presigned URL in the payload would have worked for the backoffice and
    // failed everywhere it matters: `EmailBranding` renders this value into a
    // message a candidate may open days later, and a signature that has
    // expired is a broken image on the one document deciding whether they
    // trust the invitation.
    Storage::fake();
    config(['app.url' => 'http://api.test']);

    $org = Organization::factory()->create();
    ['token' => $adminToken] = brandingUser($org, 'admin');

    $this->withToken($adminToken)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $participant = brandingParticipant($org);

    $candidateUrl = $this->withHeaders([
        'Authorization' => 'Bearer '.brandingCandidateToken($participant),
    ])->getJson('/api/candidate/session')->json('data.branding.logo_url');

    $version = basename((string) $org->fresh()->logo_path);

    expect($candidateUrl)->toBe("http://api.test/api/organizations/{$org->id}/logo?v={$version}")
        ->and($org->fresh()->absoluteLogoUrl())->toBe($candidateUrl);
});

test('replacing the logo changes the cache-busting query string, so a stale browser cache is bypassed', function (): void {
    // The regression this closes: `show()` answers with `Cache-Control:
    // public, max-age=<redirect_cache_seconds>` (up to ten minutes) against a
    // route that names the ORGANIZATION, never the stored object. Before this
    // fix, that route was byte-identical before and after a replace, so an
    // admin who uploaded a new logo and reloaded the very settings page that
    // requested it kept seeing the file that upload replaced — the browser
    // served its own cached redirect without ever asking the server again.
    // The path must stay stable (emails and the candidate app depend on it
    // resolving for days), but the query string must change on every upload,
    // so the second logo's full URL is one the browser has never fetched.
    Storage::fake();
    config(['app.url' => 'http://api.test']);

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'first.png'),
    ])->assertOk();

    $firstUrl = (string) $this->withToken($token)->getJson('/api/organization')->json('data.logo_url');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'second.png'),
    ])->assertOk();

    $secondUrl = (string) $this->withToken($token)->getJson('/api/organization')->json('data.logo_url');

    [$firstPath] = explode('?', $firstUrl, 2);
    [$secondPath] = explode('?', $secondUrl, 2);

    expect($secondPath)->toBe($firstPath)
        ->and($secondUrl)->not->toBe($firstUrl);
});

test('the logo endpoint redirects to a short-lived object URL', function (): void {
    // A redirect rather than a stream: the bytes go straight from the object
    // store to the client, so a logo on every candidate page does not occupy
    // a PHP worker for the duration of each transfer.
    Storage::fake();
    config(['app.url' => 'http://api.test']);

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $response = $this->get("/api/organizations/{$org->id}/logo");

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');

    expect($location)->toContain((string) $org->fresh()->logo_path)
        ->and($location)->not->toContain('/api/organizations/');
});

test('the logo endpoint is public — an email client carries no bearer token', function (): void {
    // Deliberately unauthenticated. Gmail fetches a remote image through its
    // own proxy, and the candidate app paints the mark before the candidate
    // has exchanged their link for a token.
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $this->get("/api/organizations/{$org->id}/logo")->assertRedirect();
});

test('the logo endpoint 404s when no logo is configured', function (): void {
    Storage::fake();

    $org = Organization::factory()->create();

    $this->get("/api/organizations/{$org->id}/logo")->assertNotFound();
});

test('the logo endpoint 404s for an organization that does not exist', function (): void {
    // The SAME status as "configured no logo", deliberately: a public endpoint
    // that distinguished the two would answer "does organization N exist?" for
    // every N.
    Storage::fake();

    $this->get('/api/organizations/999999/logo')->assertNotFound();
});

test('the logo endpoint refuses to sign a key outside organization-logos/', function (): void {
    // THE security-critical assertion in this file, and the same guard
    // `ProfilePhotoUrlSigner` states structurally. This endpoint is public and
    // presigns an object on the bucket that ALSO holds candidate proctoring
    // snapshots (`{org}/{participant}/{session}/{uuid}.jpg`). Were
    // `logo_path` ever made writable by a weaker path — a settings PATCH that
    // accepted it, a portability import — an unauthenticated GET here would
    // mint a signed URL for a candidate's webcam frame.
    //
    // The prefix check is what refuses that regardless of what wrote the
    // column, which is precisely the property a comment cannot enforce.
    Storage::fake();

    $org = Organization::factory()->create([
        'logo_path' => '1/42/7/3f0c1b8e-0000-4000-8000-0000000000ff.jpg',
    ]);

    $this->get("/api/organizations/{$org->id}/logo")->assertNotFound();
});

test('the logo redirect tells the client how long it may be reused', function (): void {
    // The header had no test at all: deleting the whole `withHeaders([...])`
    // call left every assertion in this file green, which made
    // `branding.logo.redirect_cache_seconds` config nothing had ever watched
    // fail. A logo is fetched on every candidate page and by every email
    // proxy, so "may I reuse this" is not a detail.
    Storage::fake();
    config([
        'branding.logo.url_ttl_minutes' => 60,
        'branding.logo.redirect_cache_seconds' => 600,
    ]);

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    $this->get("/api/organizations/{$org->id}/logo")
        ->assertRedirect()
        ->assertHeader('Cache-Control', 'max-age=600, public');
});

test('the redirect is never cached past the signature it points at', function (): void {
    // THE invariant `config/branding.php` used to state in prose while two
    // independent env vars could violate it. A five-minute signature against
    // the default ten-minute cache is a redirect the browser keeps replaying
    // after the URL behind it has died — a broken image served from the
    // client's own cache, with nothing on the wire and nothing in a log to
    // explain it. `min()` in the controller is what makes that unreachable.
    Storage::fake();
    config([
        'branding.logo.url_ttl_minutes' => 5,
        'branding.logo.redirect_cache_seconds' => 600,
    ]);

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertOk();

    // Half of 5 minutes, not the configured 600s.
    $this->get("/api/organizations/{$org->id}/logo")
        ->assertRedirect()
        ->assertHeader('Cache-Control', 'max-age=150, public');
});

test('uploading a logo is rate limited, because each call costs an object PUT', function (): void {
    // The sibling `POST /profile/photo` carries `throttle:10,1` and its route
    // block says why: an unthrottled upload is a storage-burn primitive for a
    // stolen bearer token. This endpoint is the same primitive and also runs
    // `getimagesize()` on a decompression-bomb candidate, so it burns CPU too.
    // It had no limiter, and admin-only narrows who can reach the loop without
    // making the loop cheaper.
    Storage::fake();

    $org = Organization::factory()->create();
    ['token' => $token] = brandingUser($org, 'admin');

    foreach (range(1, 10) as $ignored) {
        $this->withToken($token)->post('/api/organization/logo', [
            'logo' => brandingImage(brandingRealPng(), 'logo.png'),
        ])->assertOk();
    }

    $this->withToken($token)->post('/api/organization/logo', [
        'logo' => brandingImage(brandingRealPng(), 'logo.png'),
    ])->assertStatus(429);
});
