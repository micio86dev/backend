<?php

declare(strict_types=1);

/**
 * The HTTP contract of the catalogue guard exceptions. The guards throw from
 * model events deep inside a write, so the response a client sees is whatever
 * `render()` returns when nothing upstream catches them: 422 for the two
 * immutability/pin refusals, 409 with a machine-readable `error` code for a
 * write that lost a race.
 */

use App\Enums\RevisionWriteConflictCause;
use App\Exceptions\DraftRevisionPinRejectedException;
use App\Exceptions\PublishedRevisionImmutableException;
use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Route;

test('pinning a framework version to a draft revision is a 422 with the guard message', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $draftId = FrameworkCatalogRevision::factory()->draft()->create()->id;

    Route::post('/_test/pin-draft', fn () => FrameworkVersion::create([
        'organization_id' => $org->id,
        'version' => 'v-http-pin',
        'revision_id' => $draftId,
    ]));

    $this->postJson('/_test/pin-draft')
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => $m !== '');

    expect(FrameworkVersion::where('version', 'v-http-pin')->exists())->toBeFalse();
});

test('mutating a published revision is a 422 and leaves the row untouched', function (): void {
    $published = FrameworkCatalogRevision::factory()->create(['label' => 'settled content']);
    expect($published->state)->toBe('published');

    Route::post('/_test/rename-published', fn () => $published->update(['label' => 'renamed']));

    $this->postJson('/_test/rename-published')
        ->assertStatus(422)
        ->assertJsonStructure(['message']);

    expect($published->fresh()->label)->toBe('settled content');
});

test('the default guard messages are used when none is supplied', function (): void {
    Route::get('/_test/pin-default', fn () => throw new DraftRevisionPinRejectedException);
    Route::get('/_test/immutable-default', fn () => throw new PublishedRevisionImmutableException);

    $this->getJson('/_test/pin-default')
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Only a published catalogue revision may be pinned.']);

    $this->getJson('/_test/immutable-default')
        ->assertStatus(422)
        ->assertExactJson(['message' => 'A published catalogue revision is immutable.']);
});

test('a write that lost a race against a publish is a 409 with a publish-specific code', function (): void {
    Route::get('/_test/race-published', fn () => throw new RevisionPublishedDuringWriteException('', RevisionWriteConflictCause::Published));

    $this->getJson('/_test/race-published')
        ->assertStatus(409)
        ->assertJsonPath('error', 'revision_published_during_write')
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'published'));
});

test('a write that lost a race against a discard says discarded, not published', function (): void {
    Route::get('/_test/race-discarded', fn () => throw new RevisionPublishedDuringWriteException('', RevisionWriteConflictCause::Discarded));

    $this->getJson('/_test/race-discarded')
        ->assertStatus(409)
        ->assertJsonPath('error', 'revision_discarded_during_write')
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'discarded'));
});

test('an explicit message overrides the default but keeps the cause code', function (): void {
    $e = new RevisionPublishedDuringWriteException('Custom text.', RevisionWriteConflictCause::Discarded);

    expect($e->cause())->toBe(RevisionWriteConflictCause::Discarded)
        ->and($e->errorCode())->toBe('revision_discarded_during_write');

    Route::get('/_test/race-custom', fn () => throw $e);

    $this->getJson('/_test/race-custom')
        ->assertStatus(409)
        ->assertJsonPath('message', 'Custom text.');
});
