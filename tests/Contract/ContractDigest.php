<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Reduces an OpenAPI document to the comparable facts of T-CONTRACT-001:
 * for every operation, its method+path (path parameter names ignored), its
 * query parameter names, the top-level property names of its JSON request
 * body, and — per status code — the top-level property names of its JSON
 * response body. `$ref`s are resolved against the same document; `allOf`
 * branches are merged and `oneOf`/`anyOf` branches are unioned, so a schema
 * expressed differently but exposing the same fields digests identically.
 */
final class ContractDigest
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, array{query: list<string>, request: list<string>|null, responses: array<string, list<string>|null>}>
     */
    public static function of(array $document): array
    {
        $digest = [];

        foreach ($document['paths'] ?? [] as $path => $item) {
            $normalizedPath = (string) preg_replace('/\{[^}]+\}/', '{}', (string) $path);

            foreach (self::METHODS as $method) {
                if (! isset($item[$method])) {
                    continue;
                }

                $operation = $item[$method];
                $parameters = array_merge($item['parameters'] ?? [], $operation['parameters'] ?? []);
                $digest[strtoupper($method).' '.$normalizedPath] = [
                    'query' => self::queryParameterNames($document, $parameters),
                    'request' => self::bodyProperties($document, $operation['requestBody'] ?? null),
                    'responses' => self::responseProperties($document, $operation['responses'] ?? []),
                ];
            }
        }

        ksort($digest);

        return $digest;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $parameters
     * @return list<string>
     */
    private static function queryParameterNames(array $document, array $parameters): array
    {
        $names = [];

        foreach ($parameters as $parameter) {
            $parameter = self::resolve($document, $parameter);
            if (($parameter['in'] ?? null) === 'query') {
                $names[] = (string) $parameter['name'];
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>|null  $body
     * @return list<string>|null
     */
    private static function bodyProperties(array $document, ?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $body = self::resolve($document, $body);
        $schema = $body['content']['application/json']['schema'] ?? null;

        return $schema === null ? null : self::propertyNames($document, $schema);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $responses
     * @return array<string, list<string>|null>
     */
    private static function responseProperties(array $document, array $responses): array
    {
        $result = [];

        foreach ($responses as $status => $response) {
            $response = self::resolve($document, $response);
            $schema = $response['content']['application/json']['schema'] ?? null;
            $result[(string) $status] = $schema === null ? null : self::propertyNames($document, $schema);
        }

        ksort($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function propertyNames(array $document, array $schema): array
    {
        $names = array_keys(self::collectProperties($document, $schema));
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $schema
     * @return array<string, true>
     */
    private static function collectProperties(array $document, array $schema, int $depth = 0): array
    {
        if ($depth > 12) {
            return [];
        }

        $schema = self::resolve($document, $schema);
        $found = [];

        foreach (array_keys($schema['properties'] ?? []) as $name) {
            $found[(string) $name] = true;
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combinator) {
            foreach ($schema[$combinator] ?? [] as $branch) {
                $found += self::collectProperties($document, $branch, $depth + 1);
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function resolve(array $document, array $node): array
    {
        for ($hops = 0; isset($node['$ref']) && $hops < 12; $hops++) {
            $target = $document;
            foreach (explode('/', ltrim((string) $node['$ref'], '#/')) as $segment) {
                $target = $target[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? [];
            }
            $node = $target;
        }

        return $node;
    }
}
