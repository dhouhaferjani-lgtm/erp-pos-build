<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Entities\VatPeriodBreakdown;
use App\Modules\Taxation\Domain\Enums\VatDirection;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * N-4 (campaign report §N-4): API↔FE contract lock for
 * GET /api/v1/vat/reports/{periodId}/summary.
 *
 * VatReportPage crashed reading `report.period.{label,status,id}` — the payload
 * has never carried a `period` key. These tests pin the EXACT key set of BOTH
 * branches of periodSummary (live OPEN re-query vs persisted CLOSED/FILED
 * snapshot) so the two can no longer drift from each other or from the FE type.
 */
final class VatReportSummaryContractTest extends TestCase
{
    use RefreshDatabase;

    /** Top-level keys of the summary payload — no `period` among them. */
    private const SUMMARY_KEYS = [
        'amount_payable',
        'credit_brought_forward',
        'credit_carried_forward',
        'declaration',
        'input_vat',
        'net_vat',
        'output_vat',
        'special_items',
    ];

    /** Per-direction block keys. */
    private const DIRECTION_KEYS = ['breakdowns', 'total_base', 'total_vat'];

    /** Per-rate breakdown row keys — identical in both branches. */
    private const BREAKDOWN_KEYS = [
        'base_amount',
        'direction',
        'document_count',
        'is_recoverable',
        'tax_configuration_id',
        'tax_rate',
        'vat_amount',
    ];

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'VAT Contract Tenant',
            'slug' => 'vat-contract-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT Contract Co',
            'legal_name' => 'VAT Contract Co SARL',
            'tax_id' => '1234567ABC',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT Contract Accountant',
            'email' => 'vat-contract@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_open_period_summary_payload_key_set_is_exact(): void
    {
        $period = $this->createPeriod(VatPeriodStatus::Open);

        $data = $this->fetchSummary($period);

        $this->assertSame(
            self::SUMMARY_KEYS,
            $this->sortedKeys($data),
            'OPEN-period summary top-level keys drifted.'
        );
        $this->assertArrayNotHasKey(
            'period',
            $data,
            'The summary payload carries no period header — the page must fetch it from /vat/periods/{id}.'
        );
        $this->assertSame(self::DIRECTION_KEYS, $this->sortedKeys($data['output_vat']));
        $this->assertSame(self::DIRECTION_KEYS, $this->sortedKeys($data['input_vat']));
    }

    public function test_closed_period_summary_payload_key_set_matches_the_open_branch(): void
    {
        $period = $this->createPeriod(VatPeriodStatus::Closed);
        $this->createBreakdown($period, VatDirection::Output, '19.00', '10000.000', '1900.000');
        $this->createBreakdown($period, VatDirection::Input, '19.00', '2631.579', '500.000');

        $data = $this->fetchSummary($period);

        $this->assertSame(
            self::SUMMARY_KEYS,
            $this->sortedKeys($data),
            'CLOSED-period summary top-level keys drifted from the OPEN branch.'
        );
        $this->assertArrayNotHasKey('period', $data);
        $this->assertSame(self::DIRECTION_KEYS, $this->sortedKeys($data['output_vat']));
        $this->assertSame(self::DIRECTION_KEYS, $this->sortedKeys($data['input_vat']));
    }

    /**
     * The snapshot branch used to emit `rate` where the live branch emits
     * `tax_rate`, so the FE breakdown table rendered blank rate cells (and
     * duplicate `undefined` React keys) for every CLOSED/FILED period.
     */
    public function test_breakdown_rows_use_the_same_keys_in_both_branches(): void
    {
        $openPeriod = $this->createPeriod(VatPeriodStatus::Open);
        $openData = $this->fetchSummary($openPeriod);

        $closedPeriod = $this->createPeriod(VatPeriodStatus::Closed, 'February 2026', '2026-02-01', '2026-02-28');
        $this->createBreakdown($closedPeriod, VatDirection::Output, '19.00', '10000.000', '1900.000');
        $closedData = $this->fetchSummary($closedPeriod);

        $this->assertSame(
            self::BREAKDOWN_KEYS,
            $this->sortedKeys($closedData['output_vat']['breakdowns'][0]),
            'CLOSED-period breakdown row keys drifted.'
        );
        $this->assertSame('19.00', $closedData['output_vat']['breakdowns'][0]['tax_rate']);
        $this->assertSame('OUTPUT', $closedData['output_vat']['breakdowns'][0]['direction']);

        // The live branch has nothing to aggregate here, but its contract is the
        // reference: assert the snapshot row keys are a subset-free exact match.
        $this->assertSame([], $openData['output_vat']['breakdowns']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSummary(VatPeriod $period): array
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vat/reports/{$period->id}/summary");

        $response->assertOk();

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    private function createPeriod(
        VatPeriodStatus $status,
        string $label = 'January 2026',
        string $start = '2026-01-01',
        string $end = '2026-01-31',
    ): VatPeriod {
        return VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => $label,
            'period_start' => $start,
            'period_end' => $end,
            'status' => $status,
            'closed_at' => $status === VatPeriodStatus::Open ? null : now(),
            'total_output_vat' => '1900.000',
            'total_input_vat' => '500.000',
            'net_vat' => '1400.000',
            'amount_payable' => '1400.000',
        ]);
    }

    private function createBreakdown(
        VatPeriod $period,
        VatDirection $direction,
        string $rate,
        string $base,
        string $vat,
    ): void {
        VatPeriodBreakdown::create([
            'vat_period_id' => $period->id,
            'direction' => $direction,
            'tax_rate' => $rate,
            'base_amount' => $base,
            'vat_amount' => $vat,
            'document_count' => 1,
            'is_recoverable' => $direction === VatDirection::Input,
        ]);
    }
}
