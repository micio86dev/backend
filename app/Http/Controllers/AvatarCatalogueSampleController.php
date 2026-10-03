<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AvatarCatalogueSampleRequest;
use App\Models\AvatarTemplate;
use App\Services\AvatarPreview\CatalogueSampleService;
use App\Services\AvatarPreview\VoicePreviewException;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Listen to a vendor's own CATALOGUE clip of a voice (Cartesia), which only the platform key can download.
 *
 * Gated by the SAME ability as the synthesised sample (`create`): only a superadmin is served,
 * and it needs no `org.context` because it touches no tenant-scoped row.
 */
final class AvatarCatalogueSampleController extends Controller
{
    public function __construct(private readonly CatalogueSampleService $samples) {}

    /**
     * GET /api/avatar-templates/catalogue-sample
     *
     * Returns the RAW AUDIO bytes (`audio/wav`, `audio/ogg` or `audio/mpeg`), not JSON. Failures are
     * `{message: <code>}` with one of `voice_preview_unavailable` (422, the voice has no catalogue
     * clip), `voice_preview_provider_not_configured` (503), `voice_preview_voice_not_found` (404)
     * and `voice_preview_provider_error` (502).
     */
    #[ResponseDoc(200, description: 'The audio clip.', mediaType: 'audio/wav', type: 'string', format: 'binary')]
    #[ResponseDoc(200, description: 'The audio clip.', mediaType: 'audio/ogg', type: 'string', format: 'binary')]
    #[ResponseDoc(200, description: 'The audio clip.', mediaType: 'audio/mpeg', type: 'string', format: 'binary')]
    public function __invoke(AvatarCatalogueSampleRequest $request): Response|JsonResponse
    {
        $this->authorize('create', AvatarTemplate::class);

        try {
            $sample = $this->samples->sample($request->validated('voice_id'));
        } catch (VoicePreviewException $e) {
            return response()->json(
                ['message' => $e->errorCode] + ($e->reason === null ? [] : ['reason' => $e->reason]),
                $e->httpStatus(),
            );
        }

        return response($sample['audio'], 200, [
            'Content-Type' => $sample['content_type'],
            'Cache-Control' => 'private, max-age='.(int) config('avatar_preview.browser_max_age'),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
