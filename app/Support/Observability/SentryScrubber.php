<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
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
     * Keys whose values never leave the building.
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
        // `header` as well as `headers`: every other credential pair here has
        // both forms — token/tokens, secret/secrets, password/passwords,
        // cookie/cookies — and this was the one that did not.
        'header',
        // The PLURALS. The content keys were pluralised and the credential keys
        // never were — same list, same rule, half applied.
        'tokens',
        'api_keys',
        'passwords',
        'secrets',
        'cookies',
        'headers',
        // Candidate-identifying and candidate-authored content.
        'candidate_ref',
        'display_name',
        // The candidate email is the GLOBAL identity key (CLAUDE.md ruling 8,
        // reversed 2026-09-01) and is named in the GDPR retention sign-off
        // (ruling 2). Unlike candidate_ref it is directly identifying with no
        // calling system needed to resolve it.
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
        // `text` is the name this PRODUCT uses for a candidate's transcribed
        // speech — `utterances.text` in the schema, the validated field on
        // UtteranceController, and HeygenProvider's transcript shape. The list
        // named five synonyms and missed the one the database uses.
        'text',
        // The AI conversation arrives as a JSON STRING — AiIntegration's
        // `truncateMessages(): string` json_encodes it — so it lands under one
        // key with nothing inside for a key denylist to walk. Identical argument
        // to the wholesale drops of `query_string` and `request.data` above.
        'messages',
        // The LLM's behavioural rationale on `indicator_scores`. `payload`
        // covers it on the webhook path; a bare `explanation` had nothing.
        'explanation',
        'payload',
    ];

    private const REDACTED = '[redacted]';

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

        // The EVENT-LEVEL stacktrace, which is a second one. `EventItem`
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
            '/[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}/',
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

        // The classes are what an ADDRESS uses, not merely "not whitespace". A
        // broader local part swallowed any path containing an `@` and, with this
        // redactor now running on every string value, turned vendor traces into
        // `[redacted]` — not a leak, just an error reporter that can no longer
        // say where anything broke. The literal `@` anchors the scan, so the
        // looser classes' quadratic cost on a long `@`-free string is gone too —
        // it is the anchor that does that, not the TLD run, which has no upper
        // bound.
        return (string) preg_replace('/[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}/', self::REDACTED, $withoutUrls);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
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
                $suffix = 2;

                while (array_key_exists($outKey.'_'.$suffix, $out)) {
                    $suffix++;
                }

                $outKey .= '_'.$suffix;
            }

            if (is_string($key) && $this->isDenied($key)) {
                $out[$outKey] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $out[$outKey] = $this->scrub($value);

                continue;
            }

            // Free text has no key for a key denylist to catch, and a
            // provider's error message is the string nobody here controls.
            // An OBJECT is neither an array nor a string, and fell through
            // untouched. sentry-laravel hands `$logEntry->context` to breadcrumb
            // metadata RAW and the serializer JSON-encodes it, so an Eloquent
            // model emitted its whole attribute bag — `email`, `display_name`,
            // `candidate_ref`, every one of them on the list above. Walked
            // through the SAME shape the wire uses, and FAIL CLOSED: an object
            // this cannot walk is an object it does not send.
            if (is_object($value)) {
                $decoded = json_decode($this->encode($value), true);

                // A scalar is not UNWALKABLE, it is already walked. Testing
                // `is_array()` alone sent every backed enum and Carbon instance
                // dark — the two most common non-scalars in a Laravel log
                // context, neither carrying PII. REDACTED is reserved for what
                // genuinely cannot be inspected.
                $out[$outKey] = match (true) {
                    is_array($decoded) => $this->scrub($decoded),
                    is_string($decoded) => $this->redactFreeText($decoded),
                    is_scalar($decoded) => $decoded,
                    default => self::REDACTED,
                };

                continue;
            }

            $out[$outKey] = is_string($value) ? $this->redactFreeText($value) : $value;
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

        // `scrub()` is key-preserving, so index 0 always comes back.
        return $this->redactFreeText($this->stringify($this->scrub([$param])[0]));
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
        // Hyphens and dots first: header names arrive as `X-Api-Key` and
        // OpenTelemetry attributes as `auth.token`, and the words those end in
        // are already denied below. The second pattern splits acronym-leading
        // camelCase (`APIKey`, `SSOToken`), which the first cannot. Hand-rolled
        // rather than `Str::snake()`, whose static cache never evicts and would
        // be fed keys straight from a request body.
        $normalized = str_replace(['-', '.'], '_', $key);

        $camelSplit = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $normalized);
        $acronymSplit = $camelSplit === null
            ? null
            : preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $camelSplit);

        // Fails CLOSED. `(string) null` is `''`, which matches no list entry and
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

        // The LAST SEGMENT, because a namespaced key names its field at the end:
        // `http.request.header.authorization`, `user.content`,
        // `request.transcript`. Each of those trailing words is already in the
        // list; matching the whole normalised string alone could never see them,
        // which is how an exact-match list ended up not covering its own entries.
        $lastSegment = strrchr($k, '_');

        if ($lastSegment !== false && in_array(substr($lastSegment, 1), self::DENIED_KEYS, true)) {
            return true;
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
