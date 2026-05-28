<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.1.4: Create user_company_memberships table.
 *
 * UserCompanyMembership defines the many-to-many relationship between users and companies.
 * A user can belong to multiple companies with different roles.
 * Roles: owner, admin, manager, accountant, cashier, technician, viewer
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_company_memberships', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary();

            // Foreign keys
            $table->uuid('user_id');
            $table->uuid('company_id');

            // Role within this company
            $table->string('role', 50); // 'owner', 'admin', 'manager', 'accountant', 'cashier', 'technician', 'viewer'

            // Location restrictions (NULL = all locations)
            // Using JSON instead of array for SQLite compatibility
            $table->json('allowed_location_ids')->nullable();

            // Flags
            $table->boolean('is_primary')->default(false);

            // Invitation tracking
            $table->uuid('invited_by')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            // Status
            $table->string('status', 20)->default('active');

            // Timestamps
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('invited_by')->references('id')->on('users')->onDelete('set null');

            // Unique constraint: one membership per user per company
            $table->unique(['user_id', 'company_id'], 'idx_memberships_user_company');

            // Indexes for lookups
            $table->index('user_id', 'idx_memberships_user');
            $table->index('company_id', 'idx_memberships_company');
            $table->index(['user_id', 'status'], 'idx_memberships_user_status');
            $table->index(['company_id', 'role'], 'idx_memberships_company_role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_company_memberships');
    }
};
