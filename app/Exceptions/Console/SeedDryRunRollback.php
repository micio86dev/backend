<?php

declare(strict_types=1);

namespace App\Exceptions\Console;

use RuntimeException;

/**
 * Internal control-flow marker used by `CatalogueSeedDefaultQuestionsCommand`
 * (feat/seed-default-questions) — never escapes that command's own
 * `handle()`. Forces `DB::transaction()` to roll back a `--dry-run` pass
 * without committing, while still letting the closure compute and capture
 * the real post-write result before the rollback happens. Mirrors
 * `BackfillDryRunRollback` (Z22, framework-catalogue-authoring), which is
 * scoped to `BackfillProjectQuestionsCommand` by its own docblock and is
 * therefore not reused directly here — a distinct marker per command keeps
 * each rollback exception provably tied to the one closure it is thrown
 * from.
 */
final class SeedDryRunRollback extends RuntimeException {}
