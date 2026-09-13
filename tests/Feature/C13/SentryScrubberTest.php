<?php

declare(strict_types=1);

/**
 * Nothing confidential leaves for Sentry (C13).
 *
 * `send_default_pii => false` stops Sentry ADDING user and IP context. It does
 * nothing about what this application's own exceptions already carry — and in
 * this product they carry a lot: an exception in the scoring pipeline has
 * prompt text in scope, and prompts contain a candidate's spoken answers.
 *
 * Getting this wrong fails quietly and permanently. The data lands in a
 * third-party service nobody thinks of as a database, indexed and searchable,
 * and no other test in this suite would notice.
 */

use App\Enums\EvaluationStatus;
use App\Support\Observability\SentryScrubber;
use Carbon\CarbonImmutable;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Sentry\Stacktrace;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TraceId;
use Sentry\UserDataBag;

function scrubbedEvent(array $extra = [], array $request = []): Event
{
    $event = Event::createEvent();
    $event->setExtra($extra);

    if ($request !== []) {
        $event->setRequest($request);
    }

    return SentryScrubber::handle($event);
}

test('a candidate answer never reaches the sink', function (): void {
    $answer = 'I once falsified a report under deadline pressure';

    $event = scrubbedEvent([
        'transcript' => $answer,
        'prompt' => "Score this: {$answer}",
        'excerpts' => [$answer],
    ]);

    $encoded = json_encode($event->getExtra());

    // The entire premise of the product is that a candidate's answers stay
    // between them and the organization that assessed them.
    expect($encoded)->not->toContain('falsified');
});

test('tokens and secrets never reach the sink', function (): void {
    $event = scrubbedEvent([
        'authorization' => 'Bearer eyJhbGciOi.LEAKED',
        'api_key' => 'beai_live_LEAKED',
        'webhook_secret' => 'whsec_LEAKED',
        'refresh_token' => 'rt_LEAKED',
    ]);

    expect(json_encode($event->getExtra()))->not->toContain('LEAKED');
});

test('candidate identifiers never reach the sink', function (): void {
    $email = uniqid('cand-').'@example.test';

    $event = scrubbedEvent([
        'candidate_ref' => 'acme-672',
        'display_name' => 'Mario Rossi',
        'email' => $email,
    ]);

    $encoded = json_encode($event->getExtra());

    // candidate_ref is opaque to BEAI but NOT to the calling system — it is
    // their key back to a named person, which makes it identifying the moment
    // it sits alongside anything else.
    expect($encoded)->not->toContain('acme-672');
    expect($encoded)->not->toContain('Mario Rossi');

    // The email is the candidate's GLOBAL identity key (CLAUDE.md ruling 8,
    // reversed 2026-09-01) and is named in the GDPR retention sign-off
    // (ruling 2). It sat in this fixture unasserted: the test read as though
    // it covered the address while the scrubber shipped it verbatim.
    expect($encoded)->not->toContain($email);
});

test('secrets nested at any depth are scrubbed', function (): void {
    $event = scrubbedEvent([
        'context' => ['delivery' => ['payload' => ['answer' => 'NESTED-LEAK']]],
    ]);

    // A top-level-only pass would look like it worked while letting the real
    // payload through — exceptions nest their context by nature.
    expect(json_encode($event->getExtra()))->not->toContain('NESTED-LEAK');
});

test('fields ending in _token, _secret or _key are scrubbed by convention', function (): void {
    $event = scrubbedEvent([
        'provider_api_key' => 'PK-LEAK',
        'session_token' => 'ST-LEAK',
        'signing_secret' => 'SS-LEAK',
    ]);

    // Enumerating every future field name is impossible; the convention covers
    // what the denylist has not been told about yet.
    $encoded = json_encode($event->getExtra());
    expect($encoded)->not->toContain('LEAK');
});

test('user context is dropped entirely', function (): void {
    $event = Event::createEvent();
    $event->setUser(UserDataBag::createFromUserIdentifier('user-42'));

    $scrubbed = SentryScrubber::handle($event);

    // Dropped rather than scrubbed field by field: a candidate is not a Sentry
    // "user", and an operator's identity adds nothing to a stack trace that the
    // organization scope does not already give. There is no case where keeping
    // it is worth the risk of a future Sentry version adding a field this code
    // has never heard of.
    expect($scrubbed->getUser())->toBeNull();
});

test('diagnostic context that is NOT sensitive survives', function (): void {
    $event = scrubbedEvent([
        'evaluation_id' => 42,
        'competency_code' => 'COL',
        'organization_id' => 7,
        'latency_ms' => 1234,
    ]);

    $extra = $event->getExtra();

    // A denylist rather than an allowlist, deliberately: an allowlist would
    // strip the context that makes an error report useful, and an unusable
    // error reporter gets switched off — a worse outcome than a scrubbed one.
    expect($extra['evaluation_id'])->toBe(42);
    expect($extra['competency_code'])->toBe('COL');
    expect($extra['latency_ms'])->toBe(1234);
});

test('the config pins PII off and wires the scrubber', function (): void {
    // send_default_pii is deliberately NOT env-overridable to true: in this
    // product that is not a preference.
    expect(config('sentry.send_default_pii'))->toBeFalse();
    expect(config('sentry.before_send'))->toBe([SentryScrubber::class, 'handle']);
});

test('sentry is inert without a DSN', function (): void {
    // No DSN is configured anywhere in this repo, and none should be: it is a
    // deployment credential. The integration must therefore be a no-op out of
    // the box rather than an error.
    expect(config('sentry.dsn'))->toBeNull();
});

test('fields ending in _email are scrubbed by convention', function (): void {
    // Same reasoning the _token/_secret/_key suffixes already carry: enumerating
    // every future field name is impossible, a naming convention is not. And an
    // address is the one candidate identifier that needs no calling system to
    // resolve it back to a person.
    $event = scrubbedEvent([
        'candidate_email' => 'mario.rossi@example.test',
        'contactEmail' => 'anna.bianchi@example.test',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('mario.rossi@example.test');
    expect($encoded)->not->toContain('anna.bianchi@example.test');
});

test('camelCase keys are denied too, as they already are in both TS mirrors', function (): void {
    // `strtolower('candidateRef')` is `candidateref`, which is in no list and
    // ends in no convention suffix — so every camelCase spelling walked
    // straight out of this scrubber while `backoffice`/`frontend`
    // `toSnakeKey()` caught the identical key. The three denylists are
    // deliberate copies of one another and their TEST FILES name camelCase as
    // a leak class; only this side never implemented it.
    $event = scrubbedEvent([
        'candidateRef' => 'acme-672',
        'displayName' => 'Mario Rossi',
        'sessionToken' => 'ST-LEAK',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('acme-672');
    expect($encoded)->not->toContain('Mario Rossi');
    expect($encoded)->not->toContain('ST-LEAK');
});

test('the SSO token in request url and query_string never reaches the sink', function (): void {
    // `scrub()` redacts by KEY and only recurses into arrays, so a STRING under
    // a non-denied key walked straight out — and Sentry's RequestIntegration
    // populates `url` (full URI, query included) and `query_string` as plain
    // strings. `GET /api/sso/exchange?token=<jwt>` failing anywhere past the
    // signature check therefore filed the still-unspent, still-replayable token
    // into a searchable third-party index. This file's own docblock names that
    // exact scenario.
    $event = scrubbedEvent([], [
        'url' => 'https://api.beai.test/api/sso/exchange?token=eyJLEAKED',
        'query_string' => 'token=eyJLEAKED',
        'cookies' => ['beai_refresh' => 'RT-LEAKED'],
        'headers' => ['X-Api-Key' => ['HEADER-LEAKED']],
    ]);

    expect(json_encode($event->getRequest()))->not->toContain('LEAKED');
});

test('breadcrumbs are scrubbed — Laravel log context rides in them', function (): void {
    // `config/sentry.php` has `breadcrumbs.logs => true`, and the scope is
    // applied BEFORE `before_send`. So one `Log::error('scoring failed',
    // ['prompt' => $prompt])` put a candidate's transcribed answers in the
    // event under `prompt` — a key this class already denies. The denylist
    // knew; the traversal never looked here.
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_ERROR,
            Breadcrumb::TYPE_DEFAULT,
            'log',
            'scoring failed',
            ['prompt' => 'I once falsified a report'],
        ),
    ]);

    $scrubbed = SentryScrubber::handle($event);

    // Asserted on getMetadata(), NOT on json_encode() of the Breadcrumb: that
    // class implements no JsonSerializable, so encoding it yields `{}` and the
    // assertion would hold against a no-op scrubber.
    $metadata = $scrubbed->getBreadcrumbs()[0]->getMetadata();

    expect(json_encode($metadata))->not->toContain('falsified');
    expect($metadata['prompt'])->toBe('[redacted]');
});

test('tags and contexts are scrubbed', function (): void {
    $event = Event::createEvent();
    $event->setTags(['candidate_ref' => 'acme-672']);
    $event->setContext('delivery', ['payload' => ['answer' => 'NESTED-LEAK']]);

    $scrubbed = SentryScrubber::handle($event);

    expect(json_encode($scrubbed->getTags()))->not->toContain('acme-672');
    expect(json_encode($scrubbed->getContexts()))->not->toContain('NESTED-LEAK');
});

test('an exception message is redacted, not transmitted as thrown', function (): void {
    // The standards forbid confidential content in an exception message
    // outright. A message interpolating transcript text has no KEY for the
    // denylist to catch, which is the same reason the free-text pass exists on
    // the TS side.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException('entry link https://beai.test/interview/TOKENLEAK rejected')
        ),
    ]);

    $scrubbed = SentryScrubber::handle($event);

    // getValue(), not json_encode(): ExceptionDataBag encodes to `{}` too.
    expect($scrubbed->getExceptions()[0]->getValue())->not->toContain('TOKENLEAK');
});

test('hyphenated and acronym-leading keys are denied too', function (): void {
    // `X-Api-Key` lowercases to `x-api-key`: in no list, and `_key` does not
    // match because the separator is a hyphen. `APIKey` has no lowercase char
    // before the uppercase one, so the camelCase split never fires either.
    // Both are the identical leak class as camelCase, one separator over.
    $event = scrubbedEvent([
        'X-Api-Key' => 'HEADER-LEAKED',
        'APIKey' => 'ACRONYM-LEAKED',
        'SSOToken' => 'ACRONYM-LEAKED-2',
    ]);

    expect(json_encode($event->getExtra()))->not->toContain('LEAKED');
});

test('the raw request body never reaches the sink', function (): void {
    // `request.data` is a RAW STRING whenever the parsed body is empty and the
    // Content-Type is not byte-for-byte `application/json` — RequestIntegration
    // compares with `===`, so `application/json; charset=utf-8` misses and falls
    // through to the raw body. `max_request_body_size` defaults to `medium` and
    // config/sentry.php does not disable it. A failing POST /api/auth/login
    // therefore filed a plaintext password. `scrub()` redacts by KEY and a raw
    // string has none, which is the reasoning that already dropped
    // query_string/cookies/headers, stopping one key short.
    $event = scrubbedEvent([], [
        'url' => 'https://api.beai.test/api/auth/login',
        'data' => '{"email":"mario.rossi@example.test","password":"hunter2"}',
    ]);

    $encoded = json_encode($event->getRequest());

    expect($encoded)->not->toContain('hunter2');
    expect($encoded)->not->toContain('mario.rossi@example.test');
});

