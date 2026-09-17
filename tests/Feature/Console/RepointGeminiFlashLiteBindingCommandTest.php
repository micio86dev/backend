<?php

declare(strict_types=1);

/**
 * `beai:repoint-gemini-flash-lite` (fix/heygen-gemini-flash-lite).
 *
 * `gemini-3-flash-preview` is a THINKING model and HeyGen's turn-based
 * FULL-mode custom-LLM path has no room for a thinking pass between a
 * candidate's turn ending and the avatar's next line — this one-off command
 * repoints every `avatar_templates` row still bound to it onto
 * `gemini-3.1-flash-lite-preview` and pushes the change to its provider.
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function repointOldModel(): LlmModel
{
    return LlmModel::firstOrCreate(
        ['key' => 'gemini-3-flash-preview'],
        [
            'vendor' => 'google',
            'display_name' => 'Gemini 3 Flash Preview',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'capability' => 'text',
            'is_available' => true,
            'sort_order' => 10,
        ],
    );
}

function repointNewModel(): LlmModel
{
    return LlmModel::firstOrCreate(
        ['key' => 'gemini-3.1-flash-lite-preview'],
        [
            'vendor' => 'google',
            'display_name' => 'Gemini 3.1 Flash Lite Preview',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'capability' => 'text',
            'is_available' => true,
            'sort_order' => 15,
        ],
    );
}

function repointCredentialForOrg(int $orgId, string $vendor = 'google'): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($vendor): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'name' => 'Repoint-cred-'.uniqid(),
            'vendor' => $vendor,
            'api_key' => 'sk-real-key',
            'key_last_four' => 'lkey',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

/**
 * @return array{0: AvatarTemplate, 1: Organization}
 */
function repointTemplateBoundToOldModel(string $provider = 'heygen'): array
{
    $oldModel = repointOldModel();
    $org = Organization::factory()->create();
    $credential = repointCredentialForOrg($org->id, $oldModel->vendor);

    $config = $provider === 'heygen'
        ? ['avatarId' => 'a', 'voiceId' => 'v']
        : ['faceId' => 'f', 'palId' => 'p'];

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Repoint template '.uniqid(),
        'provider' => $provider,
        'config' => $config,
        'llm_model_id' => $oldModel->id,
        'llm_credential_id' => $credential->id,
    ]));

    return [$template, $org];
}

function repointHeygenFakes(): void
{
    config()->set('interview.heygen.api_key', 'platform-heygen-key');

    Http::fake([
        '*liveavatar.com/v1/secrets' => Http::response(
            ['code' => 1000, 'data' => ['id' => 'sec_repoint'], 'message' => 'success'],
            200,
        ),
        '*liveavatar.com/v1/llm-configurations*' => Http::response(
            ['data' => ['id' => 'cfg_repoint', 'display_name' => 'x', 'model_name' => 'gemini-3.1-flash-lite-preview', 'base_url' => 'x', 'secret_id' => 'sec_repoint']],
            200,
        ),
    ]);
}

/**
 * Forces the failed-status stamp write — `$template->forceFill(['llm_sync_status' =>
 * 'failed'])->saveQuietly()` inside RepointGeminiFlashLiteBindingCommand's sync-push
 * catch block — to throw for ONE specific template id, while every other write on
 * the same connection (including that same template's own EARLIER `llm_model_id`
 * save()) is completely untouched.
 *
 * A real Postgres constraint/trigger violation was considered and rejected: this
 * suite runs under RefreshDatabase, so the whole test executes inside one already-
 * open transaction, and a genuine SQLSTATE error poisons that transaction at the
 * SESSION level (`25P02: current transaction is aborted, commands ignored until end
 * of transaction block`) — every later statement in the SAME test, including
 * templateB's own processing and this test's own post-run assertions, would then
 * fail too. Confirmed empirically against this project's own Postgres connection
 * before choosing this approach.
 *
 * Instead this swaps in a connection object that shares the REAL live PDO handle —
 * so it participates in the exact same open transaction — but overrides only
 * update() to throw in pure PHP, before any SQL reaches Postgres. The transaction is
 * never touched, so the rest of the sweep and every later assertion stay healthy.
 *
 * Returns a restore callback; the caller MUST invoke it once the command run under
 * test is done, to put the real connection back before any other test sharing this
 * (parallel-worker) process runs.
 *
 * @return Closure(): void
 */
