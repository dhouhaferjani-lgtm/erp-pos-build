<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create party_contacts pivot table linking partners to contacts.
     */
    public function up(): void
    {
        Schema::create('party_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('party_id');
            $table->uuid('contact_id');
            $table->string('job_title', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('party_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();

            // Unique constraint: one link per party-contact pair
            $table->unique(['party_id', 'contact_id']);
        });

        // PostgreSQL-specific constraints (not supported by SQLite)
        if (DB::getDriverName() === 'pgsql') {
            // Partial unique index: only one primary contact per party
            DB::statement('CREATE UNIQUE INDEX idx_party_contacts_primary ON party_contacts (party_id) WHERE is_primary = true');

            // Check constraint: start_date must be <= end_date when both are set
            DB::statement('ALTER TABLE party_contacts ADD CONSTRAINT chk_party_contacts_date_range CHECK (start_date IS NULL OR end_date IS NULL OR start_date <= end_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('party_contacts');
    }
};
