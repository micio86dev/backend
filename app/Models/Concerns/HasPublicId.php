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
 * Runtime fact (step 5 review follow-up, item 14 — trimmed from a longer
 * review narrative): `Schema::hasColumn()` runs one `information_schema`
 * query per insert. It stays, unconditionally and uncached, because
 * migration-archaeology tests (`tests/Feature/Migration/
 * BaselineRevisionMigrationTest.php` and its siblings) `migrate:rollback`
 * to a schema that predates this trait's own migration, create fixture
 * rows against that OLD schema, then migrate forward again — at fixture-
 * creation time the `public_id` column genuinely does not exist yet, and
 * neither an explicit fixture value nor a cached "column exists" result
 * from an earlier test in the same process can substitute for a live
 * check: both were tried and both broke this exact test, the column
 * absent either way. A live, per-insert check is the only construct that
 * is never stale against a schema `migrate:rollback` can change mid-run.
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
