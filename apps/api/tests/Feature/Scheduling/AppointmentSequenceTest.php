<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Scheduling\Domain\Contracts\AppointmentSequenceInterface;
use App\Modules\Scheduling\Infrastructure\Persistence\AppointmentSequence;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentAppointmentSequence;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for {@see EloquentAppointmentSequence}.
 *
 * Covers format, monotonic increment within a year, year-rollover,
 * per-company isolation, and container binding of the interface.
 */
final class AppointmentSequenceTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentSequenceInterface $sequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sequence = $this->app->make(AppointmentSequenceInterface::class);
    }

    public function test_interface_binds_to_eloquent_implementation(): void
    {
        $this->assertInstanceOf(EloquentAppointmentSequence::class, $this->sequence);
    }

    public function test_first_number_for_year_is_one(): void
    {
        $company = $this->makeCompany();

        $number = $this->sequence->nextNumber($company->id, 2026);

        $this->assertSame('APT-2026-000001', $number);
    }

    public function test_numbers_are_monotonic_within_same_year_and_company(): void
    {
        $company = $this->makeCompany();

        $first = $this->sequence->nextNumber($company->id, 2026);
        $second = $this->sequence->nextNumber($company->id, 2026);
        $third = $this->sequence->nextNumber($company->id, 2026);

        $this->assertSame('APT-2026-000001', $first);
        $this->assertSame('APT-2026-000002', $second);
        $this->assertSame('APT-2026-000003', $third);
    }

    public function test_number_format_is_six_digit_zero_padded(): void
    {
        $company = $this->makeCompany();

        // Seed a high last_number to test padding boundary.
        AppointmentSequence::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'year' => 2026,
            'last_number' => 42,
        ]);

        $number = $this->sequence->nextNumber($company->id, 2026);

        $this->assertSame('APT-2026-000043', $number);
        $this->assertMatchesRegularExpression('/^APT-\d{4}-\d{6}$/', $number);
    }

    public function test_year_rollover_starts_fresh_at_one(): void
    {
        $company = $this->makeCompany();

        $this->sequence->nextNumber($company->id, 2026);
        $this->sequence->nextNumber($company->id, 2026);
        $next2027 = $this->sequence->nextNumber($company->id, 2027);

        $this->assertSame('APT-2027-000001', $next2027);
        $this->assertDatabaseHas('scheduling_appointment_sequences', [
            'company_id' => $company->id,
            'year' => 2026,
            'last_number' => 2,
        ]);
        $this->assertDatabaseHas('scheduling_appointment_sequences', [
            'company_id' => $company->id,
            'year' => 2027,
            'last_number' => 1,
        ]);
    }

    public function test_sequence_is_isolated_per_company(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();

        $a1 = $this->sequence->nextNumber($companyA->id, 2026);
        $b1 = $this->sequence->nextNumber($companyB->id, 2026);
        $a2 = $this->sequence->nextNumber($companyA->id, 2026);

        $this->assertSame('APT-2026-000001', $a1);
        $this->assertSame('APT-2026-000001', $b1);
        $this->assertSame('APT-2026-000002', $a2);
    }

    public function test_unknown_company_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->sequence->nextNumber('00000000-0000-0000-0000-000000000000', 2026);
    }

    private function makeCompany(): Company
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create();

        return Company::factory()->create(['tenant_id' => $tenant->id]);
    }
}
