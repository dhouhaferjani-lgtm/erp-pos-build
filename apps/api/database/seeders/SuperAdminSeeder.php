<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the initial super admin account.
 *
 * ============================================================================
 * SECURITY WARNING - PRODUCTION DEPLOYMENT
 * ============================================================================
 * The default password 'superadmin123' is for DEVELOPMENT ONLY.
 * In production, you MUST:
 * 1. Change the password immediately after first login
 * 2. Or modify this seeder to use an environment variable for the password
 * 3. Or create the super admin manually with a secure password
 * ============================================================================
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Super Admin',
            'email' => 'superadmin@mecanospex.com',
            'password' => Hash::make('superadmin123'), // CHANGE IN PRODUCTION!
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->command->info('Super admin created: superadmin@mecanospex.com');
        $this->command->warn('WARNING: Default password is "superadmin123" - CHANGE IT IMMEDIATELY in production!');
    }
}
