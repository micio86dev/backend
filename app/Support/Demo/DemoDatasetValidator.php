<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Models\BarsIndicator;
use App\Models\Role;
use RuntimeException;

/**
 * Validates `DemoDataset` against the live framework catalog BEFORE
 * `beai:demo-seed` writes a single row (design D4/D5).
 *
 * Three guards, corresponding to the three defects the legacy `DemoSeeder`
 * carried:
 *
 * 1. Score-vector arity: every authored score vector has EXACTLY as many
 *    entries as the catalog has indicators for that (role, competency) pair.
 *    No `array_slice` truncation (legacy Defect F) — a mismatch is a fixture
 *    bug, reported loudly, never silently trimmed.
 * 2. Excerpt-sentence bounds: every authored `excerpt_sentence` index is a
 *    real sentence of its answer, so the writer can never slice past the end
 *    of the string it is quoting.
 * 3. `potential` role-code guard: any project with `assessment_type =
 *    'potential'` must carry `role_code = null` (legacy Defect E). All demo
 *    projects are `standard` (design D10), so this is structurally
 *    unreachable today — asserted anyway, so the guard exists before the
 *    day someone adds a `potential` project back.
 */
final class DemoDatasetValidator
{
    /**
     * @return list<string> Problems found; an empty list means the fixture is valid.
     */
    public static function validate(): array
    {
        return [
            ...self::validateScoreArity(),
            ...self::validateExcerptSentenceIndices(),
            ...self::validatePotentialRoleCodeGuard(),
            ...self::validateAiRequestsConformance(),
            ...self::validateWebhookDeliveriesConformance(),
            ...self::validateNotificationLogsConformance(),
        ];
    }

    /**
     * @throws RuntimeException when the fixture disagrees with the catalog.
     */
    public static function assertValid(): void
    {
        $errors = self::validate();

        if ($errors !== []) {
            throw new RuntimeException(
                "DemoDataset fixture failed validation against the framework catalog:\n".implode("\n", $errors)
            );
        }
    }

    /**
     * The row counts `beai:demo-seed` is expected to produce, derived purely
     * from the fixture (no DB read) — the census gate's basis for
     * "already provisioned" vs "partial" vs "empty" (design D3).
     *
     * The four operational-surface keys (`ai_requests`, `api_clients`,
     * `webhook_deliveries`, `notification_logs`) are REPORT-ONLY — added for
     * `printOperationalCensus` (design D11's seam at `checkCensusGate:152-157`
     * builds `$expectedShallow` from four explicitly named keys and never
     * reads these). They are never fed into `checkCensusGate`, which stays
     * byte-for-byte untouched.
     *
     * @return array{
     *   framework_versions: int, avatar_templates: int, avatar_templates_active: int,
     *   projects: int, participants: int, interview_sessions: int,
     *   evaluations: int, snapshots: int, ai_requests: int, api_clients: int,
     *   webhook_deliveries: int, notification_logs: int
     * }
     */
    public static function expectedCensus(): array
    {
        $participants = DemoDataset::participants();

        $sessionCount = 0;
        $evaluationCount = 0;
        $snapshotCount = 0;
        $snapshotKeys = DemoDataset::snapshotParticipantKeys();

        foreach ($participants as $participant) {
            $sessionCount += count($participant['sessions']);

            if ($participant['evaluation'] !== null) {
                $evaluationCount++;
            }

            if (in_array($participant['key'], $snapshotKeys, true)) {
                $completedSessions = array_filter(
                    $participant['sessions'],
                    static fn (array $session): bool => $session['status'] === 'completed',
                );
                $snapshotCount += count($completedSessions) * 2;
            }
        }

        return [
            'framework_versions' => 1,
            'avatar_templates' => 2,
            'avatar_templates_active' => 1,
            'projects' => count(DemoDataset::projects()),
            'participants' => count($participants),
            'interview_sessions' => $sessionCount,
            'evaluations' => $evaluationCount,
            'snapshots' => $snapshotCount,
            'ai_requests' => collect(DemoDataset::aiRequestCalls())->flatten(1)->count(),
            'api_clients' => count(DemoDataset::apiClients()),
            'webhook_deliveries' => count(DemoDataset::webhookDeliveries()),
            'notification_logs' => count(DemoDataset::notificationLogs()),
        ];
    }

