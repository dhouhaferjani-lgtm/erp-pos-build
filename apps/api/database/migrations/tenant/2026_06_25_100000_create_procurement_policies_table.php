<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement — per-company AP policy configuration.
 *
 * One row per company. Controls bill-control mode (receipt-first vs
 * invoice-first), matching mode (2-way vs 3-way), match enforcement
 * (warn vs block), and variance tolerances.
 *
 * Phase 1: bill_control_mode=received, match_mode=three_way,
 *          match_enforcement=warn. Ordered mode is reserved for Phase 2.
 *
 * PostgreSQL CHECK constraints are added in the if-pgsql block; the
 * SQLite path (local tests) creates the table without them — that is
 * intentional and correct. Constraint tests are gated via
 * ProcurementPolicyCheckConstraintTest (backend-test-pgsql CI filter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('bill_control_mode')->default('received');
            $table->string('match_mode')->default('three_way');
            $table->string('match_enforcement')->default('warn');

            // Percent variance tolerance — NOT currency-scaled (scale 2 is %)
            $table->decimal('variance_tolerance_percent', 6, 2)->default('0');

            // Absolute amount cap — currency scale 3 (TND baseline)
            $table->decimal('variance_tolerance_max_amount', 15, 3)->default('0');

            $table->timestampsTz();

            $table->unique(['company_id'], 'uq_procurement_policies_company');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE procurement_policies ADD CONSTRAINT chk_pp_bill_control_mode ".
                "CHECK (bill_control_mode IN ('received','ordered'))"
            );
            DB::statement(
                "ALTER TABLE procurement_policies ADD CONSTRAINT chk_pp_match_mode ".
                "CHECK (match_mode IN ('two_way','three_way'))"
            );
            DB::statement(
                "ALTER TABLE procurement_policies ADD CONSTRAINT chk_pp_match_enforcement ".
                "CHECK (match_enforcement IN ('warn','block'))"
            );
            DB::statement(
                "ALTER TABLE procurement_policies ADD CONSTRAINT chk_pp_tolerance_percent_nonneg ".
                "CHECK (variance_tolerance_percent >= 0)"
            );
            DB::statement(
                "ALTER TABLE procurement_policies ADD CONSTRAINT chk_pp_tolerance_max_amount_nonneg ".
                "CHECK (variance_tolerance_max_amount >= 0)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_policies');
    }
};
