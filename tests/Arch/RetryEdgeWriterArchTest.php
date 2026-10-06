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
 * Detection reads PHP TOKENS, not lines of text (no pest-plugin-arch
 * dependency, in the convention of the sibling arch tests): every place under
 * `app/` where the literal `in_attesa` is ASSIGNED, returned, picked by a
 * ternary or `??`, passed as a named argument, used as an array/match value or
 * handed to a model write call (`update`, `fill`, `create`, `transitionTo` ...)
 * counts as a writer, whatever the shape of the statement. So an intermediate
 * variable (`$next = 'in_attesa'; $p->status = $next;`) and a constant or enum
 * case (`const X = 'in_attesa'`) are caught where the literal is introduced,
 * and that file must sit in the action or in the closed allowlist below.
 * Comparisons (`=== 'in_attesa'`), array membership and array KEYS are reads.
 * It is still syntactic: a value assembled at runtime ('in_'.'attesa') is out
 * of reach, which is why the transition map and the action carry their own
 * guards as well. Every allowlisted file is a
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
        'Support/PublicApi/InterviewStatus.php' => 'maps the public status enum to its stored label (`self::Pending => in_attesa`); writes no participant',
    ];
}

/**
 * Whether a PHP source WRITES the literal `in_attesa`, decided on its tokens
 * (comments and docblocks never reach the decision).
 */
function retryEdgeSourceWrites(string $source): bool
{
    $writeCalls = ['transitionTo', 'forceFill', 'fill', 'update', 'create', 'updateOrCreate', 'firstOrCreate', 'upsert', 'setAttribute', 'setStatus'];
    $kind = static fn (mixed $token): mixed => is_array($token) ? $token[0] : $token;
    $previous = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        // A raw SQL write (INSERT / UPDATE ... SET / ON CONFLICT DO UPDATE) that names the
        // value inside a string or heredoc is a writer too.
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && str_contains($token[1], 'in_attesa')
            && preg_match('/\b(insert\s+into|update\s+\w+\s+set|do\s+update)\b/i', $token[1]) === 1) {
            return true;
        }

        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '\'"') === 'in_attesa') {
            $last = $kind($previous[0] ?? null);

            if (in_array($last, ['=', '?', ':', T_DOUBLE_ARROW, T_RETURN, T_COALESCE], true)) {
                return true;
            }

            $call = $previous[1] ?? null;
            if ($last === '(' && is_array($call) && $call[0] === T_STRING && in_array($call[1], $writeCalls, true)) {
                return true;
            }
        }

        array_unshift($previous, $token);
        $previous = array_slice($previous, 0, 2);
    }

    return false;
}

/**
 * The relative paths (under app/) of every file where the literal `in_attesa`
 * is written, per retryEdgeSourceWrites().
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

        if (retryEdgeSourceWrites((string) file_get_contents($file->getPathname()))) {
            $writers[] = str_replace(app_path().'/', '', $file->getPathname());
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

test('the detector catches every way of writing in_attesa the old textual guard missed', function (string $source): void {
    expect(retryEdgeSourceWrites("<?php\n".$source))->toBeTrue();
})->with([
    'an intermediate variable' => ['$next = \'in_attesa\'; $participant->status = $next;'],
    'a class constant' => ['final class A { public const STATUS_IN_ATTESA = \'in_attesa\'; }'],
    'an enum case' => ['enum S: string { case InAttesa = \'in_attesa\'; }'],
    'a ternary' => ['$next = $flag ? \'in_attesa\' : \'completato\';'],
    'a null-coalesce' => ['$next = $maybe ?? \'in_attesa\';'],
    'a return from a helper' => ['function next(): string { return \'in_attesa\'; }'],
    'a named argument' => ['$p->forceFill(status: \'in_attesa\');'],
    'a column pair' => ['$p->update([\'status\' => \'in_attesa\']);'],
    'an arrow function value' => ['$f = fn () => \'in_attesa\';'],
    'a model write call' => ['$p->transitionTo(\'in_attesa\');'],
    'a raw SQL insert' => ['$sql = "INSERT INTO participants (status) VALUES (\'in_attesa\')";'],
    'a raw SQL conflict update' => ['$sql = "... ON CONFLICT (id) DO UPDATE SET status = \'in_attesa\'";'],
    'the shapes the old guard knew' => ['$p->status = \'in_attesa\';'],
]);

test('the detector leaves reads alone: comparisons, membership, keys, comments and docblocks', function (string $source): void {
    expect(retryEdgeSourceWrites("<?php\n".$source))->toBeFalse();
})->with([
    'a strict comparison' => ['if ($p->status === \'in_attesa\') { return 1; }'],
    'a loose inequality' => ['if ($p->status != \'in_attesa\') { return 1; }'],
    'array membership' => ['in_array($status, [\'in_attesa\', \'in_corso\'], true);'],
    'an array key' => ['$labels = [\'in_attesa\' => \'waiting\'];'],
    'a raw SQL select' => ['$sql = "SELECT id FROM participants WHERE status = \'in_attesa\'";'],
    'a where clause' => ['$q->where(\'status\', \'in_attesa\');'],
    'a line comment' => ['// $p->status = \'in_attesa\';'],
    'a docblock' => ['/** $next = \'in_attesa\'; */ function f() {}'],
]);

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
