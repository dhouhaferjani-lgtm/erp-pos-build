<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `workshop_work_orders` table — the aggregate root of the
     * Workshop/WorkOrder submodule. Mirrors Spec §5.1 column-for-column.
     *
     * Money fields use NUMERIC(14,3) to support TND 3-decimal scales.
     * Partial unique index on `(tenant_id, company_id, work_order_number)
     * WHERE deleted_at IS NULL` requires raw DDL (Blueprint doesn't expose
     * the WHERE clause portably).
     */
    public function up(): void
    {
        Schema::create('workshop_work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('work_order_number', 32);

            $table->string('status', 24)->default('received');
            $table->string('type', 24);

            // Parties
            $table->foreignUuid('customer_partner_id')->constrained('partners')->restrictOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUuid('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('primary_technician_profile_id')
                ->nullable()
                ->constrained('workshop_technician_profiles')
                ->nullOnDelete();

            // Intake
            $table->integer('mileage_at_intake')->nullable();
            $table->text('customer_complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('internal_notes')->nullable();

            // Scheduling
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->timestampTz('scheduled_end_at')->nullable();
            $table->timestampTz('promised_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 32)->nullable();

            // Customer approval
            $table->timestampTz('approval_captured_at')->nullable();
            $table->string('approval_method', 24)->nullable();
            $table->foreignUuid('approval_captured_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('approval_reference')->nullable();

            // Financial rollups (cached from lines)
            $table->string('currency', 3);
            $table->decimal('estimated_parts_total', 14, 3)->default(0);
            $table->decimal('estimated_labor_total', 14, 3)->default(0);
            $table->decimal('estimated_other_total', 14, 3)->default(0);
            $table->decimal('estimated_tax_total', 14, 3)->default(0);
            $table->decimal('estimated_grand_total', 14, 3)->default(0);
            $table->decimal('actual_parts_total', 14, 3)->default(0);
            $table->decimal('actual_labor_total', 14, 3)->default(0);
            $table->decimal('actual_other_total', 14, 3)->default(0);
            $table->decimal('actual_tax_total', 14, 3)->default(0);
            $table->decimal('actual_grand_total', 14, 3)->default(0);

            // Document linkages (FKs to existing documents table)
            $table->foreignUuid('quote_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignUuid('invoice_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'status', 'scheduled_start_at'], 'idx_wwo_status');
            $table->index('vehicle_id', 'idx_wwo_vehicle');
            $table->index('customer_partner_id', 'idx_wwo_customer');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX uq_wwo_tenant_company_number ON workshop_work_orders '.
                '(tenant_id, company_id, work_order_number) WHERE deleted_at IS NULL'
            );
            DB::statement(
                'CREATE INDEX idx_wwo_primary_tech ON workshop_work_orders (primary_technician_profile_id) '.
                'WHERE primary_technician_profile_id IS NOT NULL'
            );
            DB::statement(
                'ALTER TABLE workshop_work_orders ADD CONSTRAINT chk_wwo_status CHECK '.
                "(status IN ('received','diagnosed','quoted','approved','in_progress','paused',".
                "'waiting_parts','completed','invoiced','closed','cancelled'))"
            );
            DB::statement(
                'ALTER TABLE workshop_work_orders ADD CONSTRAINT chk_wwo_type CHECK '.
                "(type IN ('repair','maintenance','inspection','bodywork','tire_service','electrical','diagnostic','other'))"
            );
            DB::statement(
                'ALTER TABLE workshop_work_orders ADD CONSTRAINT chk_wwo_approval_method CHECK '.
                "(approval_method IS NULL OR approval_method IN ('in_person','phone','email','sms','signed_document'))"
            );
            DB::statement(
                'ALTER TABLE workshop_work_orders ADD CONSTRAINT chk_wwo_cancellation_reason CHECK '.
                '(cancellation_reason IS NULL OR cancellation_reason IN '.
                "('customer_declined','customer_no_show','internal_error','duplicate','vehicle_unfit','other'))"
            );
        } else {
            Schema::table('workshop_work_orders', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'company_id', 'work_order_number'], 'uq_wwo_tenant_company_number');
                $table->index('primary_technician_profile_id', 'idx_wwo_primary_tech');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_work_orders');
    }
};
