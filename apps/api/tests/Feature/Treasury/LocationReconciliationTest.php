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
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
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
    private Partner $supplier;
    private Location $locationA;
    private Location $locationB;
    private ExpenseCategory $expenseCategory;

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
        $this->supplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->expenseCategory = ExpenseCategory::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Reconciliation expense',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->givePermissionTo(['reports.view', 'treasury.view', 'instruments.view', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->actingAs($this->user, 'sanctum');
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

    public function test_payment_allocated_across_two_location_documents_reconciles(): void
    {
        $invoiceA = $this->invoiceAt($this->locationA, '140.000');
        $invoiceB = $this->invoiceAt($this->locationB, '160.000');
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'location_id' => $this->locationA->id,
            'amount' => '100.000',
            'currency' => 'TND',
        ]);
        PaymentAllocation::query()->create(['payment_id' => $payment->id, 'document_id' => $invoiceA->id, 'amount' => '60.000']);
        PaymentAllocation::query()->create(['payment_id' => $payment->id, 'document_id' => $invoiceB->id, 'amount' => '40.000']);
        $invoiceA->update(['balance_due' => '80.000']);
        $invoiceB->update(['balance_due' => '120.000']);

        $byLocation = $this->getJson('/api/v1/reports/aged-receivables?group_by=location')->assertOk()->json('data');
        $companyWide = $this->getJson('/api/v1/reports/aged-receivables')->assertOk()->json('data');

        self::assertSame('80.0000', $this->bucketTotal($byLocation, $this->locationA->id));
        self::assertSame('120.0000', $this->bucketTotal($byLocation, $this->locationB->id));
        self::assertSame(0, bccomp($this->sumLocationBuckets($byLocation['buckets_by_location']), $companyWide['grand_total'], 3));
    }

    public function test_bounce_then_reallocate_signed_sum_reconciles(): void
    {
        $invoiceA = $this->invoiceAt($this->locationA, '100.000');
        $invoiceB = $this->invoiceAt($this->locationB, '0.000');
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'location_id' => $this->locationA->id,
            'amount' => '100.000',
            'currency' => 'TND',
        ]);
        PaymentAllocation::query()->create(['payment_id' => $payment->id, 'document_id' => $invoiceA->id, 'amount' => '100.000']);
        PaymentAllocation::query()->create(['payment_id' => $payment->id, 'document_id' => $invoiceA->id, 'amount' => '-100.000']);
        PaymentAllocation::query()->create(['payment_id' => $payment->id, 'document_id' => $invoiceB->id, 'amount' => '100.000']);

        $byLocation = $this->getJson('/api/v1/reports/aged-receivables?group_by=location')->assertOk()->json('data');
        self::assertSame('100.0000', $this->bucketTotal($byLocation, $this->locationA->id));
        self::assertSame('0.0000', $this->bucketTotal($byLocation, $this->locationB->id));
    }

    public function test_instrument_in_transit_is_not_double_counted_across_grains(): void
    {
        $cash = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'cash_register',
            'location_id' => $this->locationA->id,
            'balance' => '0.000',
            'is_active' => true,
        ]);
        $bank = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'bank_account',
            'location_id' => null,
            'balance' => '80.000',
            'is_active' => true,
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'partner_id' => $this->customer->id,
            'reference' => 'TRANSIT-80',
            'amount' => '80.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'maturity_date' => now()->addDay()->toDateString(),
            'status' => InstrumentStatus::Deposited,
            'direction' => InstrumentDirection::Inbound,
            'kind' => InstrumentKind::Cheque,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $bank->id,
            'location_id' => $this->locationA->id,
            'deposited_to_id' => $bank->id,
        ]);
        $instrument->refresh();

        $echeancier = $this->getJson('/api/v1/treasury/maturing-instruments?location_ids[]='.$this->locationA->id)
            ->assertOk()->json('data');
        self::assertCount(1, array_filter($echeancier, static fn (array $row): bool => $row['id'] === $instrument->id));

        $cashData = $this->getJson('/api/v1/treasury/cash-position?group_by=location')->assertOk()->json('data');
        self::assertSame(0, bccomp($this->cashBucketTotal($cashData, null), '80.000', 3));
        self::assertSame(0, bccomp($this->cashBucketTotal($cashData, $this->locationA->id), '0.000', 3));
    }

    public function test_unattributed_reconciles_on_cash_ar_ap_and_upcoming(): void
    {
        $this->invoiceAt($this->locationA, '50.000');
        $this->invoiceAt(null, '30.000');
        $this->billAt($this->locationA, '25.000');
        $this->billAt(null, '15.000');
        $this->supplierInvoiceAt($this->locationA, '25.000');
        $this->supplierInvoiceAt(null, '15.000');
        $this->expenseAt($this->locationA, '18.000');
        $this->expenseAt(null, '12.000');
        PaymentRepository::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'type' => 'cash_register', 'location_id' => $this->locationA->id, 'balance' => '40.000', 'is_active' => true]);
        PaymentRepository::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'type' => 'bank_account', 'location_id' => null, 'balance' => '60.000', 'is_active' => true]);

        foreach ([
            '/api/v1/treasury/cash-position?group_by=location',
            '/api/v1/reports/aged-receivables?group_by=location',
            '/api/v1/reports/aged-payables?group_by=location',
        ] as $url) {
            $data = $this->getJson($url)->assertOk()->json('data');
            self::assertSame(0, bccomp($this->sumLocationBuckets($data['groups_by_location'] ?? $data['buckets_by_location']), $data['grand_total'], 3));
        }

        $upcoming = $this->getJson('/api/v1/reports/upcoming-payments?days=30&group_by=location')->assertOk()->json('data');
        self::assertSame(0, bccomp($this->sumUpcomingBuckets($upcoming['buckets_by_location'], 'total_in'), $upcoming['total_in'], 3));
        self::assertSame(0, bccomp($this->sumUpcomingBuckets($upcoming['buckets_by_location'], 'total_out'), $upcoming['total_out'], 3));
        self::assertSame('70.000', $upcoming['total_out']);
    }

    public function test_unattributed_reconciles_on_maturing_instruments_by_direction(): void
    {
        $this->instrumentAt($this->locationA, '20.000');
        $this->instrumentAt(null, '35.000');

        $body = $this->getJson('/api/v1/treasury/maturing-instruments?group_by=location')->assertOk()->json();
        self::assertSame(0, bccomp($this->sumUpcomingBuckets($body['meta']['buckets_by_location'], 'total_in'), $body['meta']['grand_total']['total_in'], 3));
        self::assertSame(0, bccomp($this->sumUpcomingBuckets($body['meta']['buckets_by_location'], 'total_out'), $body['meta']['grand_total']['total_out'], 3));
    }

    public function test_unattributed_reconciles_on_expense_analytics(): void
    {
        $this->expenseAt($this->locationA, '18.000');
        $this->expenseAt(null, '12.000');
        $all = $this->getJson('/api/v1/expenses/analytics')->assertOk()->json('data.tiles.total');
        $located = $this->getJson('/api/v1/expenses/analytics?location_ids[]='.$this->locationA->id)->assertOk()->json('data.tiles.total');
        self::assertSame('30.000', $all);
        self::assertSame('18.000', $located);
        self::assertSame(0, bccomp(bcadd($located, '12.00', 2), $all, 2));
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

    private function billAt(?Location $location, string $amount): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-'.uniqid(),
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

    private function supplierInvoiceAt(?Location $location, string $amount): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'document_number' => 'SI-'.uniqid(),
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

    private function expenseAt(?Location $location, string $amount): Document
    {
        $number = 'EXP-'.uniqid();
        $document = Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Expense,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'subtotal' => $amount,
            'tax_amount' => '0.000',
            'total' => $amount,
            'balance_due' => $amount,
            'location_id' => $location?->id,
            'fiscal_hash' => hash('sha256', $number),
            'chain_sequence' => Document::query()->count() + 1,
        ]);
        ExpenseMetadata::query()->create([
            'document_id' => $document->id,
            'expense_category_id' => $this->expenseCategory->id,
            'is_paid' => false,
            'vendor_name' => 'Reconciliation vendor',
        ]);

        return $document;
    }

    private function instrumentAt(?Location $location, string $amount): PaymentInstrument
    {
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'cash_register',
            'location_id' => $location?->id,
            'balance' => '0.000',
            'is_active' => true,
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        return PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'partner_id' => $this->customer->id,
            'reference' => 'MAT-'.uniqid(),
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'maturity_date' => now()->addDay()->toDateString(),
            'status' => InstrumentStatus::Received,
            'direction' => InstrumentDirection::Inbound,
            'kind' => InstrumentKind::Cheque,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $repository->id,
            'location_id' => $location?->id,
        ]);
    }

    /** @param array<int, array<string, mixed>> $buckets */
    private function sumLocationBuckets(array $buckets): string
    {
        return array_reduce(
            $buckets,
            static fn (string $carry, array $bucket): string => bcadd($carry, (string) $bucket['total'], 4),
            '0.0000',
        );
    }

    /** @param array<int, array<string, mixed>> $buckets */
    private function sumUpcomingBuckets(array $buckets, string $key): string
    {
        return array_reduce(
            $buckets,
            static fn (string $carry, array $bucket): string => bcadd($carry, (string) ($bucket[$key] ?? '0.000'), 3),
            '0.000',
        );
    }

    /** @param array<string, mixed> $data */
    private function bucketTotal(array $data, string $locationId): string
    {
        foreach ($data['buckets_by_location'] as $bucket) {
            if ($bucket['location_id'] === $locationId) {
                return (string) $bucket['total'];
            }
        }

        return '0.0000';
    }

    /** @param array<string, mixed> $data */
    private function cashBucketTotal(array $data, ?string $locationId): string
    {
        foreach ($data['groups_by_location'] as $bucket) {
            if ($bucket['location_id'] === $locationId) {
                return (string) $bucket['total'];
            }
        }

        return '0.000';
    }
}
