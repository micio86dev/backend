<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PromptSource;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\ConversationPromptSet;
use App\Services\Conversation\PromptSetResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Database\Seeders\PlatformSuperadminSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * beai:deploy — the ONE command Railway's `preDeployCommand` invokes.
 *
 * WHY THIS EXISTS
 * ---------------
 * `preDeployCommand` is NOT evaluated by a shell. A previous
 * `php artisan migrate --force && php artisan beai:sync-llm-registry` handed
 * everything after `&&` to `migrate` as inert arguments; `migrate` ignored
 * them and exited 0, so the deploy went green with the second step never
 * invoked. The workaround at the time moved the sync into
 * `docker/entrypoint.sh` and left `migrate` in `preDeployCommand` — but the
 * field was never restored to a bare `migrate --force`, so NOTHING migrated
 * on deploy. Production's schema stayed current only because a human ran the
 * migrations by hand over SSH. A deploy carrying a new migration would have
 * gone green and then queried columns that do not exist.
 *
 * One artisan command has no `&&` to lose, no quoting to get wrong, and no
 * dependence on how the platform tokenises the field.
 *
 * THE STEPS HAVE DELIBERATELY DIFFERENT FAILURE SEMANTICS
 * --------------------------------------------------------
 * 1. `migrate --force` is FATAL. A non-zero exit here aborts the deploy, and
 *    that is the entire point: booting code against a schema it does not
 *    have is the failure this command exists to prevent.
 * 2. The active conversation prompt set check is FATAL, right after the
 *    migrations (db-driven-conversation-prompts, design N-9). With
 *    `conversation.prompt_source=db` every interview start composes from the
 *    ACTIVE stored set, and a missing, ambiguous, tampered or incomplete one
 *    answers every candidate 422 `composition_error`: that is a release which
 *    must not go live, not a stale picker. The check runs the very resolution
 *    `/start` runs, for every supported locale. With `baseline` it is skipped
 *    (the break-glass reads no table); an unknown source value is fatal too.
 * 3. `beai:sync-llm-registry` is NON-FATAL, preserving the semantics the
 *    entrypoint had. It refreshes `llm_models`, which is catalogue data, not
 *    schema; a transient database hiccup over it must not refuse a release.
 *    The worst case is a stale model picker an operator fixes by redeploying
 *    or by running the command by hand. Both a non-zero exit AND a thrown
 *    exception are absorbed — a connection error surfaces as the latter, so
 *    handling only the former would make the rule half-true.
 *
 * MIGRATIONS STILL DO NOT BELONG IN THE ENTRYPOINT
 * ------------------------------------------------
 * `preDeployCommand` runs ONCE per deploy in its own container. An entrypoint
 * runs once per REPLICA and on every restart, so migrations there would race
 * between replicas. That reasoning is unchanged; this command is the correct
 * home for both steps precisely because it runs in the once-per-deploy slot.
 *
 * EVERY STEP ANNOUNCES ITSELF
 * ---------------------------
 * The defect above survived for as long as it did because the deploy log was
 * silent: a step that never ran and a step that ran cleanly look identical
 * when neither prints anything. Every line is prefixed `[deploy]` so it can
 * be grepped out of Railway's build/deploy stream.
 */
class DeployCommand extends Command
{
    protected $signature = 'beai:deploy';

    protected $description = 'Run the release steps a deploy must perform: migrations (fatal), the active prompt set check (fatal), then the framework catalogue seed, the default framework version backfill and the LLM registry sync (all non-fatal).';

    public function handle(): int
    {
        $this->line('[deploy] running migrations…');

        if (! $this->migrate()) {
            $this->error('[deploy] FAILED: migrations did not complete. Aborting the deploy.');

            return self::FAILURE;
        }

        $this->info('[deploy] migrations OK');

        $this->line('[deploy] verifying the active conversation prompt set…');

        if (! $this->verifyActivePromptSet()) {
            $this->error('[deploy] FAILED: the active conversation prompt set is unusable. Aborting the deploy.');
            $this->error('[deploy] Activate an intact set with `php artisan beai:prompt-set:activate <label>`, or set CONVERSATION_PROMPT_SOURCE=baseline to deploy on the code baseline.');

            return self::FAILURE;
        }

        $this->line('[deploy] seeding the framework catalogue…');

        if ($this->seedFrameworkCatalog()) {
            $this->info('[deploy] framework catalogue OK');
        } else {
            $this->warn('[deploy] WARNING: framework catalogue seed failed — continuing anyway.');
            $this->warn('[deploy] Projects cannot be created against an empty or stale catalogue. Run `php artisan db:seed --class=FrameworkCatalogSeeder --force`.');
        }

        $this->line('[deploy] ensuring every organization has a framework version…');

        if ($this->ensureFrameworkVersions()) {
            $this->info('[deploy] framework versions OK');
        } else {
            $this->warn('[deploy] WARNING: framework version backfill failed — continuing anyway.');
            $this->warn('[deploy] Organizations without a version cannot create projects. Run `php artisan beai:ensure-framework-versions`.');
        }

        $this->line('[deploy] provisioning the platform superadmin…');

        if ($this->seedSuperadmin()) {
            $this->info('[deploy] superadmin OK');
        } else {
            $this->warn('[deploy] WARNING: superadmin provisioning failed — continuing anyway.');
            $this->warn('[deploy] Check SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD; the seeder refuses to invent a default password.');
        }

        $this->line('[deploy] syncing the LLM model registry…');

        if ($this->syncRegistry()) {
            $this->info('[deploy] registry sync OK');
        } else {
            // Non-fatal by design — see the class docblock.
            $this->warn('[deploy] WARNING: registry sync failed — continuing anyway.');
            $this->warn('[deploy] The model picker may be empty until `php artisan beai:sync-llm-registry` succeeds.');
        }

        $this->info('[deploy] done');

        return self::SUCCESS;
    }

