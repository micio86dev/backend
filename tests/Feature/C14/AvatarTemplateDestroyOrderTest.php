<?php

declare(strict_types=1);

/**
 * A template delete tears down the HeyGen LLM configuration, and records its audit row, only AFTER the
 * database delete succeeded (hr-avatar-adapter A11). The model's `deleting` guard can still refuse the delete
 * after the controller's own checks (a project pinning the template in between), and the provider teardown
 * and the audit row are not undoable. Both template controllers.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

const DDO_CONFIGURATION = 'cfg-ddo-1';

beforeEach(function (): void {
    config(['interview.heygen.api_key' => 'HEYGEN_KEY_X']);

    Http::fake(['api.liveavatar.com/v1/llm-configurations/*' => Http::response(null, 204)]);
});

/** A heygen template that carries a provider-side LLM configuration; returns its token and id. */
function ddoTemplate(string $base): array
{
    $token = TemplateActors::token($base === '/api/avatar-templates' ? 'acting' : 'bare', Organization::factory()->create());
    $id = test()->withToken($token)->postJson($base, ['name' => 'Doomed', 'provider' => 'heygen', 'config' => ['avatarId' => 'av', 'voiceId' => 'v']])->assertCreated()->json('data.id');
    DB::table('avatar_templates')->where('id', $id)->update(['heygen_llm_configuration_id' => DDO_CONFIGURATION]);

    return [$token, $id];
}

function ddoTeardowns(): int
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'DELETE' && str_contains($pair[0]->url(), '/llm-configurations/'.DDO_CONFIGURATION))
        ->count();
}

function ddoDeletedAudits(): int
{
    return DB::table('audit_logs')->where('action', 'avatar_template.deleted')->count();
}

dataset('ddoRoutes', [
    'organization routes' => ['/api/avatar-templates'],
    'platform routes' => ['/api/admin/avatar-templates'],
]);

test('a delete refused by the model leaves the provider configuration and the audit trail alone', function (string $base): void {
    [$token, $id] = ddoTemplate($base);
    Event::listen('eloquent.deleting: '.AvatarTemplate::class, fn () => throw new RuntimeException('refused'));

    $this->withToken($token)->deleteJson("{$base}/{$id}")->assertStatus(500);

    $row = DB::table('avatar_templates')->where('id', $id)->first();

    expect(ddoTeardowns())->toBe(0)
        ->and($row->deleted_at)->toBeNull()
        ->and($row->heygen_llm_configuration_id)->toBe(DDO_CONFIGURATION)
        ->and(ddoDeletedAudits())->toBe(0);
})->with('ddoRoutes');

test('a delete that succeeds tears the provider configuration down once and records one audit row', function (string $base): void {
    [$token, $id] = ddoTemplate($base);

    $this->withToken($token)->deleteJson("{$base}/{$id}")->assertNoContent();

    $row = DB::table('avatar_templates')->where('id', $id)->first();

    expect(ddoTeardowns())->toBe(1)
        ->and($row->deleted_at)->not->toBeNull()
        ->and($row->heygen_llm_configuration_id)->toBeNull()
        ->and(ddoDeletedAudits())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
})->with('ddoRoutes');
