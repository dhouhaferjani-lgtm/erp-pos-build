<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\Events\TechnicianCertificationExpiring;
use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ExpiringCertificationsScheduledTest extends TestCase
{
    use RefreshDatabase;

    private TechnicianProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->profile = TechnicianProfile::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_fires_event_only_for_certs_within_horizon(): void
    {
        Event::fake([TechnicianCertificationExpiring::class]);

        // Within horizon (10 days) — should fire.
        $soon = TechnicianCertification::factory()->create([
            'tenant_id' => $this->profile->tenant_id,
            'technician_profile_id' => $this->profile->id,
            'expires_at' => Carbon::today()->addDays(10),
        ]);
        // Beyond horizon (40 days) — should NOT fire.
        TechnicianCertification::factory()->create([
            'tenant_id' => $this->profile->tenant_id,
            'technician_profile_id' => $this->profile->id,
            'expires_at' => Carbon::today()->addDays(40),
        ]);
        // Also beyond horizon (60 days) — should NOT fire.
        TechnicianCertification::factory()->create([
            'tenant_id' => $this->profile->tenant_id,
            'technician_profile_id' => $this->profile->id,
            'expires_at' => Carbon::today()->addDays(60),
        ]);
        // Already expired — should NOT fire.
        TechnicianCertification::factory()->create([
            'tenant_id' => $this->profile->tenant_id,
            'technician_profile_id' => $this->profile->id,
            'expires_at' => Carbon::today()->subDays(5),
        ]);

        $this->artisan('workshop:check-expiring-certifications')->assertExitCode(0);

        Event::assertDispatchedTimes(TechnicianCertificationExpiring::class, 1);
        Event::assertDispatched(
            TechnicianCertificationExpiring::class,
            fn (TechnicianCertificationExpiring $e): bool => $e->certification_id === $soon->id,
        );
    }

    public function test_no_events_when_no_matching_certs(): void
    {
        Event::fake([TechnicianCertificationExpiring::class]);

        TechnicianCertification::factory()->create([
            'tenant_id' => $this->profile->tenant_id,
            'technician_profile_id' => $this->profile->id,
            'expires_at' => Carbon::today()->addYear(),
        ]);

        $this->artisan('workshop:check-expiring-certifications')->assertExitCode(0);

        Event::assertNotDispatched(TechnicianCertificationExpiring::class);
    }

    public function test_custom_days_option(): void
    {
        Event::fake([TechnicianCertificationExpiring::class]);

        // 50 days out — outside default 30-day horizon but inside 60-day.
        TechnicianCertification::factory()->create([
            'tenant_id' => $this->profile->tenant_id,
            'technician_profile_id' => $this->profile->id,
            'expires_at' => Carbon::today()->addDays(50),
        ]);

        $this->artisan('workshop:check-expiring-certifications', ['--days' => '60'])
            ->assertExitCode(0);

        Event::assertDispatchedTimes(TechnicianCertificationExpiring::class, 1);
    }
}
