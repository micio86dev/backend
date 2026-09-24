<?php

declare(strict_types=1);

namespace Tests\Contract;

use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Validates HTTP responses against the vendored BEAI Public API contract
 * (`public-api/openapi.yaml`, SPEC.md §0 "Contract governance").
 *
 * Loads and parses the YAML ONCE per test process, cached in a static
 * property: every `/v1` integration test calling this validates against the
 * same contract, and re-parsing ~1200 lines of YAML per test would make the
 * step-7 quality-gate suite measurably slower for no benefit — the contract
 * never changes mid-process.
 *
 * `nyholm/psr7` is already installed (transitively, via `php-http/discovery`)
 * so responses are converted to PSR-7 directly rather than pulling in the
 * Symfony PSR HTTP message bridge — `Illuminate\Testing\TestResponse` wraps a
 * Symfony `Response`, whose status/headers/content map onto
 * `Nyholm\Psr7\Response`'s constructor with no adapter needed.
 *
 * **Known gap this class compensates for.** `league/openapi-psr7-validator`
 * (pinned `^0.24`, the latest tag) documents itself as an OpenAPI 3.0.2
 * validator, and its schema engine — `devizzent/cebe-php-openapi` 1.1.5 —
 * lists the JSON Schema keywords it recognises in `Schema::attributes()`
 * (vendor/devizzent/cebe-php-openapi/src/spec/Schema.php): `const` (added by
 * JSON Schema draft-06 and used freely by our OpenAPI 3.1 contract, e.g.
 * `/health`'s `{ status: { type: string, const: ok } }`) is NOT in that list.
 * `SpecBaseObject::__construct()` still stores it (as an opaque "additional
 * property", the same mechanism that holds `x-*` extensions) but the
 * validator's `BodyValidator` never reads it, so a body violating ONLY a
 * `const` constraint silently passes — confirmed empirically before this was
 * added (`{"status":"degraded"}` validated clean against `/health`).
 * `assertDeclaredConstProperties()` below closes exactly that gap for
 * TOP-LEVEL object properties (all four uses in the current contract —
 * `/health.status`, `RecordingResource.kind`, the two webhook envelope
 * `event` fields — are top-level), by reading the same schema object the
 * base validator already parsed and comparing declared `const` values
 * against the decoded response body. It does not attempt a general JSON
 * Schema 2020-12 `const`/nested-schema implementation; if the contract ever
 * uses `const` inside a nested `properties`/`items` schema, this needs
 * extending or the upstream library needs to gain 3.1 support.
 */
final class ContractValidator
{
    private static ?ResponseValidator $validator = null;

    /**
     * @throws ValidationFailed when the response does not match the
     *                          contract's schema for this operation — undeclared status code, body
     *                          that fails its schema, a `const` mismatch (see class docblock), or a
     *                          header the contract requires but the response omits.
     */
    public static function validate(TestResponse $response, string $method, string $path): void
    {
        $address = new OperationAddress($path, strtolower($method));
        $psr7Response = self::toPsr7Response($response);

        self::responseValidator()->validate($address, $psr7Response);
        self::assertDeclaredConstProperties($address, $psr7Response);
    }

    private static function responseValidator(): ResponseValidator
    {
        if (self::$validator === null) {
            self::$validator = (new ValidatorBuilder)
                ->fromYamlFile((string) config('public_api.contract_path'))
                ->getResponseValidator();
        }

        return self::$validator;
    }

    private static function toPsr7Response(TestResponse $response): ResponseInterface
    {
        return new Psr7Response(
            $response->getStatusCode(),
            $response->headers->all(),
            (string) $response->getContent()
        );
    }

    /**
     * See the class docblock "Known gap" section — `const` is not enforced
     * by the underlying schema engine, so it is checked here against the
     * already-parsed schema for this exact operation/status/media-type.
     */
    private static function assertDeclaredConstProperties(OperationAddress $address, ResponseInterface $response): void
    {
        $pathItem = self::responseValidator()->getSchema()->paths->getPath($address->path());

        if ($pathItem === null) {
            return;
        }

        $operation = $pathItem->getOperations()[$address->method()] ?? null;

        if ($operation === null) {
            return;
        }

        $responseSpec = $operation->responses->getResponse((string) $response->getStatusCode());

        if (! $responseSpec instanceof Response) {
            return;
        }

        $contentType = strtok($response->getHeaderLine('Content-Type'), ';') ?: '';
        $mediaType = $responseSpec->content[$contentType] ?? null;
        $schema = $mediaType?->schema;

        if (! $schema instanceof Schema || $schema->properties === null) {
            return;
        }

        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            return;
        }

        foreach ($schema->properties as $name => $propertySchema) {
            // `const` is not in `Schema::attributes()` (see class docblock),
            // so it is reached only through `SpecBaseObject`'s magic
            // `__isset`/`__get` — the same mechanism that exposes `x-*`
            // extensions — never through a typed accessor.
            if (! $propertySchema instanceof Schema || ! isset($propertySchema->const)) {
                continue;
            }

            $expected = $propertySchema->const;
            $actual = $body[$name] ?? null;

            if ($actual !== $expected) {
                throw new ValidationFailed(sprintf(
                    "Response [%s %s %d] property '%s' must equal const %s, got %s.",
                    strtoupper($address->method()),
                    $address->path(),
                    $response->getStatusCode(),
                    $name,
                    json_encode($expected),
                    json_encode($actual)
                ));
            }
        }
    }
}