test('the event message goes through the free-text redactor too', function (): void {
    // Breadcrumb messages get it, exception values get it, the event's own
    // message got nothing — one `Sentry\captureMessage()` away from mattering,
    // with the redactor that would catch it already in the file.
    $event = Event::createEvent();
    $event->setMessage('sso exchange failed for mario.rossi@example.test');

    $scrubbed = SentryScrubber::handle($event);

    expect((string) $scrubbed->getMessage())->not->toContain('mario.rossi@example.test');
});

test('a PARAMETERISED message is redacted in its params, not just its template', function (): void {
    // The template never holds the data; the params do. And `setMessage()` with
    // two arguments nulls `formatted`, after which the SDK rebuilds it from
    // template + params downstream of this callback — so redacting the template
    // alone is a no-op that looks like a fix.
    $event = Event::createEvent();
    $event->setMessage('sso exchange failed for %s with token %s', [
        'mario.rossi@example.test',
        'https://beai.test/interview/SECRETLEAK',
    ]);

    $scrubbed = SentryScrubber::handle($event);
    $encoded = json_encode([$scrubbed->getMessage(), $scrubbed->getMessageParams()]);

    expect($encoded)->not->toContain('mario.rossi@example.test');
    expect($encoded)->not->toContain('SECRETLEAK');
});

