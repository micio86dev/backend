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

use App\Support\Observability\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Tracing\Span;
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
