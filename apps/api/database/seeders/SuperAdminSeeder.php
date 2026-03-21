<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = config('auth.super_admin.email');
        $password = config('auth.super_admin.password');

        if (! is_string($password) || $password === '') {
            $this->command->error('SUPER_ADMIN_PASSWORD environment variable is required. Set it in .env before seeding.');

            return;
        }

        SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Super Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->command->info("Super admin created: {$email}");
    }
}
