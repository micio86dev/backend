#!/bin/sh
# Container entrypoint (pluggable-conversation-llm).
#
# WHY THIS EXISTS
# ---------------
# The LLM model registry (`llm_models`) is catalogue data, not schema: the
# migration creates an EMPTY table and `beai:sync-llm-registry` is what fills
# it. Production never runs `db:seed` — its bootstrap is a command, not a
# seeder — so without this step the registry stays empty, the backoffice model
# picker has nothing to show, and no template can be bound to a model.
#
# It lived in Railway's `preDeployCommand` first and did NOT run there: that
# field is not evaluated by a shell, so `migrate --force && sync-llm-registry`
# passed everything after `&&` as inert arguments to `migrate`, which ignored
# them and exited 0. The deploy went green with the seeder never invoked.
# Putting it here makes it version-controlled and shell-evaluated, which is
# both more honest and harder to lose.
#
# MIGRATIONS ARE DELIBERATELY NOT HERE
# ------------------------------------
# They stay in `preDeployCommand`, which runs ONCE per deploy in its own
# container. An entrypoint runs once per REPLICA and on every restart, so
# migrations here would race between replicas. The registry sync is safe in
# that position because it is upsert-by-natural-key inside a transaction and
# converges to the same rows no matter how many times it runs.
#
# FAILURE IS NON-FATAL, ON PURPOSE
# --------------------------------
# A transient database hiccup at boot must not stop the API from serving. The
# worst case here is a stale catalogue an operator can refresh by redeploying;
# the worst case of `exit 1` is the whole service down for a cosmetic table.
#
# ONLY THE WEB CONTAINER SEEDS
# ----------------------------
# `worker` and `scheduler` run this SAME image with a different start command.
# Without the guard below all three would seed on every deploy — three
# concurrent upsert transactions over the same handful of rows, for no gain.
# Keying on the start command rather than on a Railway-specific variable keeps
# the rule true under docker-compose too, where the same three services exist.
set -eu

if [ "${1:-}" = "supervisord" ]; then
    echo "[entrypoint] syncing the LLM model registry…"
    if php artisan beai:sync-llm-registry; then
        echo "[entrypoint] registry sync OK"
    else
        echo "[entrypoint] WARNING: registry sync failed — starting anyway." >&2
        echo "[entrypoint] The model picker may be empty until this succeeds." >&2
    fi
fi

exec "$@"
