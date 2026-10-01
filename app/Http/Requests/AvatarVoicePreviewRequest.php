<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Internal notes, not published (Scramble exports a request class docblock as the schema description):
// Body of `POST /api/avatar-templates/voice-preview`.
//
// There is deliberately NO free-text field: the sample sentence lives in
// `config/avatar_preview.php`, so an unknown key such as `text` is simply never
// read. `voice_id` is format-restricted because it is interpolated into a
// provider URL path (ElevenLabs, LiveAvatar).
//
// Authorization is done in the controller with the same `create` ability as
// authoring a template, like the sibling export/import actions.
/**
 * Body of `POST /api/avatar-templates/voice-preview`.
 *
 * There is deliberately no free-text field: the sample sentence is fixed by the platform, so an
 * unknown key such as `text` is simply never read. `voice_id` is format-restricted because it is
 * interpolated into a provider URL path (ElevenLabs, LiveAvatar).
 *
 * Authorization is done in the controller with the same `create` ability as authoring a template,
 * like the sibling export/import actions.
 */
class AvatarVoicePreviewRequest extends FormRequest
{
    /**
     * Literal `in:` lists, not implode() over constants — Scramble's static
     * analyser cannot evaluate those (see AvatarTemplateController::catalogue()).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:cartesia,elevenlabs,heygen,tavus'],
            // Exactly one of `voice_id` / `pal_id`; `pal_id` (a Tavus persona) only with `tavus`.
            'voice_id' => ['required_without:pal_id', 'prohibits:pal_id', 'string', 'regex:/^[A-Za-z0-9_-]{1,80}$/'],
            'pal_id' => ['required_without:voice_id', 'prohibits:voice_id', 'prohibited_unless:provider,tavus', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            // Meaningful for `tavus` only: which TTS vendor its voice is routed through.
            'tts_engine' => ['nullable', 'string', 'in:cartesia,elevenlabs,tavus-auto,azure'],
            'language' => ['nullable', 'string', 'in:it,en'],
        ];
    }
}
