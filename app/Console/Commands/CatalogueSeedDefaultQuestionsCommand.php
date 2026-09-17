<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalogue\CreateDefaultQuestion;
use App\Actions\Catalogue\OpenDraftRevision;
use App\Actions\Catalogue\PublishRevision;
use App\Exceptions\Console\SeedDryRunRollback;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan catalogue:seed-default-questions [--dry-run] [--publish]
 * [--actor-email=] [--path=]` (feat/seed-default-questions).
 *
 * Loads BEAI's product-owner-approved authored default interview questions
 * — one file, `database/framework/default-questions.json`, vendored as an
 * exact byte-for-byte copy of the wrapper's
 * `docs/app_description/02-domain/framework-authoring/default-questions.json`
 * (the wrapper is not available at API runtime, so the source of truth is
 * copied in here exactly as `roles.json`/`competencies.json`/`bars/*.json`
 * already are for `catalogue:import`/`FrameworkCatalogSeeder`) — into the
 * open draft revision, one competency at a time.
 *
 * REUSES, NEVER DUPLICATES, the catalogue's existing write paths:
 *   - `OpenDraftRevision::open()` — the SAME action `POST /catalogue/
 *     revisions/draft` and `catalogue:import` already use to open or
 *     continue the platform's one draft.
 *   - `App\Actions\Catalogue\CreateDefaultQuestion` — extracted FROM
 *     `DefaultQuestionController::store()` for this command, so a created
 *     row's validation-equivalent uniqueness, `content_version` bump and
 *     `PlatformAuditWriter` audit row are exactly what a superadmin's own
 *     UI action produces.
 *   - `PublishRevision::publish()` — the SAME action `POST /catalogue/
 *     revisions/publish` uses, for `--publish`.
 *
 * COMPETENCY RESOLUTION IS BY CODE, WITHIN THE DRAFT. Competencies are
 * revision-scoped (`Competency.revision_id`), and the draft
 * `OpenDraftRevision` opens or continues was cloned from the latest
 * published revision — every code the source file names is therefore
 * expected to already exist there. A code the draft does NOT carry (a stale
 * source file, or a draft opened before that code was ever published) is
 * reported by name and skipped — never a fatal error, mirroring
 * `BackfillProjectQuestionsCommand`'s own "still incomplete" reporting for
 * exactly the same reason: this command cannot invent a competency that was
 * never authored into the catalogue.
 *
 * IDEMPOTENT BY (competency, position, text). A default question's
 * `(revision_id, competency_id, position)` is unique by constraint
 * (`framework_default_questions_rev_competency_position_unique`), so a
 * re-run against the SAME open draft either finds nothing there yet
 * (creates it) or finds a row already occupying that position — matched by
 * TEXT, not merely existence, so a genuine re-run (identical source file,
 * same draft) is reported as "already seeded" while a position a superadmin
 * has since edited by hand is reported as a conflict and left untouched
 * rather than silently overwritten.
 *
 * `--dry-run` runs the exact same write path inside a transaction that is
 * ALWAYS rolled back — mirroring `BackfillProjectQuestionsCommand::
 * backfillProject()`'s own dry-run pattern (see that method's docblock for
 * why this is deliberately not a second, independently-maintained
 * prediction). `OpenDraftRevision::open()` runs INSIDE that same
 * transaction, so a dry run against an empty platform (no open draft yet)
 * leaves no draft behind either.
 */
final class CatalogueSeedDefaultQuestionsCommand extends Command
{
    protected $signature = 'catalogue:seed-default-questions
        {--dry-run : Report what would be created without writing anything}
        {--publish : Publish the draft after seeding — refuses cleanly, reporting the violations, if the publish sweep finds any, and refuses cleanly, reporting the missing competencies, if seeding itself left any competency without default questions}
        {--actor-email= : Email of the platform superadmin recorded as the actor for every created question and its audit row. Falls back to the sole platform superadmin when omitted and exactly one exists}
        {--path= : Override the default-questions JSON source path (testing only)}';

    protected $description = "Load BEAI's authored default interview questions (database/framework/default-questions.json) into the framework catalogue's open draft revision, reusing the same write path a superadmin's UI action uses";

    public function handle(
        OpenDraftRevision $openDraftRevision,
        CreateDefaultQuestion $createDefaultQuestion,
        PublishRevision $publishRevision,
    ): int {
        $actor = $this->resolveActor();

        if ($actor === null) {
            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?: database_path('framework/default-questions.json'));

        if (! is_readable($path)) {
            $this->error("catalogue_seed_default_questions_source_unreadable: {$path}");

            return self::FAILURE;
        }

        /** @var array{questions?: array<string, list<array{position: int, text: array<string, string>}>>} $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $questionsByCode = $decoded['questions'] ?? [];

        $dryRun = (bool) $this->option('dry-run');

        /** @var array{created: list<string>, skippedIdempotent: list<string>, skippedConflict: list<string>, missing: list<string>}|null $result */
        $result = null;

        try {
            DB::transaction(function () use ($openDraftRevision, $createDefaultQuestion, $questionsByCode, $actor, $dryRun, &$result): void {
                $draft = $openDraftRevision->open();
                $result = $this->seedInto($draft, $questionsByCode, $createDefaultQuestion, $actor);

                if ($dryRun) {
                    throw new SeedDryRunRollback;
                }
            });
        } catch (SeedDryRunRollback) {
            // Deliberate — the transaction above already rolled back every
            // write this dry run made; `$result` was captured INSIDE that
            // transaction, so it still reflects what a real run would have
            // produced.
        }

        $this->reportResult($dryRun, $result);

        if (! $this->option('publish')) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('catalogue_seed_default_questions_publish_skipped_dry_run: --publish has no effect together with --dry-run.');

            return self::SUCCESS;
        }

