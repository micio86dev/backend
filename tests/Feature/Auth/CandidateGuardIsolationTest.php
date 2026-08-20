<?php

/**
 * RED — 3.3: candidate/operator guard isolation (design D10). "Structurally
 * impossible" claims rot — this is the regression test for that claim.
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Hash;

function guardIsolationParticipant(): Participant
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'isolation-'.uniqid(),
        'display_name' => 'Isolation Test',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    return $participant->fresh();
}

// (a) a candidate JWT posted to /api/auth/refresh is rejected.
test('a candidate JWT cannot be used as a refresh credential', function (): void {
    $participant = guardIsolationParticipant();
    $candidateToken = CandidateTokenFactory::mintCandidateToken($participant);

    // The candidate token is a Bearer JWT, not a {family_id}.{secret} cookie
    // value — presenting it AS the refresh cookie must fail cleanly, never
    // resolve to any operator family.
    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $candidateToken)
        ->postJson('/api/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'refresh_token_invalid');
});

// (b) an operator access token is rejected by the api-candidate guard.
test('an operator access token is rejected by the api-candidate guard', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $org->id,
        'password' => Hash::make('secret-password'),
    ]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $operatorToken = $loginResponse->json('access_token');

    $this->withToken($operatorToken)
        ->getJson('/api/candidate/session')
        ->assertUnauthorized();
});

// (c) the candidate TTL (120 minutes, setTTL override) is unaffected by the
// operator jwt.ttl config value.
test('candidate token TTL is unaffected by the operator jwt config (D7)', function (): void {
    $participant = guardIsolationParticipant();
    $candidateToken = CandidateTokenFactory::mintCandidateToken($participant);

    $payload = \Tymon\JWTAuth\Facades\JWTAuth::setToken($candidateToken)->getPayload();

    $ttlMinutes = ($payload->get('exp') - $payload->get('iat')) / 60;

    expect((int) round($ttlMinutes))->toBe(120);
    expect((int) config('jwt.ttl'))->not->toBe(120);
});
