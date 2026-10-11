<?php

declare(strict_types=1);

/**
 * `beai:deploy` — the single command Railway's `preDeployCommand` invokes.
 *
 * WHY A COMMAND AND NOT A SHELL LINE
 * ----------------------------------
 * `preDeployCommand` is NOT shell-evaluated. A previous
 * `php artisan migrate --force && php artisan beai:sync-llm-registry` handed
 * everything after `&&` to `migrate` as inert arguments; `migrate` ignored
 * them and exited 0, so the deploy went green with the second step never
 * invoked (`docker/entrypoint.sh`'s own docblock records this). One artisan
 * command has no `&&` to lose.
 *
 * THE TWO STEPS HAVE DELIBERATELY DIFFERENT FAILURE SEMANTICS
 * -----------------------------------------------------------
 * A failed migration MUST abort the deploy — booting code against a schema
 * it does not have is the failure this command exists to prevent. A failed
 * registry sync must NOT: it is catalogue data, and a transient DB hiccup
 * over `llm_models` is not worth refusing a release for. Those two rules are
 * the whole contract, so they are asserted directly.
 */

use App\Actions\Conversation\ActivatePromptSet;
use App\Actions\Conversation\PublishPromptSet;
use App\Models\ConversationPromptSet;
use Database\Seeders\FrameworkCatalogSeeder;
use Database\Seeders\PlatformSuperadminSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Conversation\PromptSetPayload;
use Tests\Helpers\Conversation\PromptTables;

/**
 * A stand-in for `migrate` that fails, registered over the real one.
 *
 * Symfony's `Application::add()` replaces by name, so `$this->call('migrate')`
 * inside `beai:deploy` resolves THIS instance. Faking the step is the only
 * way to assert the abort contract without a destructive real migration.
 */
final class FailingMigrateStub extends Command
{
    protected $signature = 'migrate {--force} {--database=} {--path=*} {--realpath} {--pretend} {--seed} {--step} {--isolated=}';

    protected $description = 'Stub that always fails.';

    public function handle(): int
    {
        $this->error('SQLSTATE[42P07]: Duplicate table');

        return self::FAILURE;
    }
}

/** Records whether `--force` reached `migrate`; a deploy container has no TTY. */
final class ForceSpyMigrateStub extends Command
{
    public static bool $forced = false;

    protected $signature = 'migrate {--force} {--database=} {--path=*} {--realpath} {--pretend} {--seed} {--step} {--isolated=}';

    protected $description = 'Stub that records its --force flag.';

    public function handle(): int
    {
        self::$forced = (bool) $this->option('force');

        return self::SUCCESS;
    }
}

final class FailingSyncStub extends Command
{
    protected $signature = 'beai:sync-llm-registry';

    protected $description = 'Stub that always fails.';

    public function handle(): int
    {
        return self::FAILURE;
    }
}

final class ThrowingSyncStub extends Command
{
    protected $signature = 'beai:sync-llm-registry';

    protected $description = 'Stub that always throws.';

    public function handle(): int
    {
        throw new RuntimeException('SQLSTATE[08006]: connection refused');
    }
}

/**
 * Records that it ran, so "the sync is never reached after a failed
 * migration" is asserted on behaviour, not on the absence of a log line.
 */
final class RecordingSyncStub extends Command
{
    public static bool $ran = false;

    protected $signature = 'beai:sync-llm-registry';

    protected $description = 'Stub that records invocation.';

    public function handle(): int
    {
        self::$ran = true;

        return self::SUCCESS;
    }
}

/**
 * Records that the framework catalogue seed ran.
 *
 * `db:seed --class=FrameworkCatalogSeeder` is the step that fills
 * `framework_competencies`, `framework_roles` and the BARS anchors. Nothing in
 * any deploy path ran it, and the consequences were not theoretical: a
 * production database seeded before MTG/LAT were added to the catalogue stayed
 * without them forever, so every attempt to create a `potential` project came
 * back `POTENTIAL_CATALOG_INCOMPLETE`. A database that has never been seeded at
 * all is worse — `projects.framework_version_id` is NOT NULL, so no project of
 * any type can be created.
 */
final class RecordingSeedStub extends Command
{
    public static bool $ran = false;

    /** @var list<string> Every --class value this stub was called with, in order. */
    public static array $classes = [];

    public static string $class = '';

    protected $signature = 'db:seed {--class=} {--force} {--database=}';

    protected $description = 'Stub that records invocation.';

    public function handle(): int
    {
        self::$ran = true;
        self::$class = (string) $this->option('class');
        self::$classes[] = self::$class;

        return self::SUCCESS;
    }
}

