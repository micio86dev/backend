<?php

declare(strict_types=1);

/**
 * The exported OpenAPI documents carry public prose only.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * Scramble publishes docblocks and the inline comments above rules, response
 * expressions and array keys as `summary` and `description` text. A maintainer
 * writing a note for the next maintainer ("gga round 3 finding 2", "step 6
 * review follow-up", "design D4") therefore ships it to every API consumer, and
 * from there into the generated SDKs and the typed clients of both Nuxt apps.
 * It has happened: dozens of such notes reached `openapi.json` and
 * `openapi.v1.json`, and nothing noticed until a person read the SDK docs.
 *
 * The fix for a failure here is at the PHP source, never in the JSON: rewrite
 * the Scramble-visible text as prose a caller can use, and move the internal
 * note to a place Scramble does not read (a `//` block above a controller
 * method's docblock, a class docblock on a non-request class, or a method
 * docblock on a method whose docblock is not exported), then re-export both
 * documents. This test reads the committed files; CI proves they equal a fresh
 * export.
 *
 * WHAT IS SCANNED
 * ---------------
 * Every string value under a `description`, `summary` or `title` key, at any
 * depth, in `openapi.json` (the whole API) and `openapi.v1.json` (the public
 * `/v1` API that the SDKs are generated from). The public document is held to
 * a stricter standard: it must not mention PHP classes or the doc tooling
 * either.
 */

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Review-process and design-document vocabulary, case-insensitive.
 *
 * @var array<string, string>
 */
const OPENAPI_INTERNAL_WORDS = [
    'gga' => '\bgga\b',
    'review round' => '\bround [0-9]+\b',
    'review finding' => '\bfinding\b',
    'follow-up' => 'follow-up',
    'native review' => '\bnative review\b',
    'step N' => '\bstep [0-9]+\b',
    'REQ:' => '\bREQ:',
    'TODO' => '\b(TODO|FIXME)\b',
    'post-apply verification' => '(apply verification|post-apply)',
    'SDD artifacts' => '(\bopenspec\b|\bsdd\b|design\.md)',
    'internal documents' => '(CLAUDE\.md|SPEC\.md)',
    'ruling' => '\bruling [0-9]+',
    'ratified' => '\bratified\b',
    'owner' => '\bowner\b',
    // A file name, with or without a `:line`, is a pointer for a maintainer and
    // never public prose: `config/x.php`, `FooRequest.php:94`.
    'source file pointer' => '[\w\/.-]*[\w-]\.php\b(:[0-9]+)?',
];

/**
 * Decision and task identifiers, case-sensitive.
 *
 * @var array<string, string>
 */
const OPENAPI_INTERNAL_CODES = [
    'T-EXPOSE' => '\bT-EXPOSE',
    'AD-N' => '\bAD-[0-9]+',
    'design D-N' => '\bdesign (D|AD)[-0-9]',
    'D-N' => '\bD[0-9]{1,2}[a-z]?\b',
    'gap id' => '\bG-[0-9]+',
    'test id' => '\bT-[A-Z]{1,6}-?[0-9]+',
    'review part' => '\bPart [AB]\b',
    'this PR' => '\bthis PR\b',
    'PR N' => '\bPRs?[ -]?[0-9]+[a-z]?\b',
    'slice id' => '\b(P[0-9]+[a-z]|C[0-9]{1,2}|I[1-9]|FIX-[0-9]+|H[0-9]|Z[0-9]+|F[0-9])\b',
];

/**
 * What the PUBLIC document must not mention on top of the above.
 *
 * @var array<string, string>
 */
const OPENAPI_PUBLIC_ONLY = [
    'a PHP class' => '(App\\\\|\w::\w)',
    'doc tooling' => '(Scramble|docblock|class doc|static analy)',
];

/**
 * @param  array<string, string>|null  $extra
 * @return list<string> "label: matched text" for every offence in $text
 */
function openApiInternalPhrases(string $text, ?array $extra = null): array
{
    $found = [];

    foreach (OPENAPI_INTERNAL_WORDS as $label => $pattern) {
        if (preg_match('/'.$pattern.'/i', $text, $m) === 1) {
            $found[] = "{$label}: {$m[0]}";
        }
    }

    foreach ([...OPENAPI_INTERNAL_CODES, ...($extra ?? [])] as $label => $pattern) {
        if (preg_match('/'.$pattern.'/', $text, $m) === 1) {
            $found[] = "{$label}: {$m[0]}";
        }
    }

    return $found;
}

/**
 * @param  array<array-key, mixed>  $node
 * @return list<string> "json.pointer => label: matched text"
 */
function openApiOffences(array $node, string $path = '', ?array $extra = null): array
{
    $offences = [];

    foreach ($node as $key => $value) {
        $here = $path === '' ? (string) $key : $path.'.'.$key;

        if (is_array($value)) {
            $offences = [...$offences, ...openApiOffences($value, $here, $extra)];

            continue;
        }

        if (is_string($value) && in_array($key, ['description', 'summary', 'title'], true)) {
            foreach (openApiInternalPhrases($value, $extra) as $phrase) {
                $offences[] = "{$here} => {$phrase}";
            }
        }
    }

    return $offences;
}

