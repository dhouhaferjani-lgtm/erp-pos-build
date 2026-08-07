<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Enums\SuperAdminRole;
use App\Models\SuperAdmin;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function __construct(private readonly Hasher $hasher) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = config('auth.super_admin.email');
        $password = config('auth.super_admin.password');

        if (is_string($password) && $password !== '') {
            SuperAdmin::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => 'Super Admin',
                    'password' => $this->hasher->make($password),
                    'role' => SuperAdminRole::SuperAdmin->value,
                    'is_active' => true,
                ],
            );

            $this->command?->info("Super admin created or updated: {$email}");
        } else {
            $this->command->error('SUPER_ADMIN_PASSWORD environment variable is required. Set it in .env before seeding.');
        }

        $partnerEmail = config('support_access.partner.email');
        $partnerPassword = config('support_access.partner.password');
        $partnerName = config('support_access.partner.name');

        if (! is_string($partnerEmail) || filter_var($partnerEmail, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($partnerPassword) || $partnerPassword === '') {
            return;
        }

        SuperAdmin::query()->updateOrCreate(
            ['email' => strtolower($partnerEmail)],
            [
                'name' => is_string($partnerName) && $partnerName !== '' ? $partnerName : 'Business Partner Support Approver',
                'password' => $this->hasher->make($partnerPassword),
                'role' => SuperAdminRole::SupportApprover->value,
                'is_active' => true,
                'notes' => 'Configured four-eyes support-access approver.',
            ],
        );

        $this->command?->info("Support-access partner approver created or updated: {$partnerEmail}");
    }
}
