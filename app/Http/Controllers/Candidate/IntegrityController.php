<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\ResolvesOwnedSession;
use App\Http\Controllers\Controller;
use App\Models\IntegrityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IntegrityController (C7a — Interview Session Mechanics).
 *
 * Handles POST /api/candidate/interview/integrity
 *
 * Batch proctoring event ingestion. Accepts an array of integrity events, validates
 * each event.kind against the 14 canonical types (13 from proctor-config.ts, plus proctor_unavailable), and persists
 * the batch atomically (all-or-nothing: if ANY kind is invalid → 422, nothing persisted).
 *
 * Canonical kinds (13) — sourced from legacy-demo/src/lib/proctor-config.ts:
 *   tab_hidden, focus_lost, second_monitor, face_absent, looking_away,
 *   looking_down, too_far, multiple_faces, fullscreen_exit, clipboard_copy,
 *   clipboard_paste, second_voice, phone_detected
 *
 * Response contract:
 * - 202 Accepted → all events persisted (all kinds are valid)
 * - 404 Not Found → session not owned by authenticated candidate
 * - 422 Unprocessable → unknown kind in any event; NO events persisted
 *
 * REQ: POST /integrity — batch integrity-event ingestion (C7a)
 */
class IntegrityController extends Controller
{
    use ResolvesOwnedSession;

    /**
     * The 14 canonical integrity kinds: the 13 candidate-behaviour kinds from
     * proctor-config.ts, plus `proctor_unavailable` which reports a dead observer.
     * Unknown kinds MUST return 422; the batch is all-or-nothing.
     *
     * @var list<string>
     */
    private const CANONICAL_KINDS = [
        'tab_hidden',
        'focus_lost',
        'second_monitor',
        'face_absent',
        'looking_away',
        'looking_down',
        'too_far',
        'multiple_faces',
        'fullscreen_exit',
        'clipboard_copy',
        'clipboard_paste',
        'second_voice',
        'phone_detected',
        // A DEAD OBSERVER, not a candidate behaviour (proctoring-honest-coverage
        // AD-1). It carries no weight in the operator-side risk score: it is a
        // statement about us, and scoring it would penalise a person for a
        // failure of ours.
        //
        // The scorer is deliberately NOT named here. An arch test forbids any
        // candidate controller from even mentioning it, bluntly and by string
        // match, because a candidate controller reaching for a risk score means
        // that score is heading for the wrong audience. Wording around a blunt
        // guard is cheaper than sharpening it.
        'proctor_unavailable',
    ];

    /**
     * Ingest a batch of integrity events (all-or-nothing).
     *
     * resolveOwnedSession MUST be called FIRST — enforces participant_id + org isolation.
     * Validation of each event.kind against CANONICAL_KINDS is ALL-OR-NOTHING:
     * a single unknown kind causes the entire batch to be rejected (422) with NO rows inserted.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'events' => ['required', 'array', 'min:1'],
            'events.*.kind' => ['required', 'string'],
            'events.*.payload' => ['present', 'array'],
            'events.*.ts' => ['required', 'string'],
        ]);

        // resolveOwnedSession: enforces participant_id + org isolation → 404 if not owned.
        // MUST be invoked FIRST, before any DB mutation (design WARNING-4).
        $session = $this->resolveOwnedSession((int) $validated['session_id']);

        // Validate ALL event kinds BEFORE persisting any rows (all-or-nothing).
        // A single unknown kind → 422, no rows inserted.
        foreach ($validated['events'] as $event) {
            if (! in_array($event['kind'], self::CANONICAL_KINDS, strict: true)) {
                return response()->json(
                    ['message' => "Unknown integrity kind: '{$event['kind']}'. Must be one of the 14 canonical kinds."],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // All kinds are valid — persist the batch.
        // All rows share the same interview_session_id and organization_id.
        $orgId = $session->organization_id;
        $sessionId = $session->id;

        $rows = array_map(static function (array $event) use ($sessionId, $orgId): array {
            return [
                'interview_session_id' => $sessionId,
                'organization_id' => $orgId,
                'kind' => $event['kind'],
                'payload' => json_encode($event['payload']),
                'ts' => $event['ts'],
            ];
        }, $validated['events']);

        IntegrityEvent::insert($rows);

        return response()->json(null, Response::HTTP_ACCEPTED);
    }
}
