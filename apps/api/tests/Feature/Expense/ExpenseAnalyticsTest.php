<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ExpenseAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $siblingCompany;

    private User $manager;

    private ExpenseCategory $rent;

    private ExpenseCategory $utilities;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-13 09:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'EUR']);
        $this->siblingCompany = Company::factory()->for($this->tenant)->create(['currency' => 'EUR']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = $this->userFor($this->company, 'manager@example.test');
        $this->manager->assignRole('manager');

        $this->rent = $this->category($this->company, 'Rent');
        $this->utilities = $this->category($this->company, 'Utilities');
        $this->supplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Atlas Supplies',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_explicit_window_returns_exact_tiles_categories_matrix_vendors_and_mom(): void
    {
        $this->seedSixMonthMatrix();

        $response = $this->getAnalytics([
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
        ])->assertOk();

        $response
            ->assertJsonPath('data.tiles.total', '784.00')
            ->assertJsonPath('data.tiles.count', 5)
            ->assertJsonPath('data.tiles.unpaid_total', '734.00')
            ->assertJsonPath('data.tiles.mom_delta_percent', '100.00');

        $categories = collect($response->json('data.by_category'))->keyBy('category_id');
        self::assertSame('159.00', $categories[$this->rent->id]['total']);
        self::assertSame('20.28', $categories[$this->rent->id]['share_percent']);
        self::assertSame('625.00', $categories[$this->utilities->id]['total']);
        self::assertSame('79.72', $categories[$this->utilities->id]['share_percent']);

        $matrix = collect($response->json('data.matrix'))->keyBy('category_id');
        self::assertSame([
            '2026-01' => '119.00',
            '2026-03' => '40.00',
        ], $matrix[$this->rent->id]['months']);
        self::assertSame([
            '2026-01' => '50.00',
            '2026-02' => '75.00',
            '2026-03' => '500.00',
        ], $matrix[$this->utilities->id]['months']);

        $vendors = collect($response->json('data.top_vendors'));
        $partnerVendor = $vendors->firstWhere('partner_id', $this->supplier->id);
        self::assertIsArray($partnerVendor);
        self::assertSame('Atlas Supplies', $partnerVendor['vendor_name']);
        self::assertSame('159.00', $partnerVendor['total']);

        $snapshotVendor = $vendors->firstWhere('vendor_name', 'Power Company');
        self::assertIsArray($snapshotVendor);
        self::assertNull($snapshotVendor['partner_id']);
        self::assertSame('625.00', $snapshotVendor['total']);
    }

    public function test_analytics_filters_by_parent_document_location(): void
    {
        $storeA = Location::factory()->create(['company_id' => $this->company->id]);
        $storeB = Location::factory()->create(['company_id' => $this->company->id]);
        $this->expense($this->company, $this->rent, '2026-07-01', '100.00')->update(['location_id' => $storeA->id]);
        $this->expense($this->company, $this->rent, '2026-07-02', '40.00')->update(['location_id' => $storeB->id]);

        $response = $this->getAnalytics([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'location_ids' => [$storeA->id],
        ])->assertOk();

        self::assertSame('100.00', $response->json('data.tiles.total'));
    }

    public function test_default_window_is_six_months_through_today_and_excludes_drafts(): void
    {
        $this->seedSixMonthMatrix();
        $this->expense($this->company, $this->rent, '2026-07-14', '900.00');

        $this->getAnalytics()
            ->assertOk()
            ->assertJsonPath('data.tiles.total', '615.00')
            ->assertJsonPath('data.tiles.count', 3);
    }

    public function test_status_and_category_filters_apply_to_every_aggregate(): void
    {
        $this->seedSixMonthMatrix();

        $draft = $this->getAnalytics([
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
            'status' => 'draft',
        ])->assertOk();

        $draft
            ->assertJsonPath('data.tiles.total', '300.00')
            ->assertJsonPath('data.tiles.count', 1)
            ->assertJsonCount(1, 'data.by_category')
            ->assertJsonPath('data.by_category.0.category_id', $this->rent->id)
            ->assertJsonPath('data.matrix.0.months.2026-02', '300.00')
            ->assertJsonPath('data.top_vendors.0.total', '300.00');

        $rent = $this->getAnalytics([
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
            'category_id' => $this->rent->id,
        ])->assertOk();

        $rent
            ->assertJsonPath('data.tiles.total', '159.00')
            ->assertJsonPath('data.tiles.count', 2)
            ->assertJsonCount(1, 'data.by_category')
            ->assertJsonCount(1, 'data.matrix')
            ->assertJsonCount(1, 'data.top_vendors');
    }

    public function test_all_status_sentinel_is_valid_and_aggregates_posted_and_draft_expenses(): void
    {
        $this->seedSixMonthMatrix();

        $this->getAnalytics([
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
            'status' => 'all',
        ])->assertOk()
            ->assertJsonPath('data.tiles.total', '1084.00')
            ->assertJsonPath('data.tiles.count', 6)
            ->assertJsonPath('data.matrix.0.months.2026-02', '300.00');
    }

    public function test_first_and_last_dates_are_inclusive_and_legacy_null_tax_rows_sum(): void
    {
        $this->seedSixMonthMatrix();

        $this->getAnalytics([
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ])->assertOk()
            ->assertJsonPath('data.tiles.total', '169.00')
            ->assertJsonPath('data.tiles.count', 2);

        $march = $this->getAnalytics([
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ])->assertOk()
            ->assertJsonPath('data.tiles.total', '540.00')
            ->assertJsonPath('data.tiles.count', 2);

        $matrix = collect($march->json('data.matrix'))->keyBy('category_id');
        self::assertSame('40.00', $matrix[$this->rent->id]['months']['2026-03']);
        self::assertSame('500.00', $matrix[$this->utilities->id]['months']['2026-03']);
    }

    public function test_empty_window_returns_zero_strings_null_mom_and_empty_aggregates(): void
    {
        $this->getAnalytics([
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ])->assertOk()
            ->assertJsonPath('data.tiles.total', '0.00')
            ->assertJsonPath('data.tiles.count', 0)
            ->assertJsonPath('data.tiles.unpaid_total', '0.00')
            ->assertJsonPath('data.tiles.mom_delta_percent', null)
            ->assertJsonCount(0, 'data.by_category')
            ->assertJsonCount(0, 'data.matrix')
            ->assertJsonCount(0, 'data.top_vendors');
    }

    public function test_every_aggregate_excludes_sibling_company_and_mismatched_tenant_rows(): void
    {
        $this->seedSixMonthMatrix();
        $foreignCategory = $this->category($this->siblingCompany, 'Foreign category');
        $this->expense(
            $this->siblingCompany,
            $foreignCategory,
            '2026-03-15',
            '999.00',
            vendorName: 'Foreign Vendor',
        );

        $otherTenant = Tenant::factory()->create();
        $mismatched = $this->expense($this->company, $this->rent, '2026-03-16', '888.00');
        $mismatched->update(['tenant_id' => $otherTenant->id]);

        $response = $this->getAnalytics([
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
        ])->assertOk();

        $response->assertJsonPath('data.tiles.total', '784.00');
        self::assertNotContains($foreignCategory->id, array_column($response->json('data.by_category'), 'category_id'));
        self::assertNotContains('Foreign Vendor', array_column($response->json('data.top_vendors'), 'vendor_name'));
    }

    public function test_top_vendors_falls_back_to_snapshot_without_disclosing_a_sibling_company_partner(): void
    {
        $siblingPartner = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->siblingCompany->id,
            'name' => 'Sibling Company Supplier',
        ]);
        $this->expense(
            $this->company,
            $this->rent,
            '2026-03-15',
            '33.00',
            partner: $siblingPartner,
            vendorName: 'Company A receipt snapshot',
        );

        $response = $this->getAnalytics([
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ])->assertOk();

        $vendors = collect($response->json('data.top_vendors'));
        $snapshot = $vendors->firstWhere('vendor_name', 'Company A receipt snapshot');

        self::assertIsArray($snapshot);
        self::assertNull($snapshot['partner_id']);
        self::assertNotContains($siblingPartner->id, $vendors->pluck('partner_id')->all());
        self::assertNotContains($siblingPartner->name, $vendors->pluck('vendor_name')->all());
    }

    public function test_request_validation_is_scoped_and_uses_the_canonical_error_envelope(): void
    {
        $foreignCategory = $this->category($this->siblingCompany, 'Foreign category');

        $this->getAnalytics([
            'date_from' => '2026-03-31',
            'date_to' => '2026-03-01',
            'category_id' => $foreignCategory->id,
            'status' => 'invalid',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'date_to',
                'category_id',
                'status',
            ], 'error.errors');
    }

    public function test_route_is_permission_gated_and_registered_before_the_expense_id_route(): void
    {
        $this->getAnalytics()->assertOk();

        $denied = $this->userFor($this->company, 'denied@example.test');
        $this->actingAs($denied, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/expenses/analytics')
            ->assertForbidden();
    }

    private function seedSixMonthMatrix(): void
    {
        $this->expense($this->company, $this->rent, '2025-12-31', '392.00', vendorName: 'Prior Vendor');

        $this->expense(
            $this->company,
            $this->rent,
            '2026-01-01',
            '119.00',
            subtotal: '100.00',
            taxAmount: '19.00',
            partner: $this->supplier,
            vendorName: 'Atlas receipt counter',
        );
        $this->expense(
            $this->company,
            $this->utilities,
            '2026-01-31',
            '50.00',
            isPaid: true,
            vendorName: 'Power Company',
        );
        $this->expense(
            $this->company,
            $this->rent,
            '2026-02-01',
            '300.00',
            status: DocumentStatus::Draft,
            vendorName: 'Draft Vendor',
        );
        $this->expense($this->company, $this->utilities, '2026-02-28', '75.00', vendorName: 'Power Company');
        $this->expense(
            $this->company,
            $this->rent,
            '2026-03-01',
            '40.00',
            subtotal: '40.00',
            taxAmount: null,
            partner: $this->supplier,
            vendorName: 'Legacy Atlas snapshot',
        );
        $this->expense($this->company, $this->utilities, '2026-03-31', '500.00', vendorName: 'Power Company');
    }

    private function expense(
        Company $company,
        ExpenseCategory $category,
        string $date,
        string $total,
        string $subtotal = '0.00',
        ?string $taxAmount = null,
        DocumentStatus $status = DocumentStatus::Posted,
        bool $isPaid = false,
        ?Partner $partner = null,
        ?string $vendorName = null,
    ): Document {
        $documentNumber = 'EXP-'.str_replace('-', '', $date).'-'.Document::query()->count();
        $document = Document::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'partner_id' => $partner?->id,
            'type' => DocumentType::Expense,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => $status === DocumentStatus::Posted ? FiscalStatus::Sealed : FiscalStatus::Draft,
            'status' => $status,
            'document_number' => $documentNumber,
            'document_date' => $date,
            'currency' => $company->currency,
            'subtotal' => $subtotal === '0.00' ? $total : $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $isPaid ? '0.00' : $total,
            'fiscal_hash' => hash('sha256', $documentNumber),
            'chain_sequence' => Document::query()->count() + 1,
        ]);

        ExpenseMetadata::create([
            'document_id' => $document->id,
            'expense_category_id' => $category->id,
            'is_paid' => $isPaid,
            'vendor_name' => $vendorName,
            'vat_rate' => $taxAmount === null ? null : '19.00',
            'vat_deductible_percent' => $taxAmount === null ? null : '100.00',
        ]);

        return $document;
    }

    private function category(Company $company, string $name): ExpenseCategory
    {
        return ExpenseCategory::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'name' => $name,
        ]);
    }

    private function userFor(Company $company, string $email): User
    {
        $user = User::factory()->create([
            'tenant_id' => $company->tenant_id,
            'email' => $email,
        ]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        return $user;
    }

    /** @param array<string, string> $query */
    private function getAnalytics(array $query = []): TestResponse
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/expenses/analytics'.($query === [] ? '' : '?'.http_build_query($query)));
    }
}
