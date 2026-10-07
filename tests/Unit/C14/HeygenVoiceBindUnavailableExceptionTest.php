<?php

declare(strict_types=1);

use App\Exceptions\HeygenVoiceBindUnavailableException;

test('every known bind failure code keeps its documented status', function (string $code, int $status): void {
    $e = new HeygenVoiceBindUnavailableException($code);

    expect(HeygenVoiceBindUnavailableException::handles($code))->toBeTrue()
        ->and($e->httpStatus())->toBe($status);
})->with([
    ['tts_provider_unconfigured', 503],
    ['tts_vendor_key_missing', 503],
    ['tts_bind_busy', 503],
    ['tts_secret_failed', 502],
]);

test('an unknown code resolves to a defined 503 instead of an undefined index', function (string $code): void {
    $e = new HeygenVoiceBindUnavailableException($code);

    expect(HeygenVoiceBindUnavailableException::handles($code))->toBeFalse()
        ->and($e->httpStatus())->toBe(503);
})->with(['a code added to the registrar later', 'tts_voice_not_found', '']);

test('an unknown code still renders the message body with the fallback status', function (): void {
    $response = (new HeygenVoiceBindUnavailableException('tts_future_code'))->render();

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getData(true))->toBe(['message' => 'tts_future_code']);
});

test('no code, known or unknown, ever produces a 500', function (): void {
    $codes = [...array_keys(HeygenVoiceBindUnavailableException::STATUS), 'unknown', ''];

    foreach ($codes as $code) {
        $status = (new HeygenVoiceBindUnavailableException($code))->httpStatus();

        expect($status)->toBeGreaterThanOrEqual(500)->toBeLessThan(600)->not->toBe(500);
    }
});
