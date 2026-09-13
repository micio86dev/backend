<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

/**
 * Strips candidate data before anything leaves for Sentry (C13).
 *
 * `send_default_pii => false` is necessary and nowhere near sufficient. It stops
 * Sentry attaching user/IP context automatically; it does nothing about what
 * this application's own exceptions carry.
 *
 * And in this product they carry a great deal. An exception raised inside the
 * scoring pipeline has prompt text in scope — and prompts contain a candidate's
 * spoken answers, transcribed. A failing SSO exchange has a signed token in the
 * request. A webhook delivery failure has the evaluation payload. Every one of
 * those is confidential, and the whole premise of the product is that a
 * candidate's answers stay between them and the organization that assessed them.
 *
 * The consequence of getting this wrong is quiet and permanent: the data sits
 * in a third-party service that nobody at BEAI thinks of as a database, indexed
 * and searchable, and no test anywhere else would notice.
 *
 * So this scrubs by KEY at any depth, and it is a denylist rather than an
 * allowlist for a deliberate reason: an allowlist would silently drop the
 * diagnostic context that makes an error report useful, and an unusable error
 * reporter gets switched off — which is a worse outcome than a scrubbed one.
 */
final class SentryScrubber
{
    /**
     * Words that count in ANY segment position, not only as the last one.
     *
     * The single-word rule in `isDenied()` matches the whole key or its last
     * segment, and that is right for ONE word: `content`. Matching it anywhere
     * would redact `content_type` and `content_length`, which are ordinary
     * diagnostics, so it is the only single word left on the last-segment rule.
     *
     * `env` used to be cited here as the same case and it is NOT: `env_dump`
     * shipped an `APP_KEY` while `cookie_raw` one key over was cut. The
     * collateral — `app.env`, `node_env`, `build_env` all going too — is
     * accepted, because nothing distinguishes a framework's environment name
     * from a request env dump and denial is the safe side of that.
     *
     * It is wrong for these. There is no innocent key shaped like `answer_1`,
     * `prompt_body`, `transcript_lines`, `excerpt_1` or `utterance_3` — every
     * one names a candidate's speech — and the last-segment rule let them all
     * through in prefix position. A second list rather than widening the first,
     * because the collateral the first rule avoids is real.
     *
     * BOTH SPELLINGS, for the reason `DENIED_KEYS` states two lists up: each
     * time one spelling is added without the other, the missing one walks.
     *
     * @var list<string>
     */
    private const DENIED_CONTENT_WORDS = [
        // CREDENTIAL words, matched anywhere for the same reason the content
        // words are. `password_confirmation` ends in `confirmation`, so the
        // last-segment rule never tested `password` and the plaintext shipped —
        // live, not dormant: `max_request_body_size` is unset so the SDK default
        // attaches bodies, and both password requests use `confirmed`. This
        // repo's own `AuditRecorder` already denies that key; the redactor that
        // talks to a THIRD PARTY did not.
        'header',
        'headers',
        'env',
        'envs',
        'fragment',
        'fragments',
        'password',
        'passwords',
        'token',
        'tokens',
        'secret',
        'secrets',
        'authorization',
        'authorizations',
        'cookie',
        'cookies',

        'q',
        'query',
        'queries',
        'search',
        'searches',
        'filter',
        'filters',
        'text',
        'texts',
        'messages',
        'explanation',
        'explanations',
        'payload',
        'payloads',
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
    ];

    /**
     * Keys whose VALUE never leaves the building.
     *
     * Grouped rather than annotated line by line. Every paragraph that used to
     * sit between entries said the same thing in a different costume — one half
     * of a pair was on the list and the other was not — so the rule is stated
     * once here and the list is just the list.
     *
     * CREDENTIALS come in singular AND plural, and `header` as well as
     * `headers`: every pair here has both forms, and each time one was added
     * without the other the missing spelling walked. That rule was stated and
     * then applied to two groups out of three — `text`, `explanation`,
     * `payload`, `authorization`, `query_string`, `fragment` and `env` all
     * shipped in the plural while their singulars were denied. It is exhaustive
     * now; adding an entry means adding both spellings.
     *
     * ONE recorded carve-out: `messages` has no `message`. That is Sentry's own
     * top-level field and the thing an operator reads first, so denying it would
     * cost the report its headline. An invariant with a SILENT exception is how
     * the next contributor adds a second one, so it is named here and exempted
     * by name in the suite's plural check.
     *
     * REQUEST-SHAPED keys (`q`, `query`, `query_string`, `fragment`, `env`) are
     * on the LIST and not only in the `unset()` site, because unsetting a
     * literal misses `queryString`/`Query-String` and reaches neither extra,
     * tags nor a non-http context. `q` is the participants filter — free text
     * carrying the candidate's name.
     *
     * CANDIDATE IDENTITY: `candidate_ref` and `display_name`, both plurals, and
     * `email` — the global identity key per CLAUDE.md ruling 8 (reversed
     * 2026-09-01) and named in the GDPR retention sign-off (ruling 2). Unlike
     * `candidate_ref` an address is directly identifying with no calling system
     * needed to resolve it.
     *
     * CANDIDATE-AUTHORED CONTENT: transcript, prompt, answer, excerpt,
     * utterance, content and their plurals, plus three the synonym list missed:
     * `text` is what this PRODUCT calls transcribed speech (`utterances.text`
     * in the schema, the validated field on UtteranceController,
     * HeygenProvider's transcript shape); `messages` is the AI conversation,
     * which `AiIntegration::truncateMessages(): string` json_encodes so it
     * lands under ONE key with nothing inside for a key walk to reach; and
     * `explanation` is the LLM's behavioural rationale on `indicator_scores`,
     * which `payload` covered on the webhook path and nowhere else.
     *
     * @var list<string>
     */
    private const DENIED_KEYS = [
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'key_hash',
        'password',
        'secret',
        'webhook_secret',
        'authorization',
        'cookie',
        'header',
        'tokens',
        'api_keys',
        'passwords',
        'secrets',
        'cookies',
        'headers',
        'q',
        'search',
        'searches',
        'filter',
        'filters',
        'query',
        'query_string',
        'fragment',
        'env',
        'candidate_ref',
        'candidate_refs',
        'display_name',
        'display_names',
        'email',
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
        'content',
        'contents',
        'text',
        'messages',
        'explanation',
        'payload',
        'texts',
        'explanations',
        'payloads',
        'authorizations',
        'query_strings',
        'fragments',
        'envs',
        'key_hashes',
        'access_tokens',
        'refresh_tokens',
        'webhook_secrets',
        'queries',
        'emails',
    ];

    private const REDACTED = '[redacted]';

