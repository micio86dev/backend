# BEAI overrides to the generated Laravel guidelines

Boost composes its guidelines from a generic full-stack Laravel template. Two of
its sections describe machinery this repository does not have, and one of them
contradicts a binding project rule. This file corrects them, and lives in
`.ai/guidelines/` precisely so a future `boost:install` merges it back in rather
than discarding it.

## This api has NO frontend bundler

Boost's "Frontend Bundling" and "Vite Error" sections tell you to run
`npm run build` / `npm run dev`. Ignore both. This repository has no
`package.json`, no `vite.config.*`, no `resources/js` and no `resources/css` —
only `resources/views`. It is **API-only** (no Blade UI); Scramble generates the
OpenAPI spec and the two Nuxt apps consume it.

A Vite manifest exception cannot occur here. If you ever see one, something is
genuinely wrong with the deployment, and running a bundler is not the fix.

## Bun, never npm

The two Nuxt apps — `frontend` and `backoffice` — live in **separate
repositories**, not in this one. There, **Bun is the sole package manager** for
install, dev and build: never `npm`, `pnpm`, `yarn`, `npx` or `pnpx` — use `bun`
/ `bunx`. Node runs only the SSR production runtime and the Vitest/Playwright
runners.

So Boost's `npm` advice is wrong twice over: wrong tool, and pointed at a
repository that has no frontend to build.

## This repo is one submodule of a superproject

The wrapper superproject holds `docs/`, the SDD store under `openspec/`, the
authoritative `CLAUDE.md` and `DESIGN.md`, and pins this repo at a release tag.
When the generated guidelines and the wrapper's `CLAUDE.md` disagree, **the
wrapper wins** — it is project-specific and binding, while these are generic.

Consequences worth remembering:

- `.mcp.json` and `.claude/skills/` here are scoped to THIS directory. An agent
  session opened at the wrapper root does not pick them up; it must be started
  in `api/`, or the Boost MCP server registered explicitly with a working
  directory pointing here.
- Version bumps follow the wrapper's Git Flow: `VERSION` **and** `composer.json`
  must agree, and `openapi.json` must be re-exported after a bump because
  `info.version` is generated from `VERSION`.

## Export the OpenAPI spec against Postgres, never SQLite

`php artisan scramble:export` against the default `.env` produces a spec that is
WRONG, not merely different: JSON columns introspect differently, nullability is
dropped and integer ids come back typed `string`. Three committed snapshots then
agree with each other and disagree with the API. Always:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=beai_test \
DB_USERNAME=postgres DB_PASSWORD=postgres DB_URL= php artisan scramble:export
```

## Match CI locally before pushing

The API CI runs, in order: `pint --test`, `phpstan analyse --memory-limit=1G`,
migrations, `test --parallel`, coverage `--min=85`, a fresh OpenAPI export
diffed against the committed one, and VERSION/composer/openapi agreement. Run
that same sequence locally — PHPStan especially, since it catches Larastan
inference issues (a nullable-FK relation accessor is inferred NON-null, so `?->`
on one is reported as `nullsafe.neverNull`) that no test will.
