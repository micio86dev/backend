<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable stamp of the conversation prompt a session was composed under
 * (db-driven-conversation-prompts PR3, design N-8).
 *
 * The conversation `prompt_version` was persisted nowhere: it is returned to the
 * client as `question_context.prompt_version` and then lost, so two interviews run
 * under different prompts were indistinguishable afterwards. Named
 * `conversation_prompt_version`, not `prompt_version`, because
 * `evaluations.prompt_version` already means the SCORING prompt.
 *
 * Nullable, no default, no backfill: sessions that predate this column cannot be
 * reconstructed and stay NULL. Written once by `InterviewSessionLlmSnapshot::stamp()`,
 * never overwritten and never nulled. Adding a nullable column with no default is
 * metadata-only in Postgres, and the previous release tolerates the extra column.
 * Not personal data and never serialised by any resource.
 */
return new class extends Migration
{
    private const COLUMN = 'conversation_prompt_version';

    public function up(): void
    {
        if (Schema::hasColumn('interview_sessions', self::COLUMN)) {
            return;
        }

        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->string(self::COLUMN)->nullable()->after('system_prompt_chars');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('interview_sessions', self::COLUMN)) {
            return;
        }

        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
