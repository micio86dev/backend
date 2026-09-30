<?php

declare(strict_types=1);

/**
 * Read isolation for platform (NULL-organization) avatar templates (A1).
 *
 * Three rules, each pinned in every context an interview or a request can be in:
 *   - the default query NEVER returns a platform row (org, acting or bare bypass);
 *   - `availableToTenant()` returns own rows plus platform rows, and fails closed
 *     when no tenant context exists;
 *   - `platformOnly()` returns platform rows only, whatever the context.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

/** @return array{a: Organization, b: Organization, aRow: AvatarTemplate, bRow: AvatarTemplate, global: AvatarTemplate, trashed: AvatarTemplate} */
function gatWorld(): array
{
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();

    $mk = fn (Organization $org, string $name): AvatarTemplate => TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => $name, 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));

    $trashed = $mk($a, 'A trashed');
    $trashed->delete();

    return [
        'a' => $a, 'b' => $b,
        'aRow' => $mk($a, 'A row'), 'bRow' => $mk($b, 'B row'),
        'global' => PlatformTemplates::insertGlobal(['name' => 'The global']),
        'trashed' => $trashed,
    ];
}

function gatBare(callable $fn): mixed
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(true);

    try {
        return $fn();
    } finally {
        $resolver->setBypass(false);
    }
}

/** @return list<string> */
function gatNames(Builder $query): array
{
    return $query->orderBy('name')->pluck('name')->all();
}

test('T1 an organization default query returns its own rows and never a platform row', function (): void {
    $w = gatWorld();

    TenantContextScope::runFor($w['a']->id, function () use ($w): void {
        expect(gatNames(AvatarTemplate::query()))->toBe(['A row'])
            ->and(AvatarTemplate::find($w['global']->id))->toBeNull();
    });
});

test('T2 a bare bypass default query sees every organization but never a platform row', function (): void {
    gatWorld();

    gatBare(function (): void {
        expect(gatNames(AvatarTemplate::query()))->toBe(['A row', 'B row']);
    });
});

test('T3 availableToTenant returns own rows plus platform rows, never another org or trashed', function (): void {
    $w = gatWorld();

    TenantContextScope::runFor($w['a']->id, function (): void {
        expect(gatNames(AvatarTemplate::availableToTenant()))->toBe(['A row', 'The global']);
    });
});

test('T4 availableToTenant fails closed without a tenant context and opens fully under bypass', function (): void {
    gatWorld();

    // No org, no bypass: `org = NULL OR org IS NULL` would hand out platform rows here.
    expect(AvatarTemplate::availableToTenant()->count())->toBe(0);

    gatBare(function (): void {
        expect(gatNames(AvatarTemplate::availableToTenant()))->toBe(['A row', 'B row', 'The global']);
    });
});

test('T5 platformOnly returns platform rows only in member, acting and bare contexts', function (): void {
    $w = gatWorld();

    TenantContextScope::runFor($w['a']->id, function (): void {
        expect(gatNames(AvatarTemplate::platformOnly()))->toBe(['The global']);
    });

    gatBare(function (): void {
        expect(gatNames(AvatarTemplate::platformOnly()))->toBe(['The global']);
    });
});

test('an organization-context bulk update or delete never matches a platform row', function (): void {
    $w = gatWorld();

    TenantContextScope::runFor($w['a']->id, function () use ($w): void {
        AvatarTemplate::query()->update(['description' => 'touched']);
        AvatarTemplate::query()->whereKey($w['global']->id)->delete();
    });

    $row = AvatarTemplate::withoutGlobalScopes()->find($w['global']->id);
    expect($row->description)->toBeNull()->and($row->deleted_at)->toBeNull();
});

test('a bare bypass bulk update never matches a platform row either', function (): void {
    $w = gatWorld();

    gatBare(fn () => AvatarTemplate::query()->update(['description' => 'touched']));

    expect(AvatarTemplate::withoutGlobalScopes()->find($w['global']->id)->description)->toBeNull();
});
