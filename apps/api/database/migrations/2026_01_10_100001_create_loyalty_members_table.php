<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant scoping
            $table->uuid('tenant_id');

            // Link to customer (optional)
            $table->uuid('customer_id')->nullable();

            // Primary identifier (phone)
            $table->string('phone', 20); // Normalized phone number

            // Optional fields
            $table->string('email')->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();

            // Status
            $table->string('status', 20)->default('active'); // active, suspended, blocked
            $table->dateTime('enrollment_date');

            // External integration
            $table->string('external_id', 100)->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('customer_id')->references('id')->on('partners')->nullOnDelete();

            // Indexes
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_members');
    }
};
