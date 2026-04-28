<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Application\Contracts\TechnicianAvailabilityServiceInterface;
use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => 'Europe/Paris',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function service(): TechnicianAvailabilityServiceInterface
    {
        return $this->app->make(TechnicianAvailabilityServiceInterface::class);
    }

    /**
     * @param  array<int, string>|null  $specialties
     */
    private function makeActiveProfile(
        ?array $specialties = null,
        ?EmploymentStatus $status = null,
        ?bool $isActive = null,
    ): TechnicianProfile {
        return TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'specialties' => $specialties ?? [
                SpecialtyCode::GeneralService->value,
                SpecialtyCode::Brakes->value,
            ],
            'employment_status' => ($status ?? EmploymentStatus::Active)->value,
            'is_active' => $isActive ?? true,
        ]);
    }

    public function test_no_when_profile_not_found(): void
    {
        $result = $this->service()->isAvailable(
            (string) Str::uuid(),
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isNo());
        $this->assertFalse($result->isYes());
        $this->assertFalse($result->isPartial());
        $this->assertSame('not_found', $result->reason);
    }

    public function test_no_when_profile_inactive(): void
    {
        $profile = $this->makeActiveProfile(isActive: false);
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isNo());
        $this->assertSame('inactive', $result->reason);
    }

    public function test_no_when_employment_on_leave(): void
    {
        $profile = $this->makeActiveProfile(status: EmploymentStatus::OnLeave);
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isNo());
        $this->assertSame('inactive', $result->reason);
    }

    public function test_no_when_outside_schedule(): void
    {
        $profile = $this->makeActiveProfile();
        // Saturday — schedule has empty sat
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-25T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isNo());
        $this->assertSame('outside_schedule', $result->reason);
    }

    public function test_no_when_full_leave_overlap(): void
    {
        $profile = $this->makeActiveProfile();
        TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $profile->id,
            'starts_at' => new \DateTimeImmutable('2026-04-20T00:00:00', new \DateTimeZone('UTC')),
            'ends_at' => new \DateTimeImmutable('2026-04-21T00:00:00', new \DateTimeZone('UTC')),
            'reason_code' => TimeOffReason::Vacation->value,
            'is_approved' => true,
        ]);
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isNo());
        $this->assertSame('on_leave', $result->reason);
    }

    public function test_partial_when_partial_leave_overlap(): void
    {
        $profile = $this->makeActiveProfile();
        // Leave covers 08:30–14:00 UTC (10:30–16:00 Paris during CEST);
        // request 10:00 Paris (08:00 UTC) for 60min → 08:00–09:00 UTC.
        // Overlap = 08:30–09:00 UTC → partial (not full), leave starts AFTER request start.
        TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $profile->id,
            'starts_at' => new \DateTimeImmutable('2026-04-20T08:30:00', new \DateTimeZone('UTC')),
            'ends_at' => new \DateTimeImmutable('2026-04-20T14:00:00', new \DateTimeZone('UTC')),
            'reason_code' => TimeOffReason::Personal->value,
            'is_approved' => true,
        ]);
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isPartial());
        $this->assertSame('partial_leave_overlap', $result->reason);
    }

    public function test_partial_when_active_assignment_overlap(): void
    {
        $profile = $this->makeActiveProfile();
        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'started_at' => new \DateTimeImmutable('2026-04-20T07:30:00', new \DateTimeZone('UTC')),
            'ended_at' => new \DateTimeImmutable('2026-04-20T08:30:00', new \DateTimeZone('UTC')),
            'duration_minutes' => 60,
            'entry_type' => TimeEntryType::WorkOrder->value,
            'source' => TimeEntrySource::Manual->value,
        ]);
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isPartial());
        $this->assertSame('active_assignment', $result->reason);
    }

    public function test_partial_when_required_specialty_not_held(): void
    {
        $profile = $this->makeActiveProfile([
            SpecialtyCode::GeneralService->value,
        ]);
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            SpecialtyCode::HybridEv,
        );
        $this->assertTrue($result->isPartial());
        $this->assertSame('skill_mismatch', $result->reason);
    }

    public function test_yes_when_within_schedule_no_conflicts_no_specialty(): void
    {
        $profile = $this->makeActiveProfile();
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            null,
        );
        $this->assertTrue($result->isYes());
        $this->assertFalse($result->isNo());
        $this->assertFalse($result->isPartial());
    }

    public function test_yes_when_specialty_matches(): void
    {
        $profile = $this->makeActiveProfile([
            SpecialtyCode::HybridEv->value,
            SpecialtyCode::Electrical->value,
        ]);
        $result = $this->service()->isAvailable(
            $profile->id,
            new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('Europe/Paris')),
            60,
            SpecialtyCode::HybridEv,
        );
        $this->assertTrue($result->isYes());
    }
}