final class FailingSeedStub extends Command
{
    protected $signature = 'db:seed {--class=} {--force} {--database=}';

    protected $description = 'Stub that always fails.';

    public function handle(): int
    {
        return self::FAILURE;
    }
}

final class ThrowingSeedStub extends Command
{
    protected $signature = 'db:seed {--class=} {--force} {--database=}';

    protected $description = 'Stub that always throws.';

    public function handle(): int
    {
        throw new RuntimeException('SQLSTATE[08006]: connection refused');
    }
}

function registerDeployStub(Command $stub): void
{
    app(Kernel::class)->registerCommand($stub);
}

beforeEach(function (): void {
    RecordingSyncStub::$ran = false;
    RecordingSeedStub::$ran = false;
    RecordingSeedStub::$class = '';
    RecordingSeedStub::$classes = [];
    ForceSpyMigrateStub::$forced = false;
});

test('the happy path migrates, syncs the registry, and exits 0', function (): void {
    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] running migrations')
        ->expectsOutputToContain('[deploy] migrations OK')
        ->expectsOutputToContain('[deploy] syncing the LLM model registry')
        ->expectsOutputToContain('[deploy] registry sync OK')
        ->expectsOutputToContain('[deploy] done')
        ->assertExitCode(Command::SUCCESS);
});

test('the migration step runs with --force so it never waits for a TTY that a deploy container does not have', function (): void {
    registerDeployStub(new ForceSpyMigrateStub);

    $this->artisan('beai:deploy')->assertExitCode(Command::SUCCESS);

    expect(ForceSpyMigrateStub::$forced)->toBeTrue();
});

test('a failed migration aborts the deploy with a non-zero exit code', function (): void {
    registerDeployStub(new FailingMigrateStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] FAILED: migrations did not complete')
        ->assertFailed();
});

test('a failed migration stops before the registry sync — the deploy aborts, it does not limp on', function (): void {
    registerDeployStub(new FailingMigrateStub);
    registerDeployStub(new RecordingSyncStub);

    $this->artisan('beai:deploy')->assertFailed();

    expect(RecordingSyncStub::$ran)->toBeFalse();
});

