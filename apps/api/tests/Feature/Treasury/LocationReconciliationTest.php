<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Cross-grain reconciliation guard for the Wave 3 by-location surfaces. */
final class LocationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Partner $customer;
    private Location $locationA;
    private Location $locationB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'TND']);
        $this->locationA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Store A']);
        $this->locationB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Store B']);
        $this->customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->givePermissionTo(['reports.view', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_unattributed_buckets_reconcile_to_company_totals_across_cash_and_ar(): void
    {
        $this->invoiceAt($this->locationA, '100.000');
        $this->invoiceAt($this->locationB, '40.000');
        $this->invoiceAt(null, '10.000');
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'cash_register',
            'location_id' => $this->locationA->id,
            'balance' => '100.000',
            'is_active' => true,
        ]);
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'bank_account',
            'location_id' => null,
            'balance' => '50.000',
            'is_active' => true,
        ]);

        $ar = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/reports/aged-receivables?group_by=location')
            ->assertOk()
            ->json('data');
        $arBuckets = collect($ar['buckets_by_location'])->reduce(
            static fn (string $carry, array $bucket): string => bcadd($carry, (string) $bucket['total'], 3),
            '0.000',
        );
        self::assertSame(0, bccomp((string) $ar['grand_total'], $arBuckets, 3));

        $cash = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/treasury/cash-position?group_by=location')
            ->assertOk()
            ->json('data');
        $cashBuckets = collect($cash['groups_by_location'])->reduce(
            static fn (string $carry, array $bucket): string => bcadd($carry, (string) $bucket['total'], 3),
            '0.000',
        );
        self::assertSame(0, bccomp((string) $cash['grand_total'], $cashBuckets, 3));
    }

    private function invoiceAt(?Location $location, string $amount): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'REC-'.uniqid(),
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'subtotal' => $amount,
            'tax_amount' => '0.000',
            'total' => $amount,
            'balance_due' => $amount,
            'location_id' => $location?->id,
        ]);
    }
}
