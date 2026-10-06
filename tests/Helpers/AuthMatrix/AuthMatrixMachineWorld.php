<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Enums\ApiKeyMode;
use App\Enums\ParticipantSchedulingStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ApiClient;
use App\Models\BarsIndicator;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\Export;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\InterviewRecording;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\PublicApi\SessionTokenMinter;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Storage;

/**
 * The targets and credentials of the MACHINE surfaces of the matrix (T6):
 * candidate JWTs, M2M keys and Public API keys.
 *
 * It sits on top of the two-tenant {@see AuthMatrixWorld} (`orgA` owns the
 * caller's own resources, `orgB` owns the foreign ones) and adds what those
 * surfaces need and the user-JWT resources do not: participants in an
 * arbitrary lifecycle state and mode, their interview sessions, and API
 * clients with a chosen ability set and lifecycle (revoked, expired, test mode).
 *
 * Every participant carries the world's marker in its display name, so the
 * runner can prove a denied response leaked nothing about a foreign one.
 */
final class AuthMatrixMachineWorld
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(public readonly AuthMatrixWorld $world) {}

    public static function make(): self
    {
        return new self(AuthMatrixWorld::make());
    }

    /**
     * An `active`, interviewable project of the organization.
     */
    public function project(Organization $org): Project
    {
        return $this->memo["project.{$org->id}"] ??= TenantContextScope::runFor($org->id, function () use ($org): Project {
            $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
            $project = Project::factory()->create([
                'framework_version_id' => $fv->id,
                'name' => "{$this->world->marker} project of {$org->id}",
                'status' => 'active',
            ]);
            makeProjectInterviewable($project);

            return $project;
        });
    }

    /**
     * A participant of `$org` in the given (stored, Italian) lifecycle status.
     * `$label` yields several distinct participants of one org/status/mode.
     */
    public function participant(Organization $org, string $status = 'in_corso', ApiKeyMode $mode = ApiKeyMode::Live, string $label = 'candidate'): Participant
    {
        return $this->memo["participant.{$org->id}.{$status}.{$mode->value}.{$label}"] ??= TenantContextScope::runFor(
            $org->id,
            function () use ($org, $status, $mode, $label): Participant {
                $participant = Participant::factory()
                    ->forProject($this->project($org))
                    ->withStatus($status)
                    ->create([
                        'display_name' => "{$this->world->marker} {$label} of {$org->id}",
                        'mode' => $mode,
                    ]);

                return $participant->refresh();
            },
        );
    }

    /**
     * A participant whose `/start` can succeed without a live provider: a
     * TEST-mode enrolment (always routed to the mock provider) of a project
     * whose role has BARS indicators for its selected competency, and with no
     * session yet.
     */
    public function startable(Organization $org): Participant
    {
        return $this->memo["startable.{$org->id}"] ??= TenantContextScope::runFor($org->id, function () use ($org): Participant {
            $project = $this->project($org);
            $role = Role::query()->where('code', $project->role_code)->first() ?? Role::factory()->create(['code' => $project->role_code]);
            $competency = $project->competencies()->firstOrFail();

            for ($i = 0; $i < 2; $i++) {
                $indicator = new BarsIndicator;
                $indicator->forceFill([
                    'role_id' => $role->id,
                    'competency_id' => $competency->id,
                    'text' => ['en' => "Indicator {$i}"],
                    'anchor_5' => ['en' => "Excellent {$i}"],
                    'anchor_3' => ['en' => "Adequate {$i}"],
                    'anchor_1' => ['en' => "Insufficient {$i}"],
                    'position' => $i,
                ])->save();
            }

            return $this->participant($org, 'in_attesa', ApiKeyMode::Test, 'startable');
        });
    }

    /**
     * The participant's interview session (created once, `in_corso` by default).
     * Provider `fake` keeps every controller path away from a live provider.
     */
    public function session(Participant $participant, string $status = 'in_corso', string $provider = 'fake'): InterviewSession
    {
        return $this->memo["session.{$participant->id}.{$status}.{$provider}"] ??= TenantContextScope::runFor(
            $participant->organization_id,
            fn (): InterviewSession => InterviewSession::create([
                'participant_id' => $participant->id,
                'project_id' => $participant->project_id,
                'question_index' => 0,
                'competency_code' => 'PRS',
                'framework_version_id' => $participant->project?->framework_version_id,
                'provider' => $provider,
                'status' => $status,
            ]),
        );
    }

    /**
     * A participant that has FINISHED (`completato`), with a session, a
     * spoken utterance and a completed evaluation: past every read gate of
     * the Public API (transcript, answers, scoring).
     */
    public function readable(Organization $org, ApiKeyMode $mode = ApiKeyMode::Live): Participant
    {
        return $this->memo["readable.{$org->id}.{$mode->value}"] ??= TenantContextScope::runFor($org->id, function () use ($org, $mode): Participant {
            $participant = $this->participant($org, 'completato', $mode, 'readable');
            $session = $this->session($participant, 'completed');

            Utterance::create([
                'interview_session_id' => $session->id,
                'speaker' => 'Candidate',
                'text' => "{$this->world->marker} spoken words",
                'ts' => '2024-01-01 10:00:00',
            ]);

            $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
            $result = CompetencyResult::factory()->create([
                'evaluation_id' => $evaluation->id,
                'competency_code' => 'COL',
                'score' => 4.0,
                'reliability' => 0.67,
            ]);
            IndicatorScore::factory()->create(['competency_result_id' => $result->id, 'position' => 0]);

            return $participant;
        });
    }

    /**
     * A finished participant whose evaluation is still `pending`: the one kind
     * whose retry may be authorized.
     */
    public function retryable(Organization $org): Participant
    {
        return $this->memo["retryable.{$org->id}"] ??= TenantContextScope::runFor($org->id, function () use ($org): Participant {
            $participant = $this->participant($org, 'completato', ApiKeyMode::Live, 'retryable');
            Evaluation::factory()->pending()->create(['participant_id' => $participant->id]);

            return $participant;
        });
    }

    /**
     * A participant with a pending scheduled start (may be rescheduled or cancelled).
     */
    public function scheduled(Organization $org): Participant
    {
        return $this->memo["scheduled.{$org->id}"] ??= TenantContextScope::runFor($org->id, function () use ($org): Participant {
            $participant = $this->participant($org, 'in_attesa', ApiKeyMode::Live, 'scheduled');
            $participant->forceFill([
                'scheduled_at' => now('UTC')->addHours(3),
                'scheduling_status' => ParticipantSchedulingStatus::Pending,
            ])->save();

            return $participant->refresh();
        });
    }

    /**
     * A finished export job of the organization, in the given mode.
     */
    public function export(Organization $org, ApiKeyMode $mode = ApiKeyMode::Live): Export
    {
        return $this->memo["export.{$org->id}.{$mode->value}"] ??= TenantContextScope::runFor(
            $org->id,
            fn (): Export => Export::factory()->create(['mode' => $mode]),
        );
    }

    /**
     * A delivered webhook delivery of the organization (the kind a redelivery accepts).
     */
    public function delivery(Organization $org): WebhookDelivery
    {
        return $this->memo["delivery.{$org->id}"] ??= TenantContextScope::runFor(
            $org->id,
            fn (): WebhookDelivery => WebhookDelivery::factory()
                ->forParticipant($this->participant($org, 'completato', ApiKeyMode::Live, 'delivery'))
                ->create(['status' => WebhookDeliveryStatus::Delivered, 'delivered_at' => now(), 'organization_id' => $org->id]),
        );
    }

    /**
     * A ready recording of the participant, stored under its organization's prefix.
     */
    public function recording(Participant $participant): InterviewRecording
    {
        return $this->memo["recording.{$participant->id}"] ??= TenantContextScope::runFor(
            $participant->organization_id,
            function () use ($participant): InterviewRecording {
                $key = "recordings/{$participant->organization_id}/{$participant->id}/interview.ogg";
                Storage::put($key, 'fake-audio-bytes');

                return InterviewRecording::factory()->create(['participant_id' => $participant->id, 'object_key' => $key]);
            },
        );
    }

    /**
     * A genuine candidate JWT for the participant.
     */
    public function token(Participant $participant): string
    {
        return CandidateTokenFactory::mintCandidateToken($participant);
    }

    /**
     * A genuine embed session token for the (pending) participant, registered
     * as the participant's current one — what `POST /v1/interviews` hands out.
     */
    public function sessionToken(Participant $participant): string
    {
        $minted = app(SessionTokenMinter::class)->mint($participant);
        $participant->forceFill(['session_token_jti' => $minted->jti])->save();

        return $minted->token;
    }

    /**
     * A genuine SSO entry-link token for a fresh candidate of the project — what
     * `POST /m2m/sso-link` and `POST /entry-links` hand out.
     *
     * @param  array<string, mixed>  $overrides  claims to replace
     */
    public function ssoLink(Project $project, array $overrides = []): string
    {
        return CandidateTokenFactory::mintSsoLink([
            'candidate_ref' => 'sso-'.uniqid(),
            'display_name' => "{$this->world->marker} sso candidate",
            'email' => uniqid('sso-').'@matrix.test',
            'project_id' => $project->id,
            'org_id' => $project->organization_id,
            'role_code' => $project->role_code,
            'lang' => 'en',
            ...$overrides,
        ]);
    }

    /**
     * An API client of `$org` and the raw key that authenticates as it.
     *
     * `last_used_at` starts at "just now": authenticating a key stamps that
     * column (throttled to once per five minutes, ApiKeyResolver), and the
     * fingerprint that proves a refusal changed nothing must not mistake that
     * telemetry for a side effect of the request.
     *
     * @param  list<string>  $abilities
     * @param  array<string, mixed>  $overrides  ApiClient columns (`is_active`, `expires_at`, ...)
     * @return array{raw: string, client: ApiClient}
     */
    public function key(Organization $org, array $abilities, ApiKeyMode $mode = ApiKeyMode::Live, array $overrides = []): array
    {
        $raw = ApiKeyGenerator::generate($mode);
        $client = ApiClient::factory()->withRawKey($raw)->create([
            'organization_id' => $org->id,
            'abilities' => $abilities,
            'last_used_at' => now(),
            ...$overrides,
        ]);

        return ['raw' => $raw, 'client' => $client];
    }
}