test('a failed registry sync warns but still exits 0 — catalogue data must not refuse a release', function (): void {
    registerDeployStub(new FailingSyncStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: registry sync failed')
        ->expectsOutputToContain('[deploy] done')
        ->assertExitCode(Command::SUCCESS);
});

test('a registry sync that throws is caught and still exits 0', function (): void {
    // A DB connection error surfaces as an exception, not as a non-zero
    // return — the non-fatal rule must hold for BOTH shapes or it only
    // half-holds.
    registerDeployStub(new ThrowingSyncStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: registry sync failed')
        ->assertExitCode(Command::SUCCESS);
});

test('a registry sync failure never leaks the exception message into the deploy log verbatim as success', function (): void {
    registerDeployStub(new ThrowingSyncStub);

    $this->artisan('beai:deploy')
        ->doesntExpectOutputToContain('[deploy] registry sync OK')
        ->assertExitCode(Command::SUCCESS);
});

final class ThrowingMigrateStub extends Command
{
    protected $signature = 'migrate {--force} {--database=} {--path=*} {--realpath} {--pretend} {--seed} {--step} {--isolated=}';

    protected $description = 'Stub that always throws.';

    public function handle(): int
    {
        throw new RuntimeException('SQLSTATE[42703]: Undefined column');
    }
}

test('a migration that THROWS also aborts the deploy — a QueryException is the usual shape', function (): void {
    // `migrate` reports most real faults by letting a QueryException escape,
    // not by returning a non-zero code, so the fatal rule must cover both or
    // it only half-holds — and the half it missed is the common one.
    registerDeployStub(new ThrowingMigrateStub);
    registerDeployStub(new RecordingSyncStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] FAILED: migrations did not complete')
        ->assertFailed();

    expect(RecordingSyncStub::$ran)->toBeFalse();
});

// ─── Framework catalogue seed ────────────────────────────────────────────────

test('the deploy seeds the framework catalogue', function (): void {
    // The step that was missing. Without it a deploy migrates an EMPTY
    // catalogue into place and every project creation fails on a NOT NULL
    // `framework_version_id` — and an existing deployment never receives a
    // competency added to the catalogue after it was first seeded, which is
    // exactly how MTG/LAT went missing and broke `potential` projects.
    registerDeployStub(new RecordingSeedStub);

    $this->artisan('beai:deploy')->assertExitCode(Command::SUCCESS);

    expect(RecordingSeedStub::$ran)->toBeTrue();
    expect(RecordingSeedStub::$classes)->toContain(FrameworkCatalogSeeder::class);
});

test('the catalogue seed runs AFTER migrations, never before', function (): void {
    // It writes to tables the migrations create. Running it first is not a
    // slower deploy, it is a failed one.
    registerDeployStub(new FailingMigrateStub);
    registerDeployStub(new RecordingSeedStub);

    $this->artisan('beai:deploy')->assertFailed();

    expect(RecordingSeedStub::$ran)->toBeFalse();
});

test('a failed catalogue seed warns but still exits 0', function (): void {
    // Same rule as the registry sync, for the same reason: this is catalogue
    // DATA, not schema. A transient fault over it must not refuse a release —
    // the previous revision keeps serving with the catalogue it already has.
    registerDeployStub(new FailingSeedStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: framework catalogue seed failed')
        ->expectsOutputToContain('[deploy] done')
        ->assertExitCode(Command::SUCCESS);
});

test('a catalogue seed that throws is caught and still exits 0', function (): void {
    registerDeployStub(new ThrowingSeedStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: framework catalogue seed failed')
        ->assertExitCode(Command::SUCCESS);
});

// ─── Platform superadmin ─────────────────────────────────────────────────────

test('the deploy provisions the platform superadmin', function (): void {
    // `PlatformSuperadminSeeder` existed, was tested, and was called by
    // NOTHING: not by `DatabaseSeeder` (it is opt-in), not by the entrypoint,
    // not by `preDeployCommand`. Setting SUPERADMIN_EMAIL/PASSWORD on the
    // deployment therefore did exactly nothing, and the only superadmin
    // anywhere was one created by hand on a laptop.
    //
    // Safe to run every time BECAUSE of the seeder's own two rules: it skips
    // entirely when SUPERADMIN_EMAIL is unset, and it never touches the
    // password of an account that already exists — so a redeploy cannot undo a
    // rotation.
    registerDeployStub(new RecordingSeedStub);

    $this->artisan('beai:deploy')->assertExitCode(Command::SUCCESS);

    expect(RecordingSeedStub::$classes)->toContain(PlatformSuperadminSeeder::class);
});

test('a failed superadmin provisioning warns but still exits 0', function (): void {
    // The seeder THROWS when an email is configured with no password, which is
    // a misconfiguration worth shouting about and not worth refusing a release
    // for: the rest of the platform serves fine without a superadmin.
    registerDeployStub(new ThrowingSeedStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: superadmin provisioning failed')
        ->assertExitCode(Command::SUCCESS);
});

// ─── Default framework versions ──────────────────────────────────────────────

final class RecordingEnsureVersionsStub extends Command
{
    /** @var list<string> Shared step log, appended to by the seed stub too. */
    public static array $order = [];

    protected $signature = 'beai:ensure-framework-versions {--org=} {--dry-run}';

    protected $description = 'Stub that records invocation.';

    public function handle(): int
    {
        self::$order[] = 'ensure-versions';

        return self::SUCCESS;
    }
}

final class OrderedSeedStub extends Command
{
    protected $signature = 'db:seed {--class=} {--force} {--database=}';

    protected $description = 'Stub that records the order of seeds.';

    public function handle(): int
    {
        if ($this->option('class') === FrameworkCatalogSeeder::class) {
            RecordingEnsureVersionsStub::$order[] = 'catalogue-seed';
        }

        return self::SUCCESS;
    }
}

final class FailingEnsureVersionsStub extends Command
{
    protected $signature = 'beai:ensure-framework-versions {--org=} {--dry-run}';

    protected $description = 'Stub that always fails.';

    public function handle(): int
    {
        return self::FAILURE;
    }
}

final class ThrowingEnsureVersionsStub extends Command
{
    protected $signature = 'beai:ensure-framework-versions {--org=} {--dry-run}';

    protected $description = 'Stub that always throws.';

    public function handle(): int
    {
        throw new RuntimeException('SQLSTATE[08006]: connection refused');
    }
}

test('the deploy backfills default framework versions AFTER the catalogue seed', function (): void {
    // The backfill pins to the latest PUBLISHED revision, which only exists
    // once the catalogue seed has run: the reverse order skips every
    // organization on a fresh database.
    RecordingEnsureVersionsStub::$order = [];
    registerDeployStub(new OrderedSeedStub);
    registerDeployStub(new RecordingEnsureVersionsStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] framework versions OK')
        ->assertExitCode(Command::SUCCESS);

    expect(RecordingEnsureVersionsStub::$order)->toBe(['catalogue-seed', 'ensure-versions']);
});

test('a failed framework-version backfill warns but still exits 0', function (): void {
    registerDeployStub(new FailingEnsureVersionsStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: framework version backfill failed')
        ->expectsOutputToContain('[deploy] done')
        ->assertExitCode(Command::SUCCESS);
});

test('a framework-version backfill that throws is caught and still exits 0', function (): void {
    registerDeployStub(new ThrowingEnsureVersionsStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] WARNING: framework version backfill failed')
        ->assertExitCode(Command::SUCCESS);
});

test('a failed migration never reaches the framework-version backfill', function (): void {
    RecordingEnsureVersionsStub::$order = [];
    registerDeployStub(new FailingMigrateStub);
    registerDeployStub(new RecordingEnsureVersionsStub);

    $this->artisan('beai:deploy')->assertFailed();

    expect(RecordingEnsureVersionsStub::$order)->toBe([]);
});

// ─── Active conversation prompt set (db-driven-conversation-prompts PR8) ────

test('the happy path verifies the active prompt set for every supported locale', function (): void {
    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] verifying the active conversation prompt set')
        ->expectsOutputToContain('[deploy] prompt set OK')
        ->assertExitCode(Command::SUCCESS);
});

test('a deploy with no active prompt set is refused, and nothing after the check runs', function (): void {
    // Every /start would answer 422 composition_error: booting that code is the failure this gate prevents.
    PromptTables::empty();
    registerDeployStub(new RecordingSyncStub);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] FAILED: the active conversation prompt set is unusable')
        ->expectsOutputToContain('No conversation prompt set is active')
        ->assertFailed();

    expect(RecordingSyncStub::$ran)->toBeFalse();
});

test('a deploy with two active prompt sets is refused', function (): void {
    DB::statement('DROP INDEX conversation_prompt_sets_one_active');
    app(PublishPromptSet::class)->handle('deploy-second', null, PromptSetPayload::fragments());
    ConversationPromptSet::query()->where('label', 'deploy-second')->update(['is_active' => true]);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('More than one conversation prompt set is active')
        ->assertFailed();
});

test('a deploy whose active prompt set no longer matches its seal is refused', function (): void {
    DB::statement('ALTER TABLE conversation_prompt_fragments DISABLE TRIGGER USER');
    DB::table('conversation_prompt_fragments')->where('fragment_key', 'budget')->update(['body' => 'TAMPERED {{budget}}']);
    DB::statement('ALTER TABLE conversation_prompt_fragments ENABLE TRIGGER USER');

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('does not match its content seal')
        ->assertFailed();
});

test('a deploy whose active prompt set lacks a supported locale is refused', function (): void {
    app(PublishPromptSet::class)->handle('deploy-en-only', null, PromptSetPayload::fragments(['en']));
    app(ActivatePromptSet::class)->handle('deploy-en-only');

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('no fragments for locale [it]')
        ->assertFailed();
});

test('a deploy whose active prompt set holds a broken override body is refused, naming the override', function (): void {
    // The per-locale resolve loop uses a synthetic competency and no role, so it can never meet this
    // override: the guarantee rests on verify($set). The seal is recomputed over the real rows, so ONLY
    // the override contract is left to fail.
    app(PublishPromptSet::class)->handle('deploy-override', null, PromptSetPayload::fragments(), [
        PromptSetPayload::override('DEPLOY_ROLE', 'DEPLOY_COMP', 'en', 'A valid override body.'),
    ]);
    app(ActivatePromptSet::class)->handle('deploy-override');

    $set = ConversationPromptSet::query()->where('label', 'deploy-override')->sole();

    PromptTables::breakOverrideBody($set, 'Broken {{budget}} override.');

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('override for competency [DEPLOY_COMP] is invalid')
        ->doesntExpectOutputToContain('does not match its content seal')
        ->assertFailed();
});

test('the baseline source skips the active prompt set check, even with no set at all', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    PromptTables::empty();

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] prompt source is baseline')
        ->doesntExpectOutputToContain('[deploy] FAILED')
        ->assertExitCode(Command::SUCCESS);
});

test('an unknown prompt source refuses the deploy instead of choosing a source', function (): void {
    config(['conversation.prompt_source' => 'bogus']);

    $this->artisan('beai:deploy')
        ->expectsOutputToContain('[deploy] FAILED: the active conversation prompt set is unusable')
        ->expectsOutputToContain('[bogus]')
        ->assertFailed();
});

test('a failed migration never reaches the prompt set check', function (): void {
    registerDeployStub(new FailingMigrateStub);

    $this->artisan('beai:deploy')
        ->doesntExpectOutputToContain('verifying the active conversation prompt set')
        ->assertFailed();
});
