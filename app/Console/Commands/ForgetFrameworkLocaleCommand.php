<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan framework:forget-locale it [--dry-run] [--force]`
 * (framework-catalog-it-translations design D5).
 *
 * The ONLY rollback mechanism for a locale added to the catalogue.
 * `setTranslation('field', $locale, $value)` MERGES into the existing JSON
 * translation column — proved by
 * `tests/Feature/Seeders/TranslationSurvivalReseedTest.php` — so deleting a
 * locale from the SOURCE JSON and re-seeding does NOT remove it from the
 * database; it leaves the locale in place forever. A rollback nobody can
 * execute is not a rollback, so this command exists as the targeted,
 * explicit strip the spec delta ("Locale Merge Semantics — Adding Is
 * Idempotent-Safe, Removing Is Not") requires.
 *
 * `forgetTranslation($field, $locale)` (spatie/laravel-translatable) is
 * called over every translatable field of `BarsIndicator`, `Competency` and
 * `Role`, inside ONE transaction — either the whole strip commits or none of
 * it does, matching the seeder's own atomicity discipline.
 *
 * **Refuses to run while any FrameworkVersion is locked.** Removing a
 * translation from a row a locked FrameworkVersion's pinned scoring may
 * depend on IS destructive: an `it`-language project mid-interview against a
 * locked version would go from interviewable to hard-failing on its very
 * next indicator — the reverse of the seeder's fill-empty-locale exception,
 * and just as capable of moving what a candidate experiences mid-assessment.
 * The lock exists to prevent exactly that class of change; this command
 * respects it symmetrically with the seeder rather than offering a
 * back door.
 *
 * `en` may never be forgotten by this command — it is the mandatory locale
 * every translatable field requires (CompetencyNormalizer's own contract),
 * and forgetting it would leave rows with no fallback content at all.
 *
 * `--dry-run` reports the per-model counts THAT WOULD BE affected without
 * writing anything. `--force` is required outside the `local` environment —
 * same doctrine as `DemoSeedCommand`'s `--force-production` — because this
 * is a destructive, hard-to-reverse operation against shared, global
 * (non-tenant-scoped) catalogue rows.
 */
final class ForgetFrameworkLocaleCommand extends Command
{
    protected $signature = 'framework:forget-locale
        {locale : The locale to remove (e.g. it) — never "en"}
        {--dry-run : Report what would be removed without writing anything}
        {--force : Required outside the local environment}';

    protected $description = 'Remove a locale\'s translations from the framework catalogue (the only rollback for setTranslation\'s merge semantics)';

    public function handle(): int
    {
        $locale = (string) $this->argument('locale');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($locale === '') {
            $this->error('A locale argument is required.');

            return self::FAILURE;
        }

        if ($locale === 'en') {
            $this->error('Refusing to forget the "en" locale — it is mandatory on every translatable field and has no fallback of its own.');

            return self::FAILURE;
        }

        if ($this->hasLockedVersions()) {
            $this->error(
                'Refusing to run: at least one FrameworkVersion is locked. Removing a translation from a '
                ."row a locked version's pinned scoring may depend on is destructive — see this command's own "
                .'docblock. Wait until no FrameworkVersion is locked, or resolve the lock first.'
            );

            return self::FAILURE;
        }

        if (! $dryRun && ! $force && ! app()->environment('local')) {
            $this->error('This is a destructive operation outside the local environment. Pass --force to proceed, or --dry-run to preview.');

            return self::FAILURE;
        }

        $counts = [
            'roles' => 0,
            'competencies' => 0,
            'bars_indicators' => 0,
        ];

        if ($dryRun) {
            $counts['roles'] = Role::query()->get()->filter(fn (Role $role): bool => $this->roleHasLocale($role, $locale))->count();
            $counts['competencies'] = Competency::query()->get()->filter(fn (Competency $c): bool => $this->competencyHasLocale($c, $locale))->count();
            $counts['bars_indicators'] = BarsIndicator::query()->get()->filter(fn (BarsIndicator $i): bool => $this->indicatorHasLocale($i, $locale))->count();

            $this->info("DRY RUN — would remove locale [{$locale}] from:");
            $this->line("  roles: {$counts['roles']}");
            $this->line("  competencies: {$counts['competencies']}");
            $this->line("  bars_indicators: {$counts['bars_indicators']}");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($locale, &$counts): void {
            foreach (Role::query()->get() as $role) {
                if (! $this->roleHasLocale($role, $locale)) {
                    continue;
                }

                $role->forgetTranslation('name', $locale);
                $role->forgetTranslation('responsibilities', $locale);
                $role->save();
                $counts['roles']++;
            }

            foreach (Competency::query()->get() as $competency) {
                if (! $this->competencyHasLocale($competency, $locale)) {
                    continue;
                }

                $competency->forgetTranslation('name', $locale);
                $competency->forgetTranslation('definition', $locale);
                $competency->save();
                $counts['competencies']++;
            }

            foreach (BarsIndicator::query()->get() as $indicator) {
                if (! $this->indicatorHasLocale($indicator, $locale)) {
                    continue;
                }

                $indicator->forgetTranslation('text', $locale);
                $indicator->forgetTranslation('anchor_5', $locale);
                $indicator->forgetTranslation('anchor_3', $locale);
                $indicator->forgetTranslation('anchor_1', $locale);
                $indicator->save();
                $counts['bars_indicators']++;
            }

            // Removing a locale changes what BarsIndicatorResource emits
            // (translation_gap flips back to true) — the same "response body
            // changed, cache key must move" reasoning the seeder's widened
            // bump() predicate applies to an addition applies symmetrically
            // to a removal.
            if ($counts['roles'] > 0 || $counts['competencies'] > 0 || $counts['bars_indicators'] > 0) {
                CatalogMeta::bump();
            }
        });

        $this->info("Removed locale [{$locale}] from:");
        $this->line("  roles: {$counts['roles']}");
        $this->line("  competencies: {$counts['competencies']}");
        $this->line("  bars_indicators: {$counts['bars_indicators']}");

        return self::SUCCESS;
    }

    private function hasLockedVersions(): bool
    {
        return FrameworkVersion::withoutGlobalScopes()->where('is_locked', true)->exists();
    }

    private function roleHasLocale(Role $role, string $locale): bool
    {
        return $role->hasTranslation('name', $locale) || $role->hasTranslation('responsibilities', $locale);
    }

    private function competencyHasLocale(Competency $competency, string $locale): bool
    {
        return $competency->hasTranslation('name', $locale) || $competency->hasTranslation('definition', $locale);
    }

    private function indicatorHasLocale(BarsIndicator $indicator, string $locale): bool
    {
        return $indicator->hasTranslation('text', $locale)
            || $indicator->hasTranslation('anchor_5', $locale)
            || $indicator->hasTranslation('anchor_3', $locale)
            || $indicator->hasTranslation('anchor_1', $locale);
    }
}
