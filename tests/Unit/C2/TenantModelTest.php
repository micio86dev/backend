<?php

declare(strict_types=1);

use App\Models\Concerns\TenantScoped;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Model;

it('TenantModel is abstract', function (): void {
    $reflection = new ReflectionClass(TenantModel::class);
    expect($reflection->isAbstract())->toBeTrue();
});

it('TenantModel extends Eloquent Model', function (): void {
    $reflection = new ReflectionClass(TenantModel::class);
    expect($reflection->getParentClass()->getName())->toBe(Model::class);
});

it('TenantModel uses TenantScoped trait', function (): void {
    $traits = class_uses_recursive(TenantModel::class);
    expect($traits)->toContain(TenantScoped::class);
});

it('TenantModel subclass has TenantScoped global scope active', function (): void {
    $concrete = new class extends TenantModel {
        protected $table = 'organizations';
    };

    $scopes = $concrete->getGlobalScopes();
    expect($scopes)->toHaveKey('tenant');
});