    /**
     * `--force` because a deploy container has no TTY and the confirmation
     * prompt would otherwise abort in production.
     */
    private function migrate(): bool
    {
        try {
            return $this->call('migrate', ['--force' => true]) === self::SUCCESS;
        } catch (Throwable $e) {
            // A migration fault usually surfaces as a QueryException rather
            // than a non-zero return, so the fatal rule must cover both.
            $this->error('[deploy] '.$e::class.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * The same resolution `/start` performs, run once per supported locale:
     * exactly one active set, its seal, a complete key set and the placeholder
     * contract for each locale, plus the contract of every override body.
     *
     * FATAL, unlike the data steps below: see the class docblock. Any failure,
     * a refusal or a database error, is reported with its message (sets, keys
     * and locales only, never a body) and aborts the deploy.
     */
    private function verifyActivePromptSet(): bool
    {
        try {
            if (PromptSource::configured() === PromptSource::Baseline) {
                $this->warn('[deploy] prompt source is baseline: the active prompt set check is skipped.');

                return true;
            }

            // The deploy re-verifies against the database, never against a set cached by this process.
            PromptSetResolver::flushCache();
            $resolver = app(PromptSetResolver::class);

            foreach ((array) config('app.supported_locales') as $locale) {
                $resolver->resolveActive((string) $locale, 'DEPLOY-CHECK', null);
            }

            $set = ConversationPromptSet::query()->where('is_active', true)->sole();
            $resolver->verify($set);
            $this->info("[deploy] prompt set OK ({$set->label})");

            return true;
        } catch (PromptTemplateUnresolvableException $e) {
            $this->error('[deploy] '.$e->getMessage());

            return false;
        } catch (Throwable $e) {
            $this->error('[deploy] '.$e::class.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * Fill `framework_roles` / `framework_competencies` / the BARS anchors.
     *
     * WHY A DEPLOY MUST RUN THIS. Nothing else ever did.
     * `docker/entrypoint.sh` says in as many words that production "never runs
     * `db:seed`", and `DatabaseSeeder` — which calls this seeder — was
     * therefore only ever executed by a human, by hand, once. Two failures
     * follow from that, and both were observed:
     *
     *   1. A database that has never been seeded has no framework version at
     *      all, and `projects.framework_version_id` is NOT NULL. No project of
     *      any type can be created.
     *   2. A database seeded BEFORE a competency was added to the catalogue
     *      never receives it. MTG and LAT arrived that way, so every
     *      `potential` project came back `POTENTIAL_CATALOG_INCOMPLETE` on a
     *      deployment whose catalogue file on disk contained them.
     *
     * The seeder is idempotent by construction (`updateOrCreate` by code, and
     * a lock mode that refuses to overwrite authored `en` content), which is
     * what makes running it on EVERY deploy safe rather than merely tolerable.
     *
     * NON-FATAL, same rule as the registry sync below and for the same reason:
     * this is catalogue DATA, not schema. A transient fault must not refuse a
     * release — the revision already serving keeps the catalogue it has. The
     * warning names the recovery command, because a silent skip is what let
     * the missing step hide for as long as it did.
     *
     * `--force` because a deploy container has no TTY and `db:seed` prompts
     * for confirmation in production otherwise.
     */
    private function seedFrameworkCatalog(): bool
    {
        try {
            return $this->call('db:seed', [
                '--class' => FrameworkCatalogSeeder::class,
                '--force' => true,
            ]) === self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('[deploy] '.$e::class.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * Give every organization that has no FrameworkVersion a default one.
     *
     * MUST run after `seedFrameworkCatalog()`: the version is pinned to the
     * latest PUBLISHED catalogue revision, which a fresh database only has
     * once the seed has run. Idempotent, so safe on every deploy.
     *
     * NON-FATAL, same rule as the other data steps: it fixes data, not schema,
     * and the warning names the recovery command.
     */
    private function ensureFrameworkVersions(): bool
    {
        try {
            return $this->call('beai:ensure-framework-versions') === self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('[deploy] '.$e::class.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * Create the platform superadmin from configuration, if one is configured.
     *
     * `PlatformSuperadminSeeder` was written for exactly this slot — its own
     * docblock says an interactive `app:create-superadmin` "is fine on a laptop
     * and impossible in a Railway deploy hook" — and then nothing ever called
     * it. Setting SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD on the deployment
     * did nothing at all.
     *
     * Two properties of that seeder are what make running it on EVERY deploy
     * correct rather than reckless: with no SUPERADMIN_EMAIL it returns
     * immediately (it is opt-in), and when the account already exists it leaves
     * the PASSWORD alone — a redeploy cannot silently undo a rotation.
     *
     * NON-FATAL. The seeder throws when an email is set with no password, which
     * is a misconfiguration to shout about, not one to refuse a release over:
     * every other surface serves fine while it is unfixed.
     */
    private function seedSuperadmin(): bool
    {
        try {
            return $this->call('db:seed', [
                '--class' => PlatformSuperadminSeeder::class,
                '--force' => true,
            ]) === self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('[deploy] '.$e::class.': '.$e->getMessage());

            return false;
        }
    }

    private function syncRegistry(): bool
    {
        try {
            return $this->call('beai:sync-llm-registry') === self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('[deploy] '.$e::class.': '.$e->getMessage());

            return false;
        }
    }
}
