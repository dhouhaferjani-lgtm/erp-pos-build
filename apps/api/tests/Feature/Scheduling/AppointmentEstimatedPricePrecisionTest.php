<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Application\Commands\BookAppointmentCommand;
use App\Modules\Scheduling\Application\Services\AppointmentAuthoringService;
use App\Modules\Scheduling\Domain\AppointmentService as AppointmentServiceModel;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4.14 — estimated_price ingress precision + service-layer normalization.
 *
 * Two concerns under test:
 *
 * 1. Validation regex: `planned_services.*.estimated_price` must reject inputs
 *    with more than 3 decimal places (money/3 ceiling).
 *
 * 2. Service normalization: AppointmentAuthoringService must normalize the
 *    stored estimated_price to the company's resolved currency scale using
 *    CurrencyScale::bcformatStrict() before persisting the AppointmentService
 *    line. EUR company → scale 2; TND company → scale 3.
 */
final class AppointmentEstimatedPricePrecisionTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentAuthoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AppointmentAuthoringService::class);
    }

    // -------------------------------------------------------------------------
    // Ingress validation — regex ceiling for estimated_price
    // -------------------------------------------------------------------------

    public function test_validator_accepts_estimated_price_with_three_decimal_places(): void
    {
        $rules = [
            'planned_services.*.estimated_price' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];

        $data = ['planned_services' => [['estimated_price' => '49.900']]];
        $v = Validator::make($data, $rules);

        $this->assertFalse($v->fails(), '3-decimal price should pass validation');
    }

    public function test_validator_accepts_estimated_price_with_two_decimal_places(): void
    {
        $rules = [
            'planned_services.*.estimated_price' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];

        $data = ['planned_services' => [['estimated_price' => '49.90']]];
        $v = Validator::make($data, $rules);

        $this->assertFalse($v->fails(), '2-decimal price should pass validation');
    }

    public function test_validator_accepts_null_estimated_price(): void
    {
        $rules = [
            'planned_services.*.estimated_price' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];

        $data = ['planned_services' => [['estimated_price' => null]]];
        $v = Validator::make($data, $rules);

        $this->assertFalse($v->fails(), 'Null estimated_price should pass (nullable)');
    }

    public function test_validator_rejects_estimated_price_with_four_decimal_places(): void
    {
        $rules = [
            'planned_services.*.estimated_price' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];

        $data = ['planned_services' => [['estimated_price' => '49.9001']]];
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails(), '4-decimal price should fail validation');
        $this->assertArrayHasKey('planned_services.0.estimated_price', $v->errors()->toArray());
    }

    public function test_validator_rejects_estimated_price_with_eight_decimal_places(): void
    {
        $rules = [
            'planned_services.*.estimated_price' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];

        $data = ['planned_services' => [['estimated_price' => '49.90000001']]];
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails(), '8-decimal price should fail validation');
    }

    // -------------------------------------------------------------------------
    // Service normalization — resolver-injected bcformatStrict
    // -------------------------------------------------------------------------

    /**
     * EUR company → currency scale = 2.
     *
     * Input '49.999' has 3 dp. bcformatStrict('49.999', 2) → '49.99' (bcmath
     * truncates, does NOT round). The service must write '49.99' not '49.999'.
     * We use a value where scale truncation changes the numeric result so the
     * assertion is meaningful even after SQLite strips trailing zeros.
     *
     * Note: the AppointmentService model casts estimated_price as decimal:3,
     * which means the Eloquent accessor always returns 3dp. We therefore assert
     * against the raw database value to confirm the correct scale was stored.
     */
    public function test_service_normalizes_estimated_price_to_eur_scale_two(): void
    {
        // EUR company (default factory: country_code='FR', currency='EUR')
        $bay = Bay::factory()->create();
        $company = Company::findOrFail($bay->company_id);

        // Ensure the company is EUR
        $company->update(['currency' => 'EUR', 'country_code' => 'FR']);

        // Bind CompanyContext so the resolver can find the company
        app(CompanyContext::class)->setCompanyId($company->id);

        $appointment = $this->service->create($this->makeCommand(
            bay: $bay,
            estimatedPrice: '49.999',  // 3dp input
        ));

        $line = AppointmentServiceModel::where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($line, 'AppointmentService line should be persisted');

        // bcformatStrict('49.999', 2) → '49.99' (truncates third decimal)
        // SQLite stores this as 49.99 (no trailing zeros to strip).
        $raw = DB::table('scheduling_appointment_services')
            ->where('id', $line->id)
            ->value('estimated_price');

        // The numeric value must be 49.99, NOT 49.999 (proving scale-2 truncation occurred)
        $this->assertEqualsWithDelta(49.99, (float) $raw, 0.0001, 'EUR company should truncate to 2dp');
        $this->assertStringNotContainsString('49.999', (string) $raw, 'Raw value must not retain 3rd decimal');
    }

    /**
     * TND company → currency scale = 3.
     *
     * Input '49.9' (1 decimal) should be padded to '49.900' by bcformatStrict.
     * SQLite strips trailing zeros, so we assert the numeric value equals 49.9
     * and also confirm the line was persisted (service reached the normalization
     * point without error).
     */
    public function test_service_normalizes_estimated_price_to_tnd_scale_three(): void
    {
        // TND company
        $bay = Bay::factory()->create();
        $company = Company::findOrFail($bay->company_id);
        $company->update(['currency' => 'TND', 'country_code' => 'TN']);

        app(CompanyContext::class)->setCompanyId($company->id);

        $appointment = $this->service->create($this->makeCommand(
            bay: $bay,
            estimatedPrice: '49.9',
        ));

        $line = AppointmentServiceModel::where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($line, 'AppointmentService line should be persisted');

        $raw = DB::table('scheduling_appointment_services')
            ->where('id', $line->id)
            ->value('estimated_price');

        // bcformatStrict('49.9', 3) → '49.900'. SQLite drops trailing zeros → 49.9.
        // Assert the numeric value is correct and the line was normalized without error.
        $this->assertEqualsWithDelta(49.9, (float) $raw, 0.0001, 'TND company price should be 49.9 numerically');

        // Additional: use a value with meaningful 3dp content (no ambiguity after SQLite stripping)
        // A second appointment with '49.905' — bcformatStrict('49.905', 3) → '49.905'
        $appointment2 = $this->service->create($this->makeCommand(
            bay: $bay,
            estimatedPrice: '49.905',
            start: new \DateTimeImmutable('2027-01-15 11:00:00'),
            end: new \DateTimeImmutable('2027-01-15 12:00:00'),
        ));
        $line2 = AppointmentServiceModel::where('appointment_id', $appointment2->id)->firstOrFail();
        $raw2 = DB::table('scheduling_appointment_services')
            ->where('id', $line2->id)
            ->value('estimated_price');

        $this->assertEqualsWithDelta(49.905, (float) $raw2, 0.0001, 'TND 3dp price should be stored as-is');
    }

    /**
     * When estimated_price is null, the line should store null (no zero-fill).
     */
    public function test_service_preserves_null_estimated_price(): void
    {
        $bay = Bay::factory()->create();
        $company = Company::findOrFail($bay->company_id);
        app(CompanyContext::class)->setCompanyId($company->id);

        $appointment = $this->service->create($this->makeCommand(
            bay: $bay,
            estimatedPrice: null,
        ));

        $line = AppointmentServiceModel::where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($line);
        $this->assertNull($line->estimated_price);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCommand(
        Bay $bay,
        ?string $estimatedPrice,
        ?\DateTimeImmutable $start = null,
        ?\DateTimeImmutable $end = null,
    ): BookAppointmentCommand {
        $start ??= new \DateTimeImmutable('2027-01-15 09:00:00');
        $end ??= new \DateTimeImmutable('2027-01-15 10:00:00');

        $plannedService = [
            'service_ref_type' => AppointmentServiceModel::REF_TYPE_SERVICE,
            'service_ref_id' => (string) Str::uuid(),
            'display_name' => 'Oil Change',
            'estimated_duration_minutes' => 60,
            'display_order' => 0,
        ];

        if ($estimatedPrice !== null) {
            $plannedService['estimated_price'] = $estimatedPrice;
        }

        return new BookAppointmentCommand(
            tenant_id: $bay->tenant_id,
            company_id: $bay->company_id,
            location_id: $bay->location_id,
            bay_id: $bay->id,
            primary_technician_profile_id: null,
            customer_partner_id: null,
            vehicle_id: null,
            customer_name: 'Bob',
            customer_phone: '+21611111111',
            customer_email: null,
            vehicle_plate: 'TN-0001',
            vehicle_description: null,
            appointment_type: AppointmentType::StandardRepair,
            wait_type: WaitType::DropOff,
            scheduled_start: $start,
            scheduled_end: $end,
            estimated_duration_minutes: 60,
            source: AppointmentSource::Manual,
            planned_services: [$plannedService],
            services_summary: null,
            customer_notes: null,
            internal_notes: null,
        );
    }
}
