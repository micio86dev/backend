<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

/**
 * MissingTenantContextException — thrown by TenantScoped::creating (D4(a))
 * when a model is about to be created but no tenant context has been
 * established (resolver.orgId is null).
 *
 * This turns a DB-dependent latent failure (NOT NULL constraint violation,
 * which only surfaces if the column happens to be non-nullable) into a loud,
 * environment-independent one at the moment the defect actually occurs.
 *
 * The `creating` listener remains otherwise UNCONDITIONAL — this exception
 * exists so there is no code slot left in which a "set only if null" guard
 * could be written; see TenantScoped::bootTenantScoped().
 *
 * REQ: TenantScoped Create Enforcement (openspec/specs/tenancy/spec.md)
 */
class MissingTenantContextException extends \RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "No tenant context established before create on {$modelClass}. ".
            'A caller (queued job, console command, etc.) must establish tenant '.
            'context explicitly — e.g. via App\\Support\\Tenancy\\TenantContextScope — '.
            'before creating a tenant-scoped model.'
        );
    }
}
