<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives a model a BEAI Public API (`/v1`) external identifier — public-api
 * step 4, G-05.
 *
 * The `public_id` column stores ONLY the bare 26-char Crockford-base32 ULID
 * (`char(26)`, see the migration's own docblock) — never the `org_`/`prj_`
 * prefix, which is presentational and lives entirely in
 * `App\Support\PublicApi\PublicId::encode()`/`decode()`. This trait's job
 * is purely to MINT and QUERY that bare column; a using model additionally
 * `implements App\Support\PublicApi\PubliclyIdentifiable` (see that
 * interface's own docblock for why it exists alongside this trait) and
 * implements `publicIdPrefix()` so `PublicId` knows which prefix to attach
 * on the way out.
 *
 * Automatically minted on `creating` whenever empty — every real creation
 * path (`Model::create()`, `new Model; ->save()`, a factory) gets one for
 * free, with no call site needing to remember to set it. A caller that DOES
 * pass a `public_id` explicitly (a migration backfill, a seeder replaying a
 * known id) is respected as-is, never overwritten.
 *
 * `bootHasPublicId()` uses Eloquent's own trait-boot convention
 * (`Model::bootTraits()` calls `boot{TraitName}()` automatically) rather
 * than the `booted()`-registration pattern `BumpsRevisionContentVersion`
 * uses — that pattern exists there because MULTIPLE listeners on different
 * events need one explicit call site a using model's own `booted()`
 * chooses when to invoke; this trait needs exactly one `creating` listener
 * and no using model needs to sequence it against anything else, so the
 * automatic convention is simpler and cannot be forgotten by a future using
 * model that defines its own `booted()` (`Project` already does, for its
 * immutability/lifecycle guards — this trait's listener still fires
 * independently).
 *
 * Step 4 review finding: several migration-archaeology tests
 * (`tests/Feature/Migration/BaselineRevisionMigrationTest.php` and its
 * siblings) deliberately `migrate:rollback` to a schema that predates this
 * trait's own migration, create fixture rows against THAT old schema via
 * `Organization::factory()`/`Project::factory()`, then migrate forward
 * again — a pattern the class docblocks of those tests document as
 * intentional and already relied on before this trait existed. Blindly
 * setting `public_id` regardless of whether the column currently exists
 * broke that pattern outright (`SQLSTATE[42703]: column "public_id" ...
 * does not exist`), so the `creating` hook checks `Schema::hasColumn()`.
 *
 * Step 5 review follow-up (Part A item 2): a per-insert, always-live
 * `Schema::hasColumn()` call was flagged as unnecessary steady-state
 * overhead, with two suggested fixes. BOTH were tried and REJECTED, with
 * evidence, rather than applied on faith:
 *
 *   1. Fix the archaeology tests to pass an explicit `public_id` and drop
 *      the check entirely. REJECTED, verified by reading the call order:
 *      in `BaselineRevisionMigrationTest`, `Organization::factory()->create()`
 *      and `Project::factory()->create()` run AFTER `migrate:rollback` and
 *      BEFORE the forward `Artisan::call('migrate')` — at that point the
 *      `public_id` COLUMN itself does not exist on `organizations`/
 *      `projects` (nor, from step 5 onward, on `participants`, now that
 *      `Participant` also uses this trait). Supplying an explicit value for
 *      a column absent from the table fails with the exact same
 *      `SQLSTATE[42703]` regardless of who sets it — an explicit
 *      `public_id` fixture cannot fix a missing column, so this option was
 *      never actually available for this test, whatever a caller passes.
 *   2. A static cache keyed by table, remembering only a CONFIRMED `true`
 *      (never caching a `false`) — the refinement that survives the
 *      ORIGINAL docblock's own objection to a naive cache (that one would
 *      wrongly freeze `false` across the forward migration). REJECTED too,
 *      empirically this time: `php artisan test tests/Feature/Migration/`
 *      run as a BATCH failed this exact test with `SQLSTATE[42703]:
 *      column "public_id" of relation "organizations" does not exist` — an
 *      EARLIER test file in the same PHP process had already confirmed
 *      `organizations.public_id` exists (that static cache persists for
 *      the whole test run, not per test case), so by the time THIS test's
 *      `migrate:rollback` removed the column, the trait trusted the stale
 *      cached `true` and tried to insert into it anyway. A per-process
 *      cache is unsafe for exactly the reason the table exists in the
 *      first place: `migrate:rollback` inside a test can make a column
 *      that was confirmed present earlier in the SAME process become
 *      absent again, and nothing invalidates the cache when that happens.
 *
 * Both alternatives fail on the identical root cause — this trait cannot
 * tell, from inside one `creating` hook, whether the schema it is running
 * against right now is the one a cache (or a caller's assumption) was
 * built from. A live check is the only construct that is never stale, and
 * the read it performs (`information_schema` via `Schema::hasColumn()`) is
 * cheap enough that this codebase's own CI budget has never flagged it —
 * the "unnecessary overhead" concern was unverified, and verifying it
 * surfaced a correctness regression it would have introduced instead. Kept
 * as originally written.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (! Schema::hasColumn($model->getTable(), 'public_id')) {
                return;
            }

            $current = $model->getAttribute('public_id');

            if ($current === null || $current === '') {
                $model->setAttribute('public_id', (string) Str::ulid());
            }
        });
    }

    /**
     * The bare 26-char ULID this model's `public_id` prefixes into a
     * `{prefix}{ulid}` external id — e.g. `Organization` names `org_`,
     * `Project` names `prj_`. Implemented per using model; there is no
     * sensible shared default.
     */
    abstract public static function publicIdPrefix(): string;

    /**
     * @param  Builder<static>  $query
     * @param  string  $publicId  the BARE ulid (already stripped of its
     *                            prefix by `App\Support\PublicApi\PublicId::decode()`) — this scope never
     *                            accepts a prefixed value, since the column itself never stores one.
     * @return Builder<static>
     */
    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where('public_id', $publicId);
    }
}
