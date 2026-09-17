<?php

declare(strict_types=1);

namespace App\Exceptions\Console;

use RuntimeException;

/**
 * Internal control-flow marker used by `BackfillProjectQuestionsCommand`
 * (Z22, framework-catalogue-authoring) — never escapes that command's own
 * `backfillProject()`. Forces `DB::transaction()` to roll back a `--dry-run`
 * pass without committing, while still letting the closure compute and
 * capture the real post-write state before the rollback happens.
 */
final class BackfillDryRunRollback extends RuntimeException {}
