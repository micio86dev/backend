<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects.error_redirect_url — where a candidate goes when the interview FAILS.
 *
 * Sibling of `exit_redirect_url`, and the more important of the two. On success
 * the candidate is finished; on failure they are stranded on a BEAI screen, on
 * a domain they have no account on, belonging to a company they have no
 * relationship with. Only the calling system can tell them what happens next.
 *
 * Nullable, and absence is a supported configuration rather than a missing
 * setting: a client that prefers BEAI to handle its own failures keeps today's
 * inline error screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('error_redirect_url')->nullable()->after('exit_redirect_url');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('error_redirect_url');
        });
    }
};
