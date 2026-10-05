<?php

declare(strict_types=1);

/**
 * The evaluation retry email (scoring-retry-rt-b, slice PR3a, still dark).
 *
 * A successful authorization queues ONE transactional invitation of kind
 * `Retry` after the transaction commits, for a deliverable address only. The
 * template is static and multilingual (ruling 10), the tenant owns the chrome
 * and not the words, and nothing about the evaluation reaches the message.
 *
 * No route reaches the action yet (PR3b), so every case drives it directly.
 *
 * REQ: The Evaluation Retry Email Is A Static Transactional Candidate Email
 *      (openspec/changes/scoring-retry-rt-b/specs/notifications/spec.md)
 */

use App\Actions\Participant\AuthorizeEvaluationRetry;
use App\Actions\Participant\RetryActor;
use App\Actions\Participant\RetryAuthorization;
use App\Exceptions\Participant\EvaluationRetryRefusalReason;
use App\Exceptions\Participant\EvaluationRetryRefused;
use App\Jobs\SendCandidateInvitationJob;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Notifications\CandidateInvitationNotification;
use App\Support\Mail\CandidateInvitationKind;
use App\Support\Mail\EmailBranding;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['interview.candidate_app_url' => 'https://candidate.test']);
});

/**
 * A `completato` participant with a `pending` Evaluation: one valid and one
 * invalid competency, whose code and name must never reach the message.
 *
 * @param  array<string, mixed>  $o
 * @return array{org: Organization, project: Project, participant: Participant, evaluation: Evaluation, codes: list<string>}
 */
function retryMailWorld(array $o = []): array
{
    $o += [
        'email' => 'giulia@example.test',
        'language' => 'it',
        'orgName' => 'Acme Assessments',
        'color' => null,
    ];

    $org = Organization::factory()->create(['name' => $o['orgName'], 'primary_color' => $o['color']]);
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'name' => 'Sales Team 2026',
    ]);

    // Competencies are global and unique per code: a fresh pair per world.
    static $serial = 0;
    $codes = [];
    foreach ([['Z', 'Zebra Tactics', 'Tattica Zebra'], ['Q', 'Quartz Drift', 'Deriva Quarzo']] as $i => [$letter, $en, $it]) {
        $code = $letter.chr(65 + intdiv($serial, 26) % 26).chr(65 + $serial % 26);
        $serial++;
        $competency = Competency::factory()->create(['code' => $code, 'name' => ['en' => $en, 'it' => $it]]);
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'position' => $i + 1,
        ]);
        $codes[] = $code;
    }

    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create([
        'email' => $o['email'],
        'display_name' => 'Giulia Ferrari',
        'language' => $o['language'],
    ]);

    $evaluation = Evaluation::factory()->pending()->create([
        'participant_id' => $participant->id,
        'framework_version_id' => $fv->id,
        'retry_attempt' => false,
    ]);
    CompetencyResult::factory()->valid()->create(['evaluation_id' => $evaluation->id, 'competency_code' => $codes[0]]);
    CompetencyResult::factory()->unscorable()->create(['evaluation_id' => $evaluation->id, 'competency_code' => $codes[1]]);

    return compact('org', 'project', 'participant', 'evaluation', 'codes');
}

function retryMailAuthorize(array $world, ?string $reason = null): RetryAuthorization
{
    return app(AuthorizeEvaluationRetry::class)->handle(
        $world['participant']->id,
        $world['org']->id,
        RetryActor::user(User::factory()->create(['organization_id' => $world['org']->id])->id),
        $reason,
    );
}

/** @return list<SendCandidateInvitationJob> */
function retryMailQueued(): array
{
    return Queue::pushed(SendCandidateInvitationJob::class)->all();
}

/**
 * Run the queued job against the real mail path and capture the message as the
 * recipient would get it: the subject, every line and the full HTML (so the
 * per-tenant chrome is part of what is asserted).
 *
 * @return array{subject: string, lines: string, html: string, actionUrl: string|null}
 */
function retryMailRender(SendCandidateInvitationJob $job): array
{
    $captured = null;
    Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$captured): void {
        $mail = $event->notification->toMail($event->notifiable);
        $captured = [
            'subject' => (string) $mail->subject,
            'lines' => implode("\n", [...$mail->introLines, ...$mail->outroLines, (string) $mail->greeting, (string) $mail->salutation]),
            'html' => $mail->render()->toHtml(),
            'actionUrl' => $mail->actionUrl,
        ];
    });

    $job->handle();

    return $captured ?? throw new RuntimeException('The job sent nothing.');
}

// ─── queued after commit, for a deliverable address ─────────────────────────

test('a successful authorization queues exactly one retry invitation for a deliverable address', function (): void {
    Queue::fake();
    $world = retryMailWorld();

    $result = retryMailAuthorize($world);

    $jobs = retryMailQueued();
    expect($jobs)->toHaveCount(1)
        ->and($result->emailSent)->toBeTrue();

    // The very link the authorizer got back, addressed to the participant.
    Notification::fake();
    $jobs[0]->handle();
    Notification::assertSentOnDemand(
        CandidateInvitationNotification::class,
        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'giulia@example.test'
            && $notification->toMail($notifiable)->actionUrl === $result->entryUrl,
    );
});

