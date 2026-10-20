<?php

declare(strict_types=1);

/**
 * Listing the avatar templates costs the same number of queries however many of them are bound to a
 * language model (hr-avatar-adapter A10). Each row's cost forecast reads its model, and that read used to be
 * one `llm_models` query per template.
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{queries: int, llm_model_queries: int}
 */
function aiqListCounting(object $test, string $token): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $test->withToken($token)->getJson('/api/avatar-templates')->assertOk();

    return [
        'queries' => count($sql),
        'llm_model_queries' => count(array_filter($sql, fn (string $statement): bool => str_contains($statement, 'from "llm_models"'))),
    ];
}

function aiqBoundTemplates(Organization $org, int $count): void
{
    $model = LlmModel::firstOrCreate(['key' => 'aiq-model'], [
        'vendor' => 'google',
        'display_name' => 'Count model',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);

    TenantContextScope::runFor($org->id, function () use ($org, $model, $count): void {
        $credential = new LlmCredential;
        $credential->forceFill([
            'name' => "aiq credential {$org->id}",
            'vendor' => 'google',
            'api_key' => 'sk-aiq',
            'key_last_four' => 'aiq0',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        for ($i = 0; $i < $count; $i++) {
            AvatarTemplate::create([
                'name' => "Bound {$org->id} ".uniqid(),
                'provider' => 'tavus',
                'config' => ['faceId' => 'f', 'palId' => 'p'],
                'llm_model_id' => $model->id,
                'llm_credential_id' => $credential->id,
            ]);
        }
    });
}

test('the template list makes the same queries for two bound templates as for six', function (): void {
    $small = Organization::factory()->create();
    $large = Organization::factory()->create();
    aiqBoundTemplates($small, 2);
    aiqBoundTemplates($large, 6);

    $few = aiqListCounting($this, authTokenForRole($small, 'admin'));
    $many = aiqListCounting($this, authTokenForRole($large, 'admin'));

    expect($many['llm_model_queries'])->toBe($few['llm_model_queries'])
        ->and($many['llm_model_queries'])->toBeLessThanOrEqual(1)
        ->and($many['queries'])->toBe($few['queries']);
});

test('the list still carries each bound template cost forecast', function (): void {
    $org = Organization::factory()->create();
    aiqBoundTemplates($org, 3);

    $rows = $this->withToken(authTokenForRole($org, 'admin'))->getJson('/api/avatar-templates')->assertOk()->json('data');

    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row['llm_model_id'])->not->toBeNull();
    }
});
