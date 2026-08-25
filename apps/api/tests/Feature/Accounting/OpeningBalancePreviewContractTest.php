<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * N-3 (campaign report §N-3): API↔FE contract lock for
 * GET /api/v1/companies/{companyId}/opening-batches/{batchId}/preview.
 *
 * The wizard's BatchPreview crashed on ACCOUNTING batches because the FE type
 * claimed a `batch` header on EVERY variant while the ACCOUNTING branch emits
 * `entry`. These tests assert the EXACT key set of each of the three payload
 * variants so any future drift on either side fails here instead of in the
 * browser.
 */
final class OpeningBalancePreviewContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Preview Contract Tenant',
            'slug' => 'preview-contract-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Preview Contract Co',
            'legal_name' => 'Preview Contract Co LLC',
            'tax_id' => 'TAX-PREVIEW-1',
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
            'name' => 'Preview Admin',
            'email' => 'preview-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['accounts.view', 'accounts.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_accounting_preview_payload_key_set_is_exact(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        $batch = $this->createBatch(OpeningBatchType::Accounting);
        $this->createRow($batch, 'GL', 1, [
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'debit' => '10000.000',
            'credit' => '0.000',
            'description' => 'Cash opening',
        ]);

        $data = $this->fetchPreview($batch);

        $this->assertSame(
            ['batch_type', 'entry', 'lines', 'totals'],
            $this->sortedKeys($data),
            'ACCOUNTING preview top-level keys drifted.'
        );
        $this->assertSame('ACCOUNTING', $data['batch_type']);
        $this->assertSame(
            ['description', 'entry_date', 'is_historical', 'source_type'],
            $this->sortedKeys($data['entry'])
        );
        $this->assertSame(
            ['account_code', 'account_name', 'credit', 'debit', 'description', 'repository_code', 'repository_name', 'row_number'],
            $this->sortedKeys($data['lines'][0])
        );
        $this->assertSame(
            ['credit', 'debit', 'is_balanced'],
            $this->sortedKeys($data['totals'])
        );
        $this->assertSame('2026-01-01', $data['entry']['entry_date']);
        $this->assertTrue($data['entry']['is_historical']);
        $this->assertSame('opening_balance', $data['entry']['source_type']);
        $this->assertSame('10000.000', $data['totals']['debit']);
        $this->assertFalse($data['totals']['is_balanced']);
    }

    public function test_inventory_preview_payload_key_set_is_exact(): void
    {
        $unit = Unit::factory()->create(['decimal_places' => 3]);
        $product = Product::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create(['unit_id' => $unit->id]);

        $batch = $this->createBatch(OpeningBatchType::Inventory);
        $this->createRow($batch, 'INVENTORY', 1, [
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'product_name' => $product->name,
            'location_code' => 'WH-1',
            'location_name' => 'Warehouse',
            'quantity' => '1.2500',
            'unit_cost' => '2.000',
        ]);

        $data = $this->fetchPreview($batch);

        $this->assertSame(
            ['batch', 'batch_type', 'gl_entry', 'lines', 'totals'],
            $this->sortedKeys($data),
            'INVENTORY preview top-level keys drifted.'
        );
        $this->assertSame('INVENTORY', $data['batch_type']);
        $this->assertSame(
            ['cutover_date', 'description', 'is_historical', 'source_type'],
            $this->sortedKeys($data['batch'])
        );
        $this->assertSame(
            [
                'line_value',
                'location_code',
                'location_name',
                'product_name',
                'product_sku',
                'quantity',
                'quantity_decimals',
                'row_number',
                'unit_cost',
            ],
            $this->sortedKeys($data['lines'][0])
        );
        $this->assertSame(
            ['total_lines', 'total_quantity', 'total_value'],
            $this->sortedKeys($data['totals'])
        );
        $this->assertSame(
            ['amount', 'credit_account', 'debit_account'],
            $this->sortedKeys($data['gl_entry'])
        );
        $this->assertSame('2026-01-01', $data['batch']['cutover_date']);
        $this->assertSame(3, $data['lines'][0]['quantity_decimals']);
    }

    public function test_ar_open_items_preview_payload_key_set_is_exact(): void
    {
        $batch = $this->createBatch(OpeningBatchType::ArOpenItems);
        $this->createRow($batch, 'AR', 1, [
            'partner_code' => 'CUST-1',
            'partner_name' => 'Client Un',
            'external_invoice_number' => 'INV-2025-001',
            'document_type' => DocumentType::Invoice,
            'document_date' => '2025-12-01',
            'due_date' => '2026-01-15',
            'currency' => 'TND',
            'total' => '1200.000',
            'open_amount' => '900.000',
        ]);

        $data = $this->fetchPreview($batch);

        $this->assertSame(
            ['batch', 'batch_type', 'documents', 'note', 'totals'],
            $this->sortedKeys($data),
            'AR_OPEN_ITEMS preview top-level keys drifted.'
        );
        $this->assertSame('AR_OPEN_ITEMS', $data['batch_type']);
        $this->assertSame(
            ['batch_type', 'cutover_date', 'description', 'is_historical'],
            $this->sortedKeys($data['batch'])
        );
        $this->assertSame(
            [
                'currency',
                'document_date',
                'document_type',
                'due_date',
                'external_invoice_number',
                'open_amount',
                'partner_code',
                'partner_name',
                'row_number',
                'total',
            ],
            $this->sortedKeys($data['documents'][0])
        );
        $this->assertSame(
            ['total_amount', 'total_documents', 'total_open_amount'],
            $this->sortedKeys($data['totals'])
        );
        $this->assertSame('invoice', $data['documents'][0]['document_type']);
        $this->assertSame('900.000', $data['totals']['total_open_amount']);
    }

    /**
     * The three variants intentionally differ. This test pins the ONE key every
     * variant shares — the discriminator the frontend narrows the union on.
     */
    public function test_every_variant_carries_the_batch_type_discriminator(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        $accountingBatch = $this->createBatch(OpeningBatchType::Accounting);
        $this->createRow($accountingBatch, 'GL', 1, [
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'debit' => '1.000',
            'credit' => '0.000',
        ]);

        $apBatch = $this->createBatch(OpeningBatchType::ApOpenItems);
        $this->createRow($apBatch, 'AP', 1, [
            'partner_code' => 'SUPP-1',
            'partner_name' => 'Fournisseur Un',
            'external_invoice_number' => 'SINV-1',
            'document_type' => DocumentType::Invoice,
            'document_date' => '2025-12-01',
            'due_date' => '2026-01-15',
            'currency' => 'TND',
            'total' => '10.000',
            'open_amount' => '10.000',
        ]);

        $this->assertSame('ACCOUNTING', $this->fetchPreview($accountingBatch)['batch_type']);
        $this->assertSame('AP_OPEN_ITEMS', $this->fetchPreview($apBatch)['batch_type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPreview(OpeningBalanceBatch $batch): array
    {
        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/preview"
        );

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

    private function createBatch(OpeningBatchType $type): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => $type,
            'name' => 'Contract Batch',
            'cutover_date' => '2026-01-01',
            'status' => OpeningBatchStatus::Draft,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $mappedData
     */
    private function createRow(
        OpeningBalanceBatch $batch,
        string $rowType,
        int $rowNumber,
        array $mappedData,
    ): void {
        OpeningBalanceImportRow::create([
            'batch_id' => $batch->id,
            'row_type' => $rowType,
            'row_number' => $rowNumber,
            'status' => OpeningImportRowStatus::Valid,
            'raw_data' => [],
            'mapped_data' => $mappedData,
        ]);
    }
}
