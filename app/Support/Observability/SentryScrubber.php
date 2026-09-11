<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

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
                static fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value),
                $this->scrub($tags)
            );
            $event->setTags($scrubbedTags);
        }

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, $this->scrub($context));
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
            foreach ($spans as $span) {
                // `setData()` and `setTags()` both array_merge rather than
                // replace, so these read like assignments and behave like
                // overwrites. That is correct here only because span keys are
                // dotted attribute names (`gen_ai.input.messages`) — an
                // integer-like key would be RENUMBERED and appended, leaving the
                // original beside the redacted copy.
                $span->setData($this->scrub($span->getData()));

                // Tags ride the same wire as data: `serializeSpan()` transmits
                // them, and `collectV2Attributes()` folds tags and data into one
                // attributes bag. Event-level tags were already walked; this is
                // the same treatment one object down.
                /** @var array<string, string> $spanTags */
                $spanTags = array_map(
                    static fn ($value): string => (string) $value,
                    $this->scrub($span->getTags())
                );
                $span->setTags($spanTags);

                $description = $span->getDescription();

                if ($description !== null) {
                    $span->setDescription($this->redactFreeText($description));
                }
            }

            $event->setSpans($spans);
        }

        // An exception message has no KEY for a key denylist to catch, and the
        // standards forbid confidential content in one outright.
        $exceptions = $event->getExceptions();

        if ($exceptions !== []) {
            foreach ($exceptions as $exception) {
                $exception->setValue($this->redactFreeText($exception->getValue()));
            }

            $event->setExceptions($exceptions);
        }

        // Breadcrumb messages and exception values both go through the free-text
        // redactor; the event's OWN message got nothing until now.
        $message = $event->getMessage();

        if ($message !== null) {
            // The TEMPLATE never holds the data; the params do. And
            // `setMessage()` called with two arguments nulls `formatted`, after
            // which the SDK rebuilds it from template + params downstream of
            // this callback — so redacting the template alone was a no-op that
            // read like a fix. All three are passed explicitly.
            $params = array_map(
                // Untyped and coerced rather than hinted `string`: the SDK's
                // PHPDoc promises strings, but a hint that can be violated
                // raises a TypeError under strict_types INSIDE before_send —
                // a failure in the error reporter itself.
                fn ($param): string => $this->redactFreeText((string) $param),
                $event->getMessageParams()
            );

            $formatted = $event->getMessageFormatted();

            $event->setMessage(
                $this->redactFreeText($message),
                $params,
                $formatted === null ? null : $this->redactFreeText($formatted)
            );
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

        // Dropped WHOLESALE rather than filtered, the same call both TS mirrors
        // make. `scrub()` redacts by KEY and only recurses into arrays, so a
        // STRING under a non-denied key walked straight out — and Sentry's
        // RequestIntegration populates `query_string` and `url` (full URI, query
        // included) as plain strings. `GET /api/sso/exchange?token=<jwt>` failing
        // past the signature check therefore filed a still-unspent, still
        // replayable token into a searchable third-party index, which is the
        // exact scenario this class's docblock names. Headers and cookies go for
        // the same reason: `X-Api-Key` is neither denied by name nor caught by
        // the `_key` convention, the separator being a hyphen.
        unset($scrubbed['query_string'], $scrubbed['cookies'], $scrubbed['headers']);

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

            return $scheme.($parts['host'] ?? '').($parts['path'] ?? '');
        }

        return $withoutQuery;
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
        return (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', self::REDACTED, $withoutUrls);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isDenied($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->scrub($value);

                continue;
            }

            // A STRING under a non-denied key is free text, and free text has no
            // key for a key denylist to catch — the argument this class already
            // makes for `request.data`, withheld until now from extra, contexts,
            // tags and breadcrumb metadata. A provider's error message lands in
            // exactly one of those and is the string nobody here controls.
            $out[$key] = is_string($value) ? $this->redactFreeText($value) : $value;
        }

        return $out;
    }

    private function isDenied(string $key): bool
    {
        // `strtolower('candidateRef')` was `candidateref` — in no list, ending in
        // no convention suffix — so every camelCase spelling walked straight out
        // while both TS mirrors caught it with `toSnakeKey()`.
        //
        // Hyphens AND dots go first. Header names arrive as `X-Api-Key`, and
        // OpenTelemetry attributes arrive dotted — `auth.token`, `user.content`,
        // `request.transcript`, `http.request.header.authorization`. Every one of
        // those trailing words is already in the list below; without this the
        // normalizer simply could not reach them.
        //
        // The second pattern is what a lone `/([a-z0-9])([A-Z])/` cannot do:
        // `APIKey` and `SSOToken` have no lowercase character before the
        // uppercase one.
        //
        // Hand-rolled rather than `Str::snake()`, whose static cache never
        // evicts and would be fed here with keys taken straight from a request
        // body — unbounded growth in a long-lived queue worker.
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
        return str_ends_with($k, '_token')
            || str_ends_with($k, '_secret')
            || str_ends_with($k, '_key')
            // Any key NAMING an address. `_email` alone missed `email_address`,
            // `emails` and `emailAddress` — `excerpts` was already pluralised in
            // the list above and the same reasoning stopped one word short of
            // the field ruling 8 makes the global identity key.
            || str_contains($k, 'email')
            // `gen_ai.input.messages` normalises to `gen_ai_input_messages`.
            || str_ends_with($k, '_messages');
    }
}
