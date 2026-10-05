<?php

declare(strict_types=1);

/**
 * Architecture guard: the `completato -> in_attesa` edge has ONE writer
 * (scoring-retry-rt-b, design D2; the `Participant` docblock promises it).
 *
 * `completato` used to be terminal. It now has exactly one way out, the retry
 * authorization, and a second writer would let a finished assessment be
 * re-opened without the guards, the row lock, the invalid-competency reset, the
 * single-use flag and the audit row that make the retry a ONE-time, auditable
 * operation. The model's transition map cannot tell the two callers apart (it
 * only knows the edge exists), so the guard lives here.
 *
 * Detection is textual, in the convention of the sibling arch tests (no
 * pest-plugin-arch dependency): every statement under `app/` that WRITES the
 * status value `in_attesa` (an assignment or a column => value pair) must sit
 * in the action or in the closed allowlist below. Every allowlisted file is a
 * place that is not the `completato` edge: it CREATES a participant at
 * `in_attesa`, or is the `errore -> in_attesa` recovery. Adding a file to that
 * list is a reviewed decision with a written reason, never a way to silence
 * the test.
 *
 * REQ: completato returns to in_attesa only via the retry authorization action
 *      (openspec/changes/scoring-retry-rt-b/specs/participant-sso/spec.md)
 */

use App\Models\Participant;

const RETRY_EDGE_WRITER = 'Actions/Participant/AuthorizeEvaluationRetry.php';

/**
 * Files that write the value `in_attesa` and are NOT the completato edge.
 *
 * @return array<string, string> path under app/ => why it is not the retry edge
 */
function retryEdgeAllowedWriters(): array
{
    return [
        'Actions/Participant/RecoverFailedParticipant.php' => 'errore -> in_attesa, the participant-error-recovery edge',
        'Actions/ReusableLinks/RedeemReusableInterviewLink.php' => 'creates a visitor participant at in_attesa',
        'Actions/Scheduling/CreateScheduledParticipant.php' => 'creates a scheduled participant at in_attesa',
        'Http/Controllers/M2m/ParticipantController.php' => 'creates a participant at in_attesa',
        'Http/Controllers/Sso/SsoExchangeController.php' => 'upsert guarded by WHERE status = in_attesa; never touches another status',
        'Support/Demo/DemoDataset.php' => 'demo seed data created at in_attesa',
    ];
}

/**
 * The relative paths (under app/) of every file with a statement that writes
 * the status `in_attesa`, comment lines excluded.
 *
 * @return list<string>
 */
function retryEdgeWriters(): array
{
    $writers = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/[\'"]status[\'"]\s*=>\s*[\'"]in_attesa[\'"]|->status\s*=\s*[\'"]in_attesa[\'"]|\bstatus\s*=\s*\'in_attesa\'/', $line) === 1) {
                $writers[] = str_replace(app_path().'/', '', $file->getPathname());

                break;
            }
        }
    }

    sort($writers);

    return $writers;
}

test('the completato -> in_attesa edge is written only by AuthorizeEvaluationRetry', function (): void {
    $writers = retryEdgeWriters();

    expect(in_array(RETRY_EDGE_WRITER, $writers, true))->toBeTrue('The action no longer writes the edge: this guard would pass for the wrong reason.');

    $strangers = array_values(array_diff($writers, [RETRY_EDGE_WRITER], array_keys(retryEdgeAllowedWriters())));

    expect($strangers)->toBe([], sprintf(
        "These files write the status `in_attesa` outside the retry action and the reviewed allowlist: %s.\n".
        'Only AuthorizeEvaluationRetry may re-open a `completato` participant. If a file merely creates a participant, add it to retryEdgeAllowedWriters() with the reason.',
        implode(', ', $strangers),
    ));
});

test('every allowlisted writer still exists and still writes in_attesa', function (): void {
    $writers = retryEdgeWriters();

    foreach (array_keys(retryEdgeAllowedWriters()) as $path) {
        expect(in_array($path, $writers, true))->toBeTrue("`{$path}` is allowlisted but no longer writes in_attesa: remove it from the list.");
    }
});

test('the transition map keeps the edge as the only way out of completato', function (): void {
    $map = (new ReflectionProperty(Participant::class, 'allowedTransitions'))->getValue();

    expect($map['completato'])->toBe(['in_attesa']);
});
