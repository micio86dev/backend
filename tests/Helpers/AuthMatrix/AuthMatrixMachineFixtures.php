<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\WebhookDeliveryId;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * The FIXTURE RESOLVER of the M2M (`/api/m2m/*`) and Public API
 * (`/api/v1/*`) routes: how to build a VALID request against the resources of
 * a given organization (and, for `/v1`, a given key mode).
 *
 * "Valid" is what makes a 403/404 evidence of authorization rather than of a
 * validation accident. The organization argument is what turns the same
 * fixture into an isolation probe: build it for `orgB`, send it with a key of
 * `orgA`, and the only thing left that can refuse it is tenancy.
 *
 * Each route also records the ABILITY (M2M) or SCOPE (`/v1`) it demands, which
 * `AuthMatrixMachineGuardTest` compares with the router — so the value the
 * cases rely on cannot drift from the middleware that enforces it.
 */
final class AuthMatrixMachineFixtures
{
    /**
     * `/api/m2m/*` route => the ability it demands (null: any valid key).
     *
     * @var array<string, string|null>
     */
    public const M2M_ABILITIES = [
        'POST api/m2m/participants' => 'participants:create',
        'GET api/m2m/participants' => 'participants:read',
        'GET api/m2m/participants/{id}' => 'participants:read',
        'PATCH api/m2m/participants/{id}/schedule' => 'participants:schedule',
        'DELETE api/m2m/participants/{id}/schedule' => 'participants:schedule',
        'POST api/m2m/sso-link' => 'sso_link:generate',
        'GET api/m2m/whoami' => null,
    ];

    /**
     * `/api/v1/*` route => the scope it demands (null: any valid key).
     * `GET api/v1/health` is public and outside this list.
     *
     * @var array<string, string|null>
     */
    public const V1_SCOPES = [
        'POST api/v1/exports' => 'exports:write',
        'GET api/v1/exports' => 'exports:read',
        'GET api/v1/exports/{id}' => 'exports:read',
        'POST api/v1/interviews' => 'interviews:write',
        'GET api/v1/interviews' => 'interviews:read',
        'GET api/v1/interviews/{interview}' => 'interviews:read',
        'GET api/v1/interviews/{interview}/answers' => 'interviews:read',
        'GET api/v1/interviews/{interview}/events' => 'interviews:read',
        'GET api/v1/interviews/{interview}/recording' => 'recordings:read',
        'GET api/v1/interviews/{interview}/scoring' => 'interviews:read',
        'POST api/v1/interviews/{interview}/session-tokens' => 'interviews:write',
        'GET api/v1/interviews/{interview}/transcript' => 'interviews:read',
        'GET api/v1/organization' => null,
        'GET api/v1/projects' => 'projects:read',
        'GET api/v1/projects/{project}' => 'projects:read',
        'GET api/v1/usage' => 'usage:read',
        'GET api/v1/webhooks/deliveries' => 'webhooks:read',
        'POST api/v1/webhooks/deliveries/{id}/redeliver' => 'webhooks:write',
    ];

    /**
     * Routes that name a resource of the target tenant (URL id or body reference)
     * and so can be pointed at another tenant's.
     *
     * @var list<string>
     */
    public const M2M_RESOURCE_ROUTES = [
        'POST api/m2m/participants',
        'GET api/m2m/participants/{id}',
        'PATCH api/m2m/participants/{id}/schedule',
        'DELETE api/m2m/participants/{id}/schedule',
        'POST api/m2m/sso-link',
    ];

    /**
     * Every ability an M2M key may hold, as the routes demand them.
     *
     * @return list<string>
     */
    public static function m2mAbilities(): array
    {
        return array_values(array_unique(array_filter(self::M2M_ABILITIES)));
    }

    /**
     * Every scope a `/v1` key may hold, as the routes demand them.
     *
     * @return list<string>
     */
    public static function v1Scopes(): array
    {
        return array_values(array_unique(array_filter(self::V1_SCOPES)));
    }

    /**
     * Environment set up before the request (queues faked, disks faked).
     */
    public static function prepare(string $key): void
    {
        // The hosted interview URL a new enrolment or session token carries needs the candidate app's origin.
        if (in_array($key, ['POST api/v1/interviews', 'POST api/v1/interviews/{interview}/session-tokens'], true)) {
            config(['interview.candidate_app_url' => 'https://interview.example.test']);
        }

        match ($key) {
            'POST api/v1/exports', 'POST api/v1/webhooks/deliveries/{id}/redeliver' => Queue::fake(),
            'GET api/v1/interviews/{interview}/recording' => Storage::fake(),
            default => null,
        };
    }

