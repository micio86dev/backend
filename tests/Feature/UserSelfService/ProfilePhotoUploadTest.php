<?php

declare(strict_types=1);

/**
 * ProfilePhotoUploadTest (user-avatar-image, design D3/D3b/D8).
 *
 * POST /api/profile/photo. Validation is content-based only (magic bytes +
 * getimagesize() + a byte cap) — the declared Content-Type, extension, and
 * client filename are NEVER consulted, mirroring SnapshotController.php's
 * precedent. No re-encode: what passes validation is stored verbatim.
 *
 * REQ: Upload Is Validated By Content, Not By Declared Type
 * (openspec/changes/user-avatar-image/specs/user-self-service/spec.md)
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Demo\PlaceholderJpeg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A real, decodable 1x1 PNG — getimagesize() succeeds, unlike a magic-bytes-only fixture. */
function validPngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        strict: true
    );
}

/**
 * A header-valid PNG whose IHDR chunk declares an oversized dimension, with
 * no pixel data — getimagesize() reads only the header, so this is
 * sufficient to prove the dimension cap is reachable without decoding
 * anything (design D3: "this test failing to *run* is the signal
 * getimagesize() is unavailable").
 */
function oversizedPngBytes(int $width = 8000, int $height = 8000): string
{
    $signature = "\x89PNG\r\n\x1a\n";
    $ihdrData = pack('N', $width).pack('N', $height).chr(8).chr(2).chr(0).chr(0).chr(0);
    $crc = pack('N', crc32('IHDR'.$ihdrData));
    $ihdrChunk = pack('N', strlen($ihdrData)).'IHDR'.$ihdrData.$crc;

    return $signature.$ihdrChunk;
}

/**
 * @param  TestCase  $testCase  passed explicitly — a free function has
 *                              no bound $this, unlike a test() closure.
 */
function uploadPhotoAs($testCase, string $token, UploadedFile $file)
{
    return $testCase->withToken($token)->post('/api/profile/photo', ['photo' => $file]);
}

beforeEach(function (): void {
    Storage::fake();
    $this->org = Organization::factory()->create();
    ['user' => $this->photoUser, 'token' => $this->photoToken] = authUserAndTokenForRole($this->org, 'operator');
});

test('a real JPEG is accepted', function (): void {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', PlaceholderJpeg::decode());

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertOk();
    expect($response->json('data.photo_url'))->not->toBeNull();
    expect($this->photoUser->fresh()->profile_photo_path)->not->toBeNull();
});

test('a real PNG is accepted', function (): void {
    $file = UploadedFile::fake()->createWithContent('photo.png', validPngBytes());

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertOk();
    expect($response->json('data.photo_url'))->not->toBeNull();
    expect($this->photoUser->fresh()->profile_photo_path)->not->toBeNull();
});

test('a spoofed content-type/extension does not bypass magic-byte validation', function (): void {
    // Named and declared as a JPEG; the actual bytes are neither JPEG nor PNG.
    $file = UploadedFile::fake()->createWithContent('photo.jpg', 'not-an-image-at-all');

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photo');
    expect($this->photoUser->fresh()->profile_photo_path)->toBeNull();
    Storage::assertDirectoryEmpty('profile-photos');
});

test('a renamed executable is rejected despite the .jpg extension', function (): void {
    $file = UploadedFile::fake()->createWithContent('malware.exe', "MZ\x90\x00".str_repeat("\x00", 32));

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photo');
    Storage::assertDirectoryEmpty('profile-photos');
});

test('a valid SVG is rejected outright', function (): void {
    $file = UploadedFile::fake()->createWithContent(
        'photo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
    );

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photo');
    Storage::assertDirectoryEmpty('profile-photos');
});

test('a GIF is rejected', function (): void {
    $file = UploadedFile::fake()->createWithContent('photo.gif', 'GIF89a'.str_repeat("\x00", 32));

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photo');
    Storage::assertDirectoryEmpty('profile-photos');
});

