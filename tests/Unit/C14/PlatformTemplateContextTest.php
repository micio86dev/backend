<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\AvatarTemplates\PlatformTemplateContext;
use Illuminate\Auth\Access\AuthorizationException;

function ptcSuperadmin(): User
{
    return User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
}

test('the context is inactive by default and active only inside run()', function (): void {
    $context = app(PlatformTemplateContext::class);

    expect($context->active())->toBeFalse();

    $seen = $context->run(ptcSuperadmin(), fn (): bool => $context->active());

    expect($seen)->toBeTrue()->and($context->active())->toBeFalse();
});

test('run() refuses anybody who is not a superadmin, and stays inactive', function (): void {
    $context = app(PlatformTemplateContext::class);
    $orgAdmin = User::factory()->create(['is_superadmin' => false]);

    expect(fn () => $context->run($orgAdmin, fn () => 'never'))->toThrow(AuthorizationException::class);
    expect($context->active())->toBeFalse();
});

test('run() is reentrant: the outer activation survives an inner run()', function (): void {
    $context = app(PlatformTemplateContext::class);
    $actor = ptcSuperadmin();

    $afterInner = $context->run($actor, function () use ($context, $actor): bool {
        $context->run($actor, fn () => null);

        return $context->active();
    });

    expect($afterInner)->toBeTrue()->and($context->active())->toBeFalse();
});

test('run() restores the previous state when the callback throws', function (): void {
    $context = app(PlatformTemplateContext::class);

    expect(fn () => $context->run(ptcSuperadmin(), fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect($context->active())->toBeFalse();
});

test('run() returns the callback result', function (): void {
    expect(app(PlatformTemplateContext::class)->run(ptcSuperadmin(), fn (): int => 42))->toBe(42);
});
