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
     * Stores professional certifications tied to a technician profile.
     * Partial index on expires_at supports the daily expiring-cert scan.
     */
    public function up(): void
    {
        Schema::create('workshop_technician_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('technician_profile_id')
                ->constrained('workshop_technician_profiles')
                ->cascadeOnDelete();

            $table->string('certification_name', 200);
            $table->string('issuing_body', 200)->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX idx_wtc_expiry ON workshop_technician_certifications(expires_at) '.
                'WHERE expires_at IS NOT NULL'
            );
        } else {
            Schema::table('workshop_technician_certifications', function (Blueprint $table): void {
                $table->index('expires_at', 'idx_wtc_expiry');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_technician_certifications');
    }
};