    /**
     * A valid `/api/m2m/*` request against the resources of `$org`.
     *
     * @return array{params: array<string, string|int>, payload: array<string, mixed>}
     */
    public static function m2m(string $key, AuthMatrixMachineWorld $m, Organization $org): array
    {
        return match ($key) {
            'POST api/m2m/participants' => ['params' => [], 'payload' => self::enrolment($m, $org)],
            'GET api/m2m/participants' => ['params' => [], 'payload' => []],
            'GET api/m2m/participants/{id}' => ['params' => ['id' => self::target($m, $org)->id], 'payload' => []],
            'PATCH api/m2m/participants/{id}/schedule' => [
                'params' => ['id' => $m->scheduled($org)->id],
                'payload' => ['scheduled_at' => now('UTC')->addDays(2)->format('Y-m-d\TH:i:s\Z')],
            ],
            'DELETE api/m2m/participants/{id}/schedule' => ['params' => ['id' => $m->scheduled($org)->id], 'payload' => []],
            'POST api/m2m/sso-link' => ['params' => [], 'payload' => self::enrolment($m, $org)],
            'GET api/m2m/whoami' => ['params' => [], 'payload' => []],
            default => throw new LogicException("No M2M fixture for [{$key}]."),
        };
    }

    /**
     * A valid `/api/v1/*` request against the resources of `$org` in `$mode`.
     *
     * @return array{params: array<string, string|int>, payload: array<string, mixed>}
     */
    public static function v1(string $key, AuthMatrixMachineWorld $m, Organization $org, ApiKeyMode $mode = ApiKeyMode::Live): array
    {
        $readable = static fn (): string => PublicId::encode($m->readable($org, $mode));

        return match ($key) {
            'POST api/v1/exports' => ['params' => [], 'payload' => ['scope' => 'interviews', 'format' => 'jsonl']],
            'GET api/v1/exports' => ['params' => [], 'payload' => []],
            'GET api/v1/exports/{id}' => ['params' => ['id' => PublicId::encode($m->export($org, $mode))], 'payload' => []],
            'POST api/v1/interviews' => ['params' => [], 'payload' => [
                'project_id' => PublicId::encode($m->project($org)),
                'candidate' => [
                    'candidate_ref' => 'v1-'.Str::lower(Str::random(8)),
                    'email' => Str::lower(Str::random(8)).'@matrix.test',
                    'display_name' => 'Enrolled by the matrix',
                ],
            ]],
            'GET api/v1/interviews' => ['params' => [], 'payload' => []],
            'GET api/v1/interviews/{interview}',
            'GET api/v1/interviews/{interview}/answers',
            'GET api/v1/interviews/{interview}/events',
            'GET api/v1/interviews/{interview}/scoring',
            'GET api/v1/interviews/{interview}/transcript' => ['params' => ['interview' => $readable()], 'payload' => []],
            'GET api/v1/interviews/{interview}/recording' => [
                'params' => ['interview' => PublicId::encode(self::recorded($m, $org, $mode))],
                'payload' => [],
            ],
            'POST api/v1/interviews/{interview}/session-tokens' => [
                'params' => ['interview' => PublicId::encode($m->participant($org, 'in_attesa', $mode, 'pending'))],
                'payload' => [],
            ],
            'GET api/v1/organization', 'GET api/v1/projects', 'GET api/v1/usage', 'GET api/v1/webhooks/deliveries' => ['params' => [], 'payload' => []],
            'GET api/v1/projects/{project}' => ['params' => ['project' => PublicId::encode($m->project($org))], 'payload' => []],
            'POST api/v1/webhooks/deliveries/{id}/redeliver' => [
                'params' => ['id' => WebhookDeliveryId::encode($m->delivery($org))],
                'payload' => [],
            ],
            default => throw new LogicException("No /v1 fixture for [{$key}]."),
        };
    }

    private static function target(AuthMatrixMachineWorld $m, Organization $org): Participant
    {
        return $m->participant($org, 'in_attesa', ApiKeyMode::Live, 'target');
    }

    private static function recorded(AuthMatrixMachineWorld $m, Organization $org, ApiKeyMode $mode): Participant
    {
        $participant = $m->readable($org, $mode);
        $m->recording($participant);

        return $participant;
    }

    /**
     * @return array<string, mixed>
     */
    private static function enrolment(AuthMatrixMachineWorld $m, Organization $org): array
    {
        return [
            'project_id' => $m->project($org)->id,
            'candidate_ref' => 'm2m-'.Str::lower(Str::random(8)),
            'email' => Str::lower(Str::random(8)).'@matrix.test',
            'display_name' => 'Enrolled by the matrix',
        ];
    }
}