/**
 * @return array<string, mixed>
 */
function openApiExport(string $file): array
{
    $path = base_path($file);

    expect(file_exists($path))->toBeTrue("{$file} is not committed — run `php artisan scramble:export`.");

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

test('openapi.json carries no internal review notes', function (): void {
    $offences = openApiOffences(openApiExport('openapi.json'));

    expect($offences)->toBe([], "Internal notes reached the exported API descriptions. Rewrite them at the PHP source, then re-export:\n".implode("\n", $offences));
});

test('openapi.v1.json carries no internal review notes, class names or doc-tooling talk', function (): void {
    $offences = openApiOffences(openApiExport('openapi.v1.json'), extra: OPENAPI_PUBLIC_ONLY);

    expect($offences)->toBe([], "Internal notes reached the public API descriptions. Rewrite them at the PHP source, then re-export:\n".implode("\n", $offences));
});

test('the scanner flags a planted internal phrase, whatever the form', function (string $planted): void {
    $document = ['paths' => ['/x' => ['get' => ['summary' => 'Lists things', 'description' => $planted]]]];

    expect(openApiOffences($document))->not->toBe([]);
})->with([
    'gga round' => ['max:255 (gga round 3 finding 2) — matches the column width.'],
    'review follow-up' => ['documented explicitly (step 6 review follow-up, Part A item 8).'],
    'step N' => ['Added by public-api step 4.'],
    'REQ' => ['REQ: Password Change Requires The Current Password'],
    'T-EXPOSE' => ['See T-EXPOSE-001 for the catalogue.'],
    'AD-N' => ['interview-scheduling (design AD-2/AD-3): optional.'],
    'design D-N' => ['Unpaginated (D5) — the panel answers a whole-set question.'],
    'gap id' => ['The one documented exception (G-12).'],
    'this PR' => ['Out of scope for this PR.'],
    'TODO' => ['TODO: describe the 409.'],
    'owner' => ['The owner decided to ignore it.'],
    'a slice id' => ['The wire format of C10, reused verbatim.'],
    'a file path' => ['The sample sentence lives in `config/avatar_preview.php`.'],
    'a file and line' => ["Closed event-type set — mirrors UpdateProjectRequest.php:94's rule."],
]);

test('the scanner leaves ordinary public prose alone', function (string $prose): void {
    $document = ['components' => ['schemas' => ['X' => ['description' => $prose]]]];

    expect(openApiOffences($document, extra: OPENAPI_PUBLIC_ONLY))->toBe([]);
})->with([
    'a project' => ['Enrols a candidate on an active project and returns the interview.'],
    'a session review' => ['One session, with its evidence, for the review page.'],
    'a round-trip' => ['Safe to retry: the request round-trips unchanged.'],
    'a date' => ['Strict ISO 8601 date-time, for example 2026-01-01T00:00:00Z.'],
    'a code' => ['Answers `409 transcript_not_ready` before the interview completes.'],
    'php mentioned as a language' => ['Runs on PHP 8.5; a script that is not a .phpt file is ignored by the example.'],
    'a php.ini setting' => ['The upload limit follows the php.ini value.'],
]);

test('a schema property that is itself called description is not mistaken for text', function (): void {
    $document = ['properties' => ['description' => ['type' => 'string', 'description' => 'What the thing is.']]];

    expect(openApiOffences($document))->toBe([]);
});

// ---------------------------------------------------------------------------
// The mechanism the whole rewrite depends on
// ---------------------------------------------------------------------------
//
// Moving an internal note out of Scramble's reach only works if we know exactly
// what Scramble reads. These fixtures pin it, against a tiny controller and
// FormRequest that exist only in this file, so a Scramble upgrade that changes
// the rule fails HERE, loudly, instead of silently leaking notes (or silently
// dropping public text) into the next export.
//
// A fixture, not an assertion on two real controllers: a real controller's
// comments are rewritten by ordinary work, and the test would then fail for the
// wrong reason or, worse, keep passing on text nobody meant to protect. The
// markers below are unique tokens that nothing else in the repository uses.
//
// What it pins (all observed against the installed Scramble):
//
//   exported:      the method docblock; a comment above an array key in rules()
//                  or in an inline validate() call; a comment above a
//                  `return response()->json(...)`.
//   NOT exported:  a `//` block above the method's docblock; the docblock of
//                  rules() and of a private helper; a comment above an `if`,
//                  above a `try` and above the `validate()` statement itself.

final class DocMechanismFixtureRequest extends FormRequest
{
    /**
     * MECHANISM-RULES-DOCBLOCK is the docblock of rules(), which is not exported.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // MECHANISM-RULES-ABOVE-RETURN lives above the return of rules().
        return [
            // MECHANISM-RULES-KEY is the comment above an array key, which is exported.
            'name' => ['required', 'string'],
        ];
    }
}

final class DocMechanismFixtureController
{
    // MECHANISM-ABOVE-METHOD is a // block above the docblock, which is not exported.
    /**
     * Fixture summary.
     *
     * MECHANISM-METHOD-DOCBLOCK is the method docblock, which is exported.
     */
    public function store(DocMechanismFixtureRequest $request): JsonResponse
    {
        // MECHANISM-ABOVE-IF is a comment above an if, which is not exported.
        if ($request->boolean('reject')) {
            // MECHANISM-ABOVE-RETURN is a comment above a return response()->json, which is exported.
            return response()->json(['rejected' => true], 409);
        }

        return response()->json(['ok' => true], 201);
    }

    public function update(Request $request): JsonResponse
    {
        // MECHANISM-ABOVE-VALIDATE is a comment above the validate() statement, which is not exported.
        $request->validate([
            // MECHANISM-VALIDATE-KEY is the comment above a key of an inline validate(), which is exported.
            'title' => ['required', 'string'],
        ]);

        // MECHANISM-ABOVE-TRY is a comment above a try, which is not exported.
        try {
            $payload = self::payload();
        } catch (Throwable) {
            return response()->json(['ok' => false], 500);
        }

        return response()->json($payload);
    }

    /**
     * MECHANISM-HELPER-DOCBLOCK is the docblock of a private helper, which is not exported.
     *
     * @return array{count: int}
     */
    private static function payload(): array
    {
        return ['count' => 1];
    }
}

/**
 * @return array<string, mixed>
 */
function docMechanismDocument(): array
{
    // Generated once per run: the document is plain data, and generating it again
    // would register the same routes and the same API twice.
    static $document = null;

    if ($document !== null) {
        return $document;
    }

    Route::post('doc-mechanism-fixture/things', [DocMechanismFixtureController::class, 'store']);
    Route::put('doc-mechanism-fixture/things/{id}', [DocMechanismFixtureController::class, 'update']);

    Scramble::registerApi('doc-mechanism', ['api_path' => 'doc-mechanism-fixture', 'export_path' => 'doc-mechanism.json']);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) json_encode(app(Generator::class)(Scramble::getGeneratorConfig('doc-mechanism'))), true, 512, JSON_THROW_ON_ERROR);
    $document = $decoded;

    return $document;
}

