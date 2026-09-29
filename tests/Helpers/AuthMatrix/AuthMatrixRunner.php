<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Sends ONE real HTTP request for a catalogue cell and judges the result.
 *
 * Pure mechanics, no fixtures: the caller supplies the URL parameters, the
 * payload and the credential. What it owns is the JUDGEMENT, so every matrix
 * file applies exactly the same standard:
 *
 *  (a) the status matches the catalogue cell (`2xx` is a status CLASS, every
 *      other outcome an exact code);
 *  (b) a DENIED outcome (401/403/404/409/422) changed nothing — the whole
 *      database is fingerprinted before and after ({@see AuthMatrixSnapshot});
 *  (c) a denied response never mentions the target resource (its marker), so
 *      a 403/404 cannot be a quiet existence oracle.
 */
final class AuthMatrixRunner
{
    /** Outcomes that must leave the database untouched. */
    private const DENIED = [
        AuthMatrix::UNAUTHENTICATED_401,
        AuthMatrix::FORBIDDEN,
        AuthMatrix::NOT_FOUND,
        AuthMatrix::CONFLICT,
        '422',
    ];

    /**
     * The HTTP verb a catalogue key is exercised with (`PUT|PATCH` → PATCH).
     */
    public static function method(string $key): string
    {
        $methods = explode('|', explode(' ', $key, 2)[0]);

        return in_array('PATCH', $methods, true) ? 'PATCH' : $methods[0];
    }

    /**
     * The catalogue URI with its `{placeholders}` filled from `$params`.
     *
     * @param  array<string, string|int>  $params
     */
    public static function uri(string $key, array $params): string
    {
        $uri = '/'.explode(' ', $key, 2)[1];

        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($params, $key): string {
            if (! array_key_exists($m[1], $params)) {
                throw new LogicException("No value for {{$m[1]}} while resolving [{$key}].");
            }

            return (string) $params[$m[1]];
        }, $uri);
    }

    /**
     * @param  array<string, string|int>  $params
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    public static function send(TestCase $test, string $key, ?string $token, array $params, array $payload = []): TestResponse
    {
        resetAuthGuardState();

        $method = self::method($key);
        $uri = self::uri($key, $params);
        $client = $token === null ? $test : $test->withToken($token);
        $client = $client->withHeader('Accept', 'application/json');

        if (self::hasUpload($payload)) {
            return $client->call($method, $uri, self::scalars($payload), [], self::uploads($payload));
        }

        return $client->json($method, $uri, $payload);
    }

    /**
     * Assert the response satisfies the cell, and that a denial changed nothing.
     *
     * @param  TestResponse<Response>  $response
     * @param  array<string, array{count: int, checksum: string}>  $before
     */
    public static function judge(string $cell, string $expected, TestResponse $response, array $before, string $marker): void
    {
        $status = $response->getStatusCode();

        if ($expected === AuthMatrix::ALLOW) {
            Assert::assertTrue(
                $status >= 200 && $status < 300,
                "{$cell}: expected 2xx, got {$status}. Body: ".self::excerpt($response),
            );

            return;
        }

        Assert::assertSame((int) $expected, $status, "{$cell}: expected {$expected}, got {$status}. Body: ".self::excerpt($response));

        if (! in_array($expected, self::DENIED, true)) {
            return;
        }

        $changed = AuthMatrixSnapshot::diff($before, AuthMatrixSnapshot::take());
        Assert::assertSame([], $changed, "{$cell}: a {$expected} response must not change state, but: ".implode('; ', $changed));

        Assert::assertStringNotContainsString(
            $marker,
            (string) $response->getContent(),
            "{$cell}: a {$expected} response leaked the target resource.",
        );
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private static function excerpt(TestResponse $response): string
    {
        return mb_substr((string) $response->getContent(), 0, 300);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function hasUpload(array $payload): bool
    {
        return self::uploads($payload) !== [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, UploadedFile>
     */
    private static function uploads(array $payload): array
    {
        return array_filter($payload, static fn (mixed $v): bool => $v instanceof UploadedFile);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function scalars(array $payload): array
    {
        return array_filter($payload, static fn (mixed $v): bool => ! $v instanceof UploadedFile);
    }
}
