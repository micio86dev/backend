<?php

declare(strict_types=1);

/**
 * RED — EntryLinkMinter (operator-interview-link, design D1).
 *
 * Single source of truth for the mint decision, shared by both the M2M and
 * the operator-facing mint. Takes an already-resolved `Project` model, never
 * a project_id — the org-scoping stays the caller's job (design D1).
 *
 * REQ: Shared Entry Link Minting Logic
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */

use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Sso\MintedEntryLink;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

function minterActiveProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

test('mint returns a MintedEntryLink for an accessible project', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org);

    $minted = (new EntryLinkMinter)->mint($project, 'cand-1', 'Mario Rossi', uniqid('cand-').'@example.test', null, 'en');

    expect($minted)->toBeInstanceOf(MintedEntryLink::class);
    expect($minted->token)->toBeString();
    expect($minted->lang)->toBe('en');
});

test('a project failing an entry gate refuses the mint with reason gates', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org, ['deadline_at' => now()->subHour()]);

    try {
        (new EntryLinkMinter)->mint($project, 'cand-2', 'Mario Rossi', uniqid('cand-').'@example.test', null, null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::Gates);
    }
});

test('a role_code mismatch on a standard project refuses the mint with reason role_code', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org, ['role_code' => 'ICO']);

    try {
        (new EntryLinkMinter)->mint($project, 'cand-3', 'Mario Rossi', uniqid('cand-').'@example.test', 'FLL', null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::RoleCode);
        expect($e->getMessage())->not->toBe('');
    }
});

test('a role_code supplied for a potential project refuses the mint with reason role_code', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org, ['assessment_type' => 'potential', 'role_code' => null]);

    try {
        (new EntryLinkMinter)->mint($project, 'cand-3b', 'Mario Rossi', uniqid('cand-').'@example.test', 'ICO', null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::RoleCode);
    }
});

test('a completed participant refuses the mint with reason completed', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'terminal-cand',
        'display_name' => 'Done',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'completato']);

    try {
        (new EntryLinkMinter)->mint($project, 'terminal-cand', 'Done', uniqid('cand-').'@example.test', null, null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::Completed);
    }
});

test('a failed (errore) participant refuses the mint with reason failed', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'errored-cand',
        'display_name' => 'Errored',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'errore']);

    try {
        (new EntryLinkMinter)->mint($project, 'errored-cand', 'Errored', uniqid('cand-').'@example.test', null, null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::Failed);
    }
});

test('a completed participant matched only by email (different candidate_ref) refuses the mint with reason completed', function (): void {
    // `participants` carries TWO independent unique constraints per project —
    // candidate_ref (C6) and email (2026_09_01_180000_add_email_to_participants)
    // — so a terminal row can be reached by either axis alone. A mint request
    // carrying a brand-new candidate_ref but an email that already belongs to
    // a `completato` row in this project must still refuse, or it sails past
    // this guard and later dies on the DB's own unique-constraint violation
    // when the exchange writes the row.
    $org = Organization::factory()->create();
    $project = minterActiveProject($org);
    $sharedEmail = uniqid('cand-').'@example.test';

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'original-cand-ref',
        'display_name' => 'Done',
        'email' => $sharedEmail,
        'status' => 'in_valutazione',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'completato']);

    try {
        (new EntryLinkMinter)->mint($project, 'brand-new-cand-ref', 'Done', $sharedEmail, null, null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::Completed);
    }
});

test('a failed (errore) participant matched only by email (different candidate_ref) refuses the mint with reason failed', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org);
    $sharedEmail = uniqid('cand-').'@example.test';

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'original-errored-ref',
        'display_name' => 'Errored',
        'email' => $sharedEmail,
        'status' => 'in_attesa',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'errore']);

    try {
        (new EntryLinkMinter)->mint($project, 'brand-new-errored-ref', 'Errored', $sharedEmail, null, null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::Failed);
    }
});

test('when candidate_ref matches an errore row and email matches a different completato row, completed wins (anchor-primacy-style precedence)', function (): void {
    // The two axes can now implicate two DIFFERENT rows (impossible before
    // this fix, when only candidate_ref was checked). Preserve the existing
    // single-row precedence: completato always outranks errore.
    $org = Organization::factory()->create();
    $project = minterActiveProject($org);
    $sharedCandidateRef = 'precedence-cand-ref';
    $sharedEmail = uniqid('cand-').'@example.test';

    $erroredRow = new Participant;
    $erroredRow->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => $sharedCandidateRef,
        'display_name' => 'Errored Row',
        'email' => uniqid('other-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $erroredRow->save();
    DB::table('participants')->where('id', $erroredRow->id)->update(['status' => 'errore']);

    $completedRow = new Participant;
    $completedRow->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'unrelated-cand-ref',
        'display_name' => 'Completed Row',
        'email' => $sharedEmail,
        'status' => 'in_valutazione',
    ]);
    $completedRow->save();
    DB::table('participants')->where('id', $completedRow->id)->update(['status' => 'completato']);

    try {
        (new EntryLinkMinter)->mint($project, $sharedCandidateRef, 'Whoever', $sharedEmail, null, null);
        expect(false)->toBeTrue('Expected EntryLinkRefused to be thrown.');
    } catch (EntryLinkRefused $e) {
        expect($e->reason)->toBe(EntryLinkRefusalReason::Completed);
    }
});

test('expires_at equals the decoded exp claim of the minted token', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org);

    $minted = (new EntryLinkMinter)->mint($project, 'cand-4', 'Mario Rossi', uniqid('cand-').'@example.test', null, 'it');

    $payload = JWTAuth::setToken($minted->token)->getPayload();

    expect($minted->expiresAt->getTimestamp())->toBe((int) $payload->get('exp'));
});

test('an omitted role_code is inherited from a standard project', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org, ['assessment_type' => 'standard', 'role_code' => 'ICO']);

    $minted = (new EntryLinkMinter)->mint($project, 'cand-5', 'Mario Rossi', uniqid('cand-').'@example.test', null, null);

    $payload = JWTAuth::setToken($minted->token)->getPayload();

    expect($payload->get('role_code'))->toBe('ICO');
});

test('a potential project mints a null role_code when omitted', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org, ['assessment_type' => 'potential', 'role_code' => null]);

    $minted = (new EntryLinkMinter)->mint($project, 'cand-6', 'Mario Rossi', uniqid('cand-').'@example.test', null, null);

    $payload = JWTAuth::setToken($minted->token)->getPayload();

    expect($payload->get('role_code'))->toBeNull();
});

test('lang omitted falls back to the project language', function (): void {
    $org = Organization::factory()->create();
    $project = minterActiveProject($org, ['language' => 'en']);

    $minted = (new EntryLinkMinter)->mint($project, 'cand-7', 'Mario Rossi', uniqid('cand-').'@example.test', null, null);

    expect($minted->lang)->toBe('en');
});