function repointForceStatusStampWriteToThrow(int $throwingTemplateId): Closure
{
    $manager = app('db');
    $connectionName = $manager->getDefaultConnection();
    $real = $manager->connection($connectionName);

    $throwing = new class($real->getPdo(), $real->getDatabaseName(), $real->getTablePrefix(), $real->getConfig(), $throwingTemplateId) extends PostgresConnection
    {
        public function __construct(
            $pdo,
            string $database,
            string $tablePrefix,
            array $config,
            private readonly int $throwingTemplateId,
        ) {
            parent::__construct($pdo, $database, $tablePrefix, $config);
        }

        public function update($query, $bindings = [])
        {
            if (str_contains($query, 'llm_sync_status') && in_array($this->throwingTemplateId, $bindings)) {
                throw new RuntimeException('simulated DB blip stamping the failed-sync status');
            }

            return parent::update($query, $bindings);
        }
    };

    if (app()->bound('events')) {
        $throwing->setEventDispatcher(app('events'));
    }

    $connectionsProperty = new ReflectionProperty($manager, 'connections');
    $connectionsProperty->setAccessible(true);
    $connections = $connectionsProperty->getValue($manager);
    $original = $connections[$connectionName];
    $connections[$connectionName] = $throwing;
    $connectionsProperty->setValue($manager, $connections);

    return function () use ($connectionsProperty, $manager, $connectionName, $original): void {
        $connections = $connectionsProperty->getValue($manager);
        $connections[$connectionName] = $original;
        $connectionsProperty->setValue($manager, $connections);
    };
}

test('no old model in the registry: nothing to repoint, exits 0', function (): void {
    repointNewModel();

    $this->artisan('beai:repoint-gemini-flash-lite')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nothing to repoint');
});

test('the new model is missing from the registry: refuses and tells the operator to sync first', function (): void {
    repointOldModel();

    $this->artisan('beai:repoint-gemini-flash-lite')
        ->assertExitCode(1)
        ->expectsOutputToContain('beai:sync-llm-registry');
});

test('no template is bound to the old model: nothing to repoint, exits 0', function (): void {
    repointOldModel();
    repointNewModel();

    $this->artisan('beai:repoint-gemini-flash-lite')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nothing to repoint');
});

test('repoints a HeyGen template and syncs it, leaving llm_sync_status synced', function (): void {
    repointNewModel();
    [$template] = repointTemplateBoundToOldModel('heygen');
    repointHeygenFakes();

    $this->artisan('beai:repoint-gemini-flash-lite')->assertExitCode(0);

    $fresh = $template->fresh();
    expect($fresh->llm_model_id)->toBe(repointNewModel()->id);
    expect($fresh->llm_sync_status)->toBe('synced');
});

test('repoints a Tavus template too — the binding is not provider-specific', function (): void {
    repointNewModel();
    [$template] = repointTemplateBoundToOldModel('tavus');
    config()->set('interview.tavus.api_key', 'platform-tavus-key');
    Http::fake(['tavusapi.com/*' => Http::response(['persona_id' => 'p'], 200)]);

    $this->artisan('beai:repoint-gemini-flash-lite')->assertExitCode(0);

    $fresh = $template->fresh();
    expect($fresh->llm_model_id)->toBe(repointNewModel()->id);
    expect($fresh->llm_sync_status)->toBe('synced');
});

test('a vendor sync failure still repoints the model id, but exits non-zero and leaves llm_sync_status failed', function (): void {
    repointNewModel();
    [$template] = repointTemplateBoundToOldModel('heygen');
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    Http::fake(['*liveavatar.com*' => Http::response(['error' => 'boom'], 500)]);

    $this->artisan('beai:repoint-gemini-flash-lite')->assertExitCode(1);

    $fresh = $template->fresh();
    expect($fresh->llm_model_id)->toBe(repointNewModel()->id);
    expect($fresh->llm_sync_status)->toBe('failed');
});

