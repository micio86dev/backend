<?php

declare(strict_types=1);

namespace Tests\Contract;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
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
 * `assertDeclaredConstProperties()` below closes exactly that gap. What it
 * covers, precisely: the TOP-LEVEL properties of the response body's schema
 * — either declared directly in `properties` (`/health.status`), OR
 * contributed by a TOP-LEVEL `allOf` branch that is an inline object schema
 * or a `$ref` to one (the two webhook envelope `event` fields, and
 * `RecordingResource.kind`), merged in `allOf` INTERSECTION semantics: a
 * later branch that declares its OWN `const` for the same property overrides
 * an earlier one — exactly how `ProgressWebhookPayload`/
 * `EvaluationWebhookPayload` narrow `WebhookEnvelope`'s plain
 * `event: { $ref: WebhookEventType }` into their own `event: { const: ... }`
 * — but a later branch merely REDECLARING the property with no `const` of
 * its own (e.g. just repeating `type: string`) leaves an earlier branch's
 * `const` in force, since `allOf` requires every branch's constraints to
 * hold simultaneously. It does NOT attempt a general JSON Schema
 * 2020-12 `const`/nested-schema implementation: a `const` declared inside a
 * NESTED `properties`/`items` schema (i.e. anything deeper than the
 * response body's own top-level properties and top-level `allOf` branches)
 * is out of scope; if the contract ever needs that, this needs extending or
 * the upstream library needs to gain 3.1 support.
 */
final class ContractValidator
{
    /**
     * One cached validator PER resolved `contract_path`, not a single slot
     * for whichever path was configured the first time this ran in the
     * process — otherwise overriding `config('public_api.contract_path')`
     * (e.g. to an inline test fixture) after the first call would be
     * silently ignored, keeping every later call validating against the
     * FIRST contract ever seen.
     *
     * @var array<string, ResponseValidator>
     */
    private static array $validators = [];

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     *
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

    /**
     * Validates a response's body against `components.schemas.Problem`
     * ALONE — not a full operation match — plus the `application/problem+json`
     * content type, the status code, and the `X-Request-Id` header. For a
     * TEST-ONLY probe route (public-api step 2's auth middleware tests):
     * that route has no entry in `openapi.yaml`, so `validate()` above (which
     * requires a real `paths.<path>.<method>` operation) cannot be used —
     * schema-level validation is the only piece of the contract a probe
     * route CAN honestly be checked against.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     *
     * @throws ValidationFailed when the status/content-type/header
     *                          expectations are not met, or the body does not match the `Problem` schema.
     */
    public static function assertProblemSchema(TestResponse $response, int $status): void
    {
        if ($response->getStatusCode() !== $status) {
            throw new ValidationFailed(sprintf(
                'Expected status %d, got %d.',
                $status,
                $response->getStatusCode()
            ));
        }

        $contentType = strtok((string) $response->headers->get('Content-Type'), ';') ?: '';

        if ($contentType !== 'application/problem+json') {
            throw new ValidationFailed(sprintf(
                "Expected Content-Type 'application/problem+json', got '%s'.",
                $response->headers->get('Content-Type') ?? '(none)'
            ));
        }

        if (! $response->headers->has('X-Request-Id') || $response->headers->get('X-Request-Id') === '') {
            throw new ValidationFailed('Response is missing a non-empty X-Request-Id header.');
        }

        $components = self::responseValidator()->getSchema()->components;
        $problemSchema = $components !== null ? ($components->schemas['Problem'] ?? null) : null;

        if ($problemSchema instanceof Reference) {
            $problemSchema = $problemSchema->resolve();
        }

        if (! $problemSchema instanceof Schema) {
            throw new ValidationFailed('Contract has no components.schemas.Problem to validate against.');
        }

        /** @var mixed $body */
        $body = json_decode((string) $response->getContent(), true);

        try {
            (new SchemaValidator)->validate($body, $problemSchema);
        } catch (SchemaMismatch $exception) {
            throw new ValidationFailed(
                'Response body does not match the Problem schema: '.$exception->getMessage()
            );
        }
    }

    private static function responseValidator(): ResponseValidator
    {
        $path = config()->string('public_api.contract_path');

        if (! isset(self::$validators[$path])) {
            self::$validators[$path] = (new ValidatorBuilder)
                ->fromYamlFile($path)
                ->getResponseValidator();
        }

        return self::$validators[$path];
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
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

        if ($operation->responses === null) {
            // The base `responseValidator()->validate()` call above already
            // matched this exact status code against a declared response —
            // it would have thrown otherwise. Reaching here with no
            // `responses` block at all is a contract-parsing inconsistency,
            // not a "nothing to check" case, so this fails loudly instead of
            // silently skipping the const check.
            throw new ValidationFailed(sprintf(
                "Response [%s %s %d]: operation declares no 'responses' block, but the base validator already matched this status code.",
                strtoupper($address->method()),
                $address->path(),
                $response->getStatusCode()
            ));
        }

        $responseSpec = $operation->responses->getResponse((string) $response->getStatusCode());

        if (! $responseSpec instanceof Response) {
            return;
        }

        $contentType = strtok($response->getHeaderLine('Content-Type'), ';') ?: '';
        $mediaType = $responseSpec->content[$contentType] ?? null;
        $schema = $mediaType?->schema;

        if (! $schema instanceof Schema) {
            return;
        }

        $constProperties = self::topLevelConstProperties($schema);

        if ($constProperties === []) {
            return;
        }

        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            return;
        }

        foreach ($constProperties as $name => $expected) {
            // An ABSENT property is only a violation when the schema also
            // declares it `required` — and that is already enforced by the
            // base `responseValidator()->validate()` call above, which runs
            // (and throws) before this method is ever reached. A `const`
            // property that is merely optional (e.g. `Recording.kind`) must
            // be allowed to be absent; only a PRESENT value that mismatches
            // is this method's own concern.
            if (! array_key_exists($name, $body)) {
                continue;
            }

            $actual = $body[$name];

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

    /**
     * Collects the response body schema's TOP-LEVEL properties that declare
     * `const` — its own `properties` plus whatever each TOP-LEVEL `allOf`
     * branch contributes (see the class docblock "Known gap" section for
     * exactly what this covers and does not). No `null` guard on
     * `$objectSchema->properties`: an object schema with no declared
     * properties resolves it to an empty array at runtime (never `null`,
     * per `cebe\openapi\SpecBaseObject::__get()`'s generic fallback for an
     * `attributes()` entry typed as an array), so the `foreach` below
     * already handles that case by simply not iterating.
     *
     * @return array<string, mixed> property name => its declared `const` value
     */
    private static function topLevelConstProperties(Schema $schema): array
    {
        $constProperties = [];

        foreach (self::topLevelObjectSchemas($schema) as $objectSchema) {
            foreach ($objectSchema->properties as $name => $propertySchema) {
                $name = (string) $name;

                $const = $propertySchema instanceof Schema
                    ? self::declaredConst($propertySchema)
                    : null;

                if ($const !== null) {
                    $constProperties[$name] = $const;
                }

                // `allOf` is an INTERSECTION, not a last-wins merge: a later
                // branch redeclaring the same property WITHOUT `const` (e.g.
                // just repeating its `type`) does NOT widen an earlier
                // branch's `const` away — every branch's constraints must
                // still hold simultaneously. So a redeclaration with no
                // `const` of its own leaves whatever this loop already
                // recorded for `$name` untouched (no `unset()` here). Only a
                // LATER branch that declares its OWN `const` overwrites the
                // earlier one, which is the one case where "later wins" is
                // actually correct — see the class docblock's "Known gap"
                // section for the `ProgressWebhookPayload` example.
            }
        }

        return $constProperties;
    }

    /**
     * Reads a schema's `const` value. `const` is not in `Schema::attributes()`
     * (see class docblock "Known gap"), so `SpecBaseObject` never exposes it
     * through a typed `@property` — PHPStan has no declared property to read
     * (`property.notFound` against `$schema->const`). The only documented,
     * statically-typed way to reach it is `getSerializableData()`, which
     * re-serialises the schema to a `stdClass` including whatever
     * undeclared/"additional" keys (the same mechanism `x-*` extensions use)
     * were present in the source document. Returns `null` both when `const`
     * is absent AND when it is explicitly declared `null` — the current
     * contract only ever declares string consts, so that ambiguity is out
     * of scope (see the class docblock's "Known gap" section).
     */
    private static function declaredConst(Schema $schema): mixed
    {
        $data = $schema->getSerializableData();

        if (! $data instanceof \stdClass) {
            // `getSerializableData()` is only typed `@return mixed` upstream,
            // but its implementation always returns `(object) $data` on
            // every return path, i.e. a `stdClass` — this narrows that for
            // PHPStan without asserting it away, and degrades safely to "no
            // const" if that ever stops being true.
            return null;
        }

        return property_exists($data, 'const') ? $data->const : null;
    }

    /**
     * The schema itself, plus every TOP-LEVEL `allOf` element that resolves
     * to an object schema — never anything nested deeper than that.
     *
     * @return list<Schema>
     */
    private static function topLevelObjectSchemas(Schema $schema): array
    {
        $schemas = [$schema];

        foreach ($schema->allOf ?? [] as $element) {
            if ($element instanceof Reference) {
                $element = $element->resolve();
            }

            if ($element instanceof Schema) {
                $schemas[] = $element;
            }
        }

        return $schemas;
    }
}
