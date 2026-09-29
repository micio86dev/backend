<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Avatar voice preview
|--------------------------------------------------------------------------
|
| `POST /api/avatar-templates/voice-preview` lets a platform operator LISTEN to
| a vendor voice before activating an avatar template. The sample sentence is
| defined HERE and only here: the client never supplies text, so the endpoint
| cannot be used as a free text-to-speech proxy on the platform's provider keys.
|
| The Italian phrase is data, not UI copy: a natural interview opener that also
| exercises a few sounds non-native voices tend to get wrong ("gli", "sc"/"ci",
| double consonants). Changing any phrase MUST bump `phrase_version`, which is
| part of the audio cache key — otherwise the old sentence keeps being served.
*/

return [

    'phrase_version' => 'v1',

    'phrases' => [
        'it' => 'Buongiorno, grazie per essere qui. Cerchiamo di capire insieme, in modo specifico, '
            .'quale esperienza ha maturato e cosa gli ha insegnato.',
        'en' => 'Good morning, thank you for being here. Let us walk through, in specific terms, '
            .'the experience you have built and what it has taught you.',
    ],

    'models' => [
        'cartesia' => 'sonic-3',
        'elevenlabs' => 'eleven_multilingual_v2',
    ],

    // Cartesia requires an explicit API version header; same value the voice
    // catalogue (AvatarProviderCatalogue) sends.
    'cartesia_version' => '2025-04-16',

    'output_formats' => [
        'cartesia' => ['container' => 'mp3', 'sample_rate' => 44100, 'bit_rate' => 128000],
        // ElevenLabs takes it as the `output_format` query parameter.
        'elevenlabs' => 'mp3_44100_128',
    ],

    // Disk holding the generated audio cache (`voice-previews/` prefix). Null
    // means the application's default filesystem disk.
    'disk' => env('AVATAR_PREVIEW_DISK'),

    // Upper bound on the audio accepted from a provider, so a misbehaving vendor
    // cannot fill the disk or the response. A ~20 word sample is ~100 KB of mp3.
    'max_bytes' => 2 * 1024 * 1024,

    'timeout_seconds' => 20,

    // Browser cache lifetime of a successful response.
    'browser_max_age' => 86400,

    // Per-user requests per minute (named limiter `avatar-voice-preview`).
    'throttle_per_minute' => (int) env('AVATAR_PREVIEW_THROTTLE_PER_MINUTE', 10),

];