test('a sync push that throws is caught, counted separately, and never stops the sweep', function (): void {
    Log::spy();
    repointNewModel();
    [$templateA, $orgA] = repointTemplateBoundToOldModel('heygen');
    [$templateB, $orgB] = repointTemplateBoundToOldModel('heygen');
    repointHeygenFakes();

    expect($orgA->id)->not->toBe($orgB->id);

    // A prior REAL sync under the OLD binding — the state this row is in
    // before the repoint ever runs. If a thrown sync push left
    // llm_sync_status untouched, this stale 'synced' value would survive the
    // repoint and describe the OLD model's vendor state, not the new one.
    $templateA->forceFill(['llm_sync_status' => 'synced'])->saveQuietly();

    // Only $templateA's provider call explodes — the rest of the sweep,
    // including $templateB in a LATER organization, must still be reached.
    $throwingTemplateId = $templateA->id;
    $realRegistrar = new HeygenLlmRegistrar;

    app()->bind(HeygenLlmRegistrar::class, fn () => new class($throwingTemplateId, $realRegistrar)
    {
        public function __construct(
            private readonly int $throwingTemplateId,
            private readonly HeygenLlmRegistrar $delegate,
        ) {}

        public function ensureConfiguration(AvatarTemplate $template): array
        {
            if ($template->id === $this->throwingTemplateId) {
                throw new RuntimeException('simulated network failure pushing to HeyGen');
            }

            return $this->delegate->ensureConfiguration($template);
        }
    });

    // Artisan::call()/output(), not $this->artisan()->assertExitCode(): the
    // latter's PendingCommand only actually runs the command from its own
    // __destruct() unless ->run() is called explicitly, which — depending on
    // how the returned object is chained/assigned — can defer the real run
    // until after this test has already read the database, making the DB
    // assertions below observe pre-run state. Artisan::call() runs
    // synchronously and Artisan::output() reads the real buffered output.
    $exitCode = Artisan::call('beai:repoint-gemini-flash-lite');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(sprintf('template %d', $templateA->id))
        ->and($output)->toContain('sync push threw')
        ->and($output)->toContain('1 threw');

    // The DB write is authoritative even though the sync push threw: the
    // template's own save() happens before run() and is never rolled back.
    $freshA = $templateA->fresh();
    expect($freshA->llm_model_id)->toBe(repointNewModel()->id);

    // llm_sync_status must NOT still read the stale 'synced' set up above —
    // that value described the OLD model's confirmed vendor state, and
    // nothing confirmed the NEW one. It reads 'failed', the same "needs a
    // retry" value a non-throwing vendor failure already gets.
    expect($freshA->llm_sync_status)->toBe('failed');

    // Every OTHER template — including one in a later organization — is
    // still processed and synced normally; the throw never aborts the sweep.
    $freshB = $templateB->fresh();
    expect($freshB->llm_model_id)->toBe(repointNewModel()->id)
        ->and($freshB->llm_sync_status)->toBe('synced');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'sync push threw')
            && $context['template_id'] === $throwingTemplateId
        );
});

