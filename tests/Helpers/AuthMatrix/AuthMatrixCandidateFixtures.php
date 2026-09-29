<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Models\InterviewSession;
use App\Models\Participant;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * The FIXTURE RESOLVER of the candidate routes: how to build a VALID request
 * for each `/api/candidate/*` route.
 *
 * A candidate route has no URL placeholder: the resource is named in the BODY
 * (`session_id`) and must belong to the participant the bearer token
 * identifies. So a fixture is a payload builder taking the SESSION the
 * request names — which is what lets an isolation case point a victim's
 * session at an attacker's token.
 *
 * `/start` and `/session` name no session: they act on the token's own
 * participant.
 */
final class AuthMatrixCandidateFixtures
{
    /** @var list<string> routes whose body names an interview session */
    public const SESSION_ROUTES = [
        'POST api/candidate/interview/end',
        'POST api/candidate/interview/integrity',
        'POST api/candidate/interview/snapshot',
        'POST api/candidate/interview/suspend',
        'POST api/candidate/interview/utterance',
    ];

    /**
     * Every candidate route, in catalogue order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'POST api/candidate/interview/end',
            'POST api/candidate/interview/integrity',
            'POST api/candidate/interview/snapshot',
            'POST api/candidate/interview/start',
            'POST api/candidate/interview/suspend',
            'POST api/candidate/interview/utterance',
            'GET api/candidate/session',
        ];
    }

    public static function namesSession(string $key): bool
    {
        return in_array($key, self::SESSION_ROUTES, true);
    }

    /**
     * Route-specific environment set up before the request.
     */
    public static function prepare(string $key): void
    {
        if ($key === 'POST api/candidate/interview/snapshot') {
            Storage::fake();
        }
    }

    /**
     * The participant that calls `$key` while ACTIVE — the one for whom the
     * call is valid. `/start` needs a test-mode enrolment with no session
     * (routed to the mock provider); every other route an `in_corso` one with
     * a live session.
     */
    public static function activeParticipant(string $key, AuthMatrixMachineWorld $m): Participant
    {
        $org = $m->world->orgA;

        return $key === 'POST api/candidate/interview/start'
            ? $m->startable($org)
            : $m->participant($org, 'in_corso', label: 'active');
    }

    /**
     * The session a request of `$participant` names, when the route names one.
     */
    public static function sessionOf(string $key, AuthMatrixMachineWorld $m, Participant $participant, string $status = 'in_corso'): ?InterviewSession
    {
        return self::namesSession($key) ? $m->session($participant, $status) : null;
    }

    /**
     * The valid body of `$key`, naming `$session` where the route names one.
     *
     * @return array<string, mixed>
     */
    public static function payload(string $key, ?InterviewSession $session): array
    {
        $id = $session?->id;
        $now = now()->toIso8601String();

        return match ($key) {
            'POST api/candidate/interview/end' => ['session_id' => $id, 'ended_reason' => 'skipped'],
            'POST api/candidate/interview/integrity' => [
                'session_id' => $id,
                'events' => [['kind' => 'tab_hidden', 'payload' => [], 'ts' => $now]],
            ],
            'POST api/candidate/interview/snapshot' => [
                'session_id' => $id,
                'image_base64' => base64_encode("\xFF\xD8\xFF\xE0\x00\x10JFIF"),
            ],
            'POST api/candidate/interview/suspend' => ['session_id' => $id],
            'POST api/candidate/interview/utterance' => [
                'session_id' => $id,
                'speaker' => 'candidate',
                'text' => 'A spoken answer',
                'ts' => $now,
            ],
            default => [],
        };
    }

    /**
     * Ways a candidate bearer can be WRONG although it looks like one. Each is
     * a credential a real attacker can produce, and each must be a 401.
     *
     * @var list<string>
     */
    public const FORGERIES = [
        'expired',
        'tampered_signature',
        'swapped_subject',
        'sso_link_type',
        'foreign_type',
        'missing_type',
        'deleted_participant',
        'not_a_jwt',
    ];

    /**
     * The bearer for one forgery. `$owner` is the participant the token claims
     * to be; `$victim` the one a `swapped_subject` forgery tries to become.
     */
    public static function forgedToken(string $forgery, AuthMatrixMachineWorld $m, Participant $owner, Participant $victim): string
    {
        return match ($forgery) {
            'expired' => self::tokenFromThePast($m, $owner),
            'tampered_signature' => self::flipLastCharacter($m->token($owner)),
            'swapped_subject' => self::withSubject($m->token($owner), (string) $victim->id),
            'sso_link_type' => CandidateTokenFactory::mintCandidateToken($owner, ['typ' => 'sso-link']),
            'foreign_type' => CandidateTokenFactory::mintCandidateToken($owner, ['typ' => 'access']),
            'missing_type' => CandidateTokenFactory::mintCandidateToken($owner, ['typ' => null]),
            'deleted_participant' => self::tokenOfDeletedParticipant($m, $owner),
            'not_a_jwt' => 'not-a-jwt',
            default => throw new LogicException("Unknown candidate token forgery '{$forgery}'."),
        };
    }

    /**
     * A genuine token whose 120-minute life is over: minted now, then the clock
     * moves past it (a JWT refuses to be MINTED already expired). Carbon's test
     * clock is released by the test case's tear-down.
     */
    private static function tokenFromThePast(AuthMatrixMachineWorld $m, Participant $owner): string
    {
        $token = $m->token($owner);
        Carbon::setTestNow(Carbon::now()->addHours(3));

        return $token;
    }

    private static function tokenOfDeletedParticipant(AuthMatrixMachineWorld $m, Participant $owner): string
    {
        $token = $m->token($owner);
        Participant::withoutGlobalScopes()->whereKey($owner->id)->delete();

        return $token;
    }

    private static function flipLastCharacter(string $token): string
    {
        $last = substr($token, -1);

        return substr($token, 0, -1).($last === 'A' ? 'B' : 'A');
    }

    /**
     * The token with its `sub` claim rewritten and its ORIGINAL signature kept:
     * exactly what a candidate editing their own token to become someone else does.
     */
    private static function withSubject(string $token, string $subject): string
    {
        [$header, $payload, $signature] = explode('.', $token);

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['sub'] = $subject;
        $forged = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        return "{$header}.{$forged}.{$signature}";
    }
}
