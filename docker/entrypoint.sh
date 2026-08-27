#!/bin/sh
# Container entrypoint.
#
# THIS FILE INTENTIONALLY DOES NOTHING BUT EXEC THE START COMMAND
# ---------------------------------------------------------------
# It is the Dockerfile's `ENTRYPOINT` (see `Dockerfile`), which is what makes
# Railway's per-service start commands compose: `worker` and `scheduler` run
# this SAME image and pass their own command as arguments, which `exec "$@"`
# hands straight through with PID 1 semantics intact. Keeping the hook means a
# future boot-time step has a documented home; keeping it EMPTY is the point of
# the history below.
#
# WHAT USED TO LIVE HERE, AND WHY IT MOVED
# ----------------------------------------
# This file used to run `beai:sync-llm-registry`, which fills `llm_models` —
# catalogue data the migration creates an empty table for, and which production
# has no other path to populate (it never runs `db:seed`).
#
# It landed here because Railway's `preDeployCommand` is NOT shell-evaluated:
# `migrate --force && php artisan beai:sync-llm-registry` passed everything
# after `&&` to `migrate` as inert arguments, which ignored them and exited 0.
# The deploy went green with the sync never invoked.
#
# The real cost of that workaround only became visible later: the `&&` was
# removed from `preDeployCommand` but a bare `migrate --force` was never put
# back, so NOTHING migrated on deploy. Production's schema stayed current only
# because a human ran the migrations by hand over SSH.
#
# Both steps now live in ONE artisan command — `beai:deploy` — which is what
# `preDeployCommand` invokes. A single command has no `&&` to lose, migrations
# are fatal there (a failed migration aborts the deploy, which is the whole
# point), and the registry sync keeps the non-fatal semantics it had here.
#
# WHY THE SYNC IS NOT ALSO KEPT HERE AS A SAFETY NET
# --------------------------------------------------
# It would be harmless to run twice — it is an upsert-by-natural-key inside a
# transaction — but it would be worthless as a net and costly as documentation.
# Worthless: the only scenario it covers is `preDeployCommand` not running, and
# in that scenario the MIGRATIONS did not run either, so a stale model picker is
# not the failure anyone would notice. Costly: two places both claiming to own
# catalogue refresh is exactly the drift that hid the missing migrate step for
# as long as it hid. `beai:deploy` also runs it at a strictly better moment —
# after migrations, before the new revision serves traffic — whereas an
# entrypoint runs it on every replica boot and every restart, writing to a
# shared table for data that only changes when the committed catalogue does.
#
# MIGRATIONS ARE STILL DELIBERATELY NOT HERE
# ------------------------------------------
# `preDeployCommand` runs ONCE per deploy in its own container. An entrypoint
# runs once per REPLICA and on every restart, so migrations here would race
# between replicas. Unchanged, and the reason `beai:deploy` is invoked from the
# once-per-deploy slot rather than from this file.
#
# RECOVERY BY HAND
# ----------------
#   php artisan beai:deploy              # both steps, as a deploy runs them
#   php artisan beai:sync-llm-registry   # catalogue only; idempotent
set -eu

exec "$@"