// R3-unguarded-failed-stamp — the failed-status stamp write inside the sync-push
// catch block (`$template->forceFill(['llm_sync_status' => 'failed'])->
// saveQuietly()`) was itself unguarded: if IT threw too (e.g. a DB connection blip
// between the earlier successful save() and this one), the exception would escape
// the outer catch block and abort the sweep mid-loop — one level deeper than the
// bug the test above already covers.
test('a sync push that throws AND a failed-status stamp write that also throws are both caught, counted as one failure, and never stop the sweep', function (): void {
    Log::spy();
    repointNewModel();
    [$templateA, $orgA] = repointTemplateBoundToOldModel('heygen');
    [$templateB, $orgB] = repointTemplateBoundToOldModel('heygen');
    repointHeygenFakes();

    expect($orgA->id)->not->toBe($orgB->id);

    // A prior REAL sync under the OLD binding, same setup as the sync-push-throws
    // test above — proves the stale value survives when even the "failed" stamp
    // itself cannot be written.
    $templateA->forceFill(['llm_sync_status' => 'synced'])->saveQuietly();

    $throwingTemplateId = $templateA->id;
    $realRegistrar = new HeygenLlmRegistrar;

    // Same sync-push throw as the test above — the double failure only makes sense
    // once the FIRST guard (hardened in the prior round) is already in play.
    app()->bind(HeygenLlmRegistrar::class, fn () => new class($throwingTemplateId, $realRegistrar)
    {
        public function __construct(
            private readonly int $throwingTemplateId,
            private readonly HeygenLlmRegistrar $delegate,
        ) {}

        public function ensureConfiguration(AvatarTemplate $template): array
        {
            if ($template->id === $this->throwingTemplateId) {
                throw new RuntimeException('simulated network failure pushing to HeyGen');
            }

            return $this->delegate->ensureConfiguration($template);
        }
    });

    // Only the SECOND write on templateA — the failed-status stamp inside the
    // sync-push catch block — throws. templateA's OWN earlier llm_model_id save()
    // and every write for templateB stay on the real connection.
    $restoreConnection = repointForceStatusStampWriteToThrow($throwingTemplateId);

    try {
        $exitCode = Artisan::call('beai:repoint-gemini-flash-lite');
        $output = Artisan::output();
    } finally {
        $restoreConnection();
    }

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(sprintf('template %d', $templateA->id))
        ->and($output)->toContain('sync push threw')
        ->and($output)->toContain('the status stamp itself also failed; llm_sync_status may still read a stale value')
        // Still exactly ONE failed-sync event for templateA — no new counter was
        // added for the double failure.
        ->and($output)->toContain('1 threw');

    // The llm_model_id write is authoritative and untouched by the LATER stamp
    // failure — it happened before the sync push was even attempted.
    $freshA = $templateA->fresh();
    expect($freshA->llm_model_id)->toBe(repointNewModel()->id);

    // The stamp write never landed, so the STALE 'synced' value set up above
    // survives — exactly the risk the warning text above calls out.
    expect($freshA->llm_sync_status)->toBe('synced');

    // Every OTHER template — including one in a later organization — is still
    // processed and synced normally; the double failure on templateA never aborts
    // the sweep.
    $freshB = $templateB->fresh();
    expect($freshB->llm_model_id)->toBe(repointNewModel()->id)
        ->and($freshB->llm_sync_status)->toBe('synced');

    // Both throws are logged as separate error() calls — one for the sync push
    // itself, one for the stamp write that failed trying to record it. Two
    // separate ->once()->withArgs() expectations on the same spied method don't
    // compose reliably in Mockery (each verifies against the TOTAL call count,
    // not just the calls matching its own filter), so both messages are asserted
    // by a single ->twice() expectation whose matcher accepts either shape and a
    // manual tally proves BOTH distinct messages — not the same one twice — were
    // actually logged.
    $loggedMessages = [];
    Log::shouldHaveReceived('error')
        ->twice()
        ->withArgs(function (string $message, array $context) use ($throwingTemplateId, &$loggedMessages): bool {
            $loggedMessages[] = $message;

            return $context['template_id'] === $throwingTemplateId
                && (str_contains($message, 'sync push threw') || str_contains($message, 'failed-status stamp threw'));
        });

    expect($loggedMessages)->toHaveCount(2)
        ->and(array_filter($loggedMessages, fn (string $m): bool => str_contains($m, 'sync push threw')))->toHaveCount(1)
        ->and(array_filter($loggedMessages, fn (string $m): bool => str_contains($m, 'failed-status stamp threw')))->toHaveCount(1);
});

test('a template save() throw is caught, counted separately, leaves that template untouched, and never stops the sweep', function (): void {
    Log::spy();
    repointNewModel();
    [$templateA, $orgA] = repointTemplateBoundToOldModel('heygen');
    [$templateB, $orgB] = repointTemplateBoundToOldModel('heygen');
    repointHeygenFakes();

    expect($orgA->id)->not->toBe($orgB->id);

    // Forces AvatarTemplate::booted()'s `saving` hook to throw
    // InvalidLlmBindingException on I4 (vendor mismatch) for templateA's
    // OWN save() — a real validation path, not a mock. Written through the
    // query builder (bypassing Eloquent events) so this simulates data that
    // drifted AFTER templateA was created bound to the old model (which
    // legitimately passed I4 at creation time); only the repoint's own
    // save(), which re-validates on every write, now fails it.
    LlmCredential::withoutGlobalScopes()
        ->whereKey($templateA->llm_credential_id)
        ->update(['vendor' => 'openai']);

    $exitCode = Artisan::call('beai:repoint-gemini-flash-lite');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(sprintf('template %d', $templateA->id))
        ->and($output)->toContain('could not be saved')
        ->and($output)->toContain('1 could not be saved');

    // templateA is entirely unaffected: still bound to the OLD model, and
    // resyncTemplateBinding->run() was never attempted for it — there is
    // nothing new in the database to push.
    $freshA = $templateA->fresh();
    expect($freshA->llm_model_id)->toBe(repointOldModel()->id);

    // Every OTHER template — including one in a later organization — is
    // still processed and synced normally; the throw never aborts the sweep.
    $freshB = $templateB->fresh();
    expect($freshB->llm_model_id)->toBe(repointNewModel()->id)
        ->and($freshB->llm_sync_status)->toBe('synced');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'save threw')
            && $context['template_id'] === $templateA->id
        );

    // No resync attempt for templateA — its own save() never happened, so
    // there is nothing new to push. Exactly templateB's own sync (its
    // secret POST and its configuration POST) reaches HeyGen.
    Http::assertSentCount(2);
});