test('email keys are denied in the plural and the compound too', function (): void {
    // `excerpts` was already pluralised in the denylist; the same reasoning
    // stopped one word short of the address that ruling 8 makes the global
    // identity key.
    $event = scrubbedEvent([
        'email_address' => 'mario.rossi@example.test',
        'emails' => ['anna.bianchi@example.test'],
        'emailAddress' => 'carla.verdi@example.test',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('mario.rossi@example.test');
    expect($encoded)->not->toContain('anna.bianchi@example.test');
    expect($encoded)->not->toContain('carla.verdi@example.test');
});

test('free text under a NON-denied key is redacted too', function (): void {
    // The file already argues this for `request.data`: a raw string has no key
    // for a key denylist to catch. That reasoning was applied to one field and
    // withheld from extra/contexts/tags/breadcrumb metadata — and a provider
    // error message is exactly the string nobody here controls.
    $event = scrubbedEvent([
        'user_input' => 'my address is carla.verdi@example.test',
        'reason' => 'link https://beai.test/interview/SECRETLEAK2 rejected',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('carla.verdi@example.test');
    expect($encoded)->not->toContain('SECRETLEAK2');
});

test('a TRANSACTION event is scrubbed, not only an error event', function (): void {
    // Sentry dispatches by event TYPE: `before_send` for errors,
    // `before_send_transaction` for transactions, which fell back to the SDK's
    // identity pass-through. RequestIntegration registers as a global processor
    // with no type gate, so it sets url/query_string on transactions too — and
    // the moment SENTRY_TRACES_SAMPLE_RATE is set, a sampled
    // `GET /api/sso/exchange?token=<jwt>` filed the token unscrubbed. That is
    // verbatim the scenario scrubRequest() exists for: the error path was
    // closed and the performance path was left open.
    $event = Event::createTransaction();
    $event->setRequest([
        'url' => 'https://api.beai.test/api/sso/exchange?token=eyJLEAKED',
        'query_string' => 'token=eyJLEAKED',
    ]);

    expect(json_encode(SentryScrubber::handle($event)->getRequest()))->not->toContain('LEAKED');
});

test('the config wires the scrubber on BOTH dispatch paths', function (): void {
    // Asserting `before_send` alone read as proving the wiring was complete
    // while a whole event type walked past it.
    expect(config('sentry.before_send'))->toBe([SentryScrubber::class, 'handle']);
    expect(config('sentry.before_send_transaction'))->toBe([SentryScrubber::class, 'handle']);
});

test('a scoped package path in a stack survives the address pattern', function (): void {
    // `redactFreeText` now runs on every string value, so an over-broad local
    // part turns a vendor path into `[redacted]`. Not a leak — just an error
    // reporter that can no longer say where anything broke, which this class's
    // own docblock calls the worse outcome.
    $stack = 'at vendor/laravel/framework/src/Illuminate/Support/helpers.php:120';

    $event = scrubbedEvent(['trace' => $stack]);

    expect($event->getExtra()['trace'])->toBe($stack);
});

test('transaction SPANS are scrubbed — they carry the AI messages', function (): void {
    // `before_send_transaction` is wired to this callback, but __invoke never
    // walked `getSpans()`. Laravel's AiIntegration sets `gen_ai.input.messages`
    // and `gen_ai.output.messages` as span DATA, and in this product those
    // messages are a candidate's transcribed answers. `candidate_ref` and
    // `email` are already denied keys — the denylist knew, nothing walked here.
    $span = new Span;
    $span->setDescription('POST https://beai.test/interview/TOKENLEAK');
    $span->setData([
        'gen_ai.input.messages' => '[{"role":"user","content":"I led the migration"}]',
        'candidate_ref' => 'CR-9',
        'email' => 'mario.rossi@example.test',
    ]);

    $transaction = Event::createTransaction();
    $transaction->setSpans([$span]);

    $scrubbed = SentryScrubber::handle($transaction);
    $encoded = json_encode($scrubbed->getSpans()[0]->getData());

    // The key this test is NAMED for, asserted first. The previous version
    // checked only the sibling keys `candidate_ref` and `email` — both caught by
    // unrelated rules — while the transcript sat in its own fixture, green.
    expect($encoded)->not->toContain('I led the migration');
    expect($encoded)->not->toContain('CR-9');
    expect($encoded)->not->toContain('mario.rossi@example.test');
    expect((string) $scrubbed->getSpans()[0]->getDescription())->not->toContain('TOKENLEAK');
});

test('the request url keeps its PATH — only the query is cut', function (): void {
    // `scrub()` runs first and `url` is not a denied key, so its value went
    // through the free-text pass, which collapses any URL to scheme://host.
    // redactUrl() then ran on a string that could no longer contain a `?`,
    // making it unreachable in intent and leaving every error event reporting a
    // bare hostname. Which endpoint failed is the single most useful field on
    // the event, and this class's docblock calls an unusable error reporter the
    // worse outcome.
    $event = scrubbedEvent([], [
        'url' => 'https://api.beai.test/api/sso/exchange?token=abc.def.ghi',
    ]);

    $url = $event->getRequest()['url'];

    expect($url)->toBe('https://api.beai.test/api/sso/exchange');
});

test('the keys this product actually uses for candidate speech are denied', function (): void {
    // `text` is the real column name: `utterances.text`, UtteranceController's
    // validated field, and HeygenProvider's transcript shape. The list covered
    // `transcript`/`utterance`/`answer`/`prompt`/`content` and stopped short of
    // the one the schema uses. `explanation` is the LLM's behavioural rationale
    // on indicator_scores — `payload` covers the webhook path, a bare
    // `explanation` was covered by nothing.
    //
    // The PLURALS matter for the same reason `excerpts` was already pluralised:
    // `['utterances' => $session->utterances->toArray()]` recurses into numeric
    // keys and every `text` inside rides out.
    $event = scrubbedEvent([
        'text' => 'Nel mio ultimo progetto ho gestito un conflitto nel team.',
        'explanation' => 'The candidate described de-escalating a peer dispute.',
        'utterances' => [['speaker' => 'candidate', 'text' => 'ho gestito un conflitto']],
        'answers' => ['I led the migration'],
        'prompts' => ['Score this: I led the migration'],
        'transcripts' => ['full transcript body'],
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('conflitto');
    expect($encoded)->not->toContain('de-escalating');
    expect($encoded)->not->toContain('I led the migration');
    expect($encoded)->not->toContain('full transcript body');
});

test('span TAGS are scrubbed, not only span data', function (): void {
    // TransactionItem::serializeSpan() transmits getTags(), and
    // collectV2Attributes() folds tags and data into the SAME attributes bag —
    // one walked, one not. Event-level tags are already scrubbed; this is that
    // treatment withheld one object down.
    $span = new Span;
    $span->setTags(['candidate_ref' => 'CR-9', 'route' => '/participants']);

    $transaction = Event::createTransaction();
    $transaction->setSpans([$span]);

    $tags = SentryScrubber::handle($transaction)->getSpans()[0]->getTags();

    expect(json_encode($tags))->not->toContain('CR-9');
    // The non-sensitive tag has to survive: an unusable error reporter is the
    // worse outcome this class keeps naming.
    expect($tags['route'])->toBe('/participants');
});

test('a url with userinfo is redacted like free text redacts one', function (): void {
    // redactUrl() cut at `?`/`#` only, while redactFreeText()'s URL pass rebuilds
    // scheme://host through parse_url() and drops userinfo. Two passes claiming
    // the same promise, disagreeing on the same input.
    $event = scrubbedEvent([], [
        'url' => 'https://user:hunter2@api.beai.test/api/sso/exchange?token=abc',
    ]);

    expect($event->getRequest()['url'])->not->toContain('hunter2');
});

test('dotted OpenTelemetry keys reach the denylist', function (): void {
    // The normalizer handled hyphens and camelCase but never dots, so every
    // OTel-style key missed both the exact list and the convention suffixes.
    // `authorization`, `content` and `transcript` are all IN the list — the list
    // knew, the normalizer could not reach them.
    $event = scrubbedEvent([
        'auth.token' => 'TOKENLEAK',
        'user.content' => 'CONTENTLEAK',
        'request.transcript' => 'TRANSCRIPTLEAK',
        'http.request.header.authorization' => 'AUTHLEAK',
    ]);

    expect(json_encode($event->getExtra()))->not->toContain('LEAK');
});

test('a key ending in _messages is denied — the AI payload is a JSON STRING', function (): void {
    // AiIntegration::truncateMessages() json_encodes, so the conversation
    // arrives as a string under a single key. A string has no keys for the
    // denylist to walk, which is the identical argument that already drops
    // `query_string` and `request.data` wholesale in this same file.
    $event = scrubbedEvent([
        'gen_ai.input.messages' => '[{"role":"user","content":"I led the migration"}]',
        'messages' => '[{"content":"my answer"}]',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('I led the migration');
    expect($encoded)->not->toContain('my answer');
});

test('the http context query string never reaches the sink', function (): void {
    // `request.query_string` is dropped wholesale, but Sentry ALSO populates an
    // `http` context whose `query_string`/`query` carry the same value by a
    // different route. `contexts` is walked by key, and `query` is in no list —
    // so the SSO token that scrubRequest() exists to cut walked out one context
    // over from where it was cut.
    $event = Event::createEvent();
    $event->setContext('http', [
        'url' => 'https://api.beai.test/api/sso/exchange?token=eyJLEAKED',
        'query' => 'token=eyJLEAKED',
        'method' => 'GET',
    ]);

    $scrubbed = SentryScrubber::handle($event);
    $encoded = json_encode($scrubbed->getContexts());

    expect($encoded)->not->toContain('LEAKED');
    // The method is diagnostic and must survive — an unusable error reporter is
    // the outcome this class calls worse than a scrubbed one.
    expect($scrubbed->getContexts()['http']['method'])->toBe('GET');
});

test('an http context that scrubs down to NOTHING does not leave the original behind', function (): void {
    // `Event::setContext()` is a no-op on an empty array (`if (!empty($data))`,
    // Event.php:640) while `setRequest()` assigns unconditionally. `scrubRequest()`
    // works by REMOVING keys, so a context made up only of removed keys reduces
    // to `[]`, the setter declines it, and the ORIGINAL stays on the event.
    //
    // The previous test could never catch this: its fixture carried `method`,
    // which survives, so the array was never empty and the one failure mode of
    // the fix went unexercised.
    $event = Event::createEvent();
    $event->setContext('http', ['query_string' => 'token=eyJLEAKED']);

    $scrubbedA = SentryScrubber::handle($event);

    expect(json_encode($scrubbedA->getContexts()))->not->toContain('LEAKED');

    $event2 = Event::createEvent();
    $event2->setContext('http', [
        'cookies' => ['session' => 'eyJLEAKED'],
        'headers' => ['authorization' => 'Bearer LEAKED'],
    ]);

    expect(json_encode(SentryScrubber::handle($event2)->getContexts()))->not->toContain('LEAKED');
});

test('exception stacktrace frame vars are scrubbed — they are function ARGUMENTS', function (): void {
    // `FrameBuilder::getFunctionArguments()` reflects `$backtraceFrame['args']`
    // into named parameters and `StacktraceFrameSeralizerTrait` emits them as
    // `vars`. So a method taking `string $transcript` puts the candidate's words
    // on the wire under the key `transcript` — a word already on DENIED_KEYS.
    // The denylist knew; nothing walked frames.
    //
    // Live rather than theoretical: this needs `zend.exception_ignore_args=Off`,
    // and the container ships no active php.ini, so the built-in default applies
    // and arguments ARE captured.
    $frame = new Frame('scoreInterview', '/app/ScoreInterview.php', 42);
    $frame->setVars([
        'transcript' => 'CANDIDATE SAID: I once falsified a report',
        'token' => 'eyJLEAKED',
        'line' => 42,
    ]);

    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('boom'), new Stacktrace([$frame])),
    ]);

    $vars = SentryScrubber::handle($event)->getExceptions()[0]->getStacktrace()?->getFrames()[0]->getVars();
    $encoded = json_encode($vars);

    expect($encoded)->not->toContain('falsified');
    expect($encoded)->not->toContain('LEAKED');
    // Non-sensitive frame context survives.
    expect($vars['line'])->toBe(42);
});

test('the PLURAL credential keys are denied too', function (): void {
    // The content keys were pluralised (`transcripts`, `answers`, `excerpts`,
    // `utterances`) and the credential keys never were — same list, same rule,
    // half applied.
    $event = scrubbedEvent([
        'tokens' => ['T1LEAK'],
        'api_keys' => ['K1LEAK'],
        'passwords' => ['P1LEAK'],
        'secrets' => ['S1LEAK'],
        'cookies' => ['session' => 'SESSLEAK'],
        // A header name that is in NO list and matches NO convention, so only
        // the wholesale `headers` drop can catch it. With `authorization` here
        // the assertion went green off a key that was already denied before this
        // commit — deleting `'headers'` from the list left it passing.
        'headers' => ['x-trace-context' => 'sess=HLEAK'],
    ]);

    expect(json_encode($event->getExtra()))->not->toContain('LEAK');
});

test('the plural is never weaker than the singular', function (): void {
    // `stripe_api_keys` was ALLOWED while `stripe_api_key` was denied: the list
    // gained `api_keys` and the CONVENTION did not, so the plural form of a
    // credential was strictly weaker than its singular — the defect the plural
    // entries exist to close, reproduced one rule over. `header` had the mirror
    // asymmetry: denied as `headers`, allowed on its own.
    $event = scrubbedEvent([
        'stripe_api_keys' => ['sk_live_LEAKED'],
        'provider_api_keys' => ['openai' => 'sk-LEAKED2'],
        'header' => 'Cookie: sess=LEAKED3',
    ]);

    expect(json_encode($event->getExtra()))->not->toContain('LEAKED');
});

test('an address used as an array KEY is redacted too', function (): void {
    // `isDenied()` inspects what a key is CALLED; `redactFreeText()` inspects
    // what a value CONTAINS. Nothing inspected what a key contains — so the
    // class denied `email`, `emails`, `email_address` and `emailAddress` by
    // name, then handed the address over the moment it moved one position left.
    // Keying a map by address is the ordinary way to write a delivery-result map.
    $event = scrubbedEvent([
        'delivery_results' => ['mario.rossi@example.test' => 'bounced'],
    ]);

    expect(json_encode($event->getExtra()))->not->toContain('mario.rossi@example.test');
});

test('request.env goes with the user context, not without it', function (): void {
    // RequestIntegration populates `env.REMOTE_ADDR` and builds the user bag
    // from THAT SAME value. The SDK treats them as one datum; dropping the user
    // and keeping env kept the IP by another name.
    $event = scrubbedEvent([], [
        'url' => 'https://api.beai.test/api/health',
        'method' => 'POST',
        'env' => ['REMOTE_ADDR' => '203.0.113.77'],
    ]);

    expect(json_encode($event->getRequest()))->not->toContain('203.0.113.77');
});

test('a non-string span tag survives as JSON, not as the word Array', function (): void {
    // A bare `(string)` cast on an array raises "Array to string conversion"
    // INSIDE before_send — a failure in the error reporter itself — and emits
    // the literal "Array". Event-level tags already handle this; the span path
    // did not.
    $span = new Span;
    $span->setTags(['route' => 'x', 'attempts' => ['a', 'b']]);

    $transaction = Event::createTransaction();
    $transaction->setSpans([$span]);

    $tags = SentryScrubber::handle($transaction)->getSpans()[0]->getTags();

    // The real value, not merely "not the literal Array": excluding one string
    // is not the contract, and replacing the cast with `""` kept it green.
    //
    // The ELEMENTS are redacted, and that REVERSES what this test asserted. A
    // list element has no key — nothing can deny it and nothing in the free-text
    // pass can recognise it — and both Nuxt mirrors have cut these from the
    // start while this class cut them only inside a decoded document. The same
    // transcript shipped as `['lines' => $lines]` and was redacted as the
    // json_encode of it; two shapes, two answers, against this file's own
    // invariant.
    //
    // The COST is stated rather than hidden: a harmless `['a','b']` goes too.
    expect($tags['attempts'])->toBe('["[redacted]","[redacted]"]');
    expect($tags['route'])->toBe('x');
});

test('an address used as a span data or tag KEY does not survive the merge', function (): void {
    // `scrub()` RENAMES an address-bearing key rather than overwriting it. That
    // is safe for `setExtra()`/`setTags()`, which assign — and unsafe for
    // `Span::setData()`/`setTags()`, which array_merge. Merge cannot remove, so
    // the renamed copy was appended and the ORIGINAL key kept its value: the
    // address stayed on the wire as a key name beside a `[redacted]` twin.
    $span = new Span;
    $span->setData(['mario.rossi@example.test' => 'delivered', 'op' => 'http']);
    $span->setTags(['anna.bianchi@example.test' => 'bounced', 'route' => '/x']);

    $transaction = Event::createTransaction();
    $transaction->setSpans([$span]);

    $scrubbed = SentryScrubber::handle($transaction)->getSpans()[0];
    $encoded = json_encode([$scrubbed->getData(), $scrubbed->getTags()]);

    expect($encoded)->not->toContain('mario.rossi@example.test');
    expect($encoded)->not->toContain('anna.bianchi@example.test');
    // The diagnostic half survives the rebuild.
    expect($scrubbed->getData()['op'])->toBe('http');
    expect($scrubbed->getTags()['route'])->toBe('/x');
});

test('two redacted keys do not collapse into one', function (): void {
    // Both addresses normalise to the same marker, and a plain assignment would
    // drop one — a delivery map of five bounced addresses reporting one. Silent
    // loss of the diagnostic context this class argues is worth keeping.
    $event = scrubbedEvent([
        'delivery' => [
            'mario.rossi@example.test' => 'bounced',
            'anna.bianchi@example.test' => 'delivered',
        ],
    ]);

    $delivery = $event->getExtra()['delivery'];

    expect(json_encode($delivery))->not->toContain('@example.test');
    expect($delivery)->toHaveCount(2);
});

test('the span rebuild keeps its origin', function (): void {
    // `serializeSpan()` emits `getOrigin() ?? 'manual'`, so a rebuild that drops
    // it relabels every `auto.db.sql` span as hand-instrumented.
    $span = new Span;
    $span->setOrigin('auto.db.sql');

    $transaction = Event::createTransaction();
    $transaction->setSpans([$span]);

    expect(SentryScrubber::handle($transaction)->getSpans()[0]->getOrigin())->toBe('auto.db.sql');
});

test('a sibling that is already the marker does not swallow its neighbour', function (): void {
    // The dedupe guard was gated on the key having CHANGED, so a sibling already
    // spelled `[redacted]` skipped it and overwrote — the same collapse the
    // guard exists to prevent, one ordering over.
    $event = scrubbedEvent([
        'delivery' => ['mario.rossi@example.test' => 'first', '[redacted]' => 'second'],
    ]);

    expect($event->getExtra()['delivery'])->toHaveCount(2);
});

test('an OBJECT value is walked, not waved through', function (): void {
    // `scrub()` recursed on arrays and free-text-redacted strings; everything
    // else fell through untouched. sentry-laravel passes `$logEntry->context`
    // RAW into breadcrumb metadata and the serializer JSON-encodes it, so an
    // Eloquent model emits its whole attribute bag — `email`, `display_name`
    // and `candidate_ref`, all three on DENIED_KEYS. The denylist knew; nothing
    // walked into the object.
    $participant = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return [
                'email' => 'candidate@example.test',
                'display_name' => 'Jane Roe',
                'candidate_ref' => 'REF-1',
                'project_id' => 7,
            ];
        }
    };

    $event = scrubbedEvent(['participant' => $participant]);
    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('candidate@example.test');
    expect($encoded)->not->toContain('Jane Roe');
    expect($encoded)->not->toContain('REF-1');
    // The non-sensitive attribute survives the walk.
    expect($encoded)->toContain('project_id');
});

test('the url fragment is cut like the query is', function (): void {
    // `redactUrl()` cuts at `?` AND `#`. `scrubRequest()` dropped query_string
    // and query for the stated reason that both carry `?token=` — `fragment` is
    // the sibling key carrying the same data under a third name.
    $event = scrubbedEvent([], [
        'url' => 'https://api.beai.test/api/health',
        'fragment' => 'token=JWTSECRETLEAK',
    ]);

    expect(json_encode($event->getRequest()))->not->toContain('LEAK');
});

test('the span rebuild carries every field it copies by hand', function (): void {
    // Eleven fields copied one by one is the shape where FORGETTING one is the
    // characteristic failure — and the first attempt did forget `sampled` and
    // `origin`. Dropping `parent_span_id` orphans every child span and flattens
    // the trace tree silently, with nothing in the suite to say so.
    $parentId = SpanId::generate();
    $spanId = SpanId::generate();
    $traceId = TraceId::generate();

    $context = new SpanContext;
    $context->setTraceId($traceId);
    $context->setSpanId($spanId);
    $context->setParentSpanId($parentId);
    $context->setOp('db.sql.query');
    $context->setStatus(SpanStatus::ok());
    $context->setOrigin('auto.db.sql');
    $context->setStartTimestamp(1000.0);
    $context->setEndTimestamp(1002.5);

    $transaction = Event::createTransaction();
    $transaction->setSpans([new Span($context)]);

    $rebuilt = SentryScrubber::handle($transaction)->getSpans()[0];

    expect((string) $rebuilt->getTraceId())->toBe((string) $traceId);
    expect((string) $rebuilt->getParentSpanId())->toBe((string) $parentId);
    // `spanId` above all: the constructor MINTS A NEW ONE when the context has
    // none, so dropping it neither throws nor blanks — it silently repoints the
    // span while every child's parent_span_id still names the old id. Every
    // child orphans and the trace tree flattens with nothing to say so.
    expect((string) $rebuilt->getSpanId())->toBe((string) $spanId);
    expect($rebuilt->getOp())->toBe('db.sql.query');
    expect((string) $rebuilt->getStatus())->toBe((string) SpanStatus::ok());
    expect($rebuilt->getOrigin())->toBe('auto.db.sql');
    expect($rebuilt->getStartTimestamp())->toBe(1000.0);
    expect($rebuilt->getEndTimestamp())->toBe(1002.5);
});

test('a structured message param is key-walked before it is flattened', function (): void {
    // `stringify()` json_encodes and hands the result to `redactFreeText()`,
    // which strips only URLs and addresses — DENIED_KEYS never ran. The tag path
    // does it the right way round: scrub() FIRST, stringify() second. The old
    // `(string)` cast collapsed an array to "Array", lossy but safe; serializing
    // it traded a warning for a leak.
    $event = Event::createEvent();
    $event->setMessage('scoring failed for %s', [[
        'display_name' => 'Mario Rossi',
        'candidate_ref' => 'CR-9',
        'transcript' => 'I led the team',
    ]]);

    $encoded = json_encode(SentryScrubber::handle($event)->getMessageParams());

    expect($encoded)->not->toContain('Mario Rossi');
    expect($encoded)->not->toContain('CR-9');
    expect($encoded)->not->toContain('I led the team');
});

test('an enum or timestamp in log context stays readable', function (): void {
    // The is_object branch is right to fail closed, but its test was
    // `is_array($decoded)` — so any object serializing to a SCALAR went dark.
    // Backed enums and Carbon instances are the two most common non-scalars in
    // a Laravel log context, neither carries PII, and both reported
    // `[redacted]`. That is the diagnostic loss this class calls the worse
    // outcome.
    $event = scrubbedEvent([
        'stage' => EvaluationStatus::Completed,
        'occurred' => CarbonImmutable::parse('2026-09-11T10:00:00Z'),
        'count' => 3,
    ]);

    $extra = $event->getExtra();

    expect($extra['stage'])->not->toBe('[redacted]');
    expect($extra['occurred'])->not->toBe('[redacted]');
    expect($extra['count'])->toBe(3);
});

test('a recursive OBJECT does not take before_send down with it', function (): void {
    // Renamed to what it actually proves. The old name promised "a recursive or
    // malformed VALUE" and only ever built a recursive `stdClass` — the object
    // path, which is safe because it goes through `encode()` with
    // `JSON_PARTIAL_OUTPUT_ON_ERROR`. The ARRAY path had no guard at all and
    // segfaulted the process; it has its own test below now.
    //
    // Bare `json_encode()` emits E_WARNING on a recursive reference, and
    // Laravel turns any E_WARNING into a thrown ErrorException — the error
    // reporter failing while reporting an error. Invalid UTF-8 blanked the
    // whole value instead of the offending byte.
    $recursive = new stdClass;
    $recursive->self = $recursive;

    $event = scrubbedEvent([
        'loop' => $recursive,
        'bad_utf8' => "valid\xB1tail",
        'ratio' => 1.0,
    ]);

    $extra = $event->getExtra();

    expect($extra)->toHaveKey('loop');
    // The bad byte is substituted, not the whole string dropped.
    expect($extra['bad_utf8'])->toContain('valid');
    expect($extra['ratio'])->toBe(1.0);
});

test('the EVENT-level stacktrace is walked too, not only the exception one', function (): void {
    // `Event` carries a second stacktrace, serialized by `EventItem` through the
    // same frame serializer that emits `vars`. Dormant — `attach_stacktrace`
    // defaults false and is not a key in config/sentry.php — but that is the
    // same "one config line from being live" this class refused to accept for
    // spans.
    $frame = new Frame('scoreInterview', '/app/ScoreInterview.php', 42);
    $frame->setVars(['transcript' => 'candidate said X', 'email' => 'mario@x.test']);

    $event = Event::createEvent();
    $event->setStacktrace(new Stacktrace([$frame]));

    $vars = SentryScrubber::handle($event)->getStacktrace()?->getFrames()[0]->getVars();

    expect(json_encode($vars))->not->toContain('candidate said X');
    expect(json_encode($vars))->not->toContain('mario@x.test');
});

test('the formatted message is not a pre-scrub rendering of the params', function (): void {
    // `formatted` is the string with the RAW param values interpolated. Handing
    // it back defeated the param scrubbing: `user Jane Doe failed` went out
    // beside a `[redacted]` param. EventItem rebuilds from
    // `vsprintf(getMessage(), getMessageParams())`, so null is the SAFE branch.
    $event = Event::createEvent();
    $event->setMessage('user %s failed', ['Jane Doe'], 'user Jane Doe failed');

    $scrubbed = SentryScrubber::handle($event);

    expect($scrubbed->getMessageFormatted())->toBeNull();
});

test('an address in a URL PATH is redacted, not just one in a query', function (): void {
    // redactUrl() cut `?`, `#` and userinfo and left
    // `/api/participants/jane.doe@acme.test/transcript` whole. A URL is
    // client-controlled, so a 404 on a hand-typed path is enough.
    $event = scrubbedEvent([], [
        'url' => 'https://api.beai.test/api/participants/jane.doe@acme.test/transcript',
    ]);

    expect($event->getRequest()['url'])->not->toContain('jane.doe@acme.test');
});

test('the candidate-identifying plurals are denied — the third half of the rule', function (): void {
    // The plural rule was applied to the credential keys and to the content keys
    // and skipped here. `redactFreeText('CR-99')` has nothing to grip on.
    $event = scrubbedEvent([
        'candidate_refs' => ['CR-99'],
        'display_names' => ['Ada Lovelace'],
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('CR-99');
    expect($encoded)->not->toContain('Ada Lovelace');
});

test('a JSON DOCUMENT under an unlisted key is scrubbed as the document it is', function (string $key): void {
    // The denylist reads KEYS and a serialised body has none. `payload` happens
    // to be on the list; `body`, `raw`, `detail` are not, and the set of names
    // nobody thought of is unbounded — several earlier rounds of this class were
    // spent adding the name someone missed. So the rule is the document, not the
    // key. Both Nuxt mirrors carry the identical rule in `scrubJsonString`.
    $event = scrubbedEvent([
        $key => '{"candidate_ref":"CR-99","display_name":"Ada Lovelace"}',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('CR-99');
    expect($encoded)->not->toContain('Ada Lovelace');
})->with(['body', 'raw', 'detail', 'result', 'note', 'response_body']);

test('a harmless JSON document stays readable', function (): void {
    // The positive twin. Cutting every JSON-looking string outright would be the
    // destruction this class calls the worse outcome — `{"status":"ok"}` is
    // exactly the diagnostic an operator needs.
    $event = scrubbedEvent([
        'body' => '{"status":"ok","count":3}',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->toContain('ok');
    expect($encoded)->toContain('3');
});

test('a string that only LOOKS like JSON still gets the free-text pass', function (): void {
    // `{` alone is not a document. The decode fails, and the fallback must be the
    // free-text redactor rather than the marker — otherwise every brace in a log
    // line becomes `[redacted]`.
    $event = scrubbedEvent([
        'note' => '{not json at all, but mentions jane@acme.test}',
    ]);

    $encoded = json_encode($event->getExtra());

    expect($encoded)->not->toContain('jane@acme.test');
    expect($encoded)->toContain('not json at all');
});

test('the URL and query keys are denied OUTSIDE the request branch too', function (string $key): void {
    // `unset()` on the request and the `http` context covered those two carriers
    // and nothing else. The same names under `extra`, `tags` or any other
    // context reach the generic key walk, where only the denylist can stop them
    // — and `redactFreeText('Jane Doe')` has nothing to grip on.
    $event = scrubbedEvent([$key => 'Jane Doe']);

    expect(json_encode($event->getExtra()))->not->toContain('Jane Doe');
})->with(['q', 'query', 'query_string', 'fragment', 'env']);

test('a denied name is found in ANY segment run, not only the last', function (string $key): void {
    // The old check read the LAST `_`-delimited segment, so `ref`, `name`,
    // `hash` and `string` decided the answer and every compound spelling walked.
    // A denied name sitting anywhere inside a compound key is still that name.
    $event = scrubbedEvent([$key => 'SECRET-VALUE-99']);

    expect(json_encode($event->getExtra()))->not->toContain('SECRET-VALUE-99');
})->with([
    'request_candidate_ref',
    'user_display_name',
    'apiKey_hash',
    'http_query_string',
]);

test('an OVERSIZED JSON-looking string is still scrubbed', function (): void {
    // NOT a test of the length gate. That gate is a COST guard: past it the
    // free-text pass runs on the raw string, under it the document is decoded
    // and re-encoded — and `scrubJsonString` returns a string either way, so
    // both paths hand back a string with the address redacted. No behaviour
    // assertion can separate them, and the code says so rather than this test
    // pretending otherwise.
    //
    // What this DOES pin is that size never becomes an escape hatch.
    $oversized = '{"note":"'.str_repeat('a', 100_001).' jane@acme.test"}';

    $event = scrubbedEvent(['body' => $oversized]);

    expect(json_encode($event->getExtra()))->not->toContain('jane@acme.test');
});

test('a denied name is found on the PREFIX side too, not only as a suffix', function (string $key): void {
    // The Nuxt mirrors walk every contiguous segment RUN; this class walked
    // suffixes only, so `candidate_ref_original` and `display_name_raw` — both
    // ordinary shapes — were open on the prefix side. A leak class present on
    // both sides gets the same answer on both sides.
    $event = scrubbedEvent([$key => 'SECRET-VALUE-99']);

    expect(json_encode($event->getExtra()))->not->toContain('SECRET-VALUE-99');
})->with([
    'candidate_ref_original',
    'display_name_raw',
    'access_token_issued_at',
    'query_string_source',
]);

test('MAX_DENIED_SEGMENTS still bounds the LONGEST denied key', function (): void {
    // The bound is a hand-written constant here because PHP cannot derive it in
    // a `const`, and a hand-written bound drifts. Adding a three-segment entry
    // without raising it would put that entry silently out of the run walk's
    // reach — denied on paper, unreachable in fact.
    $source = file_get_contents(app_path('Support/Observability/SentryScrubber.php'));

    expect($source)->toBeString();

    preg_match('/private const DENIED_KEYS = \[(.*?)\];/s', (string) $source, $listMatch);

    // COMMENTS STRIPPED FIRST. The block's own prose contains apostrophes —
    // `candidate's`, `AiIntegration's`, `LLM's` — which desync the quote pairing
    // and make the extractor capture the GAPS BETWEEN keys instead of the keys.
    // It still returned 45 strings, so the count looked right and the guard
    // could not fail on the drift it was written to catch.
    $listBody = preg_replace('#//[^\n]*#', '', $listMatch[1] ?? '') ?? '';

    preg_match_all("/'([^']+)'/", $listBody, $keyMatch);

    $longest = 0;

    foreach ($keyMatch[1] ?? [] as $key) {
        $longest = max($longest, substr_count($key, '_') + 1);
    }

    preg_match('/private const MAX_DENIED_SEGMENTS = (\d+);/', (string) $source, $boundMatch);

    expect($longest)->toBeGreaterThan(0)
        ->and((int) ($boundMatch[1] ?? 0))->toBeGreaterThanOrEqual($longest);
});

test('a denied key inside a Guzzle EXCEPTION VALUE is redacted', function (): void {
    // The carrier that matters most is not a whole-string document, so
    // `scrubJsonString()` never fires on it: an HTTP-client exception embeds the
    // response body in prose. `redactFreeText()` cut URLs and addresses and had
    // nothing to say about a denied KEY sitting in the middle of a sentence.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException(
                'Client error: `POST /api/score` resulted in a `422` response: '
                .'{"candidate_ref":"CR-99","display_name":"Ada Lovelace"}'
            )
        ),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('CR-99');
    expect($value)->not->toContain('Ada Lovelace');
    // And the DIAGNOSTIC survives — the status code is why anyone opens this.
    expect($value)->toContain('422');
});

test('a denied key inside a BREADCRUMB message is redacted', function (): void {
    // Third carrier, same shape. A fetch breadcrumb records the response body as
    // text, and it is neither a document nor a URL.
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_ERROR,
            Breadcrumb::TYPE_HTTP,
            'http',
            'response {"candidate_ref":"CR-99","display_name":"Ada Lovelace"} for 422'
        ),
    ]);

    $message = SentryScrubber::handle($event)->getBreadcrumbs()[0]->getMessage();

    expect($message)->not->toContain('CR-99');
    expect($message)->not->toContain('Ada Lovelace');
    expect($message)->toContain('422');
});

test('a TRUNCATED embedded document is still scrubbed', function (): void {
    // Why pairs and not a decode: Sentry and most HTTP clients cap the body they
    // attach, so the embedded document routinely arrives cut off mid-value. A
    // decode of that fails, and failing would hand the whole string back.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException(
                'response truncated: {"candidate_ref":"CR-99","display_name":"Ada Love'
            )
        ),
    ]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())->not->toContain('CR-99');
});

