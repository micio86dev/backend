<?php

namespace Database\Seeders;

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * A working tenant to click through locally.
 *
 * NOT part of DatabaseSeeder, and never run automatically. It creates an
 * account with a known password, which belongs in a developer's laptop and
 * absolutely nowhere else:
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * It exists because BEAI has no organization-provisioning surface. Projects and
 * participants have APIs; ORGANIZATIONS have none, and a platform superadmin is
 * created with organization_id = null. So there is no supported path — through
 * the backoffice or the API — from a migrated database to something you can log
 * into. Until one exists, this is that path.
 *
 * Idempotent: re-running updates nothing and creates nothing twice, so it is
 * safe to leave in a boot script.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_EMAIL = 'admin@beai.local';

    public const DEMO_PASSWORD = 'password';

    public const DEMO_CANDIDATE_REF = 'demo-candidate-001';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoSeeder refuses to run in production: it creates an account with a published password.');

            return;
        }

        $org = Organization::firstOrCreate(
            ['slug' => 'dev-org'],
            ['name' => 'Dev Organization'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

        $admin = User::firstOrCreate(
            ['email' => self::DEMO_EMAIL],
            ['name' => 'Demo Admin', 'password' => Hash::make(self::DEMO_PASSWORD)],
        );

        // organization_id is deliberately NOT fillable — it is set only by
        // trusted server code, never by a payload. A seeder is trusted code, so
        // it assigns the property directly rather than smuggling it through
        // firstOrCreate's attribute array, which would silently drop it.
        if ($admin->organization_id !== $org->id) {
            $admin->organization_id = $org->id;
            $admin->save();
        }

        $admin->assignRole(SpatieRole::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api',
            'team_id' => $org->id,
        ]));

        [$project, $participant] = TenantContextScope::runFor($org->id, function () use ($org): array {
            // The framework CATALOG (competencies, indicators, anchors) is
            // global and seeded by FrameworkCatalogSeeder. A FrameworkVersion is
            // the per-tenant pin a project points at, and nothing creates one
            // for you — a project cannot exist without it.
            $version = FrameworkVersion::firstOrCreate(
                ['organization_id' => $org->id, 'version' => '1.0.0'],
                ['label' => 'Demo framework version'],
            );

            $project = Project::firstOrCreate(
                ['slug' => 'demo-project'],
                [
                    'framework_version_id' => $version->id,
                    'name' => 'Demo Project',
                    // 'standard' rather than 'potential' so the interview runs
                    // the adaptive role competencies — the flow worth looking at.
                    'assessment_type' => 'standard',
                    'role_code' => 'ICO',
                    'language' => 'it',
                    'status' => 'active',
                ],
            );

            // Built by hand rather than via firstOrCreate, because a
            // Participant's organization_id is a named security invariant: it
            // is NOT fillable, and it is set server-side from the project it
            // belongs to — never from a payload and never inferred from the
            // ambient tenant. Nothing stamps it on this path, so passing it
            // through the attribute array silently drops it and the insert dies
            // on a not-null violation.
            $participant = Participant::where('project_id', $project->id)
                ->where('candidate_ref', self::DEMO_CANDIDATE_REF)
                ->first();

            if ($participant === null) {
                $participant = new Participant;
                $participant->forceFill([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'candidate_ref' => self::DEMO_CANDIDATE_REF,
                    'display_name' => 'Candidato Demo',
                    'role_code' => 'ICO',
                    'language' => 'it',
                    'status' => 'in_attesa',
                ])->save();

                $participant->refresh();
            }

            return [$project, $participant];
        });

        $this->command->newLine();
        $this->command->info('Demo tenant ready.');
        $this->command->table(
            ['What', 'Value'],
            [
                ['Organization', "{$org->name} (id={$org->id})"],
                ['Backoffice login', self::DEMO_EMAIL.' / '.self::DEMO_PASSWORD],
                ['Project', "{$project->name} — slug={$project->slug}, role=ICO, type=standard"],
                ['Participant', self::DEMO_CANDIDATE_REF." (id={$participant->id}, status={$participant->status})"],
            ],
        );
        $this->command->newLine();
        $this->command->warn('The password above is public knowledge. Local use only.');
    }
}
