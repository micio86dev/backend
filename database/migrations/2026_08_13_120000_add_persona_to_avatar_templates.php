<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persona on avatar templates (C14 portability).
 *
 * The system prompt body and spoken greeting travel with a template when it is
 * exported from `quint-avatar-tester`, and they are provider-AGNOSTIC: the same
 * persona can front a HeyGen or a Tavus avatar.
 *
 * A separate column rather than a key inside `config`, because `config` is
 * validated strictly against `ProviderFieldSpecs` — an unknown key is refused.
 * Smuggling the persona in there would either force a hole in that validation
 * or make the persona look like a provider knob it is not.
 *
 * Nullable: a template may be pure provider configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->jsonb('persona')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->dropColumn('persona');
        });
    }
};