test('an ORDINARY diagnostic key is NOT collateral of the run walk', function (string $key): void {
    // The run walk widened `isDenied` to any contiguous segment, and a bare word
    // like `content` then matched inside every compound — redacting
    // `content_type` and `content_length`, which are exactly the diagnostics this
    // class says it must not destroy. Single-word entries match only the whole
    // key or the LAST segment now.
    //
    // `app_env` is deliberately NOT in this set. Its last segment IS `env`, so
    // the last-segment rule denies it — and that is the ruling: nothing here
    // distinguishes Laravel's harmless `app.env` from a request env dump, and
    // denial is the safe side of that ambiguity. The class comment says the
    // same; if you change one, change both.
    $event = scrubbedEvent([$key => 'application/json']);

    expect($event->getExtra()[$key] ?? null)->toBe('application/json');
})->with(['content_type', 'content_length', 'contentType']);

test('a denied key holding STRUCTURE is redacted, not only a scalar', function (string $blob): void {
    // The first version of this pass matched only SCALAR values, so a denied key
    // holding structure passed through untouched — and structure is exactly what
    // the worst keys hold. `transcript` and `payload` are a candidate's spoken
    // answers, which is the single thing this class exists to stop.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException('Client error: `POST /api/score` resulted in a `422` response: '.$blob)
        ),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('TRANSCRIPT-SECRET-99');
    expect($value)->not->toContain('ANSWER-SECRET-99');
})->with([
    '{"transcript":["step one, then", "TRANSCRIPT-SECRET-99"],"status":422}',
    '{"payload":{"note":"step one, then","answer_summary":"ANSWER-SECRET-99"},"status":422}',
    '{"payload":{"note":"step one, then","answers":[{"text":"ANSWER-SECRET-99"}]},"status":422}',
    '{"transcript":["step one, then", "TRANSCRIPT-SECRET-99"',
    // An unterminated STRING value, not an unterminated structure: a different
    // fail-closed branch, and one a truncated body hits just as often.
    '{"transcript":"step one, then TRANSCRIPT-SECRET-99',
]);