    /**
     * `$participants`/`$projects` default to the real fixture — `validate()`
     * always calls this with no arguments. Both are accepted explicitly so
     * this guard is testable against a DELIBERATELY corrupted vector without
     * editing `DemoDataset` itself (`DemoDatasetValidatorTest` exercises this
     * directly via `ReflectionMethod`, proving the guard actually FIRES on a
     * mismatch rather than merely re-validating already-good data).
     *
     * @param  list<array{key: string, project_key: string, scores: array<string, list<int>>}>|null  $participants
     * @param  list<array{key: string, role_code: string|null}>|null  $projects
     * @return list<string>
     */
    private static function validateScoreArity(?array $participants = null, ?array $projects = null): array
    {
        $errors = [];
        $projectsByKey = self::projectsByKey($projects);
        $roleIdByCode = [];

        foreach ($participants ?? DemoDataset::participants() as $participant) {
            if ($participant['scores'] === []) {
                continue;
            }

            $project = $projectsByKey[$participant['project_key']] ?? null;

            if ($project === null) {
                $errors[] = "Participant [{$participant['key']}] references unknown project [{$participant['project_key']}].";

                continue;
            }

            $roleCode = $project['role_code'];

            if ($roleCode === null) {
                $errors[] = "Participant [{$participant['key']}] scores a project with a null role_code — cannot resolve a catalog role.";

                continue;
            }

            $roleIdByCode[$roleCode] ??= Role::where('code', $roleCode)->value('id');
            $roleId = $roleIdByCode[$roleCode];

            if ($roleId === null) {
                $errors[] = "Role [{$roleCode}] not found — run FrameworkCatalogSeeder before validating.";

                continue;
            }

            foreach ($participant['scores'] as $competencyCode => $scores) {
                $indicatorCount = BarsIndicator::where('role_id', $roleId)
                    ->whereHas('competency', fn ($q) => $q->where('code', $competencyCode))
                    ->count();

                if ($indicatorCount === 0) {
                    $errors[] = "No BARS indicators for (role={$roleCode}, competency={$competencyCode}) — the catalog cannot score this pair.";

                    continue;
                }

                if (count($scores) !== $indicatorCount) {
                    $errors[] = sprintf(
                        'Participant [%s] competency [%s] authored %d score(s) but the catalog defines %d indicator(s) for (role=%s) — EXACT arity required, no truncation.',
                        $participant['key'],
                        $competencyCode,
                        count($scores),
                        $indicatorCount,
                        $roleCode,
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateExcerptSentenceIndices(): array
    {
        $errors = [];

        foreach (DemoDataset::competencyContent() as $language => $competencies) {
            foreach ($competencies as $competencyCode => $answers) {
                foreach ($answers as $position => $answer) {
                    $sentences = self::splitSentences($answer['text']);

                    if (! isset($sentences[$answer['excerpt_sentence']])) {
                        $errors[] = sprintf(
                            'Content [%s/%s position %d] excerpt_sentence index %d has no matching sentence (answer has %d).',
                            $language,
                            $competencyCode,
                            $position,
                            $answer['excerpt_sentence'],
                            count($sentences),
                        );
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * `$projects` defaults to the real fixture; see `validateScoreArity()`'s
     * docblock for why this parameter exists — `DemoDatasetValidatorTest`
     * passes a deliberately-invalid fabricated project through it to prove
     * this guard fires, since every REAL demo project is `standard` (D10)
     * and would otherwise never exercise the `potential` branch at all.
     *
     * @param  list<array{key: string, assessment_type: string, role_code: string|null}>|null  $projects
     * @return list<string>
     */
    private static function validatePotentialRoleCodeGuard(?array $projects = null): array
    {
        $errors = [];

        foreach ($projects ?? DemoDataset::projects() as $project) {
            if ($project['assessment_type'] === 'potential' && $project['role_code'] !== null) {
                $errors[] = "Project [{$project['key']}] is assessment_type=potential but carries a non-null role_code — potential requires role_code=null.";
            }
        }

        return $errors;
    }

    /**
     * `ai_requests` fixture guard (design D14): `(success = false) =
     * (failure_reason IS NOT NULL)` — the same equivalence the raw-DDL CHECK
     * enforces at the DB — checked here before any row is written, and every
     * authored `model` must exist in `scoring.cost_rates_usd_per_million` or
     * `AiRequestCostEstimator` would silently write a 0.000000 anomaly row.
     *
     * @return list<string>
     */
    private static function validateAiRequestsConformance(): array
    {
        $errors = [];
        $rates = config('scoring.cost_rates_usd_per_million', []);

        foreach (DemoDataset::aiRequestCalls() as $participantKey => $rows) {
            foreach ($rows as $index => $row) {
                $hasFailureReason = $row['failure_reason'] !== null;

                if ((! $row['success']) !== $hasFailureReason) {
                    $errors[] = "ai_requests fixture [{$participantKey}][{$index}]: success/failure_reason equivalence violated.";
                }

                if (! array_key_exists($row['model'], $rates)) {
                    $errors[] = "ai_requests fixture [{$participantKey}][{$index}]: model [{$row['model']}] is not in the rate table.";
                }
            }
        }

        return $errors;
    }

    /**
     * `webhook_deliveries` fixture guard (design D15). Only the equivalences
     * fully derivable from the fixture itself are checked here —
     * `delivered_at` is computed by the writer at write time (persisted
     * anchor + offset, design D16), so `status='delivered' <=> delivered_at
     * IS NOT NULL` is enforced by the raw-DDL CHECK at write time instead.
     *
     * @return list<string>
     */
    private static function validateWebhookDeliveriesConformance(): array
    {
        $errors = [];
        $maxAttempts = (int) config('webhooks.delivery.max_attempts');
        $seenKeys = [];
        $seenDedupeIdentities = [];

        foreach (DemoDataset::webhookDeliveries() as $row) {
            $isSkipped = $row['status'] === 'skipped';
            $hasSkipReason = array_key_exists('skip_reason', $row);

            if ($isSkipped !== $hasSkipReason) {
                $errors[] = "webhook_deliveries fixture [{$row['key']}]: skipped <=> skip_reason violated.";
            }

            if ($isSkipped && ($row['attempt_count'] ?? 0) !== 0) {
                $errors[] = "webhook_deliveries fixture [{$row['key']}]: skipped rows must have attempt_count=0.";
            }

            if (($row['attempt_count'] ?? 0) > $maxAttempts) {
                $errors[] = "webhook_deliveries fixture [{$row['key']}]: attempt_count exceeds max_attempts.";
            }

            if (isset($seenKeys[$row['key']])) {
                $errors[] = "webhook_deliveries fixture: duplicate key [{$row['key']}].";
            }
            $seenKeys[$row['key']] = true;

            $dedupeIdentity = implode('|', [
                $row['participant_key'],
                $row['event_type'],
                $row['progress_kind'] ?? 'n/a',
                $row['competency_code'] ?? 'n/a',
            ]);

            if (isset($seenDedupeIdentities[$dedupeIdentity])) {
                $errors[] = "webhook_deliveries fixture [{$row['key']}]: duplicate dedupe identity [{$dedupeIdentity}].";
            }
            $seenDedupeIdentities[$dedupeIdentity] = true;
        }

        return $errors;
    }

    /**
     * `notification_logs` fixture guard (design D18). `sent_at` is
     * writer-computed (design D16), so `status='sent' <=> sent_at IS NOT
     * NULL` is enforced by the raw-DDL CHECK at write time; the
     * `suppression_reason` equivalence IS fully fixture-derivable and
     * checked here.
     *
     * @return list<string>
     */
    private static function validateNotificationLogsConformance(): array
    {
        $errors = [];
        $seenSubjects = [];

        foreach (DemoDataset::notificationLogs() as $index => $row) {
            $isSuppressed = $row['status'] === 'suppressed';
            $hasSuppressionReason = array_key_exists('suppression_reason', $row);

            if ($isSuppressed !== $hasSuppressionReason) {
                $errors[] = "notification_logs fixture [{$index}]: suppressed <=> suppression_reason violated.";
            }

            $subject = $row['subject_kind'].':'.$row['subject_key'];

            if (isset($seenSubjects[$subject])) {
                $errors[] = "notification_logs fixture: duplicate subject [{$subject}] — violates the (org, type, subject_type, subject_id) unique.";
            }
            $seenSubjects[$subject] = true;
        }

        return $errors;
    }

    /**
     * @param  list<array{key: string, role_code: string|null}>|null  $projects
     * @return array<string, array{key: string, role_code: string|null}>
     */
    private static function projectsByKey(?array $projects = null): array
    {
        $byKey = [];

        foreach ($projects ?? DemoDataset::projects() as $project) {
            $byKey[$project['key']] = $project;
        }

        return $byKey;
    }

    /**
     * Splits on sentence-ending punctuation followed by whitespace — the
     * same boundary the writer uses to slice an excerpt out of a stored
     * answer, so a fixture's sentence count here is exactly what the writer
     * will see.
     *
     * @return list<string>
     */
    public static function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];

        return array_values(array_filter($parts, static fn (string $s): bool => $s !== ''));
    }
}
