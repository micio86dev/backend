<?php

declare(strict_types=1);

/**
 * The reusable link token scrubber, pinned by its LENGTH contract.
 *
 * The project-wide target is the over-inclusive pattern
 * `beai_rl_[A-Za-z0-9_-]{16,}`: not end-anchored and not exactly 43, so a
 * truncated or over-long copy of a bearer secret is still taken, while the
 * 16-character display prefix (`beai_rl_` plus 8 characters) is not a secret and
 * stays readable. The frontend and backoffice carry the same pattern and the same
 * cases; this file is the api side of that agreement.
 *
 * The cases are written with literal, deterministic tails (never a random
 * token), so a quantifier that only fails for some alphabets cannot pass by luck.
 */

use App\Services\ReusableLinkTokenGenerator;
use App\Support\Observability\SentryScrubber;
use Sentry\Event;
use Sentry\ExceptionDataBag;

const REUSABLE_LINK_PATTERN_ALPHABET = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_';

/**
 * A literal token tail of exactly $length characters, drawn from the full
 * base64url alphabet (both `-` and `_` included).
 */
function reusableLinkPatternTail(int $length): string
{
    return substr(str_repeat(REUSABLE_LINK_PATTERN_ALPHABET, 2), 0, $length);
}

function reusableLinkPatternToken(int $tailLength): string
{
    return ReusableLinkTokenGenerator::MARKER.reusableLinkPatternTail($tailLength);
}

/**
 * What an exception message carrying $text looks like after the scrubber.
 */
function reusableLinkPatternMessage(string $text): string
{
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException($text))]);

    return SentryScrubber::handle($event)->getExceptions()[0]->getValue();
}

/**
 * What a request URL carrying $url looks like after the scrubber.
 */
function reusableLinkPatternRequestUrl(string $url): string
{
    $event = Event::createEvent();
    $event->setRequest(['url' => $url]);

    return (string) SentryScrubber::handle($event)->getRequest()['url'];
}

test('the scrubber pattern is the over-inclusive one and is defined exactly once in app/', function (): void {
    $definitions = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Any quoted regex literal that names the marker.
        preg_match_all("#'(/\\^?beai_rl_[^']*)'#", $source, $matches);

        foreach ($matches[1] as $literal) {
            $definitions[] = $file->getFilename().' '.$literal;
        }
    }

    // `FORMAT` validates a whole token on redemption (anchored, exactly 43); it
    // is the ONE other regex naming the marker and it is not a scrubber.
    expect($definitions)->toEqualCanonicalizing([
        'ReusableLinkTokenGenerator.php /^beai_rl_[A-Za-z0-9_-]{43}\z/',
        'SentryScrubber.php /beai_rl_[A-Za-z0-9_-]{16,}/',
    ]);
});

test('a token is redacted whatever the length of its tail from 16 characters up', function (int $tailLength): void {
    $token = reusableLinkPatternToken($tailLength);

    expect(reusableLinkPatternMessage("got {$token} here"))->toBe('got [redacted] here')
        ->and(reusableLinkPatternMessage("got {$token}"))->toBe('got [redacted]')
        ->and(reusableLinkPatternMessage($token))->toBe('[redacted]');
})->with([
    'a 16-character tail' => [16],
    'a 42-character tail' => [42],
    'the real 43-character token' => [43],
    'a 60-character tail' => [60],
]);

test('a tail ending in a dash or an underscore is taken whole, at any length', function (int $tailLength, string $last): void {
    // A word-boundary or end-anchor added to the pattern would leave a trailing
    // `-` behind (it is not a word character), and a random token ends in one
    // about one time in thirty-two.
    $token = ReusableLinkTokenGenerator::MARKER.substr(reusableLinkPatternTail($tailLength), 0, -1).$last;

    expect(reusableLinkPatternMessage("got {$token} here"))->toBe('got [redacted] here');
})->with(function (): array {
    $cases = [];

    foreach ([16, 42, 43, 60] as $length) {
        foreach (['-', '_'] as $last) {
            $cases["{$length} characters ending in {$last}"] = [$length, $last];
        }
    }

    return $cases;
});

test('a longer-than-real tail is taken whole, with nothing left behind to read', function (): void {
    $token = reusableLinkPatternToken(60);

    // Not end-anchored and not capped at 43: the 17 characters past the real
    // length are part of the same run and go with it.
    expect(reusableLinkPatternMessage("got {$token} here"))
        ->not->toContain(reusableLinkPatternTail(44))
        ->not->toContain(reusableLinkPatternTail(60))
        ->toBe('got [redacted] here');
});

test('the 16-character display prefix and a short near miss are not scrubbed', function (int $tailLength): void {
    $kept = reusableLinkPatternToken($tailLength);

    expect(reusableLinkPatternMessage("link {$kept} was disabled"))->toBe("link {$kept} was disabled");
})->with([
    'the display prefix: marker plus 8' => [8],
    'a 10-character near miss' => [10],
    'one below the floor: 15' => [15],
]);

test('the token goes wherever it rides: URL fragment, JSON string, query string, request URL', function (string $carrier): void {
    $token = reusableLinkPatternToken(43);
    $tail = reusableLinkPatternTail(43);

    $sent = match ($carrier) {
        'a URL fragment in a message' => reusableLinkPatternMessage("opened https://app.beai.test/en/interview/reusable#{$token} in a stand"),
        'a JSON string in a message' => reusableLinkPatternMessage('422 response: '.json_encode(['note' => "see {$token}", 'status' => 'refused'], JSON_THROW_ON_ERROR)),
        'a query string in a message' => reusableLinkPatternMessage("POST /api/reusable-links/redeem?note={$token}&attempt=2 failed"),
        'a request URL fragment' => reusableLinkPatternRequestUrl("https://app.beai.test/en/interview/reusable#{$token}"),
        'a request URL query' => reusableLinkPatternRequestUrl("https://api.beai.test/api/reusable-links/redeem?note={$token}"),
        'a request URL path' => reusableLinkPatternRequestUrl("https://api.beai.test/api/x/{$token}"),
    };

    expect($sent)->not->toContain($tail)->not->toContain($token);
})->with([
    'a URL fragment in a message',
    'a JSON string in a message',
    'a query string in a message',
    'a request URL fragment',
    'a request URL query',
    'a request URL path',
]);

test('the exact outputs the frontend and backoffice mirrors are held to', function (string $input, string $expected): void {
    expect(reusableLinkPatternMessage($input))->toBe($expected);
})->with(function (): array {
    $t43 = reusableLinkPatternToken(43);
    $t60 = reusableLinkPatternToken(60);

    return [
        'a URL fragment' => ["see https://app.beai.test/en/interview/reusable#{$t43} now", 'see https://app.beai.test now'],
        'a path' => ["POST /api/x/{$t43} failed", 'POST /api/x/[redacted] failed'],
        'repeated twice in one string' => ["first {$t43} then {$t60} done", 'first [redacted] then [redacted] done'],
        'the display prefix beside a real token' => ['list beai_rl_AbCdEfGh and '.$t43.' done', 'list beai_rl_AbCdEfGh and [redacted] done'],
        'adjacent tokens split by a space' => ["{$t43} {$t43}", '[redacted] [redacted]'],
    ];
});

test('a token repeated twice in one string is redacted both times', function (): void {
    $first = reusableLinkPatternToken(43);
    $second = reusableLinkPatternToken(16);

    $sent = reusableLinkPatternMessage("first {$first}, then {$second}, done");

    expect($sent)->toBe('first [redacted], then [redacted], done')
        ->and(substr_count($sent, '[redacted]'))->toBe(2);
});