test('an UNDENIED field stays readable next to a redacted subtree', function (): void {
    // The positive twin. Redacting the denied subtree must not swallow what
    // follows it — the scanner has to find the END of the value, not give up.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException('422 — {"transcript":["step one, then", "SPEECH-SECRET-99"],"attempt":3,"route":"/score"}')
        ),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('SPEECH-SECRET-99');
    expect($value)->toContain('attempt');
    expect($value)->toContain('3');
});

test('the embedded-pair scanner fails CLOSED when the PCRE engine gives up', function (): void {
    // `preg_match()` returns FALSE on a resource failure, and `!== 1` collapsed
    // "no more keys" and "the engine gave up" into one `break` that returned the
    // raw remainder. Every other pass in this class fails closed on exactly that
    // failure; this one handed back a candidate's answer verbatim.
    //
    // Latent at default limits — the pattern does not exhaust them even on
    // adversarial input — so the failure is forced here rather than provoked.
    $original = ini_get('pcre.backtrack_limit');

    ini_set('pcre.backtrack_limit', '1');

    try {
        $event = scrubbedEvent(['body' => 'oops {"transcript":"SECRET-ANSWER-99"} tail']);

        expect(json_encode($event->getExtra()))->not->toContain('SECRET-ANSWER-99');
    } finally {
        ini_set('pcre.backtrack_limit', $original === false ? '1000000' : $original);
    }
});

test('a denied key behind ESCAPED quotes is redacted', function (string $blob): void {
    // A document nested inside another JSON string arrives escaped —
    // `{\"transcript\":…}`, the ordinary shape once an error body has been
    // serialised twice — and the plain-quote scanner walked straight past it.
    // Single-quoted PHP strings keep the backslash literal, which is what makes
    // these fixtures genuinely escaped rather than merely quoted.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('422 — '.$blob)),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('TRANSCRIPT-SECRET-99');
    expect($value)->not->toContain('ANSWER-SECRET-99');
})->with([
    '{\"transcript\":\"step one, then TRANSCRIPT-SECRET-99\"}',
    '{\"transcript\":[\"step one, then\", \"TRANSCRIPT-SECRET-99\"]}',
    '{\"payload\":{\"note\":\"a, b\",\"answer_summary\":\"ANSWER-SECRET-99\"}}',
]);

test('a key longer than any cap is still tested against the denylist', function (): void {
    // The old `{1,64}` bound was fail-OPEN: a longer key was never tested at all.
    $longKey = str_repeat('x', 60).'_transcript';

    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException('422 — {"'.$longKey.'":"step one, then TRANSCRIPT-SECRET-99"}')
        ),
    ]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())
        ->not->toContain('TRANSCRIPT-SECRET-99');
});

test('the TRANSACTION NAME is scrubbed, like the URL it mirrors', function (): void {
    // Sentry's tracing middleware seeds the transaction with the raw
    // client-controlled path and only replaces it once a ROUTE matches — so on a
    // 404 it stays verbatim. The identical string reached `request.url` and was
    // cut there while this field carried it out intact, and
    // `before_send_transaction` is wired to this same callback precisely so
    // transaction events get scrubbed.
    $event = Event::createEvent();
    $event->setTransaction('/api/participants/jane.doe@acme.test/transcript');

    expect(SentryScrubber::handle($event)->getTransaction())
        ->not->toContain('jane.doe@acme.test');
});

test('an escaped STRUCTURE does not swallow its sibling fields', function (string $blob): void {
    // Fail-closed was never in question; the defect was over-reach. Deducing
    // escaped mode from the VALUE's opener left it false for a structure, so the
    // brace walker read the inner `\"` as non-terminating and ran to the end of
    // the string — taking every sibling field with it. An error reporter that
    // deletes the context around the secret is the outcome this class calls
    // worse than a scrubbed one.
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('422 — '.$blob))]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('TRANSCRIPT-SECRET-99');
    expect($value)->toContain('keep-this');
})->with([
    '{\"transcript\":[\"a, b\", \"TRANSCRIPT-SECRET-99\"],\"other\":\"keep-this\"}',
    '{\"payload\":{\"note\":\"a, b\",\"x\":\"TRANSCRIPT-SECRET-99\"},\"other\":\"keep-this\"}',
]);

test('the FINGERPRINT is scrubbed, the last carrier the walk stopped short of', function (): void {
    // `EventItem` puts the fingerprint straight on the wire. Nothing in `app/`
    // sets one today — and that is the same "dormant, one config line from being
    // live" argument this class already refused for spans and for the event
    // stacktrace. One `configureScope(fn ($s) => $s->setFingerprint([$email]))`
    // and it is live.
    $event = Event::createEvent();
    $event->setFingerprint(['scoring-failure', 'jane.doe@acme.test']);

    $fingerprint = SentryScrubber::handle($event)->getFingerprint();

    expect(json_encode($fingerprint))->not->toContain('jane.doe@acme.test');
    // And the GROUPING key survives — a fingerprint reduced to markers groups
    // every event together, which is the same failure as no fingerprint at all.
    expect($fingerprint[0])->toBe('scoring-failure');
});

test('the marker is quoted the way the DOCUMENT is', function (): void {
    // A plain-quoted marker inside an escaped document closes the outer string
    // early and the rest of the payload stops being parseable — an error report
    // nobody can read.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException('422 — {\"transcript\":\"TRANSCRIPT-SECRET-99\",\"other\":\"keep-this\"}')
        ),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('TRANSCRIPT-SECRET-99');
    expect($value)->toContain('\"[redacted]\"');
    expect($value)->toContain('keep-this');
});

test('a self-referential ARRAY does not segfault the process', function (): void {
    // `scrub()` recursed unbounded on `is_array()`, and a self-referential array
    // — which is what a Laravel log context IS — exhausted the stack and killed
    // PHP with SIGSEGV: exit 139, no exception, nothing catchable. The error
    // reporter took the process down while reporting an error, which is the
    // exact failure `encode()` prevents for OBJECTS and which arrays had no
    // guard against.
    //
    // The sibling test above only ever built a recursive `stdClass`, and its
    // name promised the array case it never exercised.
    $recursive = ['x' => 1];
    $recursive['self'] = &$recursive;

    $event = scrubbedEvent(['ctx' => $recursive, 'candidate_ref' => 'CR-99']);

    // It came back, and it still scrubbed — a depth cap must not become an
    // excuse to stop denying.
    //
    // `JSON_PARTIAL_OUTPUT_ON_ERROR` because the scrubbed structure is still 512
    // levels deep: finite, which is the whole point, but past `json_encode`'s own
    // default limit, so a bare encode returns `false` and the assertion would be
    // testing nothing.
    expect(array_key_exists('ctx', $event->getExtra()))->toBeTrue();
    expect((string) json_encode($event->getExtra(), JSON_PARTIAL_OUTPUT_ON_ERROR))
        ->not->toContain('CR-99');
});

