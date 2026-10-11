<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform currency catalogue and each organization's display currency
 * (organization-currencies, D3 and D8).
 *
 * D3: `organizations.currency_code` is a REAL foreign key to `currencies.code`
 * (RESTRICT both ways), not a free string validated in a FormRequest. The
 * database is the last line for the portability import path and any future
 * writer, and it is what makes "a currency in use cannot be deleted" a fact
 * rather than a convention. The column defaults to `USD`, so every
 * pre-existing organization reads `USD` with no backfill.
 *
 * D8: the seed lives HERE, not in a seeder, because `USD` is structural: the
 * column default references it, so every environment (RefreshDatabase tests,
 * a fresh Railway database) must have it without a seeding step. `EUR` is
 * seeded INACTIVE with NO rate. An exchange rate is an operator's decision and
 * a moving number: THIS MIGRATION and its seed carry none, and
 * `currencies_active_rated` guarantees an unrated currency can never be
 * activated, so EUR cannot be selected until a rate is set. The rate comes
 * later: the owner-authorized `CurrencyRatesSeeder` (2026-10-11, ECB reference
 * rate of 2026-10-09) sets the confirmed EUR rate where the table is populated,
 * and a superadmin may edit it afterwards.
 *
 * Amounts stay stored in USD (`*_usd`); a currency only changes how they are
 * displayed, by `rate_per_usd` (units of the currency per 1 USD).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE currencies (
                code          char(3)       PRIMARY KEY,
                symbol        varchar(8)    NOT NULL,
                decimals      smallint      NOT NULL,
                rate_per_usd  numeric(18,6) NULL,
                rate_as_of    date          NULL,
                is_active     boolean       NOT NULL DEFAULT false,
                created_at    timestamp(0)  NULL,
                updated_at    timestamp(0)  NULL,
                CONSTRAINT currencies_code_iso     CHECK (code ~ '^[A-Z]{3}$'),
                CONSTRAINT currencies_decimals     CHECK (decimals BETWEEN 0 AND 4),
                CONSTRAINT currencies_rate_range   CHECK (rate_per_usd IS NULL OR (rate_per_usd > 0 AND rate_per_usd < 1000000)),
                CONSTRAINT currencies_rate_pair    CHECK ((rate_per_usd IS NULL) = (rate_as_of IS NULL)),
                CONSTRAINT currencies_active_rated CHECK (NOT is_active OR rate_per_usd IS NOT NULL),
                CONSTRAINT currencies_usd_anchor   CHECK (code <> 'USD' OR (rate_per_usd = 1 AND is_active))
            )
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO currencies (code, symbol, decimals, rate_per_usd, rate_as_of, is_active, created_at, updated_at) VALUES
                ('USD', '$', 2, 1, CURRENT_DATE, true, now(), now()),
                ('EUR', '€', 2, NULL, NULL, false, now(), now())
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN currency_code char(3) NOT NULL DEFAULT 'USD'
                CONSTRAINT organizations_currency_code_foreign
                REFERENCES currencies (code) ON DELETE RESTRICT ON UPDATE RESTRICT
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP COLUMN IF EXISTS currency_code');
        DB::statement('DROP TABLE IF EXISTS currencies');
    }
};
