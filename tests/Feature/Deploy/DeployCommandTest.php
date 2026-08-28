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

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

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

function registerDeployStub(Command $stub): void
{
    app(Kernel::class)->registerCommand($stub);
}

beforeEach(function (): void {
    RecordingSyncStub::$ran = false;
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