test('the retry job carries the Retry kind and the initial invitation keeps its own', function (): void {
    Queue::fake();
    retryMailAuthorize(retryMailWorld());

    $job = retryMailQueued()[0];
    $property = new ReflectionProperty($job, 'kind');

    expect($property->getValue($job))->toBe(CandidateInvitationKind::Retry);

    $default = new SendCandidateInvitationJob('a@example.test', 'https://x.test', 'A', 'O', 'P', 'label', 'en');
    expect($property->getValue($default))->toBe(CandidateInvitationKind::Initial);
});

test('the retry email is rendered in the interview language, subject and intro included', function (string $language, string $subject, string $intro): void {
    Queue::fake();
    retryMailAuthorize(retryMailWorld(['language' => $language]));

    $mail = retryMailRender(retryMailQueued()[0]);

    expect($mail['subject'])->toBe($subject)
        ->and($mail['lines'])->toContain($intro);
})->with([
    'italian' => ['it', 'Completa una parte del tuo colloquio per Sales Team 2026', 'Acme Assessments ti invita a ripetere una parte del colloquio per Sales Team 2026.'],
    'english' => ['en', 'Please complete part of your interview for Sales Team 2026', 'Acme Assessments invites you to retake part of the interview for Sales Team 2026.'],
]);

test('the retry email reuses the invitation lines and never the initial subject or intro', function (): void {
    Queue::fake();
    retryMailAuthorize(retryMailWorld(['language' => 'en']));

    $mail = retryMailRender(retryMailQueued()[0]);

    expect($mail['lines'])
        ->toContain(__('candidate_invitation.requirements', [], 'en'))
        ->toContain(__('candidate_invitation.url_fallback', [], 'en'))
        ->not->toContain('has invited you to a short interview')
        ->and($mail['subject'])->not->toBe('Your interview for Sales Team 2026');
});

test('the retry email carries the name, organization, project, link and the absolute expiry taken from the token', function (string $language): void {
    $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
    Queue::fake();
    $result = retryMailAuthorize(retryMailWorld(['language' => $language]));

    $mail = retryMailRender(retryMailQueued()[0]);

    $expiry = $result->expiresAt->copy();
    $expiry->locale($language);
    $label = $expiry->isoFormat('LLL');

    expect($mail['lines'])
        ->toContain('Giulia Ferrari')
        ->toContain('Acme Assessments')
        ->toContain('Sales Team 2026')
        ->toContain($result->entryUrl)
        ->toContain($label)
        // 24 h after the mint: the emailed lifetime, not a hard-coded duration.
        ->and($result->expiresAt->getTimestamp() - now()->getTimestamp())->toBe(1440 * 60);
})->with(['it', 'en']);

test('the retry email states that the link is single-use', function (string $language, string $phrase): void {
    Queue::fake();
    retryMailAuthorize(retryMailWorld(['language' => $language]));

    expect(retryMailRender(retryMailQueued()[0])['lines'])->toContain($phrase);
})->with([
    'italian' => ['it', 'una sola volta'],
    'english' => ['en', 'only once'],
]);

test('the retry email reveals no score, competency, evaluation status or authorizer reason', function (string $language): void {
    Queue::fake();
    $world = retryMailWorld(['language' => $language]);

    retryMailAuthorize($world, 'secret-reason-zeta-9921');

    $mail = retryMailRender(retryMailQueued()[0]);
    // The signed link is random base64 and may contain any short code by chance.
    $everything = preg_replace('#https://candidate\.test/[^\s"\'<]+#', 'URL', $mail['subject']."\n".$mail['lines']."\n".$mail['html']);

    foreach ([...$world['codes'], 'Zebra Tactics', 'Tattica Zebra', 'Quartz Drift', 'Deriva Quarzo', '3.67', '3,67', 'secret-reason-zeta-9921', 'pending', 'unscorable'] as $secret) {
        expect(str_contains($everything, $secret))->toBeFalse("The {$language} retry email leaks `{$secret}`.");
    }
})->with(['it', 'en']);

test('branding is chrome only: the colour reaches the layout and the words are identical for every tenant', function (): void {
    Queue::fake();
    retryMailAuthorize(retryMailWorld(['color' => '#ff6600', 'language' => 'en']));
    $branded = retryMailRender(retryMailQueued()[0]);

    Queue::fake();
    retryMailAuthorize(retryMailWorld(['color' => null, 'language' => 'en']));
    $plain = retryMailRender(retryMailQueued()[0]);

    expect($branded['html'])->toContain('background-color: #ff6600')
        ->and($plain['html'])->not->toContain('#ff6600')
        // Same organization name and project in both fixtures: any difference in
        // the words would be tenant-controlled copy.
        ->and($branded['subject'])->toBe($plain['subject'])
        ->and(preg_replace('#https://candidate\.test/\S+#', 'URL', $branded['lines']))
        ->toBe(preg_replace('#https://candidate\.test/\S+#', 'URL', $plain['lines']));
});