test('--dry-run writes nothing and calls no vendor', function (): void {
    repointNewModel();
    [$template] = repointTemplateBoundToOldModel('heygen');
    $oldModelId = repointOldModel()->id;
    Http::fake();

    $this->artisan('beai:repoint-gemini-flash-lite', ['--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('[dry-run] would repoint')
        ->expectsOutputToContain('Would repoint 1 template');

    expect($template->fresh()->llm_model_id)->toBe($oldModelId);
    Http::assertNothingSent();
});

test('is idempotent — a second run finds nothing left bound to the old model', function (): void {
    repointNewModel();
    repointTemplateBoundToOldModel('heygen');
    repointHeygenFakes();

    $this->artisan('beai:repoint-gemini-flash-lite')->assertExitCode(0);

    Http::fake(); // any second-run vendor call would be unexpected

    $this->artisan('beai:repoint-gemini-flash-lite')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nothing to repoint');

    Http::assertNothingSent();
});

test('repoints every bound template across every tenant', function (): void {
    repointNewModel();
    [$templateA, $orgA] = repointTemplateBoundToOldModel('heygen');
    [$templateB, $orgB] = repointTemplateBoundToOldModel('heygen');
    repointHeygenFakes();

    // Two DIFFERENT organizations — this is what proves the sweep does not
    // silently stop after the first one it visits.
    expect($orgA->id)->not->toBe($orgB->id);

    $this->artisan('beai:repoint-gemini-flash-lite')
        ->assertExitCode(0)
        ->expectsOutputToContain('Repointed 2 template(s)');

    $newModelId = repointNewModel()->id;
    expect($templateA->fresh()->llm_model_id)->toBe($newModelId);
    expect($templateB->fresh()->llm_model_id)->toBe($newModelId);
});

test('a template in an organization with no matching binding is never touched while another organization is repointed', function (): void {
    $oldModel = repointOldModel();
    $newModel = repointNewModel();
    [$boundTemplate, $boundOrg] = repointTemplateBoundToOldModel('heygen');
    repointHeygenFakes();

    // An organization the sweep also visits (every organization is listed),
    // but whose template is ALREADY on the new model — the row this test
    // proves stays untouched while $boundOrg's row is repointed.
    $untouchedOrg = Organization::factory()->create();
    $untouchedCredential = repointCredentialForOrg($untouchedOrg->id);
    $untouchedTemplate = TenantContextScope::runFor($untouchedOrg->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Already on new model',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $newModel->id,
        'llm_credential_id' => $untouchedCredential->id,
    ]));
    $untouchedSyncStatus = $untouchedTemplate->llm_sync_status;
    $untouchedUpdatedAt = $untouchedTemplate->updated_at;

    $this->artisan('beai:repoint-gemini-flash-lite')->assertExitCode(0);

    expect($boundTemplate->fresh()->llm_model_id)->toBe($newModel->id);

    $freshUntouched = $untouchedTemplate->fresh();
    expect($freshUntouched->llm_model_id)->toBe($newModel->id)
        ->and($freshUntouched->llm_sync_status)->toBe($untouchedSyncStatus)
        ->and($freshUntouched->updated_at->equalTo($untouchedUpdatedAt))->toBeTrue();
});

test('the per-organization query the command runs never resolves another organization\'s row bound to the old model', function (): void {
    $oldModel = repointOldModel();
    repointNewModel();
    [$templateA, $orgA] = repointTemplateBoundToOldModel('heygen');
    [$templateB, $orgB] = repointTemplateBoundToOldModel('heygen');

    expect($orgA->id)->not->toBe($orgB->id);

    // Exactly the query shape `handle()` runs inside
    // `TenantContextScope::runFor($organization->id, …)` — scoped to org A,
    // org B's row bound to the SAME old model must be invisible, and vice
    // versa. This is the query-layer guarantee the per-organization loop
    // relies on rather than a `withoutGlobalScopes()` sweep.
    $seenByA = TenantContextScope::runFor(
        $orgA->id,
        fn () => AvatarTemplate::where('llm_model_id', $oldModel->id)->pluck('id')->all(),
    );
    $seenByB = TenantContextScope::runFor(
        $orgB->id,
        fn () => AvatarTemplate::where('llm_model_id', $oldModel->id)->pluck('id')->all(),
    );

    expect($seenByA)->toBe([$templateA->id])
        ->and($seenByB)->toBe([$templateB->id]);
});
