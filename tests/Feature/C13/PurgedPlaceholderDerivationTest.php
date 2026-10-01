<?php

declare(strict_types=1);

/**
 * The purged placeholder is derived twice: in PHP (`PlaceholderEmail::forPurged()`,
 * what a test, a guard or a re-issue compares against) and in SQL
 * (`PlaceholderEmail::purgedSqlExpression()`, what the purge writes). They are
 * two spellings of one function, and a purge that wrote a value the PHP side does
 * not recognise as the participant's own would be re-selected on every run and
 * never settle. So they are compared on the database itself, for the inputs where
 * the byte handling differs: ASCII, multibyte text, an emoji and the longest
 * reference the column allows.
 *
 * Postgres only: the SQL twin uses `sha256(bytea)` and `convert_to()`.
 *
 * REQ: The Participant Purge Also Redacts The Email
 *      (sdd/reusable-link-visitor-identity/spec/data-retention)
 */

use App\Support\Participant\PlaceholderEmail;
use Illuminate\Support\Facades\DB;

test('the PHP derivation and the SQL derivation agree', function (string $reference): void {
    $row = DB::selectOne(
        'select '.PlaceholderEmail::purgedSqlExpression().' as placeholder from (select ?::text as candidate_ref) as p',
        [$reference],
    );

    expect($row->placeholder)->toBe(PlaceholderEmail::forPurged($reference));
})->with([
    'ASCII' => 'ref-1',
    'non-ASCII' => 'Ünïcödé-ref',
    'an emoji' => 'candidate-😀-ref',
    'the longest reference' => str_repeat('r', 255),
    'the longest multibyte reference' => str_repeat('é', 255),
]);
