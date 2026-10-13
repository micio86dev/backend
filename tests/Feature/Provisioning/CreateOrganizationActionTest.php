<?php

declare(strict_types=1);

/**
 * `CreateOrganization` makes an organization usable in one transaction: the three
 * authorization roles, and a default framework version when a catalogue revision is
 * published. Without a published revision the organization must still be created.
 */

use App\Actions\Organizations\CreateOrganization;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role as AuthorizationRole;

test('creating an organization provisions its roles and a default framework version', function (): void {
    $organization = app(CreateOrganization::class)->create('Acme', 'acme');

    expect(AuthorizationRole::where('team_id', $organization->id)->pluck('name')->sort()->values()->all())->toBe(['admin', 'operator', 'viewer'])
        ->and(FrameworkVersion::withoutGlobalScopes()->where('organization_id', $organization->id)->exists())->toBeTrue()
        ->and($organization->currency_code)->toBe('USD');
});

test('an explicit currency is stored on the new organization', function (): void {
    expect(app(CreateOrganization::class)->create('Acme', 'acme', currencyCode: 'EUR')->currency_code)->toBe('EUR');
});

test('with no published catalogue revision the organization is still created, and the missing version is logged', function (): void {
    Log::spy();
    DB::table('framework_catalog_revisions')->delete();

    $organization = app(CreateOrganization::class)->create('Acme', 'acme');

    expect(Organization::find($organization->id))->not->toBeNull()
        ->and(AuthorizationRole::where('team_id', $organization->id)->count())->toBe(3)
        ->and(FrameworkVersion::withoutGlobalScopes()->where('organization_id', $organization->id)->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'without a default framework version')
            && $context['organization_id'] === $organization->id,
    );
});

test('a failure part-way rolls the whole organization back', function (): void {
    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'insert into "roles"')) {
            throw new RuntimeException('roles table unavailable');
        }
    });

    expect(fn () => app(CreateOrganization::class)->create('Acme', 'acme'))->toThrow(RuntimeException::class);
    expect(Organization::where('slug', 'acme')->exists())->toBeFalse();
});
