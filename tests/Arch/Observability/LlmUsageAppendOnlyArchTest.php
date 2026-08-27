<?php

declare(strict_types=1);

/**
 * Architecture guard: `interview_session_llm_usage` rows are never mutated
 * (pluggable-conversation-llm PR P6a, design D5). Copied from
 * `AiRequestAppendOnlyArchTest.php` — same doctrine, same table shape
 * (`created_at` only, no `updated_at`).
 *
 * A cost record that can be edited is not a cost record.
 */

use App\Models\InterviewSessionLlmUsage;

test('no business logic mutates an interview_session_llm_usage row', function (): void {
    $violations = [];

    $bannedNeedles = [
        'InterviewSessionLlmUsage::query()->update(',
        'InterviewSessionLlmUsage::where(',
        'InterviewSessionLlmUsage::find(',
        "DB::table('interview_session_llm_usage')->update(",
        "DB::table('interview_session_llm_usage')->delete(",
        "DB::table('interview_session_llm_usage')->increment(",
        "DB::table('interview_session_llm_usage')->decrement(",
    ];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        // The model file itself is allowed to mention these — it defines them.
        if ($relative === 'app/Models/InterviewSessionLlmUsage.php') {
            continue;
        }

        foreach ($bannedNeedles as $needle) {
            if (str_contains($source, $needle)) {
                $violations[] = "{$relative} contains {$needle}";
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "interview_session_llm_usage is append-only — read it, never mutate it:\n  - %s",
        implode("\n  - ", $violations)
    ));
});

test('the model declares no updated_at', function (): void {
    expect((new InterviewSessionLlmUsage)->timestamps)->toBeFalse();
});

test('PurgeExpiredDataCommand never references interview_session_llm_usage', function (): void {
    // Non-negotiable: cost history must survive the GDPR transcript purge.
    $source = (string) file_get_contents(app_path('Console/Commands/PurgeExpiredDataCommand.php'));

    expect($source)->not->toContain('InterviewSessionLlmUsage')
        ->and($source)->not->toContain('interview_session_llm_usage');
});
