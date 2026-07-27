<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `webhook_events` to `projects` (C10 — Webhooks Integration, D10).
 *
 * "Enabled event types" — the binding doc requires this alongside webhook_url and
 * webhook_secret. Placed on `projects` (not a related table) so the delivery-decision
 * read resolves the whole webhook config in ONE withoutGlobalScopes() read.
 *
 * NOT NULL, defaults to both event types (`["progress","evaluation"]`) for every existing
 * and future row: no project can silently stop receiving events, and — since C10 is the
 * first delivery implementation ever shipped — there is no prior delivery state to
 * preserve and no opt-in history to violate (design.md D10).
 *
 * Additive column on an existing table: `down()` drops it and restores the C4 schema
 * exactly (reversible).
 *
 * REQ: projects.webhook_events (C10 D10)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->jsonb('webhook_events')
                ->default(json_encode(['progress', 'evaluation'], JSON_THROW_ON_ERROR))
                ->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('webhook_events');
        });
    }
};
