<?php

declare(strict_types=1);

/**
 * The closed value sets of the avatar-template responses are published as `enum` (hr-avatar-adapter A9).
 *
 * Scramble types these members as plain strings, which forced the backoffice to hand-write the unions
 * (`PAL_SYNC_CODES`, `CatalogueErrorCode`, ...). Each set is checked twice: the COMMITTED `openapi.json`, read
 * the way the SDK codegen reads it, must list exactly the PHP source of truth, and the code that produces the
 * values must emit exactly that set, so neither the documentation nor the constant can drift on its own.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use App\Support\AvatarTemplates\FieldType;
use App\Support\AvatarTemplates\TavusPalSync;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function csSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The listed values of a schema (a `$ref` is followed), the `null` of a nullable member left out.
 *
 * @param  array<string, mixed>  $schema
 * @return list<string>
 */
function csEnum(array $schema): array
{
    if (isset($schema['$ref'])) {
        $schema = csSpec()['components']['schemas'][basename((string) $schema['$ref'])];
    }

    expect($schema)->toHaveKey('enum');

    return array_values(array_filter($schema['enum'], static fn (mixed $v): bool => $v !== null));
}

/**
 * @return array<string, mixed>
 */
function csCatalogueData(): array
{
    return csSpec()['paths']['/avatar-templates/catalogue']['get']['responses']['200']['content']['application/json']['schema']['properties']['data'];
}

/**
 * Every field-spec item schema in the document, however many routes return one.
 *
 * @param  array<string, mixed>  $node
 * @return list<array<string, mixed>>
 */
function csFieldSpecItems(array $node): array
{
    $found = isset($node['properties']['label_key'], $node['properties']['type']) ? [$node] : [];

    foreach ($node as $child) {
        if (is_array($child)) {
            $found = [...$found, ...csFieldSpecItems($child)];
        }
    }

    return $found;
}

function csTemplate(array $config = [], string $provider = 'tavus'): AvatarTemplate
{
    $org = Organization::factory()->create();

    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Closed sets '.uniqid(),
        'provider' => $provider,
        'config' => $config + ['faceId' => 'r', 'palId' => 'p_sets', 'llmTemperature' => 0.5],
    ]));
}

test('the pal sync status is a documented enum on every template representation', function (): void {
    $schemas = csSpec()['components']['schemas'];

    foreach (['AvatarTemplateResource', 'PlatformAvatarTemplateResource'] as $resource) {
        $status = $schemas[$resource]['properties']['pal_sync']['properties']['status'];

        expect(csEnum($status))->toEqualCanonicalizing(TavusPalSync::STATUSES)
            ->and($status['type'])->toContain('null');
    }
});

test('the pal sync code is a documented enum on every template representation', function (): void {
    $schemas = csSpec()['components']['schemas'];

    foreach (['AvatarTemplateResource', 'PlatformAvatarTemplateResource'] as $resource) {
        $code = $schemas[$resource]['properties']['pal_sync']['properties']['code'];

        expect(csEnum($code))->toEqualCanonicalizing(TavusPalSync::CODES)
            ->and($code['type'])->toContain('null');
    }
});

test('a pal sync emits exactly the documented statuses and codes', function (): void {
    Http::preventStrayRequests();
    config()->set('interview.tavus.api_key', 'test-key');

    $results = [];
    $sync = fn (?AvatarTemplate $template = null): array => app(TavusPalSync::class)->sync($template ?? csTemplate());

    // `Http::fake()` stubs stack and the first match wins, so one stub reads what the next call should answer.
    $answer = null;
    Http::fake(function () use (&$answer) {
        return $answer instanceof Closure ? $answer() : $answer;
    });

    $answer = Http::response([], 200);
    $results[] = $sync();
    $results[] = $sync(csTemplate(provider: 'heygen'));

    foreach ([
        [400, ['message' => 'Invalid persona_id']],
        [400, ['message' => 'bad layer']],
        [401, []],
        [404, []],
        [500, []],
    ] as [$status, $body]) {
        $answer = Http::response($body, $status);
        $results[] = $sync();
    }

    $answer = fn () => throw new ConnectionException('timed out');
    $results[] = $sync();

    $withoutPalId = csTemplate();
    $withoutPalId->config = ['faceId' => 'r', 'llmTemperature' => 0.5];
    $results[] = $sync($withoutPalId);

    config()->set('interview.tavus.api_key', '');
    $results[] = $sync();

    expect(array_values(array_unique(array_column($results, 'status'))))->toEqualCanonicalizing(TavusPalSync::STATUSES)
        ->and(array_values(array_unique(array_filter(array_column($results, 'message')))))->toEqualCanonicalizing(TavusPalSync::CODES);
});

