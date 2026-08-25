<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Reports;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatIntent;
use App\Shared\Contracts\Treasury\RepositoryOpeningBalanceSeederInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class UpcomingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private User $user;

    private User $userWithoutPermission;

    private Partner $customer;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-02'));

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $this->otherCompany = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([$this->user, $this->userWithoutPermission] as $user) {
            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $this->company->id,
                'role' => 'admin',
            ]);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('reports.operational', 'sanctum');
        $this->user->givePermissionTo('reports.operational');
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->customer = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'name' => 'Clinique Ennasr',
        ]);
        $this->supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Supplier,
            'name' => 'Medis Distribution',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_upcoming_payments_report_lists_in_out_overdue_fallbacks_totals_and_is_company_scoped(): void
    {
        $this->createDocument('INV-DUE-10', DocumentType::Invoice, $this->customer, '119.000', '2026-07-12');
        $this->createDocument('INV-OVERDUE', DocumentType::Invoice, $this->customer, '50.000', '2026-06-30');
        $this->createDocument('INV-NULL-DUE', DocumentType::Invoice, $this->customer, '20.000', null, '2026-07-05');
        $this->createDocument('INV-PAID', DocumentType::Invoice, $this->customer, '0.000', '2026-07-09');
        $this->createDocument('INV-BEYOND', DocumentType::Invoice, $this->customer, '999.000', '2026-08-20');

        $this->createDocument('SUP-DUE-7', DocumentType::SupplierInvoice, $this->supplier, '70.000', '2026-07-09');

        // Real expense path: ExpenseService never sets balance_due, so the report
        // must source unpaid expenses from expense_metadata.is_paid instead.
        $expenseService = app(ExpenseService::class);
        $unpaidExpense = $expenseService->create([
            'company_id' => $this->company->id,
            'total' => '30.000',
            'payment_date' => '2026-07-05',
            'is_paid' => false,
            'vendor_name' => 'STEG',
        ], $this->user);
        $unpaidExpense = $expenseService->post($unpaidExpense, $this->user);
        $unpaidExpenseNumber = $unpaidExpense->document_number;
        self::assertNull($unpaidExpense->balance_due, 'Precondition: real expenses never populate balance_due');

        // W4-10: a cash-paid expense must name the repository the money left.
        // This test's subject is the unpaid/paid split in the report, not the
        // payment shape, so the paid fixture simply names a till.
        $till = PaymentRepository::forceCreate([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-UPCOMING',
            'name' => 'Caisse',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
        ]);

        // ...and a till only pays out what it holds, so give it its day-one
        // float through the one sanctioned path (W4-2).
        // gate r1 F-12: the port asserts that batchId names a REAL opening
        // batch of this tenant+company — an opening movement with no document
        // behind it is what document-per-action forbids — so post one.
        $openingBatch = OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Accounting,
            'name' => 'Fixture opening CASH-UPCOMING',
            'cutover_date' => CarbonImmutable::now()->subYears(2)->toDateString(),
            'status' => OpeningBatchStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        DB::transaction(function () use ($till, $openingBatch): void {
            app(RepositoryOpeningBalanceSeederInterface::class)->seed(new OpeningFloatIntent(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                repositoryId: $till->id,
                amount: '100.000',
                currency: (string) $this->company->currency,
                batchId: $openingBatch->id,
                occurredAt: CarbonImmutable::now()->subYears(2),
                journalEntryId: null,
                createdBy: $this->user->id,
            ));
        });

        $paidExpense = $expenseService->create([
            'company_id' => $this->company->id,
            'total' => '45.000',
            'payment_date' => '2026-07-06',
            'is_paid' => true,
            'payment_repository_id' => $till->id,
            'vendor_name' => 'Tunisie Telecom',
        ], $this->user);
        $paidExpense = $expenseService->post($paidExpense, $this->user);

        $otherPartner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->otherCompany->id,
            'type' => PartnerType::Customer,
        ]);
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'OTHER-INV',
            'document_date' => '2026-07-02',
            'due_date' => '2026-07-03',
            'currency' => 'TND',
            'subtotal' => '500.000',
            'tax_amount' => '0.000',
            'total' => '500.000',
            'balance_due' => '500.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/reports/upcoming-payments?days=30');

        $response->assertOk();
        $response->assertJsonPath('data.total_in', '189.000');
        $response->assertJsonPath('data.total_out', '100.000');
        $response->assertJsonPath('data.net', '89.000');
        $response->assertJsonPath('data.in.0.document_number', 'INV-OVERDUE');
        $response->assertJsonPath('data.in.0.days_until_due', -2);
        $response->assertJsonPath('data.in.0.overdue', true);
        $response->assertJsonPath('data.in.2.document_number', 'INV-DUE-10');
        $response->assertJsonPath('data.in.2.days_until_due', 10);
        $response->assertJsonPath('data.out.0.document_number', $unpaidExpenseNumber);
        $response->assertJsonPath('data.out.0.partner_name', 'STEG');
        $response->assertJsonPath('data.out.0.balance_due', '30.000');
        $response->assertJsonPath('data.out.1.document_number', 'SUP-DUE-7');
        $response->assertJsonMissingPath('data.out.2');
        $response->assertJsonMissing(['document_number' => $paidExpense->document_number]);
        $response->assertJsonMissingPath('data.in.3');
        $response->assertJsonMissing(['document_number' => 'INV-PAID']);
        $response->assertJsonMissing(['document_number' => 'INV-BEYOND']);
        $response->assertJsonMissing(['document_number' => 'OTHER-INV']);
    }

    public function test_upcoming_payments_location_filter_uses_document_location(): void
    {
        $storeA = Location::factory()->create(['company_id' => $this->company->id]);
        $storeB = Location::factory()->create(['company_id' => $this->company->id]);
        $this->createDocument('INV-A', DocumentType::Invoice, $this->customer, '100.000', '2026-07-05')->update(['location_id' => $storeA->id]);
        $this->createDocument('INV-B', DocumentType::Invoice, $this->customer, '40.000', '2026-07-05')->update(['location_id' => $storeB->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/reports/upcoming-payments?location_ids[]='.$storeA->id);

        $response->assertOk()->assertJsonPath('data.total_in', '100.000');
    }

    /**
     * W-6 D2 — `documents.balance_due` is a PostgreSQL trigger cache fired by
     * allocation DML only, so a posted invoice that was never allocated against
     * keeps a NULL cache forever. This report's `where('balance_due', '>', 0)`
     * hid exactly those documents from the cash-flow forecast, the same blindness
     * the aged reports carried.
     */
    public function test_upcoming_payments_sees_a_posted_never_allocated_invoice(): void
    {
        $neverAllocated = $this->createDocument('INV-NEVER-PAID', DocumentType::Invoice, $this->customer, '119.000', '2026-07-12');
        $neverAllocated->update(['balance_due' => null]);

        $partiallyPaid = $this->createDocument('INV-PARTIAL', DocumentType::Invoice, $this->customer, '200.000', '2026-07-14');
        $partiallyPaid->update(['balance_due' => null]);
        PaymentAllocation::create(['document_id' => $partiallyPaid->id, 'amount' => '50.000']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/reports/upcoming-payments?days=30');

        $response->assertOk();
        $response->assertJsonPath('data.total_in', '269.000');
        $response->assertJsonPath('data.in.0.document_number', 'INV-NEVER-PAID');
        $response->assertJsonPath('data.in.0.balance_due', '119.000');
        $response->assertJsonPath('data.in.1.document_number', 'INV-PARTIAL');
        $response->assertJsonPath('data.in.1.balance_due', '150.000');
    }

    public function test_upcoming_payments_report_requires_reports_operational_permission(): void
    {
        $response = $this->actingAs($this->userWithoutPermission, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/reports/upcoming-payments');

        $response->assertForbidden();
    }

    private function createDocument(
        string $number,
        DocumentType $type,
        ?Partner $partner,
        string $balanceDue,
        ?string $dueDate,
        string $documentDate = '2026-07-02',
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner?->id,
            'type' => $type,
            'status' => DocumentStatus::Posted,
            'document_number' => $number,
            'document_date' => $documentDate,
            'due_date' => $dueDate,
            'currency' => 'TND',
            'subtotal' => $balanceDue,
            'tax_amount' => '0.000',
            'total' => $balanceDue,
            'balance_due' => $balanceDue,
        ]);
    }
}
