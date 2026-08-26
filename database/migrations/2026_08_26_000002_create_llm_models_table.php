<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * llm_models — the global registry of conversation LLM models and their
 * published rates (pluggable-conversation-llm PR P1, design D1).
 *
 * GLOBAL, not tenant-scoped: a rate card is a vendor fact, not an
 * organization's private data. `App\Models\LlmModel` extends `Model`
 * directly and joins the documented exclusion list in
 * `tests/Arch/C2/TenantModelArchTest.php`, beside `Competency`,
 * `BarsIndicator`, `Role`, `CatalogMeta`, `FrameworkGap`.
 *
 * Every rate column is nullable `decimal(12,6)` with NO default. NULL means
 * "Google does not publish this" — a different fact from zero — and a
 * `NOT NULL DEFAULT 0` would let the cost estimator silently bill an
 * unpriced model at $0.00, a number an operator would believe. There is no
 * `mode` column: mode is derived from `capability` by `LlmCapability::mode()`,
 * an exhaustive match with no default arm, because a stored `mode` alongside
 * `capability` would be a second source of truth for a 1:1 relation.
 *
 * `context_tier_threshold_tokens` / `*_high` express a context-length pricing
 * tier (`gemini-3.1-pro-preview` is $2.00/$12.00 up to 200k tokens and
 * $4.00/$18.00 above it) that a single flat rate could not hold — applied
 * PER REQUEST by the estimator, never on the session total.
 *
 * A model removed from the seed array becomes `is_available = false` and is
 * NEVER deleted: a deleted row would break the display name on every
 * historical cost row that already references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_models', function (Blueprint $table): void {
            $table->id();

            // The exact vendor model string, sent VERBATIM to Tavus
            // (layers.llm.model) and HeyGen (/v1/llm-configurations). The
            // natural key.
            $table->string('key')->unique();
            $table->string('vendor');
            $table->string('display_name');

            // https://generativelanguage.googleapis.com/v1beta/openai/ for
            // every seeded row — trailing slash included.
            $table->string('base_url');

            // 'text' | 'native_duplex'. A CHECK rather than a Postgres enum,
            // mirroring avatar_templates.provider's documented reasoning: one
            // ALTER of a constraint costs less than a type migration when a
            // vendor adds a third capability.
            $table->string('capability', 32);

            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('rate_card_source_url')->nullable();
            $table->timestampTz('rate_card_verified_at')->nullable();

            // Rate card. Every column below is NULLABLE with NO default —
            // see the class docblock for why a default would be worse than
            // an omission.
            $table->decimal('text_input_usd_per_million', 12, 6)->nullable();
            $table->decimal('text_output_usd_per_million', 12, 6)->nullable();

            // The high-tier rate above context_tier_threshold_tokens.
            $table->decimal('text_input_usd_per_million_high', 12, 6)->nullable();
            $table->decimal('text_output_usd_per_million_high', 12, 6)->nullable();
            $table->unsignedInteger('context_tier_threshold_tokens')->nullable();

            $table->decimal('audio_input_usd_per_million', 12, 6)->nullable();
            $table->decimal('audio_output_usd_per_million', 12, 6)->nullable();
            $table->decimal('audio_input_usd_per_minute', 12, 6)->nullable();
            $table->decimal('audio_output_usd_per_minute', 12, 6)->nullable();

            // NO default of 25 — that rate is published for 3.5 Live
            // Translate and Omni Flash Preview, NEITHER of which this
            // registry seeds (design.md C-C).
            $table->unsignedSmallInteger('audio_tokens_per_second')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_models');
    }
};
