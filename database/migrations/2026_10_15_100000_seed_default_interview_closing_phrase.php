<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the default interview closing greeting.
 *
 * Inserted ONLY when no row exists, so a superadmin's edit (or a deliberate clear that
 * a later deploy re-runs over) is never overwritten. down() removes the row only while it
 * still equals the default, for the same reason.
 *
 * Neither text contains its locale's `end_phrase`: that would end a competency early.
 */
return new class extends Migration
{
    private const KEY = 'interview_closing_phrase';

    /** @var array<string, string> */
    private const DEFAULT = [
        'en' => 'Thank you for taking the time to complete this interview and for sharing your experiences with us. Your answers will now be reviewed, and you will receive feedback in due course. We wish you all the best.',
        'it' => 'Grazie per aver dedicato il tuo tempo a questo colloquio e per aver condiviso con noi le tue esperienze. Le tue risposte verranno ora analizzate e riceverai un riscontro a tempo debito. Ti auguriamo il meglio.',
    ];

    public function up(): void
    {
        DB::table('platform_settings')->insertOrIgnore([
            'key' => self::KEY,
            'value' => json_encode(self::DEFAULT, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('platform_settings')->where('key', self::KEY)->first();

        if ($row !== null && json_decode((string) $row->value, true) === self::DEFAULT) {
            DB::table('platform_settings')->where('key', self::KEY)->delete();
        }
    }
};
