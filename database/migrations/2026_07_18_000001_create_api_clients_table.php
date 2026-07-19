<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `api_clients` table (C5 — M2M API Authentication).
 *
 * Stores opaque API-key credentials for machine-to-machine callers.
 * The raw key is NEVER stored — only its SHA-256 hex hash (key_hash).
 *
 * Design invariants:
 * - organization_id: cascadeOnDelete — deleting an org removes all M2M credentials.
 * - key_hash: UNIQUE index — enables O(1) lookup at guard resolution.
 * - abilities: jsonb — flat lowercase-canonical string array.
 * - org-first composite index [organization_id, is_active] — tenant-scoped queries.
 * - timestampTz for expires_at and last_used_at — UTC; consistent with PHP Carbon now().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('name');

            // SHA-256 hex of the raw bearer key — hot-path UNIQUE lookup.
            // The raw key is NEVER stored.
            $table->string('key_hash')->unique();

            // Flat lowercase-canonical ability names (e.g. ["participants:read"]).
            $table->jsonb('abilities');

            $table->boolean('is_active')->default(true);

            // Timezone-aware timestamps so UTC values survive DB round-trips correctly.
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();

            $table->timestamps();

            // D22 org-first composite index for tenant-scoped queries.
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
