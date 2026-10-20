<?php

declare(strict_types=1);

/**
 * Two operators activating different templates of one organization and provider at the same time (hr-avatar-adapter
 * A16). The partial unique index `avatar_templates_one_active_per_org_provider` already makes two active templates
 * impossible, so the loser of the race used to meet a raw unique violation: a 500 that told the operator nothing.
 * The loser now gets a 422 and the winner's activation stands untouched, and the previously active templates are
 * read under a row lock so the swap is decided on the state the transaction will actually change.
 */

use App\Models\AuditLog;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

function raceSeed(Organization $org, string $name, bool $active): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => $name, 'provider' => 'heygen', 'is_active' => $active,
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
}

/** @return list<int> the ids of the organization's active heygen templates */
function raceActiveIds(Organization $org): array
{
    return AvatarTemplate::withoutGlobalScopes()
        ->where('organization_id', $org->id)->where('provider', 'heygen')->where('is_active', true)
        ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

/**
 * Commits a rival activation (`$old` off, `$rival` on) the moment the request's own deactivation UPDATE is about to
 * run: after it has read the previously active template, before it activates its own.
 */
function raceRivalActivatesBeforeSwap(AvatarTemplate $old, AvatarTemplate $rival): void
{
    $armed = true;

    DB::beforeExecuting(function (string $query) use (&$armed, $old, $rival): void {
        if ($armed && str_starts_with($query, 'update "avatar_templates" set "is_active"')) {
            $armed = false;
            DB::table('avatar_templates')->where('id', $old->id)->update(['is_active' => false]);
            DB::table('avatar_templates')->where('id', $rival->id)->update(['is_active' => true]);
        }
    });
}

test('the loser of two concurrent activations gets a 422, never a second active template or an audit row', function (): void {
    Http::fake();
    $org = Organization::factory()->create();
    $old = raceSeed($org, 'Old face', true);
    $mine = raceSeed($org, 'Mine', false);
    $rival = raceSeed($org, 'Rival', false);
    raceRivalActivatesBeforeSwap($old, $rival);

    $this->withToken(TemplateActors::token('acting', $org))
        ->postJson("/api/avatar-templates/{$mine->id}/activate")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active']);

    // The rival runs on the request's own connection, so the failed activation's savepoint takes it back too: what
    // is left is the state before the race: the old template alone is active, never the refused one.
    expect(raceActiveIds($org))->toBe([$old->id])
        ->and(AuditLog::withoutGlobalScopes()->whereIn('action', ['avatar_template.activated', 'avatar_template.deactivated'])->count())->toBe(0);
});

test('an activation nobody races still swaps the active template', function (): void {
    Http::fake();
    $org = Organization::factory()->create();
    $old = raceSeed($org, 'Old face', true);
    $mine = raceSeed($org, 'Mine', false);

    $this->withToken(TemplateActors::token('acting', $org))
        ->postJson("/api/avatar-templates/{$mine->id}/activate")
        ->assertOk();

    expect(raceActiveIds($org))->toBe([$mine->id])
        ->and($old->id)->not->toBe($mine->id);
});

test('the previously active templates are read under a row lock', function (): void {
    Http::fake();
    $org = Organization::factory()->create();
    raceSeed($org, 'Old face', true);
    $mine = raceSeed($org, 'Mine', false);
    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"avatar_templates"') && str_contains($query->sql, '"is_active"')) {
            $selects[] = $query->sql;
        }
    });

    $this->withToken(TemplateActors::token('acting', $org))
        ->postJson("/api/avatar-templates/{$mine->id}/activate")
        ->assertOk();

    $locked = array_values(array_filter($selects, fn (string $sql): bool => str_contains($sql, 'for update')));

    expect($locked)->toHaveCount(1);
});

test('the lost-race message follows the operator language', function (string $locale, string $message): void {
    Http::fake();
    $org = Organization::factory()->create();
    $old = raceSeed($org, 'Old face', true);
    $mine = raceSeed($org, 'Mine', false);
    raceRivalActivatesBeforeSwap($old, raceSeed($org, 'Rival', false));

    $this->withToken(TemplateActors::token('acting', $org))->withHeader('Accept-Language', $locale)
        ->postJson("/api/avatar-templates/{$mine->id}/activate")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active' => $message]);
})->with([
    'english' => ['en', 'Another template of this provider was activated at the same time. Reload and try again.'],
    'italian' => ['it', 'Un altro template di questo provider è stato attivato nello stesso momento. Ricarica e riprova.'],
]);
