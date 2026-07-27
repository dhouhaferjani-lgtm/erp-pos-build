<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MaturingInstrumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $method;

    private PaymentRepository $repository;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-11 09:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo('instruments.view');
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'currency' => 'TND',
        ]);
        $this->partner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pending_instruments_are_bucketed_forward_with_exact_direction_totals(): void
    {
        $overdue = $this->instrument('10.000', InstrumentDirection::Inbound, -1, InstrumentStatus::Received);
        $dueNow = $this->instrument('2.000', InstrumentDirection::Outbound, null, InstrumentStatus::Received);
        $remitted = $this->instrument('3.000', InstrumentDirection::Inbound, 7, InstrumentStatus::Deposited);
        $this->instrument('4.000', InstrumentDirection::Inbound, 8, InstrumentStatus::Received);
        $this->instrument('5.000', InstrumentDirection::Outbound, 31, InstrumentStatus::Deposited);
        $this->instrument('6.000', InstrumentDirection::Inbound, 61, InstrumentStatus::Received);
        $this->instrument('7.000', InstrumentDirection::Outbound, 91, InstrumentStatus::Received);
        $this->instrument('100.000', InstrumentDirection::Inbound, 1, InstrumentStatus::Cleared);
        $this->foreignCompanyInstrument();

        $response = $this->actingAs($this->user)->getJson('/api/v1/treasury/maturing-instruments');

        $response->assertOk()->assertJsonCount(7, 'data');
        $response->assertJsonPath('meta.buckets.overdue.count', 1);
        $response->assertJsonPath('meta.buckets.overdue.total_in', '10.000');
        $response->assertJsonPath('meta.buckets.overdue.total_out', '0.000');
        $response->assertJsonPath('meta.buckets.d0_7.count', 2);
        $response->assertJsonPath('meta.buckets.d0_7.total_in', '3.000');
        $response->assertJsonPath('meta.buckets.d0_7.total_out', '2.000');
        $response->assertJsonPath('meta.buckets.d8_30.total_in', '4.000');
        $response->assertJsonPath('meta.buckets.d31_60.total_out', '5.000');
        $response->assertJsonPath('meta.buckets.d61_90.total_in', '6.000');
        $response->assertJsonPath('meta.buckets.d90_plus.total_out', '7.000');
        $response->assertJsonPath('meta.grand_total.count', 7);
        $response->assertJsonPath('meta.grand_total.total_in', '23.000');
        $response->assertJsonPath('meta.grand_total.total_out', '14.000');
        $response->assertJsonFragment(['id' => $overdue->id, 'certainty' => 'portfolio', 'bucket' => 'overdue']);
        $response->assertJsonFragment(['id' => $dueNow->id, 'certainty' => 'portfolio', 'bucket' => 'd0_7']);
        $response->assertJsonFragment(['id' => $remitted->id, 'certainty' => 'remitted', 'bucket' => 'd0_7']);
    }

    public function test_location_filter_uses_frozen_instrument_origin_and_buckets_by_location(): void
    {
        $storeA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Store A']);
        $storeB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Store B']);
        $this->instrument('10.000', InstrumentDirection::Inbound, 2, InstrumentStatus::Received, false, $storeA->id);
        $this->instrument('20.000', InstrumentDirection::Inbound, 2, InstrumentStatus::Received, false, $storeB->id);

        $response = $this->actingAs($this->user)->getJson('/api/v1/treasury/maturing-instruments?group_by=location&location_ids[]='.$storeA->id);

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.location_id', $storeA->id);
        $response->assertJsonPath('meta.buckets_by_location.0.total_in', '10.000');
    }

    public function test_restricted_membership_hides_other_origins_and_unattributed_without_a_filter(): void
    {
        $storeA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Store A']);
        $storeB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Store B']);
        $this->instrument('10.000', InstrumentDirection::Inbound, 2, InstrumentStatus::Received, false, $storeA->id);
        $this->instrument('20.000', InstrumentDirection::Inbound, 2, InstrumentStatus::Received, false, $storeB->id);
        $this->instrument('30.000', InstrumentDirection::Inbound, 2, InstrumentStatus::Received);
        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => [$storeA->id]]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/maturing-instruments?group_by=location')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.location_id', $storeA->id);
        $response->assertJsonMissing(['location_id' => null]);
    }

    public function test_filters_are_company_scoped_and_compose(): void
    {
        $match = $this->instrument('12.000', InstrumentDirection::Inbound, 5, InstrumentStatus::Received, true);
        $this->instrument('13.000', InstrumentDirection::Outbound, 5, InstrumentStatus::Received, true);
        $this->instrument('14.000', InstrumentDirection::Inbound, 40, InstrumentStatus::Received, true);
        $this->instrument('15.000', InstrumentDirection::Inbound, 5, InstrumentStatus::Received, false);

        $query = http_build_query([
            'from' => '2026-07-11',
            'to' => '2026-07-18',
            'direction' => 'inbound',
            'kind' => 'cheque',
            'repository_id' => $this->repository->id,
            'partner_id' => $this->partner->id,
            'needs_details' => 'true',
        ]);
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/maturing-instruments?'.$query);

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $match->id);
        $response->assertJsonPath('meta.buckets.d0_7.count', 1);
        $response->assertJsonPath('meta.grand_total.total_in', '12.000');
    }

    public function test_date_window_treats_null_maturity_as_due_today(): void
    {
        $atSight = $this->instrument('9.000', InstrumentDirection::Inbound, null, InstrumentStatus::Received);

        $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/maturing-instruments?from=2026-07-11&to=2026-07-18')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $atSight->id)
            ->assertJsonPath('data.0.bucket', 'd0_7')
            ->assertJsonPath('meta.grand_total.total_in', '9.000');

        $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/maturing-instruments?from=2026-07-12&to=2026-07-18')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function instrument(
        string $amount,
        InstrumentDirection $direction,
        ?int $maturityOffsetDays,
        InstrumentStatus $status,
        bool $needsDetails = false,
        ?string $locationId = null,
    ): PaymentInstrument {
        return PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->method->id,
            'reference' => 'MAT-'.uniqid(),
            'partner_id' => $this->partner->id,
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => '2026-07-01',
            'maturity_date' => $maturityOffsetDays === null
                ? null
                : CarbonImmutable::today()->addDays($maturityOffsetDays)->toDateString(),
            'status' => $status,
            'direction' => $direction,
            'kind' => InstrumentKind::Cheque,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $this->repository->id,
            'location_id' => $locationId,
            'needs_details' => $needsDetails,
        ]);
    }

    private function foreignCompanyInstrument(): void
    {
        $company = Company::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'TND']);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => 'CHECK-FOREIGN',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'currency' => 'TND',
        ]);
        PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'FOREIGN',
            'amount' => '200.000',
            'currency' => 'TND',
            'received_date' => '2026-07-01',
            'maturity_date' => '2026-07-12',
            'status' => InstrumentStatus::Received,
            'direction' => InstrumentDirection::Inbound,
            'kind' => InstrumentKind::Cheque,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $repository->id,
        ]);
    }
}
