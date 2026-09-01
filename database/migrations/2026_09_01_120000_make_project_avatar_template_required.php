<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `projects.avatar_template_id` becomes REQUIRED.
 *
 * It shipped nullable, with the organization's active template as the fallback,
 * so no existing project had to change. The product decision is now that a
 * project must always name the template it runs on: leaving it implicit is what
 * let the `INTERVIEW_PROVIDER` default silently choose, which is the defect the
 * column was added to fix in the first place.
 *
 * THREE THINGS HAVE TO HAPPEN IN THIS ORDER, and the order is not cosmetic.
 *
 * 1. BACKFILL. `ALTER COLUMN SET NOT NULL` on a table holding NULLs fails
 *    outright, so every existing project is first given the same template the
 *    fallback would have resolved for it — its organization's active one for
 *    its provider, then the org's active one on any provider, then its oldest
 *    template. Deliberately not "the newest": reproducing what each project was
 *    ALREADY running matters more than being tidy, and the active template is
 *    exactly what `ActiveTemplateResolver` was returning for it yesterday.
 *
 * 2. NOT NULL.
 *
 * 3. The foreign key changes from `nullOnDelete` to `restrictOnDelete`,
 *    because those two are mutually exclusive: a NOT NULL column cannot be
 *    nulled when its parent row goes. This is a REAL behaviour change and not a
 *    technicality — deleting a template that a project uses is now refused
 *    rather than quietly returning that project to a fallback that no longer
 *    exists. `AvatarTemplateController::destroy()` already refuses to delete
 *    the ACTIVE template for a comparable reason; this extends the same
 *    doctrine to a template a project depends on.
 *
 * A project whose organization owns NO template cannot be backfilled, and
 * therefore cannot exist. `down()` is exact: it restores nullability and
 * `nullOnDelete`, but it CANNOT restore which rows were null before, because
 * that information is destroyed by step 1. Recorded here rather than discovered
 * during a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfill();

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['avatar_template_id']);
        });

        DB::statement('ALTER TABLE projects ALTER COLUMN avatar_template_id SET NOT NULL');

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreign('avatar_template_id')
                ->references('id')
                ->on('avatar_templates')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['avatar_template_id']);
        });

        DB::statement('ALTER TABLE projects ALTER COLUMN avatar_template_id DROP NOT NULL');

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreign('avatar_template_id')
                ->references('id')
                ->on('avatar_templates')
                ->nullOnDelete();
        });
    }

    /**
     * Resolve, per project, the template the nullable fallback would have
     * given it. Raw SQL rather than Eloquent: models carry the TenantScoped
     * global scope and a migration runs outside any tenant context, so a
     * model-based backfill would silently see nothing.
     */
    private function backfill(): void
    {
        $orphans = DB::table('projects')
            ->whereNull('avatar_template_id')
            ->get(['id', 'organization_id', 'provider_override']);

        $default = (string) config('interview.provider', 'heygen');

        foreach ($orphans as $project) {
            $provider = $project->provider_override ?? $default;

            $templateId = DB::table('avatar_templates')
                ->where('organization_id', $project->organization_id)
                ->where('is_active', true)
                ->where('provider', $provider)
                ->value('id')
                ?? DB::table('avatar_templates')
                    ->where('organization_id', $project->organization_id)
                    ->where('is_active', true)
                    ->value('id')
                ?? DB::table('avatar_templates')
                    ->where('organization_id', $project->organization_id)
                    ->orderBy('id')
                    ->value('id');

            if ($templateId === null) {
                throw new RuntimeException(sprintf(
                    'Project %d (organization %d) cannot be backfilled: the organization owns no '
                    .'avatar template, and `projects.avatar_template_id` is about to become NOT NULL. '
                    .'Create a template for that organization first, then re-run this migration.',
                    $project->id,
                    $project->organization_id,
                ));
            }

            DB::table('projects')
                ->where('id', $project->id)
                ->update(['avatar_template_id' => $templateId]);
        }
    }
};