// design D3 — this test failing to RUN (a fatal error, not a red assertion)
// is the signal getimagesize() is unavailable in this environment; it living
// in ext-standard, not ext-gd, is what design.md verifies and this proves.
test('a header-valid oversized PNG is rejected by the dimension cap', function (): void {
    $file = UploadedFile::fake()->createWithContent('photo.png', oversizedPngBytes());

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photo');
    Storage::assertDirectoryEmpty('profile-photos');
});

// Asserted against config, never a literal (design D3/D3b): the byte cap is
// `config('profile.photo.max_bytes')`, so a config change and this test never
// drift apart silently.
test('a file exceeding the configured byte cap is rejected', function (): void {
    $maxBytes = (int) config('profile.photo.max_bytes');
    $oversized = validPngBytes().str_repeat("\x00", $maxBytes - strlen(validPngBytes()) + 1);
    $file = UploadedFile::fake()->createWithContent('photo.png', $oversized);

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photo');
    Storage::assertDirectoryEmpty('profile-photos');
});

// CRITICAL (post-apply verification finding): the test above only ever
// exercises the DEFAULT config value, which happens to equal the
// FormRequest's hardcoded `max:2048` (2 097 152 bytes) — so it cannot tell
// real config-driven enforcement from coincidence. This test drives
// `profile.photo.max_bytes` to a NON-default value (500 000, well under the
// FormRequest's literal 2 097 152-byte ceiling) and uploads a file that
// passes the FormRequest's `max:2048` but exceeds the lowered config value.
// If the controller reads `config('profile.photo.max_bytes')` against the
// real byte count, as UpdateProfilePhotoRequest's docblock claims, this
// must still 422. If the config key is decorative, this file uploads fine.
test('the byte cap is actually driven by config, not merely coincident with the FormRequest literal', function (): void {
    config(['profile.photo.max_bytes' => 500_000]);

    $base = validPngBytes();
    $file = UploadedFile::fake()->createWithContent(
        'photo.png',
        $base.str_repeat("\x00", 1_000_000 - strlen($base))
    );

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photo');
    expect($this->photoUser->fresh()->profile_photo_path)->toBeNull();
    Storage::assertDirectoryEmpty('profile-photos');
});

// design D3b — no orphan when the row write fails: the object was already
// written to disk before save() throws, so the controller MUST clean it up
// in the catch before re-throwing.
test('a row-save failure after a successful disk write leaves no orphaned object', function (): void {
    $failingUserId = $this->photoUser->id;

    User::saving(function (User $u) use ($failingUserId): void {
        if ($u->id === $failingUserId) {
            throw new RuntimeException('Simulated row-write failure.');
        }
    });

    $file = UploadedFile::fake()->createWithContent('photo.jpg', PlaceholderJpeg::decode());

    $response = uploadPhotoAs($this, $this->photoToken, $file);

    expect($response->status())->toBeGreaterThanOrEqual(500);
    expect($this->photoUser->fresh()->profile_photo_path)->toBeNull();
    Storage::assertDirectoryEmpty('profile-photos');
});

// design D3b/D5 — no orphan on replace: uploading B after A must leave
// exactly one object under profile-photos/{user}/, and A's key must no
// longer exist.
test('replacing a photo leaves exactly one object for that user', function (): void {
    $fileA = UploadedFile::fake()->createWithContent('a.jpg', PlaceholderJpeg::decode());
    $responseA = uploadPhotoAs($this, $this->photoToken, $fileA);
    $responseA->assertOk();
    $keyA = $this->photoUser->fresh()->profile_photo_path;

    $fileB = UploadedFile::fake()->createWithContent('b.png', validPngBytes());
    $responseB = uploadPhotoAs($this, $this->photoToken, $fileB);
    $responseB->assertOk();
    $keyB = $this->photoUser->fresh()->profile_photo_path;

    expect($keyA)->not->toBeNull();
    expect($keyB)->not->toBeNull();
    expect($keyB)->not->toBe($keyA);

    Storage::assertMissing($keyA);
    Storage::assertExists($keyB);

    $objectsUnderPrefix = collect(Storage::allFiles("profile-photos/{$this->photoUser->id}"));
    expect($objectsUnderPrefix)->toHaveCount(1);
});