        $missing = $result['missing'] ?? [];

        if ($missing !== []) {
            $this->error(sprintf(
                'catalogue_seed_default_questions_publish_refused_incomplete_seed: refusing to publish — the source file is missing these competencies, so their default questions were never seeded: %s',
                implode(', ', $missing),
            ));

            return self::FAILURE;
        }

        return $this->publishDraft($publishRevision, $actor);
    }

    /**
     * Resolves the actor recorded on every created question's audit row.
     *
     * `--actor-email` is the explicit, unambiguous path: it must name an
     * EXISTING platform superadmin (`organization_id IS NULL`,
     * `is_superadmin = true`) — this command creates no user of its own.
     * Without it, the fallback is only ever taken when it is genuinely
     * unambiguous: exactly ONE platform superadmin exists. Zero or several
     * refuse cleanly rather than guess which one a seed run should be
     * attributed to.
     */
    private function resolveActor(): ?User
    {
        $emailOption = $this->option('actor-email');

        if (is_string($emailOption) && trim($emailOption) !== '') {
            $email = trim($emailOption);
            $actor = User::where('email', $email)->where('organization_id', null)->where('is_superadmin', true)->first();

            if ($actor === null) {
                $this->error("catalogue_seed_default_questions_actor_not_found: no platform superadmin found with email [{$email}].");

                return null;
            }

            return $actor;
        }

        $superadmins = User::where('organization_id', null)->where('is_superadmin', true)->orderBy('id')->get();

        if ($superadmins->count() === 1) {
            return $superadmins->first();
        }

        if ($superadmins->isEmpty()) {
            $this->error('catalogue_seed_default_questions_no_actor: no platform superadmin exists yet. Create one with app:create-superadmin, then pass --actor-email=.');

            return null;
        }

        $this->error('catalogue_seed_default_questions_ambiguous_actor: multiple platform superadmins exist. Pass --actor-email= to choose one unambiguously.');

        return null;
    }

    /**
     * @param  array<string, list<array{position: int, text: array<string, string>}>>  $questionsByCode
     * @return array{created: list<string>, skippedIdempotent: list<string>, skippedConflict: list<string>, missing: list<string>}
     */
    private function seedInto(
        FrameworkCatalogRevision $draft,
        array $questionsByCode,
        CreateDefaultQuestion $createDefaultQuestion,
        User $actor,
    ): array {
        $created = [];
        $skippedIdempotent = [];
        $skippedConflict = [];
        $missing = [];

        foreach ($questionsByCode as $code => $questions) {
            $competency = Competency::where('revision_id', $draft->id)->where('code', $code)->first();

            if ($competency === null) {
                $missing[] = $code;

                continue;
            }

            foreach ($questions as $entry) {
                $position = (int) $entry['position'];
                $text = $entry['text'];

                $existing = FrameworkDefaultQuestion::where('revision_id', $draft->id)
                    ->where('competency_id', $competency->id)
                    ->where('position', $position)
                    ->first();

                if ($existing !== null) {
                    if ($this->textMatches($existing->getTranslations('text'), $text)) {
                        $skippedIdempotent[] = "{$code}:{$position}";
                    } else {
                        $skippedConflict[] = "{$code}:{$position}";
                    }

                    continue;
                }

                $createDefaultQuestion->create([
                    'revision_id' => $draft->id,
                    'competency_id' => $competency->id,
                    'text' => $text,
                    'position' => $position,
                ], $actor);

                $created[] = "{$code}:{$position}";
            }
        }

        return compact('created', 'skippedIdempotent', 'skippedConflict', 'missing');
    }

    /**
     * @param  array<string, string>  $a
     * @param  array<string, string>  $b
     */
    private function textMatches(array $a, array $b): bool
    {
        return ($a['en'] ?? null) === ($b['en'] ?? null) && ($a['it'] ?? null) === ($b['it'] ?? null);
    }

    /**
     * @param  array{created: list<string>, skippedIdempotent: list<string>, skippedConflict: list<string>, missing: list<string>}|null  $result
     */
    private function reportResult(bool $dryRun, ?array $result): void
    {
        $result ??= ['created' => [], 'skippedIdempotent' => [], 'skippedConflict' => [], 'missing' => []];

        $createdVerb = $dryRun ? 'Would create' : 'Created';

        foreach ($result['created'] as $key) {
            $this->line("  {$createdVerb}: {$key}");
        }

        foreach ($result['skippedIdempotent'] as $key) {
            $this->line("  Skipped (already seeded): {$key}");
        }

        foreach ($result['skippedConflict'] as $key) {
            $this->warn("  Skipped (an existing question at this position has different text — not overwritten): {$key}");
        }

        foreach ($result['missing'] as $code) {
            $this->warn("  Competency not found in the draft, skipped: {$code}");
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d default question(s); skipped %d already-seeded, %d with conflicting text, %d for a missing competency.',
            $createdVerb,
            count($result['created']),
            count($result['skippedIdempotent']),
            count($result['skippedConflict']),
            count($result['missing']),
        ));
    }

    private function publishDraft(PublishRevision $publishRevision, User $actor): int
    {
        $draft = FrameworkCatalogRevision::openDraft();

        if ($draft === null) {
            $this->error('catalogue_seed_default_questions_publish_no_draft: no open draft to publish.');

            return self::FAILURE;
        }

        $violations = $publishRevision->publish($draft, $actor);

        if ($violations !== []) {
            $this->warn('Publish refused — the sweep found violations:');

            foreach ($violations as $violation) {
                $this->line("  - {$violation['rule']} ({$violation['subject']}): {$violation['detail']}");
            }

            return self::FAILURE;
        }

        $this->info("Published revision {$draft->id}.");

        return self::SUCCESS;
    }
}
