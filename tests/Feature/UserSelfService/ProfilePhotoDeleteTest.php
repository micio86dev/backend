<?php

declare(strict_types=1);

/**
 * ProfilePhotoDeleteTest (user-avatar-image, design D3b/D5).
 *
 * DELETE /api/profile/photo. Object-first, then null the column — exactly
 * the purgeSnapshots() ordering. Idempotent: a second DELETE still succeeds
 * (S3/R2 deletes are idempotent), the same mirroring
 * StorageSelfTestCommand.php's missing()/delete() round trip.
 *
 * REQ: Photo Removal Deletes The Stored Object, Not Just The Reference
 * (openspec/changes/user-avatar-image/specs/user-self-service/spec.md)
 */

use App\Models\Organization;
use App\Support\Demo\PlaceholderJpeg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake();
    $this->org = Organization::factory()->create();
    ['user' => $this->photoUser, 'token' => $this->photoToken] = authUserAndTokenForRole($this->org, 'operator');
});

test('removing a photo deletes the object and clears the column', function (): void {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', PlaceholderJpeg::decode());
    $this->withToken($this->photoToken)->post('/api/profile/photo', ['photo' => $file])->assertOk();
    $key = $this->photoUser->fresh()->profile_photo_path;
    expect($key)->not->toBeNull();
    Storage::assertExists($key);

    $response = $this->withToken($this->photoToken)->delete('/api/profile/photo');

    $response->assertOk();
    expect($response->json('data.photo_url'))->toBeNull();
    Storage::assertMissing($key);
    expect($this->photoUser->fresh()->profile_photo_path)->toBeNull();
});

test('removing a photo twice is idempotent — the second call still succeeds', function (): void {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', PlaceholderJpeg::decode());
    $this->withToken($this->photoToken)->post('/api/profile/photo', ['photo' => $file])->assertOk();

    $this->withToken($this->photoToken)->delete('/api/profile/photo')->assertOk();
    $second = $this->withToken($this->photoToken)->delete('/api/profile/photo');

    $second->assertOk();
    expect($this->photoUser->fresh()->profile_photo_path)->toBeNull();
});

test('removing when there is no photo at all still succeeds', function (): void {
    $response = $this->withToken($this->photoToken)->delete('/api/profile/photo');

    $response->assertOk();
    expect($this->photoUser->fresh()->profile_photo_path)->toBeNull();
});