// ─── not queued when nobody can be written to ───────────────────────────────

test('no retry email is queued for a placeholder or purged address and the link is still returned', function (string $kind): void {
    Queue::fake();
    $world = retryMailWorld();
    $address = $kind === 'legacy'
        ? PlaceholderEmail::for('legacy-candidate')
        : PlaceholderEmail::forPurged($world['participant']->candidate_ref);
    $world['participant']->forceFill(['email' => $address])->save();

    $result = retryMailAuthorize($world);

    Queue::assertNotPushed(SendCandidateInvitationJob::class);
    expect($result->emailSent)->toBeFalse()
        ->and($result->entryUrl)->toStartWith('https://candidate.test/');
})->with(['legacy', 'purged']);

test('no retry email is queued for a reusable-link visitor and the link is still returned', function (): void {
    Queue::fake();
    $world = retryMailWorld();
    $link = ReusableInterviewLink::factory()->forProject($world['project'])->create();
    $world['participant']->forceFill(['reusable_interview_link_id' => $link->id])->save();

    $result = retryMailAuthorize($world);

    Queue::assertNotPushed(SendCandidateInvitationJob::class);
    expect($result->emailSent)->toBeFalse()
        ->and($result->entryUrl)->toStartWith('https://candidate.test/');
});

test('the log line and the audit row say whether an email was queued', function (): void {
    Queue::fake();
    Log::spy();
    $world = retryMailWorld();

    retryMailAuthorize($world);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'participant.retry_authorized') && $context['email_queued'] === true)
        ->once();
});

// ─── failure and rollback semantics ─────────────────────────────────────────

test('a mail failure when the queued job runs leaves the authorization intact and no second one possible', function (): void {
    Queue::fake();
    $world = retryMailWorld();
    retryMailAuthorize($world);
    $job = retryMailQueued()[0];

    // The transport fails on every attempt.
    Event::listen(NotificationSending::class, function (): void {
        throw new RuntimeException('mail transport down');
    });
    foreach ([1, 2, 3] as $attempt) {
        expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'mail transport down');
    }

    expect($world['participant']->fresh()->status)->toBe('in_attesa')
        ->and($world['evaluation']->fresh()->retry_attempt)->toBeTrue();

    try {
        retryMailAuthorize($world);
        $reason = null;
    } catch (EvaluationRetryRefused $e) {
        $reason = $e->reason;
    }
    expect($reason)->toBe(EvaluationRetryRefusalReason::RetryAlreadyConsumed);
    // Still exactly the one queued email: the refusal queued nothing.
    expect(retryMailQueued())->toHaveCount(1);
});

test('a failed send does not leave the tenant colour behind for the next send on the same worker', function (): void {
    Queue::fake();
    retryMailAuthorize(retryMailWorld(['color' => '#ff6600']));
    $job = retryMailQueued()[0];
    Event::listen(NotificationSending::class, function (): void {
        throw new RuntimeException('mail transport down');
    });

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    expect(app(EmailBranding::class)->primaryColor())->toBeNull();
});

test('nothing is queued before the transaction commits and nothing at all when it rolls back', function (): void {
    Queue::fake();
    $world = retryMailWorld();

    DB::beginTransaction();
    retryMailAuthorize($world);
    // The authorization has "committed" its own transaction, but the enclosing
    // one is still open: the email must wait for the real commit.
    Queue::assertNotPushed(SendCandidateInvitationJob::class);
    DB::rollBack();

    Queue::assertNotPushed(SendCandidateInvitationJob::class);
});

test('a refused authorization queues nothing', function (): void {
    Queue::fake();
    $world = retryMailWorld();
    $world['evaluation']->forceFill(['retry_attempt' => true])->save();

    expect(fn () => retryMailAuthorize($world))->toThrow(EvaluationRetryRefused::class);

    Queue::assertNotPushed(SendCandidateInvitationJob::class);
});

test('a gate refusal raised by the mint after the flip rolls back and queues nothing', function (): void {
    Queue::fake();
    // The deadline is an hour away, so the guard passes ...
    $world = retryMailWorld();
    $world['project']->forceFill(['deadline_at' => now()->addHour()])->save();

    // ... and the clock jumps past it right after the participant flip, before the mint.
    Participant::updated(function (): void {
        $this->travelTo(now()->addHours(2));
    });

    expect(fn () => retryMailAuthorize($world))->toThrow(EvaluationRetryRefused::class);

    $this->travelBack();
    Queue::assertNotPushed(SendCandidateInvitationJob::class);
    expect($world['participant']->fresh()->status)->toBe('completato');
});

// ─── not a C12 notification ─────────────────────────────────────────────────

test('the retry email is not a C12 operator notification and writes no notification audit row', function (): void {
    Queue::fake();
    $before = DB::table('notification_logs')->count();

    retryMailAuthorize(retryMailWorld());

    expect(DB::table('notification_logs')->count())->toBe($before)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'evaluation.retry_authorized')->count())->toBe(1);
});
