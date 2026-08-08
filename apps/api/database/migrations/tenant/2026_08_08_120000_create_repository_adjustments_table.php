<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document-per-action remediation, lane V3 — the missing `repository_adjustments`
 * document row.
 *
 * Before this table, `RepositoryAdjustmentController::store()` minted an
 * `$adjustmentId = Str::uuid()` inline; the journal entry
 * (`journal_entries.source_type = 'repository_adjustment'`) and the
 * `MovementSourceType::Adjustment` repository movement both stored that UUID as
 * their `source_id` — but no row anywhere carried it. The linkage SHAPE was
 * right; the referent did not exist. This table is that referent: the
 * justifying document for a manual cash adjustment (document-per-action
 * principle), and the prerequisite for the Wave-2 shift-variance lane (G3).
 *
 * NO foreign key points from `repository_movements` at this table, by design.
 * `MovementSourceType::Adjustment` is OVERLOADED:
 * `Treasury/Application/Services/AcquirerFeeService.php:46,140` records
 * acquirer-fee movements under the SAME source type with a
 * `bank_statement_lines.id` as `source_id`. A `movements.source_id →
 * repository_adjustments.id` FK would therefore reject every acquirer-fee
 * movement. The polymorphism is accepted here; minting a distinct
 * `MovementSourceType::AcquirerFee` to de-overload it is a recorded program
 * ticket and is deliberately NOT done in this lane. The FKs below run the other
 * way only (document → movement / journal entry / repository), which is
 * unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Unattended-safe (lane requirement 1): a push to origin/dev auto-deploys
        // `tenants:migrate` with no manual prerequisite, so a partially applied
        // batch re-run against a tenant must no-op rather than throw "relation
        // already exists". Same self-guard idiom as
        // 2026_07_31_950000_create_fiscal_refund_compensations_table.php.
        //
        // Gate M3, idiom note: this guard proves "a table by this name exists",
        // NOT "a table of THIS shape exists" — it would silently accept a
        // differently shaped pre-existing `repository_adjustments`. That is the
        // accepted house trade-off for a brand-new table name that no tenant can
        // already carry; any later SHAPE change must ship as its own additive
        // migration with its own `Schema::hasColumn` guard, never by editing this
        // file (which already-migrated tenants will never re-run).
        if (Schema::hasTable('repository_adjustments')) {
            return;
        }

        Schema::create('repository_adjustments', function (Blueprint $table): void {
            // The UUID that already flows to the JE's and the movement's
            // source_id — this row is what those referents address.
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('payment_repository_id');
            $table->string('direction', 3);          // MovementDirection: in|out
            $table->decimal('amount', 15, 3);
            $table->char('currency', 3);
            $table->string('reason_code', 32);       // MovementReasonCode
            $table->text('reason_text');
            // Nullable: both are backfilled later inside the SAME transaction,
            // once the GL entry and the movement exist. A committed row always
            // carries them.
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('movement_id')->nullable();
            // Gate I2 — the G3 (shift-close cash variance) consumer's origin
            // reference, shipped WITH the table rather than bolted on by a later
            // ALTER against a frozen table. Nullable because the interactive
            // adjustment endpoint has no shift; G3 will populate it so a
            // shift-variance document states WHICH shift it justifies instead of
            // burying it in `reason_text` — the exact anti-pattern this program
            // is remediating. No FK to `pos_shifts` on purpose: a schema-level
            // Treasury→POS coupling would invert the module boundary, and shift
            // ids can be device-authored (CLAUDE.md rule 20) before the server
            // row lands. Resolution is an application-layer lookup.
            $table->uuid('pos_shift_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'company_id']);
            $table->index(['payment_repository_id', 'created_at']);
            $table->index('pos_shift_id');
            // Gate M1/M2: the document is 1:1 with the movement it justifies, so
            // the claim is UNIQUE, not merely indexed (multiple NULLs are legal
            // in both Postgres and sqlite, which is what the pre-linkage window
            // inside the transaction needs). `journal_entry_id` gets a plain
            // index — Postgres does not auto-index FK columns, so the
            // GL→document lookup would otherwise be a sequential scan.
            $table->unique('movement_id');
            $table->index('journal_entry_id');

            // Same-database referential integrity only — no cross-database FK
            // (tenant_id/company_id are plain UUIDs, per the T6 Phase 0b
            // migration topology contract).
            $table->foreign('payment_repository_id')->references('id')->on('payment_repositories');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('movement_id')->references('id')->on('repository_movements');
        });

        // pgsql-only CHECKs, mirroring the sibling `repository_movements`
        // (2026_07_08_100100:55-56) EXACTLY: `amount` and `direction` only.
        //
        // Gate I4: an earlier revision of this migration also CHECKed
        // `reason_code` against the enum vocabulary. That was REMOVED — the
        // sibling deliberately leaves `reason_code` unconstrained, and a
        // hardcoded vocabulary means adding a `MovementReasonCode` case (very
        // plausible for the G3 shift-variance lane) passes the whole sqlite
        // suite and then fails on Postgres with 23514 in production. The enum is
        // enforced where it can be kept in sync — the FormRequest's
        // `Rule::enum(MovementReasonCode::class)` and the model's enum cast.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE repository_adjustments ADD CONSTRAINT repository_adjustments_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE repository_adjustments ADD CONSTRAINT repository_adjustments_direction_valid CHECK (direction IN ('in','out'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_adjustments');
    }
};
