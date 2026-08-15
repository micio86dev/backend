<?php

declare(strict_types=1);

/**
 * ProfilePhotoUrlSignerTest (user-avatar-image, design D4).
 *
 * The URL is byte-stable within its 900-second bucket, and rotates once the
 * bucket boundary is crossed. Deterministic only because phpunit.xml pins
 * CACHE_STORE=array — every request in the same test process reads the SAME
 * memoised cache entry.
 *
 * REQ: The Photo Is Served Through A Time-Limited Signed URL
 * (openspec/changes/user-avatar-image/specs/user-self-service/spec.md)
 */

use App\Models\Organization;
use App\Support\Demo\PlaceholderJpeg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake();
    $this->org = Organization::factory()->create();
    ['user' => $this->photoUser, 'token' => $this->photoToken] = authUserAndTokenForRole($this->org, 'operator');

    $file = UploadedFile::fake()->createWithContent('photo.jpg', PlaceholderJpeg::decode());
    $this->withToken($this->photoToken)->post('/api/profile/photo', ['photo' => $file])->assertOk();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the resolved photo URL is stable across requests within the same 900s window', function (): void {
    // Anchored to an exact bucket boundary so the +899s/+901s offsets below
    // land unambiguously inside vs. outside the current window.
    $window = (int) config('profile.photo.url_window_seconds');
    $bucketStart = intdiv(now()->getTimestamp(), $window) * $window;
    Carbon::setTestNow(Carbon::createFromTimestamp($bucketStart));

    $first = $this->withToken($this->photoToken)->getJson('/api/profile')->json('data.photo_url');
    $second = $this->withToken($this->photoToken)->getJson('/api/profile')->json('data.photo_url');

    expect($first)->not->toBeNull();
    expect($second)->toBe($first);

    Carbon::setTestNow(Carbon::createFromTimestamp($bucketStart + $window - 1));
    $stillInWindow = $this->withToken($this->photoToken)->getJson('/api/profile')->json('data.photo_url');
    expect($stillInWindow)->toBe($first);

    Carbon::setTestNow(Carbon::createFromTimestamp($bucketStart + $window + 1));
    $nextWindow = $this->withToken($this->photoToken)->getJson('/api/profile')->json('data.photo_url');
    expect($nextWindow)->not->toBe($first);
});

test('snapshot signing is unaffected by profile photo URL memoisation', function (): void {
    // Any presign call against an unrelated key must not be influenced by
    // the profile photo cache entry keyed on this user's id.
    Storage::put('999/1/1/unrelated-snapshot.jpg', PlaceholderJpeg::decode());
    $url = Storage::disk()->temporaryUrl('999/1/1/unrelated-snapshot.jpg', now()->addMinutes(15));

    expect($url)->toBeString();
    expect($url)->not->toContain('profile-photos');
});