test('a BARE SCALAR value under a denied key is bounded, not swallowed', function (string $blob): void {
    // The bare-scalar arm of `embeddedValueEnd()`. Delete it and nothing leaks —
    // it falls through to `return $length`, which swallows every sibling field to
    // the end of the string. That is the diagnostic collapse this class calls
    // the worse outcome, so the sibling has to survive.
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('422 — '.$blob))]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('123456');
    expect($value)->toContain('keep-this');
})->with([
    '{"api_key":123456,"stage":"keep-this"}',
    '{"api_key":true,"stage":"keep-this"}',
    '{"api_key":null,"stage":"keep-this"}',
]);

test('a bare JSON DOCUMENT reaching a free-text sink is cut', function (string $sink): void {
    // `scrubJsonString()` had one caller — the value walk — while the exception
    // value, the breadcrumb message and the transaction name all reach
    // `redactFreeText()` directly. A bare JSON ARRAY has no key for the denylist
    // and nothing for the free-text passes to grip, so a `json_encode()`d
    // transcript shipped verbatim on those sinks while the identical string one
    // key over was cut.
    $doc = '["SINK-SPEECH-99"]';
    $event = Event::createEvent();

    match ($sink) {
        'exception' => $event->setExceptions([
            new ExceptionDataBag(new RuntimeException($doc)),
        ]),
        'breadcrumb' => $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_ERROR, Breadcrumb::TYPE_DEFAULT, 'console', $doc),
        ]),
        default => $event->setTransaction($doc),
    };

    $scrubbed = SentryScrubber::handle($event);

    $value = match ($sink) {
        'exception' => $scrubbed->getExceptions()[0]->getValue(),
        'breadcrumb' => $scrubbed->getBreadcrumbs()[0]->getMessage(),
        default => $scrubbed->getTransaction(),
    };

    expect((string) $value)->not->toContain('SINK-SPEECH-99');
})->with(['exception', 'breadcrumb', 'transaction']);

test('a JSON document EMBEDDED in prose is cut, and a non-JSON bracket is not', function (): void {
    // Only spans that actually DECODE are replaced — that is what keeps this
    // from eating `at [internal function]` or a bracketed log prefix. A pass
    // that ate every bracket would be the destruction this class calls worse
    // than a leak.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException('[worker] scoring failed: ["PROSE-SPEECH-99"] at [internal function]')
        ),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('PROSE-SPEECH-99');
    expect($value)->toContain('[worker]');
    expect($value)->toContain('[internal function]');
});

test('a RELATIVE `?token=` is cut on every free-text sink', function (string $sink): void {
    // `redactUrl()` cuts at `?`; the free-text URL pass was anchored to
    // `https?://` and only ever saw an ABSOLUTE one, so the two disagreed on the
    // same string. The transaction is the sharpest case — the tracing middleware
    // seeds it with the raw client-controlled path and only replaces it once a
    // ROUTE matches, so on a 404 it stays as typed.
    // Three shapes, because the first version of the pattern kept the query
    // whenever a `#` or a second `?` followed: the path class was greedy and
    // backtracked to the LAST delimiter. `redactUrl()` cuts at the FIRST of
    // either via `strcspn($url, '?#')`, and these two passes must not disagree.
    $path = '/auth/magic?token=eyJhbGciOi.PAYLOAD.SIG#frag';
    $event = Event::createEvent();

    match ($sink) {
        'transaction' => $event->setTransaction($path),
        'extra' => $event->setExtra(['note' => $path]),
        'breadcrumb' => $event->setBreadcrumb([
            new Breadcrumb(
                Breadcrumb::LEVEL_ERROR,
                Breadcrumb::TYPE_DEFAULT,
                'navigation',
                'redirect to '.$path.' failed'
            ),
        ]),
        default => $event->setExceptions([
            new ExceptionDataBag(new RuntimeException('redirect to '.$path.' failed')),
        ]),
    };

    $scrubbed = SentryScrubber::handle($event);

    $encoded = match ($sink) {
        'transaction' => (string) $scrubbed->getTransaction(),
        // UNESCAPED_SLASHES, or `/auth/magic` comes back as `\/auth\/magic`
        // and the positive assertion below tests the encoder, not the scrubber.
        'extra' => (string) json_encode($scrubbed->getExtra(), JSON_UNESCAPED_SLASHES),
        'breadcrumb' => (string) $scrubbed->getBreadcrumbs()[0]->getMessage(),
        default => (string) $scrubbed->getExceptions()[0]->getValue(),
    };

    expect($encoded)->not->toContain('PAYLOAD');
    // And the PATH survives — a route reduced to nothing is the diagnostic loss
    // this class calls worse than a scrubbed event.
    expect($encoded)->toContain('/auth/magic');
})->with(['transaction', 'extra', 'breadcrumb', 'exception']);

test('ordinary prose with a question mark is untouched', function (): void {
    // A leading `/` and no whitespace is what distinguishes a path from prose.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('is that /the right one?')),
    ]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())
        ->toBe('is that /the right one?');
});

test('a document NESTED under non-denied keys is still walked', function (): void {
    // The one shape where `scrubDocument`'s recursion stands alone.
    // `redactEmbeddedPairs()` is defence-in-depth only when the IMMEDIATE key is
    // denied — every other test exercises the flat case or the denied-key case,
    // both of which the pair scanner rescues. Nobody tested the nested one, so
    // killing the recursion left 111 tests green while a candidate's spoken
    // answer walked out.
    $event = scrubbedEvent([
        'msg' => 'HTTP 422: {"reply":{"notes":["NESTED-SPEECH-99"]},"ok":false}',
    ]);

    $encoded = (string) json_encode($event->getExtra());

    expect($encoded)->not->toContain('NESTED-SPEECH-99');
    // And the SIBLING survives — a walk that swallows the rest of the document
    // is the diagnostic collapse this class calls worse than a scrubbed event.
    expect($encoded)->toContain('ok');
});

test('a document too deep to decode never reaches the walk', function (): void {
    // NOT a test of `scrubDocument`'s depth cap — that cap cannot fire, and the
    // reason is worth writing down: the document arrives from `json_decode`,
    // whose OWN default depth limit is 512, the same number. The decode refuses
    // first, `redactEmbeddedDocuments` leaves the span alone, and the cap is
    // defence behind a door that is already shut.
    //
    // What this pins is that a structure past that bound is still not a way out.
    $document = '"DEEP-SPEECH-99"';

    for ($i = 0; $i < 600; $i++) {
        $document = '['.$document.']';
    }

    $event = scrubbedEvent(['msg' => 'body: '.$document]);

    expect((string) json_encode($event->getExtra()))->not->toContain('DEEP-SPEECH-99');
});

test('scrubDocument keeps BOTH denied siblings, and their NAMES', function (): void {
    // Two things at once, and the second REVERSES what this test first asserted.
    //
    // The KEY survives. An earlier version replaced it with the marker, so
    // `{"email":…,"transcript":…}` came back `{"[redacted]":"[redacted]",…}` and
    // an on-call engineer could not tell WHICH field was involved. The key name
    // was never the secret, every sibling walker here keeps it, and the two
    // passes disagreed on the same input: `redactEmbeddedPairs()` produced
    // `{"transcript":"[redacted]"}` and this method re-decoded that already-safe
    // result and undid it.
    //
    // And BOTH fields survive: without the de-collision guard two went in and
    // one came out.
    $event = scrubbedEvent([
        'msg' => 'body: {"email":"a@b.test","transcript":"I led it","id":7}',
    ]);

    $encoded = (string) json_encode($event->getExtra());

    expect($encoded)->toContain('email');
    expect($encoded)->toContain('transcript');
    expect($encoded)->toContain('id');
    expect($encoded)->not->toContain('a@b.test');
    expect($encoded)->not->toContain('I led it');
});

test('a null sprintf param keeps the sentence readable', function (): void {
    // The docblock promises `sprintf('user %s failed', null)` still reads
    // `user  failed` rather than `user null failed`.
    // On the PARAMS, not the message: `getMessage()` returns the template, so
    // asserting there could not see the substitution either way.
    $event = Event::createEvent();
    $event->setMessage('user %s failed', [null], 'user %s failed');

    expect(SentryScrubber::handle($event)->getMessageParams())->toBe(['']);
});

test('a DOUBLE query on a relative path is cut at the first delimiter', function (): void {
    // The greedy path class backtracked to the LAST delimiter, so `/a?b=1?x=…`
    // lost only the second query and kept the first.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('GET /auth/magic?a=1?token=SECRET-99 failed')),
    ]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())
        ->not->toContain('SECRET-99');
});

test('an OVERSIZED embedded span is redacted rather than skipped', function (): void {
    // The length bound exists so a huge span is not decoded — a cost decision.
    // Skipping turned it into a disclosure decision: a bare JSON list over the
    // bound walked out whole while a smaller one was cut.
    $huge = '["'.str_repeat('x', 120_000).'","OVERSIZE-SPEECH-99"]';

    $event = scrubbedEvent(['msg' => 'body: '.$huge]);

    $encoded = (string) json_encode($event->getExtra());

    expect($encoded)->not->toContain('OVERSIZE-SPEECH-99');
    expect($encoded)->toContain('body:');
});

test('a LOG record is scrubbed in body and attributes', function (): void {
    // `before_send_log` takes `callable(Log): ?Log`, a type `handle()` cannot
    // satisfy — which is why the hook sat unwired — and "dormant behind an env
    // var" is the argument this class already refused three times: for spans,
    // for the event stacktrace, and for the fingerprint.
    $log = new Log(
        CarbonImmutable::now()->getTimestamp(),
        '00000000000000000000000000000000',
        LogLevel::info(),
        'scored /auth/magic?token=SECRET-99 for jane@acme.test'
    );

    $log->setAttribute('candidate_ref', 'CR-99');
    $log->setAttribute('route', '/participants');
    $log->setAttribute('attempt', 3);

    $scrubbed = SentryScrubber::handleLog($log);

    expect($scrubbed->getBody())->not->toContain('SECRET-99');
    expect($scrubbed->getBody())->not->toContain('jane@acme.test');

    $attributes = $scrubbed->attributes()->toSimpleArray();

    // `toSimpleArray()` unwraps to the raw values, not to `['value' => …]`.
    expect($attributes['candidate_ref'])->toBe('[redacted]');
    // And the DIAGNOSTICS survive — a log stripped of its route and its attempt
    // count is the unusable reporter this class calls the worse outcome.
    expect($attributes['route'])->toBe('/participants');
    expect($attributes['attempt'])->toBe(3);
});

test('a PHP list of strings is cut the same way its json_encode is', function (): void {
    // The inconsistency this closes: the same transcript shipped verbatim as a
    // PHP list and was redacted as the JSON string of it. Two shapes, two
    // answers — and both Nuxt mirrors had cut the list form from the start.
    $asList = scrubbedEvent(['lines' => ['LIST-SPEECH-99', 'second'], 'id' => 7]);
    $asJson = scrubbedEvent(['lines' => '["LIST-SPEECH-99","second"]', 'id' => 7]);

    foreach ([$asList, $asJson] as $event) {
        $encoded = (string) json_encode($event->getExtra());

        expect($encoded)->not->toContain('LIST-SPEECH-99');
        // And the NAMED sibling survives on both paths.
        expect($encoded)->toContain('id');
    }
});

