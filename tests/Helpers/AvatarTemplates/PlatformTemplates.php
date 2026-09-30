<?php

declare(strict_types=1);

namespace Tests\Helpers\AvatarTemplates;

use App\Models\AvatarTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Builds platform (global) avatar templates for tests.
 *
 * A raw insert on purpose: it bypasses model events, so a fixture never
 * depends on the write guards under test. Tests that exercise the real write
 * path go through `PlatformTemplateContext::run()` instead.
 */
final class PlatformTemplates
{
    /**
     * A NULL-organization HeyGen template, inactive unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insertGlobal(array $overrides = []): AvatarTemplate
    {
        $row = array_merge([
            'organization_id' => null,
            'name' => 'Platform '.uniqid(),
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av_platform', 'voiceId' => 'vo_platform'],
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        $row['config'] = json_encode($row['config'], JSON_THROW_ON_ERROR);

        $id = DB::table('avatar_templates')->insertGetId($row);

        return AvatarTemplate::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function insertActiveGlobal(array $overrides = []): AvatarTemplate
    {
        return self::insertGlobal(array_merge(['is_active' => true], $overrides));
    }
}
