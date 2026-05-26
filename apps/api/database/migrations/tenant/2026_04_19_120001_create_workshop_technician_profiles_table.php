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
     * Creates the sidecar technician profile table. One profile per (user, company).
     * Fields include HR-adjacent data (skill_level, specialties, rates, schedule),
     * gated by permissions at DTO serialization time.
     */
    public function up(): void
    {
        Schema::create('workshop_technician_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            $table->string('skill_level', 16);
            $table->jsonb('specialties')->default('[]');

            $table->decimal('hourly_cost_rate', 12, 3)->nullable();
            $table->decimal('hourly_billing_rate', 12, 3)->nullable();
            $table->string('currency', 3);

            $table->jsonb('weekly_schedule')->default('{}');
            $table->date('hire_date')->nullable();
            $table->string('employment_status', 16)->default('active');

            $table->string('employee_code', 32)->nullable();
            $table->text('notes')->nullable();

            // PII fields (gated via workshop.technicians.view_pii)
            $table->string('national_id', 64)->nullable();
            $table->string('personal_address', 512)->nullable();
            $table->string('personal_phone', 32)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'is_active'], 'idx_wtp_company_active');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX uq_wtp_tenant_company_user ON workshop_technician_profiles '.
                '(tenant_id, company_id, user_id) WHERE deleted_at IS NULL'
            );
            DB::statement(
                'ALTER TABLE workshop_technician_profiles ADD CONSTRAINT chk_wtp_skill_level CHECK '.
                "(skill_level IN ('apprentice','junior','general','senior','master','specialist'))"
            );
            DB::statement(
                'ALTER TABLE workshop_technician_profiles ADD CONSTRAINT chk_wtp_employment_status CHECK '.
                "(employment_status IN ('active','on_leave','terminated'))"
            );
        } else {
            Schema::table('workshop_technician_profiles', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'company_id', 'user_id'], 'uq_wtp_tenant_company_user');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_technician_profiles');
    }
};
