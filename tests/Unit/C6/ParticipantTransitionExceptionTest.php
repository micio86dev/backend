<?php

declare(strict_types=1);

/**
 * Participant status transition guard tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - Illegal jump (in_attesa → completato) throws ParticipantTransitionException
 * - Exception renders HTTP 422
 * - Record is NOT mutated after a rejected transition
 *
 * REQ: Participant Model Lifecycle Guard — scenario "Transition guard rejects illegal jump"
 */

use App\Exceptions\ParticipantTransitionException;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;

function makeParticipantWithStatus(string $status): Participant
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'guard-test-'.uniqid(),
        'display_name' => 'Test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

test('in_attesa → completato (illegal jump) throws ParticipantTransitionException', function (): void {
    $participant = makeParticipantWithStatus('in_attesa');

    expect(fn () => $participant->update(['status' => 'completato']))
        ->toThrow(ParticipantTransitionException::class);
});

/**
 * NOTE (C7a update): in_attesa → errore is now ALLOWED per CRITICAL-1 (C7a design).
 * C7a adds this edge because a hard-fail on the first competency /start
 * (provider 5xx/timeout) must transition the participant to errore immediately.
 * This test has been removed to reflect the updated complete $allowedTransitions map.
 * See: tests/Unit/C7a/ParticipantTransitionsC7aTest.php for the full coverage.
 */
test('in_attesa → in_valutazione (illegal skip) throws ParticipantTransitionException', function (): void {
    $participant = makeParticipantWithStatus('in_attesa');

    expect(fn () => $participant->update(['status' => 'in_valutazione']))
        ->toThrow(ParticipantTransitionException::class);
});

test('record status is NOT mutated after rejected transition', function (): void {
    $participant = makeParticipantWithStatus('in_attesa');

    try {
        $participant->update(['status' => 'completato']);
    } catch (ParticipantTransitionException) {
        // expected
    }

    expect($participant->fresh()->status)->toBe('in_attesa');
});

test('ParticipantTransitionException renders HTTP 422', function (): void {
    $exception = new ParticipantTransitionException('test message');
    $request = Request::create('/api/test', 'GET');
    $response = $exception->render($request);

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true))->toHaveKey('message');
});

test('in_attesa → in_corso is an allowed transition', function (): void {
    $participant = makeParticipantWithStatus('in_attesa');

    $participant->update(['status' => 'in_corso']);

    expect($participant->fresh()->status)->toBe('in_corso');
});

test('in_corso → in_valutazione is an allowed transition', function (): void {
    $participant = makeParticipantWithStatus('in_corso');

    $participant->update(['status' => 'in_valutazione']);

    expect($participant->fresh()->status)->toBe('in_valutazione');
});

test('in_valutazione → completato is an allowed transition', function (): void {
    $participant = makeParticipantWithStatus('in_valutazione');

    $participant->update(['status' => 'completato']);

    expect($participant->fresh()->status)->toBe('completato');
});

test('in_valutazione → errore is an allowed transition', function (): void {
    $participant = makeParticipantWithStatus('in_valutazione');

    $participant->update(['status' => 'errore']);

    expect($participant->fresh()->status)->toBe('errore');
});
