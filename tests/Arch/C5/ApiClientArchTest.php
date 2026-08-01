<?php

declare(strict_types=1);

/**
 * ApiClient architecture tests (C5 — M2M API Authentication).
 *
 * Asserts structural invariants that must hold across refactors:
 * - ApiClient does NOT extend Foundation\Auth\User
 * - ApiClient does NOT use HasRoles trait
 * - key_hash is in ApiClient::$hidden
 * - ApiClientResource toArray() method does not reference 'key_hash' or 'api_key'
 * - TenantContextM2m does NOT call TenantContext methods
 *
 * design §Security Notes
 */

use App\Http\Middleware\TenantContext;
use App\Http\Middleware\TenantContextM2m;
use App\Http\Resources\ApiClientResource;
use App\Models\ApiClient;
use Illuminate\Foundation\Auth\User as FoundationUser;
use Spatie\Permission\Traits\HasRoles;

test('ApiClient does NOT extend Foundation\\Auth\\User', function (): void {
    expect(ApiClient::class)->not->toExtend(FoundationUser::class);
});

test('ApiClient does NOT use HasRoles trait', function (): void {
    $traits = class_uses_recursive(ApiClient::class);
    expect($traits)->not->toContain(HasRoles::class);
});

test('key_hash is in ApiClient::$hidden', function (): void {
    $client = new ApiClient;
    expect($client->getHidden())->toContain('key_hash');
});

test('ApiClientResource toArray() never references key_hash or api_key as output keys', function (): void {
    // Use reflection to inspect the resource source for forbidden key exposure
    $reflection = new ReflectionClass(ApiClientResource::class);
    $method = $reflection->getMethod('toArray');
    $filename = $method->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $lines = file($filename);
    $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

    // The key names 'key_hash' and 'api_key' must NOT appear as array keys in toArray
    // Pattern: looks for 'key_hash' => or "key_hash" => as a key in the returned array
    expect($methodBody)->not->toContain("'key_hash' =>");
    expect($methodBody)->not->toContain('"key_hash" =>');
    expect($methodBody)->not->toContain("'api_key' =>");
    expect($methodBody)->not->toContain('"api_key" =>');
});

test('TenantContextM2m source does NOT call TenantContext handle() method', function (): void {
    $reflection = new ReflectionClass(TenantContextM2m::class);
    $filename = $reflection->getFileName();
    $source = file_get_contents($filename);

    // TenantContextM2m must NOT delegate to or call TenantContext's methods
    expect($source)->not->toContain('TenantContext::');
    expect($source)->not->toContain('new TenantContext');
    expect($source)->not->toContain('TenantContext->handle');
});

test('bootstrap/app.php uses prependToPriorityList (not appendToPriorityList) for CheckAbility', function (): void {
    $bootstrapSource = file_get_contents(base_path('bootstrap/app.php'));

    // prependToPriorityList must be called (CheckAbility before SubstituteBindings)
    expect($bootstrapSource)->toContain('prependToPriorityList');

    // The actual method call must not be appendToPriorityList.
    // Use regex to exclude the comment line that mentions appendToPriorityList as a negative example.
    $codeOnlyLines = array_filter(
        explode("\n", $bootstrapSource),
        fn (string $line) => ! str_starts_with(ltrim($line), '//')
    );
    $codeOnly = implode("\n", $codeOnlyLines);

    expect($codeOnly)->not->toContain('appendToPriorityList');
});

test('bootstrap/app.php has ability alias registered', function (): void {
    $bootstrapSource = file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrapSource)->toContain("'ability'");
    expect($bootstrapSource)->toContain('CheckAbility::class');
});
