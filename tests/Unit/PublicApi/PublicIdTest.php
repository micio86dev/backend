<?php

declare(strict_types=1);

/**
 * `App\Support\PublicApi\PublicId` and `App\Models\Concerns\HasPublicId` —
 * public-api step 4, G-05 (prefixed public ids).
 *
 * `Organization`/`Project` are used as the two real models under test —
 * both already `implements PubliclyIdentifiable` and `use HasPublicId`.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;

test('HasPublicId mints a bare 26-char ULID on creating when public_id is empty', function (): void {
    $org = Organization::factory()->create();

    expect($org->public_id)->toBeString();
    expect(strlen($org->public_id))->toBe(26);
    expect($org->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

test('HasPublicId does not overwrite an explicitly provided public_id', function (): void {
    $explicit = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $org = Organization::factory()->create(['public_id' => $explicit]);

    expect($org->public_id)->toBe($explicit);
});

test('two created rows never collide on public_id', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    expect($orgA->public_id)->not->toBe($orgB->public_id);
});

test('PublicId::encode throws a LogicException naming the model when public_id is empty (unsaved instance)', function (): void {
    $unsaved = new Organization;

    expect(fn () => PublicId::encode($unsaved))
        ->toThrow(LogicException::class, Organization::class);
});

test('PublicId::encode prefixes Organization with org_ and Project with prj_', function (): void {
    $org = Organization::factory()->create();

    TenantContextScope::runFor($org->id, function () use ($org): void {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        expect(PublicId::encode($org))->toBe('org_'.$org->public_id);
        expect(PublicId::encode($project))->toBe('prj_'.$project->public_id);
    });
});

test('PublicId::decode strips a matching prefix and returns the bare ulid', function (): void {
    $bareUlid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    expect(PublicId::decode('prj_'.$bareUlid, 'prj_'))->toBe($bareUlid);
});

test('PublicId::decode returns null on a mismatched prefix, never throws', function (): void {
    $bareUlid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    expect(PublicId::decode('org_'.$bareUlid, 'prj_'))->toBeNull();
});

test('PublicId::decode returns null on a malformed remainder (wrong length, lowercase, or disallowed characters)', function (): void {
    expect(PublicId::decode('prj_tooshort', 'prj_'))->toBeNull();
    expect(PublicId::decode('prj_'.strtolower('01ARZ3NDEKTSV4RRFFQ69G5FAV'), 'prj_'))->toBeNull();
    expect(PublicId::decode('prj_01ARZ3NDEKTSV4RRFFQ69G5FA!', 'prj_'))->toBeNull();
    // I, L, O, U are excluded from Crockford base32.
    expect(PublicId::decode('prj_01ARZ3NDEKTSV4RRFFQ69G5FAI', 'prj_'))->toBeNull();
});

test('PublicId::decode returns null on a completely unrelated string', function (): void {
    expect(PublicId::decode('not-an-id-at-all', 'prj_'))->toBeNull();
    expect(PublicId::decode('', 'prj_'))->toBeNull();
});

test('Organization::wherePublicId scope queries the bare column', function (): void {
    $org = Organization::factory()->create();

    $found = Organization::query()->wherePublicId($org->public_id)->first();

    expect($found?->id)->toBe($org->id);
});

test('Organization::publicIdPrefix and Project::publicIdPrefix', function (): void {
    expect(Organization::publicIdPrefix())->toBe('org_');
    expect(Project::publicIdPrefix())->toBe('prj_');
});
