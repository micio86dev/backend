<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews/{id}/recording` — BEAI Public API (public-api step 6,
 * G-01), SPEC.md §3.3 "Audio only". T-INT-022 (not ready → 404), T-INT-023
 * (ready → signed URL with 10-minute expiry, org-bound key, `kind: audio`).
 */

use App\Models\InterviewRecording;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-INT-022: no InterviewRecording row → 404 recording_not_ready', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/recording');

    $response->assertStatus(404)->assertJsonPath('code', 'recording_not_ready');
    $this->assertProblemMatchesContract($response, 404);
});

test('T-INT-023: a ready recording returns a signed URL with a 10-minute expiry, kind audio, and the recording metadata', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $objectKey = 'recordings/'.$org->id.'/'.$participant->id.'/interview.ogg';
    Storage::put($objectKey, 'fake-audio-bytes');

    TenantContextScope::runFor($org->id, function () use ($participant, $objectKey): void {
        InterviewRecording::factory()->create([
            'participant_id' => $participant->id,
            'object_key' => $objectKey,
            'format' => 'audio/ogg',
            'duration_seconds' => 240,
            'size_bytes' => 123_456,
        ]);
    });

    $before = now();
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/recording');
    $after = now();

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/interviews/{id}/recording');

    $body = $response->json();
    expect($body['interview_id'])->toBe(PublicId::encode($participant));
    expect($body['kind'])->toBe('audio');
    expect($body['url'])->toBeString()->not->toBe('');
    expect($body['format'])->toBe('audio/ogg');
    expect($body['duration_seconds'])->toBe(240);
    expect($body['size_bytes'])->toBe(123456);

    $expiresAt = Carbon::parse($body['expires_at']);
    expect($expiresAt->diffInSeconds($before->copy()->addMinutes(10), true))->toBeLessThan(5);
    expect($expiresAt->diffInSeconds($after->copy()->addMinutes(10), true))->toBeLessThan(5);
});

test('T-INT-023: an object key outside this organization\'s prefix is refused with 404, never presigned', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    // A key that does not start with recordings/{org->id}/ — simulating a
    // corrupted row or a key that belongs to a different organization.
    $foreignKey = 'recordings/999999/'.$participant->id.'/interview.ogg';
    Storage::put($foreignKey, 'fake-audio-bytes');

    TenantContextScope::runFor($org->id, function () use ($participant, $foreignKey): void {
        InterviewRecording::factory()->create([
            'participant_id' => $participant->id,
            'object_key' => $foreignKey,
        ]);
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/recording');

    $response->assertStatus(404)->assertJsonPath('code', 'recording_not_ready');
});

test('T-INT-023: video is never returned — the response never carries a video field or a non-audio kind', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $objectKey = 'recordings/'.$org->id.'/'.$participant->id.'/interview.ogg';
    Storage::put($objectKey, 'fake-audio-bytes');

    TenantContextScope::runFor($org->id, function () use ($participant, $objectKey): void {
        InterviewRecording::factory()->create([
            'participant_id' => $participant->id,
            'object_key' => $objectKey,
        ]);
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/recording');

    $response->assertOk();
    $body = $response->json();
    expect($body)->not->toHaveKey('video');
    expect($body['kind'])->toBe('audio');
});
