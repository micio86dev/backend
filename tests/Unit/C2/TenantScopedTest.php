<?php

declare(strict_types=1);

use App\Models\Concerns\TenantScoped;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// ── Structural tests ──

it('TenantScoped trait exists and is usable', function (): void {
    expect(trait_exists(TenantScoped::class))->toBeTrue();
});

it('TenantScoped trait has bootTenantScoped method', function (): void {
    $reflection = new ReflectionClass(TenantScoped::class);
    $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

    expect($methods)->toContain('bootTenantScoped');
});

it('TenantScoped global scope is registered when the trait is used', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(1);
    $resolver->setBypass(false);

    $model = new class extends Model
    {
        use TenantScoped;

        protected $table = 'organizations';
    };

    $scopes = $model->getGlobalScopes();
    expect($scopes)->toHaveKey('tenant');
});

// ── Creating listener — tamper-proof stamp ──
// We test via a named class so fireModelEvent is accessible

class StubTenantModel extends Model
{
    use TenantScoped;

    protected $table = 'stub';

    public $timestamps = false;

    public function fireCreating(): void
    {
        $this->fireModelEvent('creating', false);
    }
}

it('creating listener overrides organization_id unconditionally (tamper-proof)', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(10); // Org A

    $model = new StubTenantModel;
    $model->organization_id = 99; // Org B — attacker-supplied foreign org

    $model->fireCreating();

    expect($model->organization_id)->toBe(10, 'creating listener must stamp resolver org_id, not caller-supplied value');
});

it('creating listener stamps null when resolver has no org (bypass=false, null orgId)', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    $model = new StubTenantModel;
    $model->organization_id = 99;

    $model->fireCreating();

    expect($model->organization_id)->toBeNull('With null resolver orgId, creating listener stamps null (unconditional override)');
});

// ── Task 1.17: bypass flag behaviour ──

it('bypass=true — global scope is present but applies no WHERE filter', function (): void {
    // The scope is always registered; bypass=true means the scope closure returns early
    // without adding a WHERE clause. The scope key exists but does not filter.
    // This is the correct behaviour: the scope is structurally present but a no-op.
    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true);
    $resolver->setOrgId(null);

    $model = new class extends Model
    {
        use TenantScoped;

        protected $table = 'organizations';
    };

    $scopes = $model->getGlobalScopes();

    // The "tenant" scope key must exist (always registered in bootTenantScoped)
    expect($scopes)->toHaveKey('tenant');

    // The scope closure itself handles bypass internally — it returns without filtering.
    // We can verify this by executing the scope closure against a mock builder and
    // asserting no WHERE bindings are added.
    $mockBuilder = Mockery::mock(Builder::class);
    // The mock should NOT receive a ->where() call when bypass=true
    $mockBuilder->shouldNotReceive('where');

    $scopeClosure = $scopes['tenant'];
    $scopeClosure($mockBuilder);
});

it('bypass=false with null orgId — scope adds WHERE organization_id = null filter', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setBypass(false);
    $resolver->setOrgId(null);

    $model = new class extends Model
    {
        use TenantScoped;

        protected $table = 'organizations';
    };

    $scopes = $model->getGlobalScopes();
    expect($scopes)->toHaveKey('tenant');

    // Verify the scope closure calls where() when bypass=false
    $mockBuilder = Mockery::mock(Builder::class);
    $mockBuilder->shouldReceive('where')
        ->once()
        ->with('organization_id', null);

    $scopeClosure = $scopes['tenant'];
    $scopeClosure($mockBuilder);
});
