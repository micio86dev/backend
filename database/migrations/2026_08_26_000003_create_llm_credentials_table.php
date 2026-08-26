<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * llm_credentials — an organization's own bring-your-own Google Gemini key
 * (pluggable-conversation-llm PR P2, design D2).
 *
 * `api_key` is a plain `text` column. Encryption is an APPLICATION-layer
 * concern — `LlmCredential::$casts['api_key'] = 'encrypted'` AND
 * `$hidden = ['api_key']`, both halves of the `Project.php:92,103` convention
 * — never a database feature. There is no `Crypt::` call and no custom cast
 * anywhere else in this codebase; inventing one here for the first time would
 * make this the sole exception, with its own key-rotation story, inside a
 * change whose subject is not cryptography.
 *
 * `key_fingerprint` mirrors the `ai_requests.response_sha256` precedent
 * (`2026_08_25_000001_add_response_fingerprint_to_ai_requests.php`): a CHECK
 * constraint IS the enforcement mechanism, not documentation of one. It lets
 * an audit trail say "this credential" without ever holding the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_credentials', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('vendor', 32);

            // Plain text at the database. Encrypted/hidden at the Eloquent
            // layer only — see the class docblock.
            $table->text('api_key');

            $table->string('key_last_four', 4);
            $table->char('key_fingerprint', 64);

            // Non-null iff a HeyGen `/v1/secrets` object exists for this
            // credential (D8) — populated by PR P5, additive here.
            $table->string('heygen_secret_id')->nullable();

            $table->timestampTz('validated_at')->nullable();

            // A stable code — 'invalid_key' | 'rate_limited' | 'unreachable'
            // — never the vendor's own prose (design D9).
            $table->string('validation_error')->nullable();

            $table->timestamps();

            // Names are how an operator refers to a credential out loud.
            $table->unique(['organization_id', 'name']);

            $table->index(['organization_id', 'heygen_secret_id']);
        });

        DB::statement(
            "ALTER TABLE llm_credentials ADD CONSTRAINT llm_credentials_key_fingerprint_format_check
             CHECK (key_fingerprint ~ '^[0-9a-f]{64}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_credentials');
    }
};
