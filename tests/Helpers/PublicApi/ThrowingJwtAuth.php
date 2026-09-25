<?php

declare(strict_types=1);

namespace Tests\Helpers\PublicApi;

use RuntimeException;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * A `Tymon\JWTAuth\JWTAuth` double that fails at the exact point
 * `App\Support\Jwt\CandidateTokenFactory::mintCandidateToken()` calls
 * `fromUser()` — used to prove `App\Http\Controllers\Embed\
 * ExchangeController::exchange()`'s transaction (public-api step 5 review
 * follow-up, item 1) actually rolls back the compare-and-clear UPDATE and
 * the `InterviewEvent` insert when minting the candidate JWT fails, rather
 * than leaving a session token permanently burned with nothing to show
 * for it.
 *
 * Deliberately NOT a `Tymon\JWTAuth\JWTAuth` subclass: that class's
 * constructor requires a `Manager`/`Provider`/`Parser` trio this test has
 * no reason to build, and `CandidateTokenFactory::mintCandidateToken()`
 * resolves its dependency through `app(JWTAuth::class)` with no type
 * enforcement at the call site — Laravel's container hands back whatever
 * is bound, and PHP only needs this double to answer the exact chained
 * calls that method makes (`factory()->setTTL()`, then
 * `customClaims()->fromUser()`).
 */
final class ThrowingJwtAuth
{
    public function factory(): self
    {
        return $this;
    }

    public function setTTL(int $ttl): self
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function customClaims(array $claims): self
    {
        return $this;
    }

    public function fromUser(JWTSubject $user): string
    {
        throw new RuntimeException('ThrowingJwtAuth: simulated candidate token minting failure.');
    }
}
