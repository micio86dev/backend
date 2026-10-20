<?php

declare(strict_types=1);

/**
 * The sentences an operator reads when an avatar-template write is refused follow the language they work in
 * (hr-avatar-adapter A16). They were English literals in the controllers, so an operator on the Italian backoffice
 * was refused in English in the middle of an Italian screen. The English text is unchanged.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake());

/**
 * Sends one refused write as `$locale` and returns the validation errors of the 422.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
function locRefusal(object $test, string $locale, string $method, string $kind, array $payload): array
{
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);
    $existing = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Taken', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
    $url = $kind === 'collection' ? '/api/avatar-templates' : "/api/avatar-templates/{$existing->id}";

    return $test->withToken($token)->withHeader('Accept-Language', $locale)
        ->json($method, $url, $payload)->assertUnprocessable()->json('errors');
}

test('a refused write is worded in the operator language', function (string $method, string $kind, array $payload, string $field, string $en, string $it): void {
    expect(locRefusal($this, 'en', $method, $kind, $payload)[$field])->toBe([$en])
        ->and(locRefusal($this, 'it', $method, $kind, $payload)[$field])->toBe([$it]);
})->with([
    'provider is immutable' => [
        'PATCH', 'item', ['provider' => 'tavus'], 'provider',
        'The provider cannot be changed after creation. Create a new template instead.',
        'Il provider non può essere cambiato dopo la creazione. Crea invece un nuovo template.',
    ],
    'answer range is ordered' => [
        'PATCH', 'item', ['answer_min_seconds' => 200, 'answer_max_seconds' => 100], 'answer_max_seconds',
        'The maximum answer time must be greater than the minimum.',
        'Il tempo massimo di risposta deve essere maggiore del minimo.',
    ],
    'name is free' => [
        'POST', 'collection', ['name' => 'Taken', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v']], 'name',
        'A template with this name already exists.',
        'Esiste già un template con questo nome.',
    ],
    'persona carries no foreign key' => [
        'PATCH', 'item', ['persona' => ['voice' => 'x']], 'persona',
        'The persona carries a key that is not accepted.',
        'La persona contiene una chiave non accettata.',
    ],
]);