test('the catalogue status is a documented enum', function (): void {
    expect(csEnum(csCatalogueData()['properties']['status']))->toEqualCanonicalizing(AvatarProviderCatalogue::STATUSES);
});

test('the catalogue error code is a documented enum and stays optional', function (): void {
    $data = csCatalogueData();

    expect(csEnum($data['properties']['code']))->toEqualCanonicalizing(AvatarProviderCatalogue::ERROR_CODES)
        ->and($data['required'])->not->toContain('code');
});

test('the catalogue emits exactly the documented statuses and error codes', function (): void {
    Http::preventStrayRequests();
    config()->set('interview.tavus.api_key', 'test-key');
    Cache::flush();

    $results = [];
    $fetch = fn (): array => AvatarProviderCatalogue::fetch('tavus', 'voice', true);

    // `Http::fake()` stubs stack and the first match wins, so one stub reads what the next call should answer.
    $answer = null;
    Http::fake(function () use (&$answer) {
        return $answer instanceof Closure ? $answer() : $answer;
    });

    $answer = Http::response(['data' => [['voice_id' => 'v1', 'voice_name' => 'Voice', 'status' => 'completed']], 'total_count' => 1]);
    $results[] = $fetch();

    $answer = Http::response(['data' => [], 'total_count' => 0]);
    $results[] = $fetch();

    foreach ([401, 429, 500, 400] as $status) {
        $answer = Http::response([], $status);
        $results[] = $fetch();
    }

    $answer = Http::response('not json', 200);
    $results[] = $fetch();

    $answer = fn () => throw new ConnectionException('timed out');
    $results[] = $fetch();

    $results[] = AvatarProviderCatalogue::fetch('nope', 'voice', true);

    config()->set('interview.tavus.api_key', '');
    $results[] = $fetch();

    expect(array_values(array_unique(array_column($results, 'status'))))->toEqualCanonicalizing(AvatarProviderCatalogue::STATUSES)
        ->and(array_values(array_unique(array_filter(array_column($results, 'code')))))->toEqualCanonicalizing(AvatarProviderCatalogue::ERROR_CODES);
});

test('the catalogue italian classification is a documented nullable enum', function (): void {
    $italian = csCatalogueData()['properties']['items']['items']['properties']['italian'];

    expect(csEnum($italian))->toEqualCanonicalizing(AvatarProviderCatalogue::ITALIAN_VALUES)
        ->and($italian['type'])->toContain('null');
});

test('the catalogue emits exactly the documented italian classifications', function (): void {
    Http::preventStrayRequests();
    config()->set('services.elevenlabs.api_key', 'eleven-test-key');
    Cache::flush();
    Http::fake(['*' => Http::response(['voices' => [
        ['voice_id' => 'a', 'name' => 'Native', 'labels' => ['language' => 'it']],
        ['voice_id' => 'b', 'name' => 'Multi', 'labels' => ['language' => 'en'], 'verified_languages' => [['language' => 'en'], ['language' => 'it']]],
        ['voice_id' => 'c', 'name' => 'Other', 'labels' => ['language' => 'en']],
    ], 'has_more' => false])]);

    $italian = array_column(AvatarProviderCatalogue::fetch('elevenlabs', 'voice', true)['items'], 'italian');

    expect(array_values(array_filter(array_unique($italian))))->toEqualCanonicalizing(AvatarProviderCatalogue::ITALIAN_VALUES)
        ->and($italian)->toContain(null);
});

test('the field spec type is a documented enum on every route that returns specs', function (): void {
    $items = csFieldSpecItems(csSpec()['paths']);
    $types = array_map(static fn (FieldType $type): string => $type->value, FieldType::cases());

    // The organization route lists heygen, tavus and hr_avatar; the platform route lists heygen and tavus.
    expect($items)->toHaveCount(5);

    foreach ($items as $item) {
        expect(csEnum($item['properties']['type']))->toEqualCanonicalizing($types);
    }
});
