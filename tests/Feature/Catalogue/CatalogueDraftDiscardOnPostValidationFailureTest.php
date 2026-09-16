<?php

declare(strict_types=1);

/**
 * Z12 (R3-draft-orphan-on-post-validation-failure, REQUIRED BEFORE ARCHIVE):
 * `ResolvesOpenDraftRevision::failedValidation()` only discards a
 * freshly-cloned draft when VALIDATION ITSELF fails. A write that fails
 * AFTER validation passed — a Z4-style concurrent constraint violation
 * mapped to 422, or a 409 `RevisionPublishedDuringWriteException` — left
 * that same clone sitting in the platform's one-draft slot forever, holding
 * nothing real.
 *
 * The genuine INTERLEAVING that produces this state is a two-process race,
 * already proven for the underlying constraint violation itself
 * (`BarsIndicatorPairCapConcurrentHttpTest`, Z4). What THIS test proves is
 * narrower and does not need to re-run that race: GIVEN a request that
 * opened a fresh draft and then lost a uniqueness race, does the
 * CONTROLLER now clean it up? That is answered by ORCHESTRATING the same
 * STATE a race would produce — validate the request for real (which
 * genuinely clones the draft), then simulate the concurrent writer's
 * already-committed row directly, then invoke the real controller method —
 * without needing the race's own timing to reproduce non-deterministically
 * a second time.
 */

use App\Http\Controllers\Api\Catalogue\RoleController;
use App\Http\Requests\Catalogue\StoreRoleRequest;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('Z12: a fresh draft opened by this request is discarded when the actual write fails after validation passed', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();

    $request = StoreRoleRequest::create('/api/catalogue/roles', 'POST', [
        'code' => 'ZRACE',
        'name' => ['en' => 'Race Role'],
    ]);
    $request->setContainer(app());
    $request->setUserResolver(fn () => $user);

    // Runs rules() (which calls openDraftRevisionId() and CLONES a fresh
    // draft — none existed), authorize(), and caches the validated data —
    // exactly what Laravel's own request-resolution pipeline does before
    // invoking the controller. Passes: nothing about 'ZRACE' collides with
    // anything ALREADY in the freshly-cloned draft.
    $request->validateResolved();

    expect($request->openedNewDraftThisRequest())->toBeTrue();
    $draftId = $request->openDraftRevisionId();
    expect(FrameworkCatalogRevision::find($draftId)?->state)->toBe('draft');

    // Simulates exactly what a concurrent request winning a Z4-style race
    // would have ALREADY committed by this point: a role named 'ZRACE'
    // already occupies this draft's own uniqueness slot. A raw insert, not
    // Eloquent — standing in for ANOTHER caller's already-committed write,
    // which must not itself go through the validation this test proves
    // works AROUND.
    DB::table('framework_roles')->insert([
        'revision_id' => $draftId,
        'code' => 'ZRACE',
        'name' => json_encode(['en' => 'Concurrent Race Role']),
        'responsibilities' => json_encode(['en' => 'x']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = app(RoleController::class)->store($request);

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode((string) $response->getContent(), true)['error'])->toBe('code_taken');

    // Z12: the draft this SAME request opened fresh, and never got to
    // write anything real into (its own Role::create() rolled back), is
    // discarded — not left occupying the one-draft slot forever.
    expect(FrameworkCatalogRevision::find($draftId))->toBeNull();
});