    /**
     * A relative path carrying a query or fragment, anywhere in the text.
     */
    private const RELATIVE_QUERY_PATTERN = '/(^|\W)(\/[^\s\'"<>)\]?#]*)[?#][^\s\'"<>)\]]*/';

    /**
     * The `key=` pairs inside a query string, so a FREE-TEXT one is recognised.
     */
    private const QUERY_KEY_PATTERN = '/[?&]([\w.-]{1,64})=/';

    /**
     * Query keys whose VALUE is free text and therefore may contain spaces.
     *
     * Not "denied or not" — whether the value can run past the space that ends
     * the URL. A credential never does. `q` and `query` are the participants
     * filter, which carries a candidate's NAME.
     *
     * @var list<string>
     */
    private const FREE_TEXT_QUERY_KEYS = ['q', 'query', 'search', 'filter', 'name'];

    /**
     * An address, wherever it appears.
     *
     * ONE literal, because two copies is the mechanism by which two passes come
     * to disagree: widen the local part in `redactUrl()` to catch `+tag`, miss
     * the twin in `redactFreeText()`, and the two now answer differently for the
     * same input — silently, because each was tested against its own copy. That
     * is the invariant `redactUrl()` states eight lines above where the second
     * copy used to sit.
     *
     * NO leading lookbehind, unlike the Nuxt mirrors: it is semantically a no-op
     * in both engines — the local-part class is greedy, so the leftmost viable
     * start always already satisfies it — and only ever a performance guard. It
     * is one in V8 and not in PCRE.
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}/';

    /**
     * Past this length a string is not a diagnostic payload worth decoding, and
     * `json_decode` on it is a cost paid on every event.
     */
    private const JSON_STRING_LIMIT = 100000;

    /**
     * How deep `scrub()` walks before it returns the marker.
     *
     * 512, matching `json_decode`'s own default: everything here ends up
     * encoded anyway, so a structure the engine refuses to parse is not a
     * structure worth walking.
     */
    private const MAX_SCRUB_DEPTH = 512;

    /**
     * The longest denied key, in `_`-delimited segments.
     *
     * Bounds the run walk in `isDenied()`: no entry is longer than this, so a
     * longer run cannot match anything. Kept next to the list so adding a longer
     * entry is visibly a decision about this constant too — the Nuxt mirrors
     * derive it from the list, which PHP cannot do in a `const`.
     */
    private const MAX_DENIED_SEGMENTS = 2;

    /**
     * Sentry's `before_send` entry point.
     *
     * Static, and referenced as [Class::class, 'handle'] rather than a closure,
     * because a closure in a config file breaks `config:cache` — and an
     * application that cannot cache its config in production is a performance
     * regression bought for nothing.
     */
    public static function handle(Event $event, ?EventHint $hint = null): Event
    {
        return (new self)($event, $hint);
    }

    public function __invoke(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest($this->scrubRequest($request));
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            /** @var array<string, mixed> $scrubbed */
            $scrubbed = $this->scrub($extra);
            $event->setExtra($scrubbed);
        }

        $tags = $event->getTags();

        if ($tags !== []) {
            /** @var array<string, string> $scrubbedTags */
            $scrubbedTags = array_map(
                fn (mixed $value): string => $this->stringify($value),
                $this->scrub($tags)
            );
            $event->setTags($scrubbedTags);
        }

        foreach ($event->getContexts() as $name => $context) {
            // The `http` context IS the request, under a second name. Sentry
            // populates it independently of `$event->getRequest()`, with the same
            // `url`/`query_string` shapes — so the SSO token that `scrubRequest()`
            // exists to cut walked out one context over from where it was cut,
            // through a generic key walk that denies neither `url` nor `query`.
            $scrubbedContext = $name === 'http'
                ? $this->scrubRequest($context)
                : $this->scrub($context);

            // FAIL CLOSED. `setContext()` is a no-op on an empty array
            // (`if (!empty($data))`, Event.php) while `setRequest()` assigns
            // unconditionally — two sinks, two semantics. `scrubRequest()` works
            // by REMOVING keys, so an `http` context made only of removed keys
            // reduces to `[]`, the setter declines it, and the ORIGINAL stays on
            // the event: `{"http":{"query_string":"token=…"}}` walked out of the
            // very branch written to cut it. A scrubber must never default to
            // disclosure, the same argument `isDenied()` already makes.
            $event->setContext(
                $name,
                $scrubbedContext === [] ? ['scrubbed' => self::REDACTED] : $scrubbedContext
            );
        }

        // Breadcrumbs carry Laravel's log context, and `config/sentry.php` has
        // `breadcrumbs.logs => true`. The scope is applied BEFORE `before_send`,
        // so one `Log::error('scoring failed', ['prompt' => $prompt])` handed
        // this callback a candidate's transcribed answers under `prompt` — a key
        // already on the denylist below. The denylist knew; nothing walked here.
        $breadcrumbs = $event->getBreadcrumbs();

        if ($breadcrumbs !== []) {
            $event->setBreadcrumb(array_map(
                fn (Breadcrumb $breadcrumb): Breadcrumb => $this->scrubBreadcrumb($breadcrumb),
                $breadcrumbs
            ));
        }

        // Spans, because `before_send_transaction` points here too. Laravel's
        // AiIntegration sets `gen_ai.input.messages` / `gen_ai.output.messages`
        // as span DATA and this config enables every gen_ai breadcrumb — in this
        // product those messages ARE a candidate's transcribed answers. Dormant
        // while `traces_sample_rate` is null, one env var from being live, and
        // `candidate_ref`/`email` inside them are keys already denied below.
        $spans = $event->getSpans();

