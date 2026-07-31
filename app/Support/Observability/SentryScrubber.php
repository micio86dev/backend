<?php

declare(strict_types=1);

namespace App\Support\Observability;

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
        'transcript',
        'prompt',
        'answer',
        'excerpt',
        'excerpts',
        'utterance',
        'content',
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
            $event->setRequest($this->scrub($request));
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            /** @var array<string, mixed> $scrubbed */
            $scrubbed = $this->scrub($extra);
            $event->setExtra($scrubbed);
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

            $out[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $out;
    }

    private function isDenied(string $key): bool
    {
        $k = strtolower($key);

        if (in_array($k, self::DENIED_KEYS, true)) {
            return true;
        }

        // Conventions, so a newly-named field is covered without an edit here.
        return str_ends_with($k, '_token')
            || str_ends_with($k, '_secret')
            || str_ends_with($k, '_key');
    }
}