test('a sprintf param keeps its diagnostic value while still being scrubbed', function (): void {
    // A message param fills a NAMED slot in a template, so it is not a keyless
    // element — but `scrubMessageParam()` wrapped it in a list for convenience,
    // and once `scrub()` learned the keyless rule it read that wrapper as real.
    // Every STRING param was cut to the marker while an ARRAY param — the shape
    // carrying `display_name` and `transcript` — survived the key walk.
    $event = Event::createEvent();
    $event->setMessage(
        'scoring %s failed for %s',
        ['acme-corp', ['display_name' => 'Ada Lovelace', 'attempt' => 3]],
        'scoring %s failed'
    );

    $params = SentryScrubber::handle($event)->getMessageParams();

    // The benign string SURVIVES.
    expect($params[0])->toBe('acme-corp');
    // The dangerous container is still walked by key.
    expect((string) $params[1])->not->toContain('Ada Lovelace');
    expect((string) $params[1])->toContain('attempt');
});

test('a sprintf param carrying an address is still redacted', function (): void {
    // Keeping the string readable must not mean keeping it raw.
    $event = Event::createEvent();
    $event->setMessage('invite %s failed', ['jane@acme.test'], 'invite %s failed');

    expect(SentryScrubber::handle($event)->getMessageParams()[0])
        ->not->toContain('jane@acme.test');
});

test('a candidate-content word is denied in PREFIX position too', function (string $key): void {
    // The single-word rule matched the whole key or its LAST segment, which is
    // right for generic words — matching `content` anywhere redacted
    // `content_type`. It is wrong for these five: there is no innocent key
    // shaped like `answer_1` or `prompt_body`.
    $event = scrubbedEvent([$key => 'CONTENT-SPEECH-99']);

    expect((string) json_encode($event->getExtra()))->not->toContain('CONTENT-SPEECH-99');
})->with([
    'transcript_lines',
    'prompt_body',
    'answer_1',
    'excerpt_1',
    'utterance_3',
    // BOTH spellings. `DENIED_KEYS` carries singular and plural for every one of
    // these, and the content list carried only the singulars — so `answers_1`
    // was allowed while `answer_1` was denied.
    'transcripts_lines',
    'prompts_body',
    'answers_1',
    'excerpts_1',
    'utterances_3',
]);

test('a GENERIC word in prefix position is still spared', function (string $key): void {
    // The collateral the narrow list avoids. `content_type` and `app.env` are
    // ordinary diagnostics, and widening the first rule instead of adding a
    // second list would have taken them with it.
    $event = scrubbedEvent([$key => 'application/json']);

    expect($event->getExtra()[$key] ?? null)->toBe('application/json');
    // `query_builder` was here and is NOT any more: `q` / `query` / `search` /
    // `filter` count in ANY position now, because they name the participants
    // filter and that carries a candidate's NAME. Losing `query_builder` is the
    // stated cost, and it is worth paying — the same value was already cut
    // inside a query string and handed over under a key one position left.
})->with(['content_type', 'content_length']);

test('a key whose separator is WHITESPACE is still denied', function (string $key): void {
    // `-` and `.` were folded so the denylist reaches `X-Api-Key` and
    // `auth.token`; a space was not. Sentry's TAG charset would reject these,
    // but `extra`, `contexts` and breadcrumb metadata have no charset limit.
    $event = scrubbedEvent([$key => 'WHITESPACE-SECRET-99']);

    expect((string) json_encode($event->getExtra()))->not->toContain('WHITESPACE-SECRET-99');
})->with(['candidate ref', 'display name', 'access  token']);

test('handleLog scrubs the attribute KEY, not only its value', function (): void {
    // The rule `scrub()` and `scrubDocument()` already carry, and the one walker
    // that did not get it: `isDenied()` inspects what a key is CALLED and
    // `redactFreeText()` what a value CONTAINS. Nothing inspected what the key
    // itself contains, so an address used as an attribute name walked out — and
    // a map keyed by address is the ordinary shape of a delivery-result map.
    $log = new Log(
        CarbonImmutable::now()->getTimestamp(),
        '00000000000000000000000000000000',
        LogLevel::info(),
        'delivery report'
    );

    $log->setAttribute('jane.doe@acme.test', 'delivery failed');
    $log->setAttribute('route', '/participants');

    $attributes = SentryScrubber::handleLog($log)->attributes()->toSimpleArray();
    $encoded = (string) json_encode($attributes);

    expect($encoded)->not->toContain('jane.doe@acme.test');
    // And the ordinary attribute is untouched.
    expect($attributes['route'])->toBe('/participants');
});

test('an OBJECT inside a list is walked, not emitted verbatim', function (): void {
    // The list branch tested `! is_array($value)`, and an object is not an array
    // either — so it took that branch, failed the `is_string()` test and was
    // assigned verbatim. The `is_object()` walk fifty lines down never ran, and
    // Sentry serialises AFTER `before_send`, so nothing downstream saves it.
    $participant = (object) [
        'email' => 'jane.doe@acme.test',
        'display_name' => 'Jane Doe',
    ];

    $event = scrubbedEvent(['participants' => [$participant]]);

    $encoded = (string) json_encode($event->getExtra());

    expect($encoded)->not->toContain('jane.doe@acme.test');
    expect($encoded)->not->toContain('Jane Doe');
});

test('handleLog keeps BOTH attributes when two keys normalise alike', function (): void {
    // Two addresses normalise to the same marker and `setAttribute()` ASSIGNS,
    // so the second overwrote the first — two attributes in, one out. The loop
    // `scrub()` and `scrubDocument()` already carry; the newest walker did not.
    $log = new Log(
        CarbonImmutable::now()->getTimestamp(),
        '00000000000000000000000000000000',
        LogLevel::info(),
        'delivery report'
    );

    // A key whose redacted form is NOT exactly the bare marker. With two bare
    // markers, `self::REDACTED.'_'.$suffix` and `$base.'_'.$suffix` produce the
    // same string, so the buggy line and the correct one both passed — the test
    // could not see the defect it was written for.
    $log->setAttribute('a@x.test/path', 'first');
    $log->setAttribute('b@x.test/path', 'second');

    $attributes = SentryScrubber::handleLog($log)->attributes()->toSimpleArray();

    expect($attributes)->toHaveCount(2);
    expect(array_values($attributes))->toContain('first');
    expect(array_values($attributes))->toContain('second');

    // The KEYS, not just the count: the suffix appends to the redacted base, so
    // the part of the name that was never the secret survives on both.
    foreach (array_keys($attributes) as $attributeKey) {
        expect($attributeKey)->toContain('/path');
    }
});

test('a CREDENTIAL word is denied in prefix position too', function (string $key): void {
    // A single-word entry matched only the whole key or its LAST segment, so
    // `password_confirmation` ends in `confirmation` and `password` was never
    // tested — the plaintext shipped. Live, not dormant: `max_request_body_size`
    // is unset so the SDK attaches bodies, and both password requests use
    // `confirmed`. This repo's own `AuditRecorder` already denies that key; the
    // redactor that talks to a THIRD PARTY did not.
    $event = scrubbedEvent([$key => 'hunter2']);

    expect((string) json_encode($event->getExtra()))->not->toContain('hunter2');
})->with([
    'password_confirmation',
    'token_expires_at',
    'secret_rotated_at',
    'authorization_header',
    'cookie_jar',
]);

test('a key separated by the letter-to-DIGIT boundary is denied', function (string $key): void {
    // The normalizer folded hyphens, dots, whitespace and the camelCase boundary
    // and not this one — so `answer1` was ONE segment, in no list and
    // unreachable by the run walk, while `answer_1` was denied. Same field, one
    // character away.
    $event = scrubbedEvent([$key => 'DIGIT-SECRET-99']);

    expect((string) json_encode($event->getExtra()))->not->toContain('DIGIT-SECRET-99');
})->with(['answer1', 'token1', 'excerpt2', 'transcript9']);

test('a digit suffix on an ORDINARY key is not collateral', function (string $key): void {
    // A generic word plus a digit is an ordinary diagnostic, and so is a name
    // that IS a digit suffix.
    $event = scrubbedEvent([$key => 'application/json']);

    expect($event->getExtra()[$key] ?? null)->toBe('application/json');
})->with(['content1', 'utf8', 'sha256']);

test('every any-position word is denied in PREFIX position', function (string $word): void {
    // Eight of the nine credential entries were unpinned: deleting `password`
    // left the suite green while `password_confirmation` shipped the plaintext.
    // The list whose entire reason for existing is prefix-position credentials
    // was the one half not held to this class's own discipline.
    $event = scrubbedEvent([$word.'_suffix' => 'ANYPOS-SECRET-99']);

    expect((string) json_encode($event->getExtra()))->not->toContain('ANYPOS-SECRET-99');
})->with([
    'password',
    'passwords',
    'token',
    'tokens',
    'secret',
    'secrets',
    'authorization',
    'cookie',
    'cookies',
    'transcript',
    'transcripts',
    'prompt',
    'prompts',
    'answer',
    'answers',
    'excerpt',
    'excerpts',
    'utterance',
    'utterances',
]);

test('a COLLIDING key map resolves in linear time', function (): void {
    // Every colliding key restarted the suffix search at 2, so n keys redacting
    // to the same marker cost O(n^2) probes. A map keyed by address is the
    // ordinary shape of a delivery-result map.
    $extra = [];

    for ($i = 0; $i < 8000; $i++) {
        $extra["user{$i}@acme.test"] = 'x';
    }

    $started = microtime(true);

    scrubbedEvent($extra);

    expect((microtime(true) - $started) * 1000)->toBeLessThan(2000);
});

test('a FREE-TEXT query takes the rest of the line', function (string $input): void {
    // `q` is the participants filter and carries a candidate's NAME, so a tail
    // surviving past the space is a leak rather than a curiosity.
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException($input))]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())
        ->not->toContain('Lovelace');
})->with([
    'GET /participants?q=Ada Lovelace failed',
    'GET /participants?query=Ada Lovelace failed',
    'GET /p?search=Ada Lovelace failed',
]);

test('a CREDENTIAL query keeps the prose after it', function (): void {
    // The narrowing that makes the rule above affordable: a credential is a
    // single token — a JWT, a signature, an api key all contain no space — so
    // cutting at the first space already takes the whole value, and the words
    // after it are ordinary diagnostics worth keeping.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(
            new RuntimeException('redirect to /auth/magic?token=eyJhbGciOi.PAY.SIG failed')
        ),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('PAY.SIG');
    expect($value)->toContain('failed');
});

test('a `_keys` name is denied by the CONVENTION, not by the list', function (string $key): void {
    // The convention is live code that nothing pinned: deleting it left the
    // suite green while a signing key shipped. Only `api_keys` is reachable by
    // the run walk, because it is literally in `DENIED_KEYS` — these three are
    // redacted by the convention and nothing else. Its neighbour `_key` WAS
    // pinned; same half-applied shape, one line over.
    $event = scrubbedEvent([$key => 'SIGNING-SECRET-99']);

    expect((string) json_encode($event->getExtra()))->not->toContain('SIGNING-SECRET-99');
})->with(['signing_keys', 'stripe_keys', 'rotation_keys']);

