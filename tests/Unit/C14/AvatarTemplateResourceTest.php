<?php

declare(strict_types=1);

/**
 * AvatarTemplateResource unit tests (C14).
 *
 * Same schema-lie class as ApiClientResourceTest.php (dates-and-destructive-
 * actions, Phase 0 audit finding, closed here because this change's own
 * diff touches `avatar-templates/index.vue` and increases reliance on this
 * resource's generated type): `AvatarTemplate.php` already declares
 * `@property int $id` / `@property bool $is_active` /
 * `@property array<string,mixed> $config`, and the model casts already make
 * the RUNTIME values correct. Only the generated schema
 *
 * (`AvatarTemplateResource::toArray()`'s local `/** @var *\/` assignment
 * degrading Scramble's inference) lied — `id`/`config`/`is_active` typed
 * `string`. These assertions pin the wire types PHP-side.
 */

use App\Http\Resources\AvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\Request;

test('AvatarTemplateResource wire types pin the docblock against schema drift', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor(
        $org->id,
        fn (): AvatarTemplate => AvatarTemplate::create([
            'name' => 'Recruiter voice',
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
            'is_active' => true,
        ])
    );

    $resource = new AvatarTemplateResource($template);
    $array = $resource->toArray(new Request);

    expect($array['id'])->toBeInt();
    expect($array['is_active'])->toBeBool();
    expect($array['is_active'])->toBeTrue();
    expect($array['config'])->toBeArray();
    expect($array['config'])->toBe(['avatarId' => 'av_1', 'voiceId' => 'vo_1']);
});

test('AvatarTemplateResource exposes a null description as null, not a coerced string', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor(
        $org->id,
        fn (): AvatarTemplate => AvatarTemplate::create([
            'name' => 'No description',
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
        ])
    );

    $resource = new AvatarTemplateResource($template);
    $array = $resource->toArray(new Request);

    expect($array['description'])->toBeNull();
});
