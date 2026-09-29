<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AvatarVoicePreviewRequest;
use App\Models\AvatarTemplate;
use App\Services\AvatarPreview\VoicePreviewException;
use App\Services\AvatarPreview\VoicePreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Listen to a vendor voice before activating an avatar template.
 *
 * Gated by the SAME ability as authoring a template (`create`), so only a
 * superadmin is served today. It touches no tenant-scoped row, so unlike
 * `store()`/`import()` it needs no `org.context`: a bare superadmin works.
 */
final class AvatarVoicePreviewController extends Controller
{
    public function __construct(private readonly VoicePreviewService $previews) {}

    /**
     * POST /api/avatar-templates/voice-preview
     *
     * Returns the RAW AUDIO bytes (`audio/mpeg`, `audio/wav` or `audio/ogg`),
     * not JSON. Failures are `{message: <code>}` with one of
     * `voice_preview_unavailable` (422, Tavus stock voice), `voice_preview_provider_not_configured`
     * (503), `voice_preview_voice_not_found` (404) and `voice_preview_provider_error` (502).
     *
     * @response string
     */
    public function __invoke(AvatarVoicePreviewRequest $request): Response|JsonResponse
    {
        $this->authorize('create', AvatarTemplate::class);

        $validated = $request->validated();

        try {
            $preview = $this->previews->preview(
                $validated['provider'],
                $validated['voice_id'],
                $validated['tts_engine'] ?? null,
                $validated['language'] ?? 'it',
            );
        } catch (VoicePreviewException $e) {
            return response()->json(['message' => $e->errorCode], $e->httpStatus());
        }

        return response($preview['audio'], 200, [
            'Content-Type' => $preview['content_type'],
            'Cache-Control' => 'private, max-age='.(int) config('avatar_preview.browser_max_age'),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