test('Scramble publishes exactly the comments the description rewrite assumes', function (string $marker, bool $exported): void {
    $json = (string) json_encode(docMechanismDocument(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect(str_contains($json, $marker))->toBe($exported, $exported
        ? "{$marker} is no longer published: a comment that was meant to reach the API consumer is dropped."
        : "{$marker} now leaks into the export: a note written to stay out of it is published.");
})->with([
    'the method docblock' => ['MECHANISM-METHOD-DOCBLOCK', true],
    'a comment above an array key in rules()' => ['MECHANISM-RULES-KEY', true],
    'a comment above a key of an inline validate()' => ['MECHANISM-VALIDATE-KEY', true],
    'a comment above a return response()->json' => ['MECHANISM-ABOVE-RETURN', true],
    'a // block above the method docblock' => ['MECHANISM-ABOVE-METHOD', false],
    'the docblock of rules()' => ['MECHANISM-RULES-DOCBLOCK', false],
    'a comment above the return of rules()' => ['MECHANISM-RULES-ABOVE-RETURN', false],
    'the docblock of a private helper' => ['MECHANISM-HELPER-DOCBLOCK', false],
    'a comment above an if' => ['MECHANISM-ABOVE-IF', false],
    'a comment above a try' => ['MECHANISM-ABOVE-TRY', false],
    'a comment above the validate() statement' => ['MECHANISM-ABOVE-VALIDATE', false],
]);

/*
 * The mechanism test above pins the comment positions on a fixture. The session
 * resources use a third position it does not model: a note directly above the
 * bare `return [...]` of a JsonResource::toArray(). Pin that shape on the REAL
 * export, with phrases that exist only in those notes, so a Scramble upgrade (or
 * a move of the note) that starts publishing them fails here by name.
 */
test('the notes above the return of the session resources toArray() are not published', function (string $file, string $phrase): void {
    $json = (string) file_get_contents(base_path($file));

    expect($json)->not->toBe('', "{$file} is not committed.");
    expect(str_contains($json, $phrase))->toBeFalse("\"{$phrase}\" from a session resource toArray() note is published in {$file}.");
})->with(function (): array {
    $cases = [];

    foreach (['openapi.json', 'openapi.v1.json'] as $file) {
        foreach (['SessionCostEstimator', 'TWO SEPARATE labelled lines', 'pluggable-conversation-llm', 'interview-session-started-at', 'eager-load `livePeriods`'] as $phrase) {
            $cases["{$file}: {$phrase}"] = [$file, $phrase];
        }
    }

    return $cases;
});
