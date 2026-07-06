<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptData;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoodsReceiptDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function from_model_serializes_receipt_and_lines_with_decimal_strings(): void
    {
        [$receipt] = $this->createReceiptGraph();

        $data = GoodsReceiptData::fromModel($receipt->fresh(['lines']) ?? $receipt);
        $array = $data->toArray();

        $this->assertSame($receipt->id, $array['id']);
        $this->assertSame('posted', $array['status']);
        $this->assertSame('GRN-2026-0001', $array['receipt_number']);
        $this->assertCount(1, $array['lines']);
        $this->assertSame('2.5000', $array['lines'][0]['received_qty']);
        $this->assertSame('1.0000', $array['lines'][0]['free_qty']);
        $this->assertSame('5.200', $array['lines'][0]['received_unit_price']);
        $this->assertSame('5.200000', $array['lines'][0]['landed_unit_cost']);
        $this->assertSame('5.200000', $array['lines'][0]['accrual_unit_cost']);
        $this->assertSame('3.714286', $array['lines'][0]['effective_unit_cost']);
        $this->assertSame('0.0000', $array['lines'][0]['quantity_invoiced']);
        $this->assertContainsOnlyDecimalStrings($array);
    }

    #[Test]
    public function from_model_can_omit_lines_for_receive_response_meta(): void
    {
        [$receipt] = $this->createReceiptGraph();

        $array = GoodsReceiptData::fromModel($receipt, withLines: false)->toArray();

        $this->assertSame($receipt->id, $array['id']);
        $this->assertSame([], $array['lines']);
    }

    /**
     * @return array{GoodsReceipt, GoodsReceiptLine}
     */
    private function createReceiptGraph(): array
    {
        $tenant = Tenant::create([
            'name' => 'GR DTO Tenant',
            'slug' => 'gr-dto-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'GR DTO Company',
            'legal_name' => 'GR DTO Company SARL',
            'tax_id' => 'GR-DTO-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sku' => 'GR-DTO',
            'name' => 'GR DTO Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
        ]);

        $po = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-GR-DTO',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '13.000',
            'tax_amount' => '0.000',
            'total' => '13.000',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '2.5000',
            'free_quantity' => '1.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '2.5000',
            'free_quantity_received' => '1.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.200',
            'line_total' => '13.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '5.200000',
        ]);

        $receipt = GoodsReceipt::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'purchase_order_id' => $po->id,
            'receipt_number' => 'GRN-2026-0001',
            'status' => GoodsReceiptStatus::Posted,
            'received_at' => now(),
            'received_by' => null,
            'payload' => ['source' => 'test'],
        ]);

        $line = GoodsReceiptLine::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'goods_receipt_id' => $receipt->id,
            'po_line_id' => $poLine->id,
            'product_id' => $product->id,
            'received_qty' => '2.5000',
            'free_qty' => '1.0000',
            'received_unit_price' => '5.200',
            'landed_unit_cost' => '5.200000',
            'accrual_unit_cost' => '5.200000',
            'effective_unit_cost' => '3.714286',
            'quantity_invoiced' => '0.0000',
        ]);

        return [$receipt, $line];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertContainsOnlyDecimalStrings(array $payload): void
    {
        array_walk_recursive($payload, function (mixed $value, string $key): void {
            if (str_ends_with($key, '_qty')
                || str_ends_with($key, '_cost')
                || str_ends_with($key, '_price')
                || $key === 'quantity_invoiced') {
                $this->assertIsString($value, "Expected {$key} to serialize as a string.");
                $this->assertMatchesRegularExpression('/^-?\d+\.\d+$/', $value);
            }
        });
    }
}