        if ($spans !== []) {
            // Spans are REBUILT, not mutated. `Span::setData()` and `setTags()`
            // array_merge rather than assign, and merge cannot remove — so a key
            // this scrubber RENAMES (an address-bearing one) had its renamed copy
            // appended while the original kept its value, leaving the address on
            // the wire as a key name beside a `[redacted]` twin. `setSpans()` on
            // the event assigns, so a fresh Span built from a SpanContext is the
            // only replacement the SDK actually offers.
            $rebuilt = [];

            foreach ($spans as $span) {
                /** @var array<string, string> $spanTags */
                $spanTags = array_map(
                    fn (mixed $value): string => $this->stringify($value),
                    $this->scrub($span->getTags())
                );

                $description = $span->getDescription();

                $context = new SpanContext;
                $context->setTraceId($span->getTraceId());
                $context->setSpanId($span->getSpanId());
                $context->setParentSpanId($span->getParentSpanId());
                $context->setOp($span->getOp());
                $context->setStatus($span->getStatus());
                $context->setStartTimestamp($span->getStartTimestamp());
                $context->setEndTimestamp($span->getEndTimestamp());
                // `origin` too. `TransactionItem::serializeSpan()` emits
                // `$span->getOrigin() ?? 'manual'`, so dropping it relabelled
                // every `auto.db.sql` and `auto.http.client` span as
                // hand-instrumented — not a leak, the diagnostic-context loss
                // this class calls the worse outcome.
                $context->setOrigin($span->getOrigin());
                // `sampled` sits three lines from `setOrigin()` in SpanContext
                // and was dropped for the same reason `origin` was. Nothing on
                // the wire reads it, but an incomplete rebuild is the defect the
                // origin comment above was written about.
                $context->setSampled($span->getSampled());
                // Tags and data ride the same wire: `serializeSpan()` transmits
                // both and `collectV2Attributes()` folds them into one bag.
                $context->setData($this->scrub($span->getData()));
                $context->setTags($spanTags);
                $context->setDescription(
                    $description === null ? null : $this->redactFreeText($description)
                );

                $rebuilt[] = new Span($context);
            }

            $event->setSpans($rebuilt);
        }

        // An exception message has no KEY for a key denylist to catch, and the
        // standards forbid confidential content in one outright.
        $exceptions = $event->getExceptions();

        if ($exceptions !== []) {
            foreach ($exceptions as $exception) {
                $exception->setValue($this->redactFreeText($exception->getValue()));

                // Frame `vars` are the function's ARGUMENTS: the SDK reflects
                // `$backtraceFrame['args']` into named parameters, so a method
                // taking `string $transcript` puts the value on the wire under
                // a key this list already denies.
                foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                    $vars = $frame->getVars();

                    if ($vars !== []) {
                        $frame->setVars($this->scrub($vars));
                    }
                }
            }

