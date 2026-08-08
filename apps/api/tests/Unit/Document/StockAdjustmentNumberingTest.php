<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DPA V7 / T9 — the ADJ series (plan D13).
 *
 * `generateForKey($tenantId, $companyId, 'stock_adjustment', 'ADJ')`, the
 * GoodsReceiptService recipe — NOT StockTransferService::generateTransferNumber(),
 * whose racy `count() + 1` this deliberately avoids.
 */
final class StockAdjustmentNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private DocumentNumberingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'ADJ Numbering Tenant',
            'slug' => 'adj-numbering-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = $this->company('ADJ Numbering Company', 'ADJ-TAX-1');
        $this->service = new DocumentNumberingService;
    }

    #[Test]
    public function the_adj_sequence_uses_the_adj_prefix_and_increments_sequentially(): void
    {
        $year = date('Y');

        $first = $this->generate($this->company);
        $second = $this->generate($this->company);
        $third = $this->generate($this->company);

        $this->assertSame("ADJ-{$year}-0001", $first);
        $this->assertSame("ADJ-{$year}-0002", $second);
        $this->assertSame("ADJ-{$year}-0003", $third);

        // varchar(30) on the column; the format yields 12 characters.
        $this->assertLessThanOrEqual(30, strlen($first));
    }

    #[Test]
    public function the_adj_sequence_is_isolated_from_goods_receipt_and_purchase_order(): void
    {
        $year = date('Y');

        $this->service->generateForKey($this->tenant->id, $this->company->id, 'goods_receipt', 'GRN');
        $this->service->generateForKey($this->tenant->id, $this->company->id, 'goods_receipt', 'GRN');
        $this->service->generateForKey($this->tenant->id, $this->company->id, 'purchase_order', 'PO');

        $this->assertSame("ADJ-{$year}-0001", $this->generate($this->company));
    }

    #[Test]
    public function the_adj_sequence_is_isolated_per_company(): void
    {
        $year = date('Y');
        $other = $this->company('ADJ Numbering Company Two', 'ADJ-TAX-2');

        $this->assertSame("ADJ-{$year}-0001", $this->generate($this->company));
        $this->assertSame("ADJ-{$year}-0002", $this->generate($this->company));
        $this->assertSame("ADJ-{$year}-0001", $this->generate($other));
    }

    /**
     * D13's inherited caveat, TESTED rather than fixed: `generateForKeyOnce()`
     * opens its own DB::transaction, which degrades to a savepoint when called
     * inside post(), so the unique-violation retry would run inside an
     * already-poisoned PostgreSQL transaction if the first attempt raced on the
     * FIRST-EVER `(company, type, year)` row. Goods receipts have shipped with
     * this. What is asserted here is that the first-ever row is created cleanly
     * and the sequence continues from it.
     */
    #[Test]
    public function the_first_ever_row_is_created_and_the_sequence_continues_from_it(): void
    {
        $year = date('Y');

        $this->assertDatabaseMissing('document_sequences', [
            'company_id' => $this->company->id,
            'type' => 'stock_adjustment',
        ]);

        $this->assertSame("ADJ-{$year}-0001", $this->generate($this->company));

        $this->assertDatabaseHas('document_sequences', [
            'company_id' => $this->company->id,
            'type' => 'stock_adjustment',
            'year' => (int) $year,
            'last_number' => 1,
        ]);

        $this->assertSame("ADJ-{$year}-0002", $this->generate($this->company));
    }

    private function generate(Company $company): string
    {
        return $this->service->generateForKey($this->tenant->id, $company->id, 'stock_adjustment', 'ADJ');
    }

    private function company(string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' SARL',
            'tax_id' => $taxId,
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
    }
}
