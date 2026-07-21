<?php

declare(strict_types=1);

/**
 * ProviderToken value object unit tests (C7a — Phase 7.1 RED).
 *
 * Asserts:
 * - Constructor accepts all fields; readonly properties are set correctly.
 * - fromRef(string $provider, string $ref) factory creates token with correct
 *   provider + provider_session_ref; token and conversation_url are null.
 * - provider field is REQUIRED in fromRef (no empty-string silencing — F1).
 *
 * Tasks: 7.1 (RED)
 * REQ: ProviderToken value object (C7a)
 */

use App\Services\Provider\ProviderToken;

test('ProviderToken constructor sets all fields', function (): void {
    $token = new ProviderToken(
        provider: 'heygen',
        token: 'abc123',
        conversation_url: null,
        provider_session_ref: 'ref-xyz',
    );

    expect($token->provider)->toBe('heygen');
    expect($token->token)->toBe('abc123');
    expect($token->conversation_url)->toBeNull();
    expect($token->provider_session_ref)->toBe('ref-xyz');
});

test('ProviderToken fields are nullable with defaults', function (): void {
    $token = new ProviderToken(provider: 'tavus');

    expect($token->provider)->toBe('tavus');
    expect($token->token)->toBeNull();
    expect($token->conversation_url)->toBeNull();
    expect($token->provider_session_ref)->toBeNull();
});

test('ProviderToken::fromRef creates token with correct provider and ref', function (): void {
    $token = ProviderToken::fromRef('heygen', 'session-ref-001');

    expect($token->provider)->toBe('heygen');
    expect($token->provider_session_ref)->toBe('session-ref-001');
    expect($token->token)->toBeNull();
    expect($token->conversation_url)->toBeNull();
});

test('ProviderToken::fromRef with tavus provider sets correct provider', function (): void {
    $token = ProviderToken::fromRef('tavus', 'conv-ref-999');

    expect($token->provider)->toBe('tavus');
    expect($token->provider_session_ref)->toBe('conv-ref-999');
});

test('ProviderToken::fromRef requires non-empty provider (F1 — empty string would silently orphan)', function (): void {
    // F1: an empty provider would route teardown to no branch → silent orphan.
    // The class must not accept empty-string provider in fromRef.
    expect(fn () => ProviderToken::fromRef('', 'some-ref'))
        ->toThrow(InvalidArgumentException::class);
});

test('ProviderToken is readonly — properties cannot be mutated', function (): void {
    $token = new ProviderToken(provider: 'heygen', token: 'abc');

    expect(fn () => $token->provider = 'tavus')
        ->toThrow(Error::class);
});