            $event->setExceptions($exceptions);
        }

        // The EVENT-LEVEL stacktrace is a SECOND one. `EventItem`
        // serializes it through the same frame serializer that emits `vars`, so
        // the argument that made exception frames worth walking applies here
        // unchanged. Dormant today — `attach_stacktrace` defaults false and is
        // not a key in config/sentry.php — but that is the same "one config line
        // from being live" this class already refused to accept for spans.
        $eventStacktrace = $event->getStacktrace();

        if ($eventStacktrace !== null) {
            foreach ($eventStacktrace->getFrames() as $frame) {
                $vars = $frame->getVars();

                if ($vars !== []) {
                    $frame->setVars($this->scrub($vars));
                }
            }
        }

        // Breadcrumb messages and exception values both go through the free-text
        // redactor; the event's OWN message got nothing until now.
        $message = $event->getMessage();

        if ($message !== null) {
            // The TEMPLATE holds no data; the params do. All three arguments
            // are passed explicitly, and the THIRD nulls `formatted` — which the
            // SDK then rebuilds downstream of this callback.
            $params = array_map(
                fn (mixed $param): string => $this->scrubMessageParam($param),
                $event->getMessageParams()
            );

            // `formatted` is NULL deliberately: it holds the RAW params
            // interpolated, which `redactFreeText()` cannot reach. `EventItem`
            // falls back to `vsprintf(getMessage(), getMessageParams())`, so
            // null rebuilds it from the SCRUBBED halves.
            $event->setMessage($this->redactFreeText($message), $params, null);
        }

        // User context is dropped entirely rather than scrubbed field by field.
        // A candidate is not a Sentry "user", and an operator's identity adds
        // nothing to a stack trace that the organization scope does not already
        // give — so there is no case where keeping it is worth the risk of a
        // future Sentry version adding a field this code does not know about.
        $event->setUser(null);

        // The FINGERPRINT goes straight on the wire via `EventItem`, and
        // `configureScope(fn ($s) => $s->setFingerprint([$participant->email]))`
        // is the natural way to group scoring failures per candidate.
        $fingerprint = $event->getFingerprint();

        if ($fingerprint !== []) {
            $event->setFingerprint(array_map(
                fn (string $part): string => $this->redactFreeText($part),
                $fingerprint
            ));
        }

        // The TRANSACTION NAME carries the RAW client-controlled path: the
        // tracing middleware seeds it that way and only replaces it once a
        // ROUTE matches, so on a 404 it stays exactly as typed.
        $transaction = $event->getTransaction();

        if ($transaction !== null) {
            $event->setTransaction($this->redactFreeText($transaction));
        }

        return $event;
    }

    /**
     * @param  array<array-key, mixed>  $request
     * @return array<array-key, mixed>
     */
    private function scrubRequest(array $request): array
    {
        $scrubbed = $this->scrub($request);

        // Dropped WHOLESALE, not filtered: an allowlist of safe parameter
        // names is a promise nobody can keep. These are strings under
        // non-denied keys, and `scrub()` redacts by KEY — so the denylist
        // cannot see a `?token=` or an `X-Api-Key` here at all.
        // `query` as well as `query_string`: the request shape uses the latter,
        // the `http` context the former, and both carry the same `?token=`.
        unset(
            $scrubbed['query_string'],
            $scrubbed['query'],
            $scrubbed['cookies'],
            $scrubbed['headers'],
            // `env` goes with the user context, not without it. RequestIntegration
            // populates `env.REMOTE_ADDR` and builds the user bag from THAT SAME
            // value — the SDK treats them as one datum, so dropping `setUser(null)`
            // while keeping this kept the IP under another name.
            $scrubbed['env'],
            // `fragment` is the third name for the same value. `redactUrl()`
            // cuts at `?` AND `#`; this list dropped the two query spellings and
            // left the fragment carrying `token=…` untouched.
            $scrubbed['fragment'],
        );

        // `data` is a RAW STRING whenever the parsed body was empty and the
        // Content-Type is not byte-for-byte `application/json` —
        // RequestIntegration compares with `===`, so `application/json;
        // charset=utf-8` misses and the raw body comes through. `scrub()`
        // redacts by KEY, and a raw string has none, so a failing
        // `POST /api/auth/login` filed a plaintext password. An array body has
        // already been walked by key above and keeps its diagnostic value.
        if (isset($scrubbed['data']) && is_string($scrubbed['data'])) {
            $scrubbed['data'] = self::REDACTED;
        }

        // From the RAW url, not the walked copy. `url` is not a denied key, so
        // `scrub()` put it through the free-text pass, which collapses any URL
        // to scheme://host — `redactUrl()` then ran on a string that could no
        // longer hold the `?` it exists to cut, and every error event reported a
        // bare hostname. Which endpoint failed is the most useful field on the
        // event, and this class calls an unusable error reporter the worse
        // outcome. Same shape of bug as reading a stack frame back after the walk.
        if (isset($request['url']) && is_string($request['url'])) {
            $scrubbed['url'] = $this->redactUrl($request['url']);
        }

        return $scrubbed;
    }

    private function redactUrl(string $url): string
    {
        // The query string is dropped wholesale, not filtered by an allowlist of
        // parameter names — that is a promise nobody could keep, and `?token=`
        // is the one this file exists for.
        $cut = strcspn($url, '?#');
        $withoutQuery = substr($url, 0, $cut);

        // Userinfo goes too. Cutting at `?` alone left
        // `https://user:hunter2@host/x` intact here while the free-text pass —
        // which rebuilds through parse_url() — dropped it. Two passes claiming
        // the same promise must not disagree on the same input.
        $parts = parse_url($withoutQuery);

        if (isset($parts['user']) || isset($parts['pass'])) {
            $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
            $withoutQuery = $scheme.($parts['host'] ?? '').($parts['path'] ?? '');
        }

        // The address pass, which every other string in this class already gets.
        // Cutting `?`, `#` and userinfo left
        // `/api/participants/jane.doe@acme.test/transcript` whole — and a URL is
        // client-controlled, so a 404 on a hand-typed path is enough. This
        // function's own comment forbids exactly this: two passes claiming the
        // same promise must not disagree on the same input.
        return (string) preg_replace(
            self::EMAIL_PATTERN,
            self::REDACTED,
            $withoutQuery
        );
    }

    private function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $message === null ? null : $this->redactFreeText($message),
            $this->scrub($breadcrumb->getMetadata()),
            $breadcrumb->getTimestamp(),
        );
    }

    /**
     * A `"key": value` pair sitting INSIDE a longer string.
     *
     * `redactEmbeddedDocuments()` handles a whole-string document, and the carrier that matters most is not: a Guzzle exception reads
     * ``Client error: `POST /api/score` resulted in a `422` response:
     * {"transcript":[…]}``, and a breadcrumb records the same shape. Both reach
     * `redactFreeText()`, which cuts URLs and addresses and has nothing to say
     * about a denied KEY embedded in prose.
     *
     * A SCANNER, not a regex: a denied key can hold STRUCTURE, and structure is
     * exactly what the worst keys hold — `{"transcript":[…]}` is a candidate's
     * spoken answer, which is the single thing this class exists to stop.
     *
     * Scanning rather than decoding, deliberately: the embedded document is
     * frequently TRUNCATED — Sentry and most HTTP clients cap the body they
     * attach — and decoding a truncated document fails, which would hand the
     * whole thing back. An unterminated value is redacted to the end of the
     * string, which is the fail-closed direction.
     *
     * Both Nuxt mirrors carry the identical scanner in `redactEmbeddedPairs`.
     */
    private function redactEmbeddedPairs(string $text): string
    {
        if (! str_contains($text, '"')) {
            return $text;
        }

        $out = '';
        $cursor = 0;
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            // BOTH quote forms, and no length cap. A document nested inside
            // another JSON string arrives escaped — `{\"transcript\":…}`, the
            // ordinary shape once an error body has been serialised twice — and
            // the plain-quote pattern walked straight past it. The cap was the
            // same fail-open direction: a key longer than the bound was never
            // tested against the denylist at all.
            //
            // The key class is `[^"\\]+` — ANYTHING but a quote and a backslash,
            // which is exactly what a JSON key may hold — and not `[\w.-]+`.
            // The narrow class could not see the delimiter spellings `toSnakeKey`
            // can now segment, so an embedded `{"candidate ref":…}`,
            // `{"data[transcript]":…}` or `{"user:candidate_ref":…}` was never
            // even FOUND, let alone denied. Measured on a Guzzle 422 body inside
            // an exception message, the carrier this docblock already names.
            //
            // Bounded by its own quotes, so it cannot run past its key. A prose
            // value that happens to contain `"…":` can be read as a key and
            // denied — accepted, because that direction is fail-closed and this
            // class takes a false redaction over a false disclosure everywhere
            // else. Swept 184KB of quote-heavy prose: 0.1ms, no backtracking
            // pathology, output length unchanged.
            $matched = preg_match('/\\\\?"([^"\\\\]+)\\\\?"\s*:\s*/', $text, $match, PREG_OFFSET_CAPTURE, $offset);

            // FALSE and 0 are different answers, and collapsing them into one
            // `break` returned the raw remainder. `false` means the engine gave
            // up, and every other pass in this class fails CLOSED on exactly
            // that.
            if ($matched === false) {
                return self::REDACTED;
            }

            if ($matched !== 1) {
                break;
            }

            $valueStart = $match[0][1] + strlen($match[0][0]);

            if ($this->isDenied($match[1][0])) {
                // ESCAPED mode comes from the KEY's quoting, not the value's
                // opener: a structure value opens with a bare `{`, so deducing it
                // from the value left the flag false in a doubly-serialised
                // document and the walk swallowed every sibling field.
                $isEscaped = $match[0][0][0] === '\\';
                $valueEnd = $this->embeddedValueEnd($text, $valueStart, $isEscaped);
                // Quoted the way the DOCUMENT is. A plain-quoted marker inside
                // an escaped document closes the outer string early and the
                // rest of the payload stops being parseable — an error report
                // nobody can read, which is the outcome this class calls worse
                // than a scrubbed one.
                $marker = $isEscaped ? '\\"'.self::REDACTED.'\\"' : '"'.self::REDACTED.'"';
                $out .= substr($text, $cursor, $valueStart - $cursor).$marker;
                $cursor = $valueEnd;
                $offset = $valueEnd;

                continue;
            }

            $offset = $valueStart;
        }

        return $out.substr($text, $cursor);
    }

    /**
     * The index just past the value starting at `$from`, or the string length.
     */
    private function embeddedValueEnd(string $text, int $from, bool $escaped): int
    {
        $length = strlen($text);

        // `\"` is one delimiter spelled in two characters, so an escaped STRING
        // value starts one character later than its opener.
        $start = $escaped && ($text[$from] ?? '') === '\\' ? $from + 1 : $from;
        $opener = $text[$start] ?? '';

        // In escaped mode the delimiter is `\"` and an inner quote is `\\\"`.
        $closesString = function (int $i) use ($text, $escaped): int {
            if ($escaped) {
                return ($text[$i] ?? '') === '\\'
                    && ($text[$i + 1] ?? '') === '"'
                    && ($text[$i - 1] ?? '') !== '\\'
                    ? $i + 2
                    : 0;
            }

            return ($text[$i] ?? '') === '"' && ($text[$i - 1] ?? '') !== '\\' ? $i + 1 : 0;
        };

        if ($opener === '"') {
            for ($i = $start + 1; $i < $length; $i++) {
                $end = $closesString($i);

                if ($end !== 0) {
                    return $end;
                }
            }

            return $length;
        }

        if ($opener === '{' || $opener === '[') {
            $depth = 0;
            $inString = false;

            for ($i = $start; $i < $length; $i++) {
                $char = $text[$i];

                if ($inString) {
                    $end = $closesString($i);

                    if ($end !== 0) {
                        $inString = false;
                        $i = $end - 1;
                    }

                    continue;
                }

                if ($char === '"' || ($char === '\\' && ($text[$i + 1] ?? '') === '"')) {
                    $inString = true;
                    $i = $escaped ? $i + 1 : $i;
                } elseif ($char === '{' || $char === '[') {
                    $depth++;
                } elseif ($char === '}' || $char === ']') {
                    $depth--;

                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
            }

            return $length;
        }

        // A bare scalar runs to the next separator.
        for ($i = $start; $i < $length; $i++) {
            if (strpos(",}] \t\r\n", $text[$i]) !== false) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * A decoded DOCUMENT, walked under the body rule.
     *
     * `scrub()` is the general KEY walk: it denies by name and puts every other
     * string through the free-text pass. That is right for a log context, whose
     * entries all have names — and wrong for a document, whose list elements
     * have none. `["I led the migration alone"]` decoded, walked by `scrub()`
     * and re-encoded came back byte-identical: index keys deny nothing and
     * `redactFreeText()` has no slash, no `@` and no scheme to grip.
     *
     * So a LIST element is cut outright and a MAP entry keeps the key walk,
     * with its containers staying on this rule. Both Nuxt mirrors have carried
     * this split as `scrubBody` from the start; the api had only the key walk,
     * which is why the document rules landed here and changed nothing.
     *
     * Scoped to a DECODED document deliberately, not to `scrub()` at large: a
     * Sentry frame's `pre_context` is also a list of strings, and cutting those
     * is the symbolication loss this class calls worse than a scrubbed event.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>
     */
    private function scrubDocument(array $document, int $depth = 0): array
    {
        // NOT interchangeable with `scrub()`, and the swap was tried: `scrub()`
        // walks a value through `encode()`/`json_decode()` on the way in and
        // renames a denied KEY to the marker, which is right for a log context
        // and wrong for a document that has to come back out as JSON. One test
        // goes red on the substitution, which is why both walkers stay.

        // DEFENCE BEHIND A DOOR ALREADY SHUT, and said so rather than pinned:
        // this walker only ever receives a `json_decode()` result, and that
        // function's own default depth limit is 512 — the same number. The
        // decode refuses first, so the cap cannot fire and no test can reach it.
        // Kept because the input source is one refactor away from changing, and
        // because its `scrub()` twin exists for a crash that really did happen.
        if ($depth > self::MAX_SCRUB_DEPTH) {
            return [self::REDACTED => self::REDACTED];
        }

        $isList = array_is_list($document);
        /** @var array<string, int> $cursors */
        $cursors = [];
        $out = [];

        foreach ($document as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->scrubDocument($value, $depth + 1);

                continue;
            }

            // A LIST element has no key, so nothing can deny it and nothing in
            // the free-text pass can recognise it.
            if ($isList) {
                $out[$key] = is_string($value) ? self::REDACTED : $value;

                continue;
            }

            // ONE `isDenied()` call, not two: it normalises, runs two PCRE
            // passes and walks the run list, and it was being paid twice per
            // entry — once for the key, once inside the `match`.
            $denied = $this->isDenied((string) $key);

            // The KEY SURVIVES, the value does not — the way every sibling
            // walker here behaves. The name was never the secret, and replacing
            // it left an on-call engineer unable to tell WHICH field was
            // involved. It still goes through `redactFreeText()`, because an
            // ADDRESS can be a key.
            $outKey = is_string($key) ? $this->redactFreeText($key) : $key;

            // De-collision, the same guard `scrub()` carries and for the same
            // reason: two addresses normalise to the same marker and a plain
            // assignment would drop one.
            if (array_key_exists($outKey, $out)) {
                // O(1) per insert, not a rescan from 2. Every colliding key
                // restarted the search at the beginning, so n keys redacting to
                // the SAME marker cost O(n^2) probes. A map keyed by address is
                // the ordinary shape of a delivery-result map, and a map keyed
                // by absolute URL collides just as hard. The cursor remembers
                // where the scan for this base reached.
                $suffix = $cursors[$outKey] ?? 2;

                while (array_key_exists($outKey.'_'.$suffix, $out)) {
                    $suffix++;
                }

                $cursors[$outKey] = $suffix + 1;
                $outKey .= '_'.$suffix;
            }

            $out[$outKey] = match (true) {
                $denied => self::REDACTED,
                is_string($value) => $this->redactFreeText($value),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * A RELATIVE path carrying a query or fragment, anywhere in the text.
     *
     * `redactUrl()` cuts at `?` and `#`; this class's free-text URL pass is
     * anchored to `https?://`, so it only ever saw an ABSOLUTE one. The two
     * therefore disagreed on the same string — `/auth/magic?token=<jwt>` came
     * back cut under `request.url` and verbatim under `transaction`, a
     * breadcrumb, an exception message or `extra`. The transaction is the
     * sharpest: the tracing middleware seeds it with the raw client-controlled
     * path and only replaces it once a ROUTE matches, so on a 404 it stays as
     * typed.
     *
     * The invariant this restores is already written down here — two passes
     * claiming the same promise must not disagree on the same input.
     *
     * A leading `/` preceded by a NON-WORD character is what distinguishes a
     * rooted path from prose, so an ordinary sentence ending in `?` is untouched
     * and `foo/bar?x=1` — a fragment mid-token, not a path — is left alone.
     *
     * An earlier version listed the allowed leads by hand, and anything else
     * meant the pass never fired: `x:/auth/magic?token=<jwt>` shipped a live
     * bearer token, and `to=/participants?q=Ada Lovelace` shipped a candidate's
     * surname, because the free-text query cut lives inside this pass.
     *
     * The path class EXCLUDES `?` and `#`. Without that it is greedy and
     * backtracks to the LAST delimiter, keeping everything before it — so
     * `/auth/magic?token=<jwt>#f` came back with the token intact.
     * `redactUrl()` cuts at the FIRST of either via `strcspn($url, '?#')`, and
     * the invariant this pass restores is that the two must not disagree. The COST is that a
     * harmless `?y=1` goes too: the same trade `redactUrl()` makes, because an
     * allowlist of safe parameter names is a promise nobody could keep.
     */
    private function redactRelativeQuery(string $text): string
    {
        $out = '';
        $cursor = 0;
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $matched = preg_match(self::RELATIVE_QUERY_PATTERN, $text, $m, PREG_OFFSET_CAPTURE, $offset);

            // FALSE and 0 are different answers: `false` means the engine gave
            // up, and collapsing them into one `break` returned the raw
            // remainder. UNREACHABLE today — this pattern does not backtrack —
            // so declared rather than pinned with a test that cannot fail.
            if ($matched === false) {
                return self::REDACTED;
            }

            if ($matched !== 1) {
                break;
            }

            $lead = $m[1][0];
            $path = $m[2][0];
            $queryStart = $m[0][1] + strlen($lead) + strlen($path);
            $query = substr($text, $queryStart, $m[0][1] + strlen($m[0][0]) - $queryStart);

            // A FREE-TEXT key takes the rest of the LINE. Ending at the first
            // space is right for a credential — a JWT has none — and wrong for
            // `?q=Ada Lovelace`, which left ` Lovelace` standing. See
            // FREE_TEXT_QUERY_KEYS.
            $freeText = false;

            if (preg_match_all(self::QUERY_KEY_PATTERN, $query, $pairs) > 0) {
                foreach ($pairs[1] as $queryKey) {
                    if (in_array(strtolower($queryKey), self::FREE_TEXT_QUERY_KEYS, true)) {
                        $freeText = true;

                        break;
                    }
                }
            }

            $cutEnd = $m[0][1] + strlen($m[0][0]);

            if ($freeText) {
                $lineEnd = strpos($text, "\n", $queryStart);
                $cutEnd = $lineEnd === false ? $length : $lineEnd;
            }

            $out .= substr($text, $cursor, $m[0][1] - $cursor).$lead.$path;
            $cursor = $cutEnd;
            $offset = $cutEnd;
        }

        return $out.substr($text, $cursor);
    }

    /**
     * A JSON DOCUMENT embedded in prose, scrubbed as the document it is.
     *
     * `redactEmbeddedPairs()` finds `"key": value` pairs, so it saves the object
     * form. A bare ARRAY has no keys at all — `["I led the migration alone"]` —
     * and a `json_encode()`d transcript inside an exception message or a
     * breadcrumb produces exactly that.
     *
     * Only spans that actually DECODE are replaced. That is what keeps it from
     * eating `at [internal function]` or a bracketed log prefix: those are not
     * JSON, the decode fails, and the text is left alone.
     *
     * Both Nuxt mirrors carry the identical pass in `redactEmbeddedDocuments`.
     */
    private function redactEmbeddedDocuments(string $text): string
    {
        if (! str_contains($text, '[') && ! str_contains($text, '{')) {
            return $text;
        }

        // PAIRS, not a depth counter. The counter only attempted a span when it
        // returned to zero, so ONE unmatched `{` or `[` earlier in the string
        // latched it open and every later document went unattempted. That is the
        // sibling of the stray-QUOTE latch, and only one of the two was fixed.
        //
        // Recording pairs as they close means an opener that never closes simply
        // never produces one. Still a single pass, still linear.
        $stack = [];
        $pairs = [];
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            // Quotes only count INSIDE a span, or one unmatched `"` in prose
            // latches this flag on for the rest of the input.
            if ($char === '"' && $stack !== []) {
                $inString = true;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $stack[] = ['at' => $i, 'opener' => $char];

                continue;
            }

            if ($char !== '}' && $char !== ']') {
                continue;
            }

            $wanted = $char === '}' ? '{' : '[';
            $top = $stack === [] ? null : $stack[count($stack) - 1];

            // The TYPE check is a choice, not a claim: a wrongly-paired span
            // fails `json_decode` and is skipped either way. It stays so the
            // stack is never left holding an opener that did not close.
            if ($top === null || $top['opener'] !== $wanted) {
                continue;
            }

            array_pop($stack);
            $pairs[] = ['start' => $top['at'], 'end' => $i + 1];
        }

        if ($pairs === []) {
            return $text;
        }

        // OUTERMOST first, so a nested span is not rewritten and then rewritten
        // again inside its parent.
        usort($pairs, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $out = '';
        $cursor = 0;

        foreach ($pairs as $pair) {
            if ($pair['start'] < $cursor) {
                continue;
            }

            // Past the length bound the span is REDACTED, not skipped: the bound
            // is a cost decision, and skipping turns it into a disclosure one.
            if ($pair['end'] - $pair['start'] > self::JSON_STRING_LIMIT) {
                $out .= substr($text, $cursor, $pair['start'] - $cursor).self::REDACTED;
                $cursor = $pair['end'];

                continue;
            }

            $decoded = json_decode(substr($text, $pair['start'], $pair['end'] - $pair['start']), true);

            if (! is_array($decoded)) {
                continue;
            }

            $out .= substr($text, $cursor, $pair['start'] - $cursor).$this->encode($this->scrubDocument($decoded));
            $cursor = $pair['end'];
        }

        return $out.substr($text, $cursor);
    }

    /**
     * The LOG record, for `before_send_log`.
     *
     * A separate entry point because the SDK hands this callback a different
     * type — `callable(Log): ?Log` — which `handle()` cannot satisfy. That
     * signature mismatch was the whole reason the hook sat unwired, and
     * "dormant behind an env var" is the argument this class already REFUSED
     * three times: for spans, for the event stacktrace, and for the
     * fingerprint. `SENTRY_ENABLE_LOGS=true` is one variable, and a log body is
     * `Log::info('scored %s', [$participant->email])` — free text with no key
     * for the denylist to see.
     *
     * The BODY takes the free-text pass; the ATTRIBUTES take the key walk, with
     * a denied name replaced rather than dropped, the way every other branch
     * here keeps the key and loses the value.
     *
     * Returns `Log`, not `?Log`. The SDK's signature permits null to DROP the
     * record, and this class never does that — an event that says a scrub
     * happened is a signal, and silence is not. A narrower return satisfies a
     * `callable(Log): ?Log` parameter.
     */
    public static function handleLog(Log $log): Log
    {
        $scrubber = new self;

        /** @var array<string, int> $cursors */
        $cursors = [];

        $log->setBody($scrubber->redactFreeText($log->getBody()));

        foreach ($log->attributes()->all() as $key => $attribute) {
            $value = $attribute->getValue();

            // The KEY can carry the secret too — the rule `scrub()` and
            // `scrubDocument()` already carry, and the one walker that did not
            // get it. `isDenied()` inspects what a key is CALLED and
            // `redactFreeText()` what a value CONTAINS; nothing inspected what
            // the key itself contains, so an address used as an attribute name
            // walked out untouched. A map keyed by address is the ordinary shape
            // of a delivery-result map.
            $outKey = $scrubber->redactFreeText($key);

            // De-collision, the loop `scrub()` and `scrubDocument()` already
            // carry and the newest walker did not: two addresses normalise to
            // the same marker and `setAttribute()` ASSIGNS, so the second
            // overwrote the first. Two attributes in, one out — and a map keyed
            // by address is the ordinary shape of a delivery-result map.
            if ($outKey !== $key) {
                $log->attributes()->forget($key);

                // APPENDS to the redacted base and carries the CURSOR, like
                // both siblings: the name is not the secret, and restarting the
                // scan at 2 on every collision is O(n^2) on a map keyed by
                // address — the ordinary shape of a delivery-result map.
                $suffix = $cursors[$outKey] ?? 2;
                $base = $outKey;

                while ($log->attributes()->get($outKey) !== null) {
                    $outKey = $base.'_'.$suffix;
                    $suffix++;
                }

                $cursors[$base] = $suffix;
            }

            if ($scrubber->isDenied($key)) {
                $log->setAttribute($outKey, self::REDACTED);

                continue;
            }

            $log->setAttribute($outKey, is_string($value) ? $scrubber->redactFreeText($value) : $value);
        }

        return $log;
    }

    /**
     * Free text carries no key, so the denylist cannot see into it.
     *
     * Both passes fail CLOSED. A PCRE failure (backtrack or JIT stack limit)
     * used to return the untouched string from the URL pass — handing back the
     * `?token=` it was called to strip — while the email pass blanked the field
     * on the same failure. Two opposite behaviours in one function, only one of
     * them safe.
     */
    private function redactFreeText(string $text): string
    {
        // The EMBEDDED passes run FIRST and therefore on every branch below,
        // because a denied key can ride inside any of them — a bare route, a
        // prose message, an exception value. Running them once here is what
        // keeps that from being three decisions.
        $text = $this->redactRelativeQuery(
            $this->redactEmbeddedDocuments($this->redactEmbeddedPairs($text))
        );

        $withoutUrls = preg_replace_callback(
            '#https?://[^\s"\'<>]+#i',
            static function (array $match): string {
                $parts = parse_url($match[0]);

                return isset($parts['scheme'], $parts['host'])
                    ? $parts['scheme'].'://'.$parts['host']
                    : self::REDACTED;
            },
            $text
        ) ?? self::REDACTED;

        // See `EMAIL_PATTERN` for the class choice and for why the Nuxt mirrors
        // carry a lookbehind this does not.
        return (string) preg_replace(self::EMAIL_PATTERN, self::REDACTED, $withoutUrls);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data, int $depth = 0): array
    {
        // DEPTH CAP, because a self-referential ARRAY — which is what a Laravel
        // log context is — exhausted the stack and killed PHP with SIGSEGV:
        // nothing catchable, the error reporter taking the process down while
        // reporting an error. 512 matches `json_decode`'s own default.
        if ($depth > self::MAX_SCRUB_DEPTH) {
            return [self::REDACTED => self::REDACTED];
        }

        // A LIST element has no key, so nothing can deny it and nothing in the
        // free-text pass can recognise it. Cutting it only inside a decoded
        // document meant the same transcript shipped as `['lines' => $lines]`
        // and was redacted as the json_encode of it — two shapes, two answers.
        //
        // Safe for FRAMES: `pre_context` never reaches this walker, only `vars`,
        // which is a map of variable names.
        $isList = array_is_list($data);
        /** @var array<string, int> $cursors */
        $cursors = [];
        $out = [];

        foreach ($data as $key => $value) {
            // An OBJECT is not an array either, so it must be excluded here
            // explicitly — otherwise it takes this branch, fails `is_string()`
            // and is assigned VERBATIM, never reaching the `is_object()` walk.
            if ($isList && ! is_array($value) && ! is_object($value)) {
                $out[$key] = is_string($value) ? self::REDACTED : $value;

                continue;
            }

            // The KEY can carry the secret too. `isDenied()` inspects what a key
            // is CALLED and `redactFreeText()` what a value CONTAINS — nothing
            // inspected what a key contains, so this class denied `email`,
            // `emails` and `email_address` by name and then handed the address
            // over the moment it moved one position left. Keying a map by
            // address is the ordinary shape of a delivery-result map.
            $outKey = is_string($key) ? $this->redactFreeText($key) : $key;

            // Two addresses normalise to the same marker, and a plain
            // assignment would drop one — silent diagnostic loss, not a leak.
            // NOT gated on `$outKey !== $key`: a sibling that is ALREADY the
            // literal marker skipped the check and overwrote what came before,
            // so `['a@b.test' => 'first', '[redacted]' => 'second']` reported one
            // entry — the very collapse this guard exists to prevent.
            if (array_key_exists($outKey, $out)) {
                // O(1) per insert, not a rescan from 2. Every colliding key
                // restarted the search at the beginning, so n keys redacting to
                // the SAME marker cost O(n^2) probes. A map keyed by address is
                // the ordinary shape of a delivery-result map, and a map keyed
                // by absolute URL collides just as hard. The cursor remembers
                // where the scan for this base reached.
                $suffix = $cursors[$outKey] ?? 2;

                while (array_key_exists($outKey.'_'.$suffix, $out)) {
                    $suffix++;
                }

                $cursors[$outKey] = $suffix + 1;
                $outKey .= '_'.$suffix;
            }

            if (is_string($key) && $this->isDenied($key)) {
                $out[$outKey] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $out[$outKey] = $this->scrub($value, $depth + 1);

                continue;
            }

            // An OBJECT is neither array nor string and fell through untouched:
            // sentry-laravel hands `$logEntry->context` to breadcrumb metadata
            // RAW, so an Eloquent model emitted its whole attribute bag. Walked
            // through the SAME shape the wire uses, and FAIL CLOSED.
            if (is_object($value)) {
                $decoded = json_decode($this->encode($value), true);

                // A scalar is not UNWALKABLE, it is already walked. Testing
                // `is_array()` alone sent every backed enum and Carbon instance
                // dark — the two most common non-scalars in a Laravel log
                // context, neither carrying PII. REDACTED is reserved for what
                // genuinely cannot be inspected.
                $out[$outKey] = match (true) {
                    is_array($decoded) => $this->scrub($decoded, $depth + 1),
                    is_string($decoded) => $this->redactFreeText($decoded),
                    is_scalar($decoded) => $decoded,
                    default => self::REDACTED,
                };

                continue;
            }

            $out[$outKey] = is_string($value)
                ? $this->redactFreeText($value)
                : $value;
        }

        return $out;
    }

    /**
     * One message param, walked BEFORE it is flattened.
     *
     * `scrub()` first and `stringify()` second — the order the tag path already
     * uses. Flattening first hands a JSON blob to `redactFreeText()`, which
     * strips only URLs and addresses, so the denylist never ran and
     * `display_name`/`candidate_ref`/`transcript` went out verbatim inside the
     * string.
     *
     * A `null` param stays EMPTY rather than rendering as the literal "null":
     * `sprintf('user %s failed', null)` must keep saying `user  failed`.
     */
    private function scrubMessageParam(mixed $param): string
    {
        if ($param === null) {
            return '';
        }

        // A `sprintf` param fills a NAMED slot, so it is not a keyless element:
        // a string takes the free-text pass and a container takes the key walk.
        // Wrapping it in `scrub([$param])` made the keyless list rule read the
        // wrapper as real and cut every string param to the marker.
        if (is_string($param)) {
            return $this->redactFreeText($param);
        }

        return $this->redactFreeText(
            $this->stringify(is_array($param) ? $this->scrub($param) : $param)
        );
    }

    /**
     * A value as a STRING, without a bare cast.
     *
     * `(string)` on an array raises "Array to string conversion", which Laravel
     * turns into a thrown ErrorException — the error reporter failing while it
     * reports an error — and emits the literal "Array" where the value was.
     */
    private function stringify(mixed $value): string
    {
        return is_string($value) ? $value : $this->encode($value);
    }

    /**
     * The ONE place this class serializes, and it never fails loudly.
     *
     * Bare `json_encode()` emits an E_WARNING on a recursive reference, and
     * Laravel's `HandleExceptions::handleError` turns any E_WARNING into a
     * thrown ErrorException — so the error reporter would fail while reporting
     * an error. `JSON_PARTIAL_OUTPUT_ON_ERROR` yields `null` for the offending
     * branch instead, `JSON_INVALID_UTF8_SUBSTITUTE` keeps a value that carries
     * a bad byte rather than blanking it whole, and `JSON_PRESERVE_ZERO_FRACTION`
     * stops `1.0` reporting as `1` in a diagnostic.
     */
    private function encode(mixed $value): string
    {
        $encoded = @json_encode(
            $value,
            JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $encoded === false ? self::REDACTED : $encoded;
    }

    private function isDenied(string $key): bool
    {
        // Hyphen, dot and WHITESPACE all fold: keys arrive as `X-Api-Key`,
        // `auth.token` and `'candidate ref'`, and `extra`/`contexts`/breadcrumb
        // metadata carry no charset restriction to stop the third. Hand-rolled
        // rather than `Str::snake()`, whose static cache never evicts and would
        // be fed keys straight from a request body.
        $normalized = (string) preg_replace('/\W+/', '_', $key);

        // The EMPTY trailing segment a CLOSING delimiter leaves.
        // `data[content]` folded to `data_content_`, whose parts are
        // `['data','content','']`, and the single-word rule requires the denied
        // word to BE the last one — so `content` was never tested there and
        // candidate speech walked, one character from `data.content` being cut.
        // Leading too, for `[content]`.
        $normalized = (string) preg_replace('/^_+|_+$/', '', $normalized);

        // The letter->DIGIT boundary first: without it the normalizer produced
        // `answer1` as ONE segment, so `answer1` shipped while `answer_1` — the
        // same field, one character away — was denied.
        $digitSplit = (string) preg_replace('/([A-Za-z])([0-9])/', '$1_$2', $normalized);

        $camelSplit = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $digitSplit);
        $acronymSplit = $camelSplit === null
            ? null
            : preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $camelSplit);

        // Fails CLOSED, and UNREACHABLE today — neither pattern backtracks, so
        // `preg_replace()` cannot return null. Declared rather than pinned with
        // a test that could not fail.
        //
        // `(string) null` is `''`, which matches no list entry and
        // no convention — so a PCRE failure used to mark the key SAFE and emit
        // its value. `redactFreeText` was given this same treatment for the same
        // reason; a scrubber must not default to disclosure.
        if ($acronymSplit === null) {
            return true;
        }

        $k = strtolower($acronymSplit);

        if (in_array($k, self::DENIED_KEYS, true)) {
            return true;
        }

        // Every contiguous RUN of segments, BOUNDED by the longest denied key.
        // A denied name anywhere inside a compound key is still that name
        // (`http.request.header.authorization`, `candidate_ref_original`), and
        // the bound matters because the unbounded form is cubic on a key whose
        // length a request body influences.
        $parts = explode('_', $k);
        $count = count($parts);

        // MULTI-WORD entries match any contiguous run; SINGLE-WORD entries match
        // only the whole key or its LAST segment, unless they are on
        // DENIED_CONTENT_WORDS. That list's docblock owns the reasoning and the
        // `content` carve-out.
        for ($i = 0; $i < $count; $i++) {
            $limit = min($count, $i + self::MAX_DENIED_SEGMENTS);

            for ($j = $i + 1; $j <= $limit; $j++) {
                if ($i === 0 && $j === $count) {
                    continue;
                }

                $run = array_slice($parts, $i, $j - $i);

                // A single-word run only counts as the whole key or its LAST
                // segment — except for the candidate-content words, which count
                // anywhere. See `DENIED_CONTENT_WORDS` for why the two lists.
                if (count($run) === 1 && $j !== $count && ! in_array($run[0], self::DENIED_CONTENT_WORDS, true)) {
                    continue;
                }

                if (in_array(implode('_', $run), self::DENIED_KEYS, true)) {
                    return true;
                }
            }
        }

        // Conventions, so a newly-named field is covered without an edit here.
        // ONLY `_key` among the credential suffixes. `_token`, `_secret` and
        // `_messages` were shadowed dead by the last-segment check above —
        // `token`, `secret` and `messages` are all in DENIED_KEYS, so that
        // branch always decided first. `key` alone is NOT in the list (too
        // generic to deny outright), which is why this one stays reachable.
        return str_ends_with($k, '_key')
            // `_keys` too. The list gained `api_keys`; the CONVENTION did not,
            // so `stripe_api_keys` was ALLOWED while `stripe_api_key` was denied
            // — the plural strictly weaker than the singular, which is the exact
            // defect this rule exists to close. `keys` is not in the list, so
            // nothing shadows this the way it shadows `_token`/`_secret`.
            || str_ends_with($k, '_keys')
            // Any key NAMING an address. `_email` alone missed `email_address`,
            // `emails` and `emailAddress` — `excerpts` was already pluralised in
            // the list above and the same reasoning stopped one word short of
            // the field ruling 8 makes the global identity key.
            || str_contains($k, 'email');
    }
}
