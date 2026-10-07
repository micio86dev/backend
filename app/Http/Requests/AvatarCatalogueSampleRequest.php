<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Internal notes, not published (Scramble exports a request class docblock as the schema description):
// Query of `GET /api/avatar-templates/catalogue-sample`.
//
// There is deliberately NO url field: the server resolves the download URL from the vendor's own
// answer for the voice, so an unknown key such as `url` is simply never read (SSRF). `voice_id` is
// format-restricted because it is interpolated into a provider URL path. Only `cartesia` is proxied:
// ElevenLabs' `preview_url` is a public CDN url the browser plays directly.
//
// Authorization is done in the controller with the same `create` ability as the synthesised sample.
/**
 * Query of `GET /api/avatar-templates/catalogue-sample`.
 */
class AvatarCatalogueSampleRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:cartesia'],
            'voice_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,80}$/'],
        ];
    }
}
