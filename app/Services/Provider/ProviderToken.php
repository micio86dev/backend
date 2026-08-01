<?php

declare(strict_types=1);

namespace App\Services\Provider;

/**
 * Provider-neutral token payload returned by ProviderSessionService::issue().
 *
 * NEVER carries secret API keys.
 *
 * Fields:
 *   provider           — 'heygen' | 'tavus' (REQUIRED — teardown is provider-routed)
 *   token              — ephemeral session token (HeyGen LiveAvatar); null for Tavus
 *   conversation_url   — conversation URL (Tavus); null for HeyGen
 *   provider_session_ref — opaque ref used to identify/teardown the provider session
 *
 * Static factory for teardown of an already-persisted session ref (RESUME in_corso path):
 *   ProviderToken::fromRef(string $provider, string $ref): self
 *   — creates a minimal ProviderToken carrying the provider name + provider_session_ref
 *     (other fields null). The provider name is REQUIRED (F1): teardown dispatch is
 *     provider-routed (HeyGen vs Tavus), so a token with an empty provider would route
 *     to no branch and silently orphan the old session — defeating FIX-1.
 *     Always pass $session->provider (the session row carries the resolved provider).
 *
 * Security:
 *   This class MUST NEVER carry API key material. It is serialized into responses and
 *   passed between service layers. Keys live only in config/interview.php.
 *
 * REQ: ProviderToken value object (C7a)
 */
readonly class ProviderToken
{
    public function __construct(
        public string $provider,
        public ?string $token = null,
        public ?string $conversation_url = null,
        public ?string $provider_session_ref = null,
    ) {}

    /**
     * Create a minimal ProviderToken from a persisted provider + session ref.
     *
     * Used during RESUME in_corso teardown: the old session ref IS persisted in the DB
     * (non-null) — wrap it here so teardown() always receives a typed ProviderToken,
     * never a raw string.
     *
     * F1 safety: provider MUST be non-empty. An empty provider string would route
     * teardown() to no provider branch, silently orphaning the old session.
     *
     * @throws \InvalidArgumentException if provider is empty.
     */
    public static function fromRef(string $provider, string $ref): self
    {
        if ($provider === '') {
            throw new \InvalidArgumentException(
                'ProviderToken::fromRef() requires a non-empty provider (F1). '.
                'Pass $session->provider to ensure teardown routes to the correct provider client.'
            );
        }

        return new self(provider: $provider, provider_session_ref: $ref);
    }
}
