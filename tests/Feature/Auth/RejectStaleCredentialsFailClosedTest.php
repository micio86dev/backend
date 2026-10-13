<?php

declare(strict_types=1);

/**
 * RejectStaleCredentials fails CLOSED: a token it cannot read, or one without a numeric
 * `iat`, offers no proof that it was minted after the user's last password change, so it
 * is treated exactly like a stale credential (401 credentials_changed). Every token the
 * JWT guard issues carries `iat` (config/jwt.php required_claims), so no legitimate flow
 * is affected. The positive rejection path is also covered end to end by PasswordChangeTest.
 */

use App\Http\Middleware\RejectStaleCredentials;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\JWTGuard;
use Tymon\JWTAuth\Payload;

function runStaleCredentialsMiddleware(?User $user): Response
{
    $request = Request::create('/api/anything');
    $request->setUserResolver(fn () => $user);

    return (new RejectStaleCredentials)->handle($request, fn () => response()->json(['reached' => true]));
}

/**
 * Run the middleware as a user whose bearer token carries the given `iat` claim.
 * The JWT guard is stubbed, so the only thing that varies between callers is `iat`.
 */
function runStaleCredentialsMiddlewareWithTokenIssuedAt(User $user, mixed $iat): Response
{
    $payload = Mockery::mock(Payload::class);
    $payload->shouldReceive('get')->with('iat')->andReturn($iat);
    $guard = Mockery::mock(JWTGuard::class);
    $guard->shouldReceive('payload')->andReturn($payload);
    Auth::shouldReceive('guard')->with('api')->andReturn($guard);

    return runStaleCredentialsMiddleware($user);
}

function userWhoChangedPasswordAt(DateTimeInterface $at): User
{
    return User::factory()->create(['password_changed_at' => $at]);
}

test('an unauthenticated request is passed through untouched', function (): void {
    expect(runStaleCredentialsMiddleware(null)->getStatusCode())->toBe(200);
});

test('a user who never changed their password is never rejected', function (): void {
    expect(runStaleCredentialsMiddleware(User::factory()->create(['password_changed_at' => null]))->getStatusCode())->toBe(200);
});

test('a request whose token payload cannot be read is rejected as stale', function (): void {
    // actingAs() authenticates without a parseable bearer token, so the guard has no payload.
    $user = userWhoChangedPasswordAt(now()->subHour());
    $this->actingAs($user, 'api');

    $response = runStaleCredentialsMiddleware($user);

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['error' => 'credentials_changed']);
});

test('a token without a numeric iat claim is rejected as stale', function (mixed $iat): void {
    $user = userWhoChangedPasswordAt(now()->subHour());

    $response = runStaleCredentialsMiddlewareWithTokenIssuedAt($user, $iat);

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['error' => 'credentials_changed']);
})->with(['missing' => [null], 'non-numeric string' => ['yesterday'], 'array' => [[]]]);

test('a token with a valid fresh iat is let through', function (): void {
    $user = userWhoChangedPasswordAt(now()->subHour());

    expect(runStaleCredentialsMiddlewareWithTokenIssuedAt($user, now()->getTimestamp())->getStatusCode())->toBe(200);
});

test('a freshly issued login token still passes end to end after a password change', function (): void {
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id, 'password' => 'secret-pass-123', 'password_changed_at' => now()->subHour()->startOfSecond()]);

    $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass-123'])
        ->assertOk()
        ->json('access_token');

    $this->withToken($token)->getJson('/api/profile')->assertOk();
});

test('a token minted before the password change is rejected with credentials_changed', function (): void {
    $user = userWhoChangedPasswordAt(now());

    $response = runStaleCredentialsMiddlewareWithTokenIssuedAt($user, $user->password_changed_at->getTimestamp() - 1);

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['error' => 'credentials_changed']);
});

test('a token minted in the same second as the change survives (strict comparison)', function (): void {
    $user = userWhoChangedPasswordAt(now());

    expect(runStaleCredentialsMiddlewareWithTokenIssuedAt($user, $user->password_changed_at->getTimestamp())->getStatusCode())->toBe(200);
});

test('an unreadable payload logs one warning with the reason and user id only', function (): void {
    Log::spy();
    $user = userWhoChangedPasswordAt(now()->subHour());
    $this->actingAs($user, 'api');

    runStaleCredentialsMiddleware($user);

    Log::shouldHaveReceived('warning')->once()->with(
        'RejectStaleCredentials: unreadable or iat-less token rejected',
        ['reason' => 'unreadable_payload', 'user_id' => $user->id],
    );
});

test('an iat-less token logs one warning with the reason and no claim value', function (mixed $iat, string $reason): void {
    Log::spy();
    $user = userWhoChangedPasswordAt(now()->subHour());

    runStaleCredentialsMiddlewareWithTokenIssuedAt($user, $iat);

    Log::shouldHaveReceived('warning')->once()->with(
        'RejectStaleCredentials: unreadable or iat-less token rejected',
        ['reason' => $reason, 'user_id' => $user->id],
    );
})->with([
    'missing' => [null, 'missing_iat'],
    'non-numeric' => ['yesterday', 'non_numeric_iat'],
    'array' => [[], 'non_numeric_iat'],
]);

test('a stale but readable token and the happy path log nothing', function (): void {
    Log::spy();
    $user = userWhoChangedPasswordAt(now());

    runStaleCredentialsMiddlewareWithTokenIssuedAt($user, $user->password_changed_at->getTimestamp() - 1);
    runStaleCredentialsMiddlewareWithTokenIssuedAt($user, now()->getTimestamp() + 5);

    Log::shouldNotHaveReceived('warning');
});
