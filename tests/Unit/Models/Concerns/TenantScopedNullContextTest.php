<?php

declare(strict_types=1);

/**
 * TenantScoped null-context tests (queued-job-tenancy PR1).
 *
 * D4(a): TenantScoped::creating throws MissingTenantContextException when no
 * tenant context is established, instead of silently stamping null. The
 * unconditional (tamper-proof) overwrite of a caller-supplied organization_id
 * MUST remain unchanged when context IS established — no "set only if null"
 * branch may be introduced.
 *
 * Uses a locally-named stub class (NOT the StubTenantModel declared in
 * tests/Unit/C2/TenantScopedTest.php) to avoid a global class redeclaration
 * when the full suite runs both files in one process.
 *
 * REQ: TenantScoped Create Enforcement (openspec/specs/tenancy/spec.md)
 */

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Concerns\TenantScoped;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Model;

class NullContextStubTenantModel extends Model
{
    use TenantScoped;

    protected $table = 'stub';

    public $timestamps = false;

    public function fireCreating(): void
    {
        $this->fireModelEvent('creating', false);
    }
}

it('creating listener throws MissingTenantContextException when resolver has no org', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    $model = new NullContextStubTenantModel;

    expect(fn () => $model->fireCreating())
        ->toThrow(MissingTenantContextException::class);
});

it('creating listener still overwrites a caller-supplied foreign organization_id when context IS established (anti-null-guard regression)', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(10); // Org A — context IS established
    $resolver->setBypass(false);

    $model = new NullContextStubTenantModel;
    $model->organization_id = 99; // attacker-supplied foreign org

    $model->fireCreating();

    expect($model->organization_id)->toBe(10, 'established context must still unconditionally overwrite a caller-supplied org — proves no "set only if null" branch was introduced');
});
