<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

/**
 * Pest datasets of the MACHINE surfaces (candidate JWT, M2M key, Public API
 * key): one named case per (route, actor) or (route, scenario).
 *
 * Like {@see AuthMatrixDatasets}, resolved before the app boots, so they read
 * only the hand-written {@see AuthMatrixCatalogue::entries()} and the static
 * fixture registries, never the router.
 */
final class AuthMatrixMachineDatasets
{
    /**
     * Participant status -> the status of the interview session that goes with it.
     *
     * @var array<string, string>
     */
    public const LIFECYCLE = [
        'in_attesa' => 'pending',
        'in_corso' => 'in_corso',
        'in_valutazione' => 'completed',
        'completato' => 'completed',
        'errore' => 'error',
    ];

    /**
     * Every route of an auth kind x every actor the catalogue gives an outcome for.
     *
     * @return array<string, array{string, string}>
     */
    public static function actors(string $auth): array
    {
        $cases = [];

        foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
            if ($entry['auth'] !== $auth) {
                continue;
            }

            foreach (array_keys($entry['outcomes']) as $actor) {
                $cases["{$key} :: {$actor}"] = [$key, $actor];
            }
        }

        return $cases;
    }

    /**
     * Every route of an auth kind x one scenario name.
     *
     * @param  list<string>  $scenarios
     * @return array<string, array{string, string}>
     */
    public static function scenarios(string $auth, array $scenarios): array
    {
        $cases = [];

        foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
            if ($entry['auth'] !== $auth) {
                continue;
            }

            foreach ($scenarios as $scenario) {
                $cases["{$key} :: {$scenario}"] = [$key, $scenario];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function candidateForgeries(): array
    {
        return self::scenarios(AuthMatrix::AUTH_CANDIDATE, AuthMatrixCandidateFixtures::FORGERIES);
    }

    /**
     * Every session-naming candidate route x a victim session the token does not own.
     *
     * @return array<string, array{string, string}>
     */
    public static function candidateIsolation(): array
    {
        $cases = [];

        foreach (AuthMatrixCandidateFixtures::SESSION_ROUTES as $key) {
            foreach (['same_org_participant', 'other_org_participant'] as $victim) {
                $cases["{$key} :: {$victim}"] = [$key, $victim];
            }
        }

        return $cases;
    }

    /**
     * Every candidate route x participant lifecycle status, with the status
     * the documented state machine answers.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function candidateLifecycle(): array
    {
        $cases = [];

        foreach (AuthMatrixCandidateFixtures::keys() as $key) {
            foreach (array_keys(self::LIFECYCLE) as $status) {
                $cases["{$key} :: {$status}"] = [$key, $status, self::candidateLifecycleStatus($key, $status)];
            }
        }

        return $cases;
    }

    /**
     * ParticipantStatusGuard blocks `completato`/`errore` outright (403). Past it,
     * the controller judges the SESSION: only a live (`in_corso`) one accepts
     * `suspend`/`end`/`utterance`; `integrity` and `snapshot` are late-flush
     * tolerant (the client sends its last proctoring batch after `end`); and
     * `/start` has nothing left to open once every competency is done.
     */
    private static function candidateLifecycleStatus(string $key, string $status): int
    {
        if ($key === 'GET api/candidate/session') {
            return 200;
        }

        if (in_array($status, ['completato', 'errore'], true)) {
            return 403;
        }

        return match ($key) {
            'POST api/candidate/interview/start' => $status === 'in_valutazione' ? 422 : 201,
            'POST api/candidate/interview/suspend' => $status === 'in_corso' ? 200 : 409,
            'POST api/candidate/interview/end' => $status === 'in_corso' ? 200 : 409,
            'POST api/candidate/interview/utterance' => $status === 'in_corso' ? 202 : 409,
            'POST api/candidate/interview/integrity', 'POST api/candidate/interview/snapshot' => 202,
            default => throw new \LogicException("No lifecycle expectation for {$key}."),
        };
    }
}