test('handleLog resolves many colliding attribute keys in linear time', function (): void {
    // The cursor, which only a timing assertion can see. Restarting the suffix
    // scan at 2 on every collision is the O(n^2) probe cost both sibling walkers
    // were fixed for, and a map keyed by address is the ordinary shape of a
    // delivery-result map.
    $log = new Log(
        CarbonImmutable::now()->getTimestamp(),
        '00000000000000000000000000000000',
        LogLevel::info(),
        'delivery report'
    );

    // 4000 and a 200 ms bound, both measured: with the cursor this is 22 ms,
    // without it 1585 ms. A looser pair could not tell the two apart, which is
    // the whole failure mode this assertion exists to avoid.
    for ($i = 0; $i < 4000; $i++) {
        $log->setAttribute("user{$i}@acme.test", 'x');
    }

    $started = microtime(true);

    SentryScrubber::handleLog($log);

    expect((microtime(true) - $started) * 1000)->toBeLessThan(200);
});

test('the denylist carries BOTH spellings of every entry that has a plural', function (): void {
    // The rule this class states and then applied to two groups out of three:
    // `text`, `explanation`, `payload`, `authorization`, `query_string`,
    // `fragment` and `env` all shipped in the PLURAL while their singulars were
    // denied. Asserted structurally rather than by naming today's misses, so
    // tomorrow's cannot slip in the same way.
    $source = (string) file_get_contents(app_path('Support/Observability/SentryScrubber.php'));

    preg_match('/private const DENIED_KEYS = \[(.*?)\];/s', $source, $listMatch);
    preg_match_all("/'([^']+)'/", (string) preg_replace('#//[^\n]*#', '', $listMatch[1] ?? ''), $keyMatch);

    $keys = $keyMatch[1] ?? [];
    $irregular = ['key_hash' => 'key_hashes', 'query' => 'queries', 'search' => 'searches'];
    // No plural anyone emits: `q` is a query PARAMETER name, `to_json` a method
    // name, `messages` already the plural, and the last two are irregular
    // plurals the `+s` rule cannot recognise as such.
    $noPlural = ['q', 'to_json', 'messages', 'key_hashes', 'queries', 'searches'];
    $missing = [];

    foreach ($keys as $key) {
        if (in_array($key, $noPlural, true)) {
            continue;
        }

        if (str_ends_with($key, 's') && in_array(substr($key, 0, -1), $keys, true)) {
            continue;
        }

        if (! in_array($irregular[$key] ?? $key.'s', $keys, true)) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe([]);
});

test('the any-position list carries BOTH spellings too', function (): void {
    // The same rule as the denylist, asserted the same way — `authorization` was
    // on this list and `authorizations` was not, which is the half-applied shape
    // this class keeps finding in itself, one list over.
    $source = (string) file_get_contents(app_path('Support/Observability/SentryScrubber.php'));

    preg_match('/private const DENIED_CONTENT_WORDS = \[(.*?)\];/s', $source, $listMatch);
    preg_match_all("/'([^']+)'/", (string) preg_replace('#//[^\n]*#', '', $listMatch[1] ?? ''), $wordMatch);

    $words = $wordMatch[1] ?? [];
    $missing = [];

    // The same irregular/no-plural carve-outs the denylist check carries.
    $irregular = ['query' => 'queries', 'search' => 'searches'];
    $noPlural = ['q', 'queries', 'searches'];

    foreach ($words as $word) {
        if (in_array($word, $noPlural, true) || str_ends_with($word, 's')) {
            continue;
        }

        if (! in_array($irregular[$word] ?? $word.'s', $words, true)) {
            $missing[] = $word;
        }
    }

    expect($words)->not->toBe([]);
    expect($missing)->toBe([]);
});

test('a relative query is cut whatever character precedes the path', function (array $case): void {
    // The lead was a hand-written delimiter list — whitespace, quote, bracket,
    // start-of-string — and anything else meant the pass never fired at all. A
    // live bearer token shipped, and so did a candidate's surname, because the
    // free-text query cut lives INSIDE this pass.
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException($case[0]))]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())->toBe($case[1]);
})->with([
    [['x:/auth/magic?token=SECRET-LEAD-99', 'x:/auth/magic']],
    [['navigate to=/participants?q=Ada Lovelace', 'navigate to=/participants']],
    [['tried,/auth/magic?token=SECRET-LEAD-99', 'tried,/auth/magic']],
    // The other half of widening the lead: a fragment inside a word is not a
    // rooted path, and a sentence ending in `?` is a sentence.
    [['module foo/bar?x=1 failed', 'module foo/bar?x=1 failed']],
    [['is that /the right one?', 'is that /the right one?']],
]);

test('a document is found after a STRAY quote in the prose', function (): void {
    // One unmatched `"` must not latch the scanner's string flag on for the rest
    // of the input. An error message carrying a lone double quote is ordinary —
    // `Unexpected token " in JSON at position 5`.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('at Foo (a"b) then ["QUOTE-LATCH-99"]')),
    ]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('QUOTE-LATCH-99');
    expect($value)->toContain('at Foo');
});

test('a document is found after a stray OPENER in the prose', function (string $input): void {
    // The sibling of the stray-QUOTE latch, and only one of the two was fixed. A
    // depth counter only attempted a span when it returned to zero, so ONE
    // unmatched `{` or `[` latched it open and every later document went
    // unattempted. A truncated body leaves an unmatched opener by construction.
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException($input))]);

    $value = SentryScrubber::handle($event)->getExceptions()[0]->getValue();

    expect($value)->not->toContain('STRAY-SPEECH-99');
    expect($value)->toContain('then');
})->with([
    'Unexpected token { in JSON then ["STRAY-SPEECH-99"]',
    'at Foo [native code then ["STRAY-SPEECH-99"]',
    'oops { and [ then ["STRAY-SPEECH-99"]',
]);

test('the OUTERMOST span is rewritten once, not the nested one twice', function (): void {
    // Recording pairs means a nested span closes before its parent. Replacing
    // the inner one first would rewrite text the outer decode then reads.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('body {"transcript":["speech"],"ok":1} end')),
    ]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())
        ->toBe('body {"transcript":"[redacted]","ok":1} end');
});

test('a content word left off the any-position list is denied behind a prefix', function (string $key): void {
    // The single-word rule fires on the whole key or its LAST segment unless the
    // word is on the any-position list — and `text`, `messages`, `explanation`
    // and `payload` were not, so all four shipped behind a prefix while
    // `transcript_lines` one key over redacted correctly.
    //
    // This class's own comments name them as the worst entries: `text` is what
    // this PRODUCT calls transcribed speech, `messages` the AI conversation,
    // `explanation` the LLM's rationale on `indicator_scores`.
    $event = scrubbedEvent([$key => 'PREFIX-SPEECH-99']);

    expect((string) json_encode($event->getExtra()))->not->toContain('PREFIX-SPEECH-99');
})->with(['text_raw', 'messages_json', 'explanation_raw', 'payload_json', 'transcript_lines']);

test('content_* keys are the line the content list draws', function (string $key): void {
    // `content` is deliberately NOT on the any-position list, and this is why:
    // these are ordinary diagnostics. `content` keeps the last-segment rule
    // while `text` does not, and that asymmetry is the decision.
    $event = scrubbedEvent([$key => 'application/json']);

    expect($event->getExtra()[$key] ?? null)->toBe('application/json');
})->with(['content_type', 'content_length', 'content_encoding']);

test('redactUrl and redactFreeText answer the SAME on an address', function (string $input): void {
    // The invariant `redactUrl()` states — two passes claiming the same promise
    // must not disagree on the same input — enforced rather than asserted. The
    // address pattern used to be a copy-pasted literal in both, which is exactly
    // how they come to disagree: widen one, miss the twin, and no test notices
    // because each was tested against its own copy.
    $scrubber = new SentryScrubber;

    $viaUrl = (new ReflectionMethod(SentryScrubber::class, 'redactUrl'))->invoke($scrubber, $input);
    $viaText = (new ReflectionMethod(SentryScrubber::class, 'redactFreeText'))->invoke($scrubber, $input);

    // The LOCAL PART, not the whole address: narrowing the pattern to
    // `[A-Za-z]+@` still removes `doe@acme.test` and leaves `jane.` standing, so
    // asserting the full string could not see the narrowing.
    expect((string) $viaUrl)->not->toContain('jane');
    expect($viaText)->not->toContain('jane');
})->with([
    'https://api.beai.test/participants/jane.doe@acme.test/transcript',
    'https://api.beai.test/x?to=jane.doe@acme.test',
]);

test('a filter carrier is denied in prefix position', function (string $key): void {
    // These name the participants filter, which carries a candidate's NAME — and
    // the class already cut the identical value inside a query string while
    // handing it over under a key one position left. `search` and `filter` were
    // in neither list at all.
    $event = scrubbedEvent([$key => 'Ada Lovelace']);

    expect((string) json_encode($event->getExtra()))->not->toContain('Ada Lovelace');
})->with(['q_value', 'query_value', 'search_term', 'filter_input', 'queryValue']);

test('every CREDENTIAL word is on BOTH lists', function (): void {
    // `header`, `env` and `fragment` sat on the denylist only, so `headers_raw`
    // shipped a bearer token, `env_dump` shipped an APP_KEY and `fragment_raw`
    // shipped an entry-link token, while `cookie_raw` one key over was cut.
    //
    // Named here rather than derived, because "is this a credential" is a
    // judgement: `content` is deliberately last-segment-only.
    $source = (string) file_get_contents(app_path('Support/Observability/SentryScrubber.php'));

    preg_match('/private const DENIED_CONTENT_WORDS = \[(.*?)\];/s', $source, $listMatch);
    preg_match_all("/'([^']+)'/", (string) preg_replace('#//[^\n]*#', '', $listMatch[1] ?? ''), $wordMatch);

    $words = $wordMatch[1] ?? [];
    $credentials = ['password', 'token', 'secret', 'authorization', 'cookie', 'header', 'env', 'fragment'];
    $missing = [];

    foreach ($credentials as $credential) {
        if (! in_array($credential, $words, true) || ! in_array($credential.'s', $words, true)) {
            $missing[] = $credential;
        }
    }

    expect($missing)->toBe([]);
});

test('a CREDENTIAL word is denied in prefix position', function (string $key): void {
    // The half-application this closes: same shape, one list apart.
    $event = scrubbedEvent([$key => 'CRED-PREFIX-99']);

    expect((string) json_encode($event->getExtra()))->not->toContain('CRED-PREFIX-99');
})->with(['headers_raw', 'env_dump', 'fragment_raw', 'cookie_raw']);

test('a free-text query carrier takes the line for every entry on the list', function (string $key): void {
    // `q`, `query` and `search` were each pinned; `filter` and `name` carried the
    // same claim with no test. They decide whether `?name=Ada Lovelace failed`
    // cuts to end-of-line or leaves ` Lovelace` standing.
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException("GET /p?{$key}=Ada Lovelace failed")),
    ]);

    expect(SentryScrubber::handle($event)->getExceptions()[0]->getValue())->toBe('GET /p');
})->with(['q', 'query', 'search', 'filter', 'name']);
