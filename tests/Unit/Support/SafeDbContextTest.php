<?php

declare(strict_types=1);

use App\Support\Logging\SafeDbContext;
use Illuminate\Database\QueryException;

/**
 * The sanitiser is asserted against the two ways a bound value reaches a log:
 * the interpolated SQL in `QueryException::getMessage()`, and PostgreSQL's
 * `DETAIL:` line inside the driver message underneath it.
 */
test('the interpolated SQL never reaches the log context', function (): void {
    $secret = 'My previous salary was ninety thousand euro.';

    $e = new QueryException(
        'pgsql',
        'insert into "utterances" ("text") values (?)',
        [$secret],
        new PDOException('SQLSTATE[22001]: String data, right truncated'),
    );

    // The hazard is real, not hypothetical: Laravel builds this message itself.
    expect($e->getMessage())->toContain($secret);

    $context = SafeDbContext::for($e);

    expect(json_encode($context))->not->toContain($secret);
    expect($context['message'])->toContain('SQLSTATE[22001]');
});

test('the PostgreSQL DETAIL line, which echoes the failing row, is cut', function (): void {
    $secret = 'My previous salary was ninety thousand euro.';

    $e = new QueryException(
        'pgsql',
        'insert into "utterances" ("text") values (?)',
        [$secret],
        new PDOException(
            "SQLSTATE[23502]: Not null violation: null value in column \"speaker\"\nDETAIL:  Failing row contains (1, {$secret}).",
        ),
    );

    $context = SafeDbContext::for($e);

    expect(json_encode($context))->not->toContain($secret);
    expect($context['message'])->toContain('SQLSTATE[23502]');
});

test('a LOCALIZED detail label is cut too, since the redaction is not a label match', function (): void {
    $secret = 'My previous salary was ninety thousand euro.';

    // `lc_messages = it_IT`. A blocklist keyed on the English label misses this
    // and logs the failing row in full — which is why only the first line is kept.
    $e = new QueryException(
        'pgsql',
        'insert into "utterances" ("text") values (?)',
        [$secret],
        new PDOException(
            "SQLSTATE[23502]: Not null violation: valore null nella colonna \"speaker\"\nDETTAGLIO:  La riga che causa l'errore contiene (1, {$secret}).",
        ),
    );

    $context = SafeDbContext::for($e);

    expect(json_encode($context))->not->toContain($secret);
    expect($context['message'])->toContain('SQLSTATE[23502]');
});

test('a non-database exception keeps its own message', function (): void {
    $context = SafeDbContext::for(new RuntimeException('utterance_insert_failed'));

    expect($context['message'])->toBe('utterance_insert_failed');
    expect($context['exception'])->toBe(RuntimeException::class);
});

test('an empty driver message falls back rather than logging a blank line', function (): void {
    $e = new QueryException(
        'pgsql',
        'insert into "utterances" ("text") values (?)',
        ['whatever the candidate said'],
        new PDOException(''),
    );

    expect(SafeDbContext::for($e)['message'])->toBe('(no driver message)');
});
