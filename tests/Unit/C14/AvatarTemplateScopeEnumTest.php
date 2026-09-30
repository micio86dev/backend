<?php

declare(strict_types=1);

use App\Enums\AvatarTemplateScope;
use App\Models\AvatarTemplate;

test('the scope enum exposes exactly the organization and platform wire values', function (): void {
    expect(AvatarTemplateScope::Organization->value)->toBe('organization')
        ->and(AvatarTemplateScope::Platform->value)->toBe('platform')
        ->and(AvatarTemplateScope::cases())->toHaveCount(2);
});

test('a template with no organization is platform-scoped, one with an organization is not', function (): void {
    $platform = new AvatarTemplate;
    $platform->organization_id = null;

    $owned = new AvatarTemplate;
    $owned->organization_id = 7;

    expect($platform->isPlatform())->toBeTrue()
        ->and($platform->scopeLabel())->toBe(AvatarTemplateScope::Platform)
        ->and($owned->isPlatform())->toBeFalse()
        ->and($owned->scopeLabel())->toBe(AvatarTemplateScope::Organization);
});
