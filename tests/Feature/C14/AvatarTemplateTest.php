<?php

declare(strict_types=1);

/**
 * Avatar templates, and the one-active invariant (C14 PR2).
 *
 * The invariant is the feature. A tenant with two active templates has no
 * defined avatar — the next interview picks whichever row the query happens to
 * return first, which is stable in testing and arbitrary under load.
 *
 * So it is enforced by a partial unique index, and these tests go through the
 * DATABASE rather than through a service that could be bypassed. A guard that
 * only holds when callers remember to call it is not a guard.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;

function makeTemplate(Organization $org, array $attributes = []): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create(array_merge([
        'name' => 'Template '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
        'is_active' => false,
    ], $attributes)));
}

// ─── The invariant ───────────────────────────────────────────────────────────

test('an organization cannot hold two active templates', function (): void {
    $org = Organization::factory()->create();
    makeTemplate($org, ['is_active' => true]);

    // Straight at the database. The service layer will make this impossible by
    // deactivating first — but the point of the index is what happens when
    // something goes AROUND the service layer, which over a codebase's life is
    // eventually everything.
    expect(fn () => makeTemplate($org, ['is_active' => true]))
        ->toThrow(QueryException::class);
});

test('two organizations may each hold an active template', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    makeTemplate($orgA, ['is_active' => true]);
    $b = makeTemplate($orgB, ['is_active' => true]);

    // The index is partial AND per-organization. A global one-active rule would
    // mean one customer's admin silently changing every other customer's
    // avatar, which is the multi-tenancy violation this product exists to
    // avoid.
    expect($b->fresh()->is_active)->toBeTrue();
});

test('an organization may hold many INACTIVE templates', function (): void {
    $org = Organization::factory()->create();

    makeTemplate($org);
    makeTemplate($org);
    makeTemplate($org, ['is_active' => true]);

    // The reason the index is partial. A plain unique on
    // (organization_id, is_active) would cap each org at one inactive template
    // too — absurd, and exactly the mistake the WHERE clause avoids.
    $count = TenantContextScope::runFor($org->id, fn (): int => AvatarTemplate::count());

    expect($count)->toBe(3);
});

// ─── Tenancy ─────────────────────────────────────────────────────────────────

test('a template is invisible to another tenant', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    makeTemplate($orgA, ['name' => 'Only A']);

    $visible = TenantContextScope::runFor(
        $orgB->id,
        fn (): int => AvatarTemplate::where('name', 'Only A')->count(),
    );

    expect($visible)->toBe(0);
});

test('organization_id is stamped from the tenant context, never from a payload', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $template = TenantContextScope::runFor($orgA->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Payload attack',
        'provider' => 'heygen',
        // A crafted payload naming somebody else's organization. It must not
        // land — this is the same mass-assignment invariant Participant and
        // User carry, and the reason organization_id is not fillable anywhere.
        'organization_id' => $orgB->id,
        'config' => [],
    ]));

    expect($template->organization_id)->toBe($orgA->id);
});

// ─── Shape ───────────────────────────────────────────────────────────────────

test('two templates in one org cannot share a name', function (): void {
    $org = Organization::factory()->create();
    makeTemplate($org, ['name' => 'Recruiter voice']);

    // Names are how operators refer to templates out loud. Two identical ones
    // in a list, one active and one not, is a support call.
    expect(fn () => makeTemplate($org, ['name' => 'Recruiter voice']))
        ->toThrow(QueryException::class);
});

test('the same name is fine in a different org', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    makeTemplate($orgA, ['name' => 'Standard']);
    $b = makeTemplate($orgB, ['name' => 'Standard']);

    expect($b->exists)->toBeTrue();
});

test('config round-trips as an array', function (): void {
    $org = Organization::factory()->create();
    $template = makeTemplate($org, ['config' => ['avatarId' => 'av_x', 'voiceSpeed' => 1.05]]);

    $fresh = TenantContextScope::runFor($org->id, fn () => $template->fresh());

    // jsonb + an array cast. Asserted because a config that comes back as a
    // JSON string reads as "no avatar configured" downstream — a failure that
    // looks like a missing setting rather than a broken cast.
    expect($fresh->config)->toBeArray();
    expect($fresh->config['avatarId'])->toBe('av_x');
});

test('a template defaults to inactive', function (): void {
    $org = Organization::factory()->create();

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Fresh',
        'provider' => 'heygen',
        'config' => [],
    ]));

    // Creating a template must never change what candidates are seeing right
    // now. Activation is a separate, deliberate act.
    expect($template->is_active)->toBeFalse();
});
