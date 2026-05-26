<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.2.1: Migrate existing tenant data to company architecture.
 *
 * This migration:
 * - Creates a company for each active tenant
 * - Creates a default location for each company
 * - Creates owner membership for the first user
 * - Creates admin membership for additional users
 *
 * This migration is idempotent - safe to run multiple times.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // T6 Phase 0b: this is a legacy data bridge from the pre-company era. Under
        // database-per-tenant it runs inside each tenant database via Stancl, where
        // the central `tenants` table is not present — and the move is greenfield
        // (no tenant data to migrate). No local `tenants` table => tenant context =>
        // no-op. In the single-connection compat suite `tenants` is present and the
        // (idempotent) bridge runs harmlessly.
        if (! Schema::hasTable('tenants')) {
            return;
        }

        // Get all active tenants that don't have a company yet
        $tenants = Tenant::where('status', TenantStatus::Active)
            ->whereDoesntHave('companies')
            ->get();

        foreach ($tenants as $tenant) {
            DB::transaction(function () use ($tenant) {
                $this->migrateTenant($tenant);
            });
        }
    }

    /**
     * Migrate a single tenant to the new architecture.
     */
    private function migrateTenant(Tenant $tenant): void
    {
        // Create company from tenant data
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'legal_name' => $tenant->name,
            'country_code' => 'TN', // Default to Tunisia
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Create default location
        Location::create([
            'company_id' => $company->id,
            'name' => 'Siège principal',
            'code' => 'LOC-001',
            'type' => 'shop',
            'is_default' => true,
            'is_active' => true,
        ]);

        // Create memberships for all users of this tenant
        $users = User::where('tenant_id', $tenant->id)->orderBy('created_at')->get();

        foreach ($users as $index => $user) {
            // Skip if user already has a membership in this company
            $existingMembership = UserCompanyMembership::where('user_id', $user->id)
                ->where('company_id', $company->id)
                ->exists();

            if ($existingMembership) {
                continue;
            }

            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => $index === 0 ? 'owner' : 'admin',
                'is_primary' => true,
                'status' => 'active',
                'accepted_at' => now(),
            ]);
        }

        // Update tenant with first/last name if name contains a space
        $nameParts = explode(' ', $tenant->name, 2);
        if (count($nameParts) === 2) {
            $tenant->update([
                'first_name' => $nameParts[0],
                'last_name' => $nameParts[1],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        // Delete all memberships
        UserCompanyMembership::query()->delete();

        // Delete all locations
        Location::query()->delete();

        // Delete all companies
        Company::query()->delete();

        // Reset tenant first/last names
        DB::table('tenants')->update([
            'first_name' => null,
            'last_name' => null,
        ]);
    }
};
