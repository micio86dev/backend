<?php

declare(strict_types=1);

/**
 * RED — reusable-interview-links B1a.6: `App\Models\ReusableInterviewLink` and
 * its factory (design AD-5).
 *
 * The model is the boundary between a stored credential hash and everything
 * that serialises rows, so most of what is pinned here is what it must NOT do:
 * leak `token_hash` through `toArray()`/`toJson()`, let a request body bind the
 * organisation, project, counter or hash through mass assignment, or persist a
 * raw token. The tenant behaviour is the `TenantModel` contract applied to this
 * table: invisible across organisations, organisation stamped from the resolver.
 *
 * Covers the factory too: its defaults must describe a coherent link (the
 * organisation IS the project's organisation), `withRawToken()` must store the
 * hash and prefix and nothing else, and `disabled()` must agree with `active()`.
 */

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\PubliclyIdentifiable;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Establishes the tenant context the factory requires and returns the org.
 */
function reusableLinkModelTenant(): Organization
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $org;
}

// ─── Identity ────────────────────────────────────────────────────────────────

test('a created link gets a bare 26-char ULID public_id that encodes as rlk_ plus the ULID', function (): void {
    reusableLinkModelTenant();

    $link = ReusableInterviewLink::factory()->create();

    expect($link)->toBeInstanceOf(PubliclyIdentifiable::class);
    expect(ReusableInterviewLink::publicIdPrefix())->toBe('rlk_');
    expect($link->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}\z/');
    expect(PublicId::encode($link))->toBe('rlk_'.$link->public_id);
    expect(PublicId::decode(PublicId::encode($link), 'rlk_'))->toBe($link->public_id);
});

// ─── Serialisation ───────────────────────────────────────────────────────────

test('toArray() and toJson() never contain token_hash, and keep the visible token_prefix', function (): void {
    reusableLinkModelTenant();

    $link = ReusableInterviewLink::factory()->create();
    $hash = (string) $link->getAttribute('token_hash');

    expect($hash)->toMatch('/^[0-9a-f]{64}\z/');
    expect($link->toArray())->not->toHaveKey('token_hash');
    expect($link->toArray())->toHaveKey('token_prefix');
    expect($link->toJson())->not->toContain($hash)->not->toContain('token_hash');
    expect($link->fresh()?->toJson())->not->toContain($hash);
});

// ─── Mass assignment ─────────────────────────────────────────────────────────

test('only the label is mass-assignable', function (): void {
    expect((new ReusableInterviewLink)->getFillable())->toBe(['label']);
});

test('a request-body style fill() binds the label and nothing else', function (): void {
    $link = new ReusableInterviewLink;

    $link->fill([
        'label' => 'Campus drive',
        'organization_id' => 999,
        'project_id' => 999,
        'uses_count' => 999,
        'token_hash' => str_repeat('a', 64),
        'token_prefix' => 'beai_rl_AAAAAAAA',
        'lang' => 'it',
        'public_id' => 'FORGED',
        'created_by' => 999,
        'disabled_at' => now(),
        'disabled_by' => 999,
        'last_used_at' => now(),
    ]);

    expect($link->getAttributes())->toBe(['label' => 'Campus drive']);
});

test('the trusted fields bind only through forceFill()', function (): void {
    $org = reusableLinkModelTenant();
    $project = Project::factory()->create();
    $raw = ReusableLinkTokenGenerator::generate();

    $link = (new ReusableInterviewLink)->fill(['label' => 'Campus drive']);
    $link->forceFill([
        'project_id' => $project->id,
        'lang' => 'it',
        'token_hash' => ReusableLinkTokenGenerator::hash($raw),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($raw),
    ])->save();

    $fresh = ReusableInterviewLink::query()->findOrFail($link->id);

    expect($fresh->organization_id)->toBe($org->id);
    expect($fresh->project_id)->toBe($project->id);
    expect($fresh->lang)->toBe('it');
    expect($fresh->uses_count)->toBe(0);
});

// ─── Casts ───────────────────────────────────────────────────────────────────

test('uses_count is an int and the two timestamps are Carbon instances', function (): void {
    reusableLinkModelTenant();

    $link = ReusableInterviewLink::factory()->create(['uses_count' => 7, 'last_used_at' => now(), 'disabled_at' => now()])->fresh();

    expect($link)->not->toBeNull();
    expect($link->uses_count)->toBeInt()->toBe(7);
    expect($link->last_used_at)->toBeInstanceOf(Carbon::class);
    expect($link->disabled_at)->toBeInstanceOf(Carbon::class);
    expect(ReusableInterviewLink::factory()->create()->fresh()?->disabled_at)->toBeNull();
});

// ─── Scope and factory states ────────────────────────────────────────────────

test('active() returns the links that are not disabled, and disabled() builds the opposite', function (): void {
    reusableLinkModelTenant();

    $enabled = ReusableInterviewLink::factory()->create();
    $off = ReusableInterviewLink::factory()->disabled()->create();

    expect($off->disabled_at)->not->toBeNull();
    expect(ReusableInterviewLink::query()->active()->pluck('id')->all())->toBe([$enabled->id]);
    expect(ReusableInterviewLink::query()->count())->toBe(2);
});

