<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Symfony\Component\Yaml\Yaml;
use Tests\Contract\ContractDigest;

/**
 * T-CONTRACT-001 (SPEC.md §0 "Contract governance", G-00): the Scramble
 * document for `/v1` and the design reference `openapi.yaml` must describe
 * the same API. Neither may change without the other.
 *
 * Every KNOWN difference is an explicit, reasoned entry below (G-54); the
 * assertions compare against those lists, so any NEW divergence — in either
 * direction — fails here instead of drifting silently.
 */

/**
 * @return array{contract: array<string, array{query: list<string>, request: list<string>|null, responses: array<string, list<string>|null>}>, export: array<string, array{query: list<string>, request: list<string>|null, responses: array<string, list<string>|null>}>}
 */
function contractDigests(): array
{
    static $digests = null;

    if ($digests === null) {
        $contract = Yaml::parseFile(config('public_api.contract_path'));
        $export = app(Generator::class)(Scramble::getGeneratorConfig('v1'));

        $digests = ['contract' => ContractDigest::of($contract), 'export' => ContractDigest::of($export)];
    }

    return $digests;
}

test('T-CONTRACT-001: the /v1 export and openapi.yaml expose exactly the same method+path operations', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDigests();

    expect(array_keys($export))->toBe(array_keys($contract));
});

test('T-CONTRACT-001: every operation accepts exactly the same query parameters', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDigests();

    $divergences = [];
    foreach ($contract as $operation => $facts) {
        if ($facts['query'] !== $export[$operation]['query']) {
            $divergences[$operation] = ['contract' => $facts['query'], 'export' => $export[$operation]['query']];
        }
    }

    expect($divergences)->toBe([]);
});

test('T-CONTRACT-001: every operation accepts exactly the same JSON request body properties', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDigests();

    $divergences = [];
    foreach ($contract as $operation => $facts) {
        if ($facts['request'] !== $export[$operation]['request']) {
            $divergences[$operation] = ['contract' => $facts['request'], 'export' => $export[$operation]['request']];
        }
    }

    expect($divergences)->toBe([]);
});

/**
 * Statuses the contract documents on every operation but that are emitted by
 * middleware (API-key authentication, ability check, rate limiter) before any
 * controller runs, so Scramble cannot infer them from controller code.
 */
const CONTRACT_MIDDLEWARE_STATUSES = ['401', '403', '429'];

/**
 * G-54 — recorded status-code divergences other than the middleware ones.
 * `contract_only`: documented in openapi.yaml, not produced/declared by the
 * controller. `export_only`: declared by the controller, absent from
 * openapi.yaml. Each is a decision to settle, never a silent allowance.
 */
const RECORDED_STATUS_DIVERGENCES = [
    'GET /exports' => ['contract_only' => ['404'], 'export_only' => []],
    'GET /interviews' => ['contract_only' => ['404'], 'export_only' => []],
    'GET /interviews/{}' => ['contract_only' => ['400'], 'export_only' => []],
    'GET /interviews/{}/recording' => ['contract_only' => [], 'export_only' => ['500']],
    'GET /projects' => ['contract_only' => ['404'], 'export_only' => []],
    'GET /usage' => ['contract_only' => ['404'], 'export_only' => []],
    'GET /webhooks/deliveries' => ['contract_only' => ['404'], 'export_only' => []],
    'POST /exports' => ['contract_only' => ['404', '409'], 'export_only' => []],
    'POST /interviews' => ['contract_only' => ['400'], 'export_only' => []],
];

/**
 * G-54 — recorded 2xx body divergences. `Organization.default_language` is
 * OPTIONAL in openapi.yaml and deliberately omitted by the serializer:
 * `organizations` has no language column to read.
 */
const RECORDED_SUCCESS_BODY_DIVERGENCES = [
    'GET /organization 200' => ['contract_only' => ['default_language'], 'export_only' => []],
];

test('T-CONTRACT-001: response status codes differ only by the middleware set and the recorded G-54 divergences', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDigests();

    $divergences = [];
    foreach ($contract as $operation => $facts) {
        $contractStatuses = array_diff(array_map('strval', array_keys($facts['responses'])), CONTRACT_MIDDLEWARE_STATUSES);
        $exportStatuses = array_diff(array_map('strval', array_keys($export[$operation]['responses'])), CONTRACT_MIDDLEWARE_STATUSES);

        $contractOnly = array_values(array_diff($contractStatuses, $exportStatuses));
        $exportOnly = array_values(array_diff($exportStatuses, $contractStatuses));

        if ($contractOnly !== [] || $exportOnly !== []) {
            $divergences[$operation] = ['contract_only' => $contractOnly, 'export_only' => $exportOnly];
        }
    }

    expect($divergences)->toBe(RECORDED_STATUS_DIVERGENCES);
});

test('T-CONTRACT-001: 2xx response bodies expose the same properties, except the recorded G-54 divergences', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDigests();

    $divergences = [];
    foreach ($contract as $operation => $facts) {
        foreach ($facts['responses'] as $status => $contractProperties) {
            if (! str_starts_with((string) $status, '2')) {
                continue;
            }

            $exportProperties = $export[$operation]['responses'][$status] ?? null;
            if ($exportProperties === $contractProperties) {
                continue;
            }

            $divergences["{$operation} {$status}"] = [
                'contract_only' => array_values(array_diff($contractProperties ?? [], $exportProperties ?? [])),
                'export_only' => array_values(array_diff($exportProperties ?? [], $contractProperties ?? [])),
            ];
        }
    }

    expect($divergences)->toBe(RECORDED_SUCCESS_BODY_DIVERGENCES);
});

/**
 * @return array{contract: array<string, mixed>, export: array<string, mixed>}
 */
function contractDocuments(): array
{
    static $documents = null;

    if ($documents === null) {
        $documents = [
            'contract' => Yaml::parseFile(config('public_api.contract_path')),
            'export' => app(Generator::class)(Scramble::getGeneratorConfig('v1')),
        ];
    }

    return $documents;
}

test('T-CONTRACT-001: the export declares the same security scheme and default security as the reference', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDocuments();

    expect($export['components']['securitySchemes'])->toBe($contract['components']['securitySchemes'])
        ->and($export['security'])->toBe($contract['security']);
});

test('T-CONTRACT-001: the export declares the same server as the reference', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDocuments();

    expect($export['servers'])->toBe($contract['servers']);
});

test('T-CONTRACT-001: every operation carries the same per-operation security override as the reference', function (): void {
    ['contract' => $contract, 'export' => $export] = contractDocuments();

    $overrides = static function (array $document): array {
        $result = [];
        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $result[strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path)] = $operation['security'] ?? null;
            }
        }
        ksort($result);

        return $result;
    };

    expect($overrides($export))->toBe($overrides($contract));
});
