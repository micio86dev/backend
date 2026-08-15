<?php

declare(strict_types=1);

/**
 * ProfilePhotoPurgeImmunityTest (user-avatar-image, design D5).
 *
 * `beai:purge-expired-data` STRUCTURALLY cannot touch a profile photo:
 * purgeSnapshots() iterates InterviewSnapshot ROWS and deletes each row's
 * s3_key (PurgeExpiredDataCommand.php:129-150) — a photo creates no such
 * row, and the purge never lists a prefix or enumerates the bucket. Tested
 * anyway, per design D5, so a future refactor that accidentally widens the
 * purge to a prefix sweep fails loudly here.
 *
 * REQ: Photo Survives Deactivation; Deletion Sweeps The Object
 * (openspec/changes/user-avatar-image/specs/user-self-service/spec.md —
 * "the purge cannot sweep a photo" scenario)
 */

use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Demo\PlaceholderJpeg;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function photoPurgeImmunityFixture(Organization $org): InterviewSnapshot
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['organization_id' => $org->id]);
    $participant = Participant::factory()->create(['project_id' => $project->id]);

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'in_corso',
    ]);

    $snapshot = new InterviewSnapshot;
    $snapshot->forceFill([
        'interview_session_id' => $session->id,
        's3_key' => "{$org->id}/{$participant->id}/{$session->id}/".fake()->uuid().'.jpg',
        'taken_at' => now()->subDays(90),
    ]);
    $snapshot->save();

    Storage::put($snapshot->s3_key, PlaceholderJpeg::decode());

    return $snapshot->fresh();
}

test('the purge deletes an expired snapshot object but never a profile photo', function (): void {
    Storage::fake();

    $org = Organization::factory()->create();
    $snapshot = photoPurgeImmunityFixture($org);

    ['user' => $photoUser, 'token' => $photoToken] = authUserAndTokenForRole($org, 'operator');
    $file = UploadedFile::fake()->createWithContent('photo.jpg', PlaceholderJpeg::decode());
    test()->withToken($photoToken)->post('/api/profile/photo', ['photo' => $file])->assertOk();
    $photoKey = $photoUser->fresh()->profile_photo_path;

    Storage::assertExists($snapshot->s3_key);
    Storage::assertExists($photoKey);

    config()->set('retention.enabled', true);
    config()->set('retention.days.snapshot', 30);

    test()->artisan('beai:purge-expired-data')->assertSuccessful();

    Storage::assertMissing($snapshot->s3_key);
    Storage::assertExists($photoKey);
});