test('factory defaults describe a coherent link: the organisation is the project organisation', function (): void {
    $org = reusableLinkModelTenant();

    $link = ReusableInterviewLink::factory()->create();

    expect($link->organization_id)->toBe($org->id);
    expect($link->project)->toBeInstanceOf(Project::class);
    expect($link->project->organization_id)->toBe($org->id);
    expect($link->uses_count)->toBe(0);
    expect($link->last_used_at)->toBeNull();
    expect($link->disabled_at)->toBeNull();
    expect($link->lang)->toBe('en');
    expect($link->token_prefix)->toMatch('/^beai_rl_[A-Za-z0-9_-]{8}\z/');
});

test('two default links have different hashes and prefixes', function (): void {
    reusableLinkModelTenant();

    $one = ReusableInterviewLink::factory()->create();
    $two = ReusableInterviewLink::factory()->create();

    expect($one->getAttribute('token_hash'))->not->toBe($two->getAttribute('token_hash'));
    expect($one->token_prefix)->not->toBe($two->token_prefix);
});

test('forProject() pins the link to that project and to its language', function (): void {
    reusableLinkModelTenant();
    $project = Project::factory()->create(['language' => 'it']);

    $link = ReusableInterviewLink::factory()->forProject($project)->create();

    expect($link->project_id)->toBe($project->id);
    expect($link->lang)->toBe('it');
});

test('withRawToken() stores the hash and the prefix of that token, and never the token itself', function (): void {
    reusableLinkModelTenant();
    $raw = ReusableLinkTokenGenerator::generate();

    $link = ReusableInterviewLink::factory()->withRawToken($raw)->create();

    expect($link->getAttribute('token_hash'))->toBe(ReusableLinkTokenGenerator::hash($raw));
    expect($link->token_prefix)->toBe(ReusableLinkTokenGenerator::prefixOf($raw));

    // Hash-only persistence: the raw token is in no column of the stored row.
    $stored = (array) DB::table('reusable_interview_links')->where('id', $link->id)->first();
    foreach ($stored as $column => $value) {
        // One needle: Pest's `not->toContain($a, $b)` is "not (a AND b)", so the
        // message that used to be passed here as a second needle was never in the
        // value and the assertion could not fail.
        expect(str_contains((string) $value, $raw))->toBeFalse("column {$column} holds the raw token");
    }
});

// ─── Relations ───────────────────────────────────────────────────────────────

test('the relations resolve to the project, organisation, creator and disabler', function (): void {
    $org = reusableLinkModelTenant();
    $creator = User::factory()->create(['organization_id' => $org->id]);
    $disabler = User::factory()->create(['organization_id' => $org->id]);

    $link = ReusableInterviewLink::factory()->create([
        'created_by' => $creator->id,
        'disabled_by' => $disabler->id,
        'disabled_at' => now(),
    ])->fresh();

    expect($link)->not->toBeNull();
    expect($link->project)->toBeInstanceOf(Project::class);
    expect($link->organization->is($org))->toBeTrue();
    expect($link->creator?->is($creator))->toBeTrue();
    expect($link->disabler?->is($disabler))->toBeTrue();
});

test('creator and disabler are null when the link has no actor', function (): void {
    reusableLinkModelTenant();

    $link = ReusableInterviewLink::factory()->create()->fresh();

    expect($link)->not->toBeNull();
    expect($link->creator)->toBeNull();
    expect($link->disabler)->toBeNull();
});

test('participants() is a has-many over participants.reusable_interview_link_id', function (): void {
    $link = new ReusableInterviewLink;

    expect($link->participants())->toBeInstanceOf(HasMany::class);
    expect($link->participants()->getForeignKeyName())->toBe('reusable_interview_link_id');
    expect($link->project())->toBeInstanceOf(BelongsTo::class);
});

// ─── Tenancy ─────────────────────────────────────────────────────────────────

test('a link is invisible from another organisation', function (): void {
    $orgA = reusableLinkModelTenant();
    $linkA = ReusableInterviewLink::factory()->create();

    $orgB = Organization::factory()->create();
    TenantContextScope::runFor($orgB->id, function () use ($linkA): void {
        expect(ReusableInterviewLink::query()->find($linkA->id))->toBeNull();
        expect(ReusableInterviewLink::query()->count())->toBe(0);
    });

    expect(ReusableInterviewLink::query()->find($linkA->id)?->organization_id)->toBe($orgA->id);
});

test('the organisation is stamped from the tenant context even when a foreign one is forced', function (): void {
    $org = reusableLinkModelTenant();
    $other = Organization::factory()->create();

    $link = ReusableInterviewLink::factory()->create(['organization_id' => $other->id]);

    expect($link->organization_id)->toBe($org->id);
});

test('creating a link with no tenant context fails closed', function (): void {
    reusableLinkModelTenant();
    $project = Project::factory()->create();
    app(TenantResolver::class)->setOrgId(null);

    expect(fn () => ReusableInterviewLink::factory()->forProject($project)->create())
        ->toThrow(MissingTenantContextException::class);
});
