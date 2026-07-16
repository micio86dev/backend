<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

it('Organization model has hasMany relation to User', function (): void {
    // Verify via reflection that users() is defined and returns the correct type
    // without instantiating a DB connection (unit test — no DB)
    $reflection = new ReflectionMethod(Organization::class, 'users');
    expect($reflection->isPublic())->toBeTrue();

    // Verify return type is HasMany
    $returnType = $reflection->getReturnType();
    expect((string) $returnType)->toContain('HasMany');
});

it('Organization model has fillable name and slug', function (): void {
    $org = new Organization;

    expect($org->getFillable())->toContain('name');
    expect($org->getFillable())->toContain('slug');
});
