<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR-Avatar avatar config a template runs on (hr-avatar-adapter).
 *
 * An opaque `avc_...` id that lives in ANOTHER service, so there is no foreign key
 * and nothing to index: it is read only through the template that holds it. Nullable
 * permanently; every template that is not an `hr_avatar` template (and every one the
 * export command has not linked) keeps NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->string('hr_avatar_config_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->dropColumn('hr_avatar_config_id');
        });
    }
};
