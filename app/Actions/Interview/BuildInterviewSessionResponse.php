<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\Project;
use App\Services\Provider\ProviderToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

/**
 * BuildInterviewSessionResponse — build the 201 success response for
 * `/candidate/interview/start` (split-interview-controller design.md D1).
 *
 * A MOVE of `InterviewController::buildSuccessResponse()` +
 * `resolveCompletionPhrases()` + `resolveAudioOnly()`, verbatim. No constructor
 * dependencies: every call inside is a static facade (`Lang::get`, `response()`) or
 * an Eloquent static (`Project::whereKey`, `AvatarTemplate::whereKey`) — pure
 * computation from already-resolved inputs, no DB writes, no transaction coupling.
 *
 * `InterviewController::start()`'s own `@scramble-return` annotation spells out the
 * exact OpenAPI response shape by hand (Scramble cannot follow a private helper
 * whose fields come from further method calls) — this move does not change what
 * Scramble emits, since that annotation is unaffected by where the body that
 * produces this shape actually lives.
 *
 * SECURITY: NEVER include API key material. Only the ephemeral token/URL the
 * client needs to connect to the provider is included.
 */
final class BuildInterviewSessionResponse
{
    /**
     * @param  string|null  $language  The PROJECT's language (BCP-ish locale, may be null).
     * @param  string|null  $conversationId  The provider conversation id of a fresh multi-competency create; null otherwise.
     * @param  array{conversation_id: string, competency_code: string}|null  $continuation  Present only for a granted continuation on a live conversation; the key is absent otherwise.
     * @param  string|null  $promptVersion  Composed prompt template version (C8) — the composed
     *                                      prompt's version on every 201.
     */
    public function handle(
        InterviewSession $session,
        ProviderToken $token,
        ?string $language,
        ?string $promptVersion = null,
        ?int $competencyOrdinal = null,
        ?int $totalCompetencies = null,
        ?string $conversationId = null,
        ?array $continuation = null,
    ): JsonResponse {
        [$endPhrase, $finalPhrase] = $this->resolveCompletionPhrases($language);

        $body = [
            'session_id' => $session->id,
            'provider' => $token->provider,
            // Voice-only interviews, so the client knows not to mount a video
            // element it will never receive a track for.
            //
            // The knob already existed and already reached the provider
            // (`TemplatePayload` maps it to Tavus's `audio_only`); it simply
            // never reached the BROWSER. The candidate app kept attaching the
            // stream to a `<video>`, which painted an undecoded frame — green
            // and black vertical banding where a face belongs.
            //
            // Carries no vendor identity, which is what makes it safe to hand
            // a candidate: "this interview has no video" is a fact about the
            // interview, not about who renders it.
            'audio_only' => $this->resolveAudioOnly($session),
            // HeyGen: token; Tavus: null
            'provider_token' => $token->token,
            // Tavus: conversation_url; HeyGen: null
            'conversation_url' => $token->conversation_url,
            'question_context' => [
                'competency_code' => $session->competency_code,
                'question_index' => $session->question_index,
                // Machine-facing field names stay literal (snake_case); VALUES are localized.
                'end_phrase' => $endPhrase,
                'final_phrase' => $finalPhrase,
                // C8 (M-3): prompt version for audit and traceability.
                // Machine-facing: returned literally, never localized.
                'prompt_version' => $promptVersion,
                // D6: 1-based position in the project's competency order, and how
                // many there are. Machine-facing — literal in every locale.
                //
                // `competency_ordinal` is NOT `question_index + 1` as an identity —
                // it is a coincidence of well-formed data. `question_index` is
                // PERSISTED on the session row, frozen at creation, and equals
                // `position` (0-based) verbatim. `competency_ordinal` is DERIVED
                // per request from the ordered list's own array index and is
                // always dense (1..N), whatever `position` holds — it diverges
                // from `question_index + 1` whenever positions are sparse or the
                // project is reordered after a session already exists.
                'competency_ordinal' => $competencyOrdinal,
                'total_competencies' => $totalCompetencies,
            ],
        ];

        // Only a fresh single-session create names its conversation (design A6/N11); with the gate
        // closed the key is absent, not null, so the body is byte-identical to the old one.
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }

        // Present exactly when the browser may keep its conversation and retarget it (design D2):
        // a server-issued code and the id it asserted, never prose, anchors or a prompt.
        if ($continuation !== null) {
            $body['continuation'] = $continuation;
        }

        return response()->json($body, Response::HTTP_CREATED);
    }

    /**
     * Resolve the localized avatar completion-signal phrases for a language.
     *
     * Institutional UX chrome (NOT tenant/BARS content): the same phrases for every
     * project of a given language, stored in lang/{locale}/interview.php.
     *
     * Resolution rule (per interview-frontend delta spec):
     *   1. Use the PROJECT's language when a phrase file exists for it.
     *   2. Otherwise fall back to the platform default language
     *      (config app.fallback_locale) — the fallback phrase is ALWAYS included
     *      (an absent field is a contract violation).
     *
     * Lang::has() checks whether the key resolves for the exact locale (no implicit
     * fallback), so a missing/unknown/null language deterministically falls back.
     *
     * PUBLIC (not merely an internal step of `handle()`): `InterviewController`
     * also calls this directly when composing the avatar's spoken prompt, so the
     * sentence the avatar is told to say and the one the client's response body
     * advertises can never drift apart — the same reasoning that keeps
     * `resolveAudioOnly()` private (it has no such second caller).
     *
     * @param  string|null  $language  The PROJECT's language.
     * @return array{0: string, 1: string} [end_phrase, final_phrase]
     */
    public function resolveCompletionPhrases(?string $language): array
    {
        $fallback = (string) config('app.fallback_locale');

        // `Lang::get()` resolves the fallback itself, so the locale goes
        // straight through. The removed `Lang::has($key, $language)` guard
        // claimed to check the EXACT locale; `Translator::has()`'s third
        // argument defaults to true, so it answered true for locales with no
        // file on disk — and `Lang::get()` fell back regardless, which made
        // the guard unobservable. Same correction as OpeningTextComposer's.
        $locale = $language ?? $fallback;

        // Both keys resolve to scalar strings (leaf entries in lang/{locale}/interview.php).
        $endPhrase = (string) Lang::get('interview.end_phrase', [], $locale);
        $finalPhrase = (string) Lang::get('interview.final_phrase', [], $locale);

        return [$endPhrase, $finalPhrase];
    }

    private function resolveAudioOnly(InterviewSession $session): bool
    {
        $templateId = Project::whereKey($session->project_id)->value('avatar_template_id');

        if ($templateId === null) {
            return false;
        }

        $config = AvatarTemplate::availableToTenant()->whereKey($templateId)->value('config');

        if (! is_array($config)) {
            return false;
        }

        return ($config['audioOnly'] ?? false) === true;
    }
}
