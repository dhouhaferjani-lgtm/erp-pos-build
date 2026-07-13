<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ExpenseExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Export Tenant',
            'slug' => 'export-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);
        $this->company = $this->createCompany('Company A');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = $this->createUser('exporter@example.com');
        $this->user->givePermissionTo(['expenses.view', 'expenses.export']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_export_streams_every_filtered_row_with_bom_and_raw_vat_money_columns(): void
    {
        $category = $this->createCategory($this->company, 'Office');
        $partner = $this->createPartner($this->company, 'Supplier Atlas');

        for ($index = 1; $index <= 45; $index++) {
            $expense = $this->createExpense($this->company, [
                'partner_id' => $partner->id,
                'document_number' => sprintf('EXP-%03d', $index),
                'document_date' => '2026-07-'.sprintf('%02d', (($index - 1) % 20) + 1),
                'subtotal' => '100.125',
                'tax_amount' => '19.875',
                'total' => '120.000',
                'currency' => 'TND',
            ]);
            ExpenseMetadata::create([
                'document_id' => $expense->id,
                'expense_category_id' => $category->id,
                'vendor_name' => 'Legacy vendor',
                'receipt_number' => 'RCPT-'.$index,
                'is_paid' => $index % 2 === 0,
                'vat_deductible_percent' => '80.00',
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')->get(
            '/api/v1/expenses/export?date_from=2026-07-01&date_to=2026-07-31&per_page=20'
        );

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString(
            'expenses-2026-07-01-2026-07-31.csv',
            (string) $response->headers->get('content-disposition')
        );

        $body = $response->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);

        $rows = $this->csvRows($body);
        self::assertCount(46, $rows);
        self::assertSame([
            'document_number',
            'document_date',
            'partner/vendor',
            'category',
            'status',
            'is_paid',
            'subtotal',
            'vat_amount',
            'vat_deductible_percent',
            'total',
            'currency',
            'receipt_number',
        ], $rows[0]);
        $exported = collect(array_slice($rows, 1))->first(
            static fn (array $row): bool => $row[0] === 'EXP-001'
        );
        self::assertIsArray($exported);
        self::assertSame([
            'EXP-001',
            '2026-07-01',
            'Supplier Atlas',
            'Office',
            'draft',
            '0',
            '100.125',
            '19.875',
            '80.00',
            '120.000',
            'TND',
            'RCPT-1',
        ], $exported);
    }

    public function test_export_and_index_share_status_category_date_and_search_filter_semantics(): void
    {
        $includedCategory = $this->createCategory($this->company, 'Included');
        $otherCategory = $this->createCategory($this->company, 'Other');

        $included = $this->createExpenseWithMetadata($this->company, [
            'document_number' => 'BOUNDARY-MATCH',
            'document_date' => '2026-07-31',
            'status' => DocumentStatus::Posted,
        ], [
            'expense_category_id' => $includedCategory->id,
            'vendor_name' => 'Boundary supplier',
            'receipt_number' => 'NEEDLE-001',
        ]);

        $this->createExpenseWithMetadata($this->company, [
            'document_number' => 'WRONG-STATUS',
            'document_date' => '2026-07-31',
            'status' => DocumentStatus::Draft,
        ], ['expense_category_id' => $includedCategory->id, 'receipt_number' => 'NEEDLE-002']);
        $this->createExpenseWithMetadata($this->company, [
            'document_number' => 'WRONG-CATEGORY',
            'document_date' => '2026-07-31',
            'status' => DocumentStatus::Posted,
        ], ['expense_category_id' => $otherCategory->id, 'receipt_number' => 'NEEDLE-003']);
        $this->createExpenseWithMetadata($this->company, [
            'document_number' => 'AFTER-DATE',
            'document_date' => '2026-08-01',
            'status' => DocumentStatus::Posted,
        ], ['expense_category_id' => $includedCategory->id, 'receipt_number' => 'NEEDLE-004']);
        $this->createExpenseWithMetadata($this->company, [
            'document_number' => 'BEFORE-DATE',
            'document_date' => '2026-06-30',
            'status' => DocumentStatus::Posted,
        ], ['expense_category_id' => $includedCategory->id, 'receipt_number' => 'NEEDLE-005']);
        $this->createExpenseWithMetadata($this->company, [
            'document_number' => 'WRONG-SEARCH',
            'document_date' => '2026-07-15',
            'status' => DocumentStatus::Posted,
        ], ['expense_category_id' => $includedCategory->id, 'receipt_number' => 'HAYSTACK']);

        $query = http_build_query([
            'status' => 'posted',
            'category_id' => $includedCategory->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'search' => 'NEEDLE',
        ]);

        $indexResponse = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/expenses?'.$query);
        $indexResponse->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $included->id);

        $exportResponse = $this->actingAs($this->user, 'sanctum')->get('/api/v1/expenses/export?'.$query);
        $exportResponse->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        self::assertCount(2, $rows);
        self::assertSame('BOUNDARY-MATCH', $rows[1][0]);
        self::assertSame('2026-07-31', $rows[1][1]);
    }

    public function test_export_excludes_sibling_company_and_non_expense_documents(): void
    {
        $otherCompany = $this->createCompany('Company B');
        $this->createExpenseWithMetadata($this->company, ['document_number' => 'COMPANY-A'], []);
        $this->createExpenseWithMetadata($otherCompany, ['document_number' => 'COMPANY-B'], []);
        $this->createExpenseWithMetadata($this->company, [
            'document_number' => 'NOT-EXPENSE',
            'type' => DocumentType::Invoice,
        ], []);

        $response = $this->actingAs($this->user, 'sanctum')->get('/api/v1/expenses/export');
        $response->assertOk();
        $body = $response->streamedContent();

        self::assertStringContainsString('COMPANY-A', $body);
        self::assertStringNotContainsString('COMPANY-B', $body);
        self::assertStringNotContainsString('NOT-EXPENSE', $body);
    }

    public function test_export_keeps_legacy_null_fields_stable_and_does_not_disclose_cross_company_partner(): void
    {
        $otherCompany = $this->createCompany('Company B');
        $foreignPartner = $this->createPartner($otherCompany, 'Foreign Partner');
        $expense = $this->createExpense($this->company, [
            'partner_id' => $foreignPartner->id,
            'document_number' => 'LEGACY-001',
            'subtotal' => '42.000',
            'tax_amount' => null,
            'total' => '42.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'vendor_name' => 'Safe Vendor Fallback',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->get('/api/v1/expenses/export');
        $response->assertOk();
        $rows = $this->csvRows($response->streamedContent());

        self::assertSame('Safe Vendor Fallback', $rows[1][2]);
        self::assertSame('', $rows[1][3]);
        self::assertSame('', $rows[1][7]);
        self::assertSame('', $rows[1][8]);
        self::assertSame('', $rows[1][11]);
        self::assertStringNotContainsString('Foreign Partner', implode(',', $rows[1]));
    }

    public function test_export_requires_expenses_export_permission(): void
    {
        $viewer = $this->createUser('viewer@example.com');
        $viewer->givePermissionTo('expenses.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/expenses/export')
            ->assertForbidden();
    }

    private function createCompany(string $name): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'TAX-'.uniqid(),
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createUser(string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Export User',
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'accountant',
        ]);

        return $user;
    }

    private function createCategory(Company $company, string $name): ExpenseCategory
    {
        return ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => $name,
        ]);
    }

    private function createPartner(Company $company, string $name): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => $name,
            'type' => PartnerType::Supplier,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createExpense(Company $company, array $attributes = []): Document
    {
        return Document::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => null,
            'type' => DocumentType::Expense,
            'document_number' => 'EXP-'.uniqid(),
            'document_date' => '2026-07-15',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $documentAttributes
     * @param  array<string, mixed>  $metadataAttributes
     */
    private function createExpenseWithMetadata(
        Company $company,
        array $documentAttributes,
        array $metadataAttributes,
    ): Document {
        $expense = $this->createExpense($company, $documentAttributes);
        ExpenseMetadata::create(array_merge(['document_id' => $expense->id], $metadataAttributes));

        return $expense;
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            /** @var list<string> $row */
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
