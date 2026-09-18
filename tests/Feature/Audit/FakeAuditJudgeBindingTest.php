<?php

declare(strict_types=1);

use App\Contracts\AuditJudge;
use App\Testing\FakeAuditJudge;
use Illuminate\Support\Facades\Http;

/**
 * RED — P1.13: `FakeAuditJudge` is the DEFAULT `AuditJudge` binding in the
 * test environment (proposal AD-5, scoring-audit spec's "FakeAuditJudge Is
 * the Default Test Binding" requirement) — mirrors `FakeLLMProvider`'s own
 * guarantee for `LLMProvider`.
 */
test('the container default AuditJudge binding in the test environment is FakeAuditJudge', function (): void {
    expect(app(AuditJudge::class))->toBeInstanceOf(FakeAuditJudge::class);
});

test('resolving the container default AuditJudge binding makes zero HTTP requests to any TypeSafe endpoint', function (): void {
    Http::preventStrayRequests();

    app(AuditJudge::class);

    Http::assertNothingSent();
});
