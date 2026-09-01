<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class GoodsReceiptErrorMessageHygieneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Parapharmacy]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receipt message user',
            'email' => 'receipt-message@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['purchase-orders.receive', 'goods-receipt.edit-price']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Receipt message supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    public function test_paid_over_receipt_has_figure_free_uuid_free_message_and_canonical_details(): void
    {
        $po = $this->purchaseOrder($this->product('P-OVER-1'), '10.0000');
        $line = $po->lines->sole();

        $response = $this->receive($po, ['quantities' => [$line->id => '10.0001']]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'GOODS_RECEIPT_FAILED')
            ->assertJsonPath('error.reason', 'OVER_RECEIPT')
            ->assertJsonPath('error.message', 'Cannot receive more than ordered for line 1 (P-OVER-1): requested more than the remaining quantity.')
            ->assertJsonPath('error.details.ordered', '10.0000')
            ->assertJsonPath('error.details.already_received', '0.0000')
            ->assertJsonPath('error.details.requested', '10.0001')
            ->assertJsonPath('error.details.remaining', '10.0000');

        $this->assertMessageHygiene($response, permitsLineNumber: true);
    }

    public function test_batch_prepass_does_not_shadow_over_receipt_when_batch_data_is_supplied(): void
    {
        $po = $this->purchaseOrder($this->product('P-OVER-TRACKED', tracked: true), '10.0000');
        $line = $po->lines->sole();

        $this->receive($po, [
            'quantities' => [$line->id => '10.0001'],
            'batches' => [$line->id => $this->batch('LOT-OVER-TRACKED')],
        ])->assertUnprocessable()
            ->assertJsonPath('error.reason', 'OVER_RECEIPT');
    }

    public function test_free_over_receipt_uses_typed_reason_without_uuid_or_quantities_in_message(): void
    {
        $po = $this->purchaseOrder($this->product('P-FREE-OVER'), '1.0000', '1.0000');
        $line = $po->lines->sole();

        $response = $this->receive($po, [
            'quantities' => [$line->id => '0.0000'],
            'free_quantities' => [$line->id => '1.0001'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.reason', 'OVER_RECEIPT_FREE')
            ->assertJsonPath('error.details.ordered', '1.0000')
            ->assertJsonPath('error.details.requested', '1.0001');
        $this->assertMessageHygiene($response, permitsLineNumber: true);
    }

    public function test_draft_over_receipt_uses_description_without_loading_a_product(): void
    {
        $po = $this->purchaseOrder($this->product('P-DRAFT-OVER'), '2.0000', description: 'Brake cleaner');
        $line = $po->lines->sole();

        $response = $this->receive($po, [
            'save_as_draft' => true,
            'quantities' => [$line->id => '3.0000'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.reason', 'OVER_RECEIPT')
            ->assertJsonPath('error.message', 'Cannot receive more than ordered for line 1 (Brake cleaner): requested more than the remaining quantity.');
        $this->assertMessageHygiene($response, permitsLineNumber: true);
    }

    public function test_missing_batch_data_returns_typed_line_details_without_uuid(): void
    {
        $product = $this->product('P-BATCH-HYGIENE', tracked: true);
        $po = $this->purchaseOrder($product, '1.0000');
        $line = $po->lines->sole();

        $response = $this->receive($po, ['quantities' => [$line->id => '1.0000']]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.reason', 'BATCH_DATA_REQUIRED')
            ->assertJsonPath('error.details.lines.0.sku', 'P-BATCH-HYGIENE');
        $this->assertMessageHygiene($response, permitsLineNumber: true);
    }

    public function test_variant_required_refusal_names_the_loaded_product_not_its_uuid(): void
    {
        $product = $this->product('P-LOT-8', tracked: true);
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'is_active' => true,
        ]);
        $po = $this->purchaseOrder($product, '1.0000');
        $line = $po->lines->sole();

        $response = $this->receive($po, [
            'quantities' => [$line->id => '1.0000'],
            'batches' => [$line->id => $this->batch('LOT-VARIANT-REQUIRED')],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.reason', 'VARIANT_REQUIRED')
            ->assertJsonPath('error.message', 'Product P-LOT-8 on line 1 has active variants; batches must be variant-scoped — a variant_id is required.');
        $this->assertMessageHygiene($response, permitsLineNumber: true);
    }

    public function test_invalid_received_price_is_typed_and_uuid_free_during_draft_creation(): void
    {
        $po = $this->purchaseOrder($this->product('P-PRICE-DRAFT'), '1.0000');
        $line = $po->lines->sole();

        $response = $this->receive($po, [
            'save_as_draft' => true,
            'quantities' => [$line->id => '1.0000'],
            'received_unit_prices' => [$line->id => '0.000'],
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.reason', 'RECEIVED_PRICE_INVALID');
        $this->assertMessageHygiene($response, permitsLineNumber: true);
    }

    public function test_invalid_received_price_is_typed_and_uuid_free_during_post(): void
    {
        $po = $this->purchaseOrder($this->product('P-PRICE-POST'), '1.0000');
        $line = $po->lines->sole();
        $draft = app(GoodsReceiptService::class)->createDraft(
            $po,
            [$line->id => '1.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );
        GoodsReceiptLine::query()->where('goods_receipt_id', $draft->id)->update(['received_unit_price' => '0.000']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/goods-receipts/{$draft->id}/post");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'GOODS_RECEIPT_POST_FAILED')
            ->assertJsonPath('error.reason', 'RECEIVED_PRICE_INVALID');
        $this->assertMessageHygiene($response, permitsLineNumber: true);
    }

    /** @param TestResponse<Response> $response */
    private function assertMessageHygiene(TestResponse $response, bool $permitsLineNumber): void
    {
        $message = (string) $response->json('error.message');
        self::assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/i', $message));

        if ($permitsLineNumber) {
            $message = preg_replace('/line \d+/', 'line', $message) ?? $message;
        }

        self::assertSame(0, preg_match('/\d+\.\d/', $message));
    }

    private function product(string $sku, bool $tracked = false): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => $tracked,
            'cost_price' => '1.000000',
        ]);
    }

    private function purchaseOrder(
        Product $product,
        string $quantity,
        string $freeQuantity = '0.0000',
        ?string $description = null,
    ): Document {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-HYGIENE-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $quantity,
            'tax_amount' => '0.000',
            'total' => $quantity,
        ]);
        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => $description ?? $product->sku,
            'quantity' => $quantity,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity' => $freeQuantity,
            'free_quantity_received' => '0.0000',
            'unit_price' => '1.000',
            'line_total' => $quantity,
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '1.000000',
        ]);

        $po->load('lines');

        return $po;
    }

    /** @return array{batch_number: string, expiry_date: string} */
    private function batch(string $number): array
    {
        return ['batch_number' => $number, 'expiry_date' => now()->addYear()->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function receive(Document $po, array $payload): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", $payload);
    }
}
