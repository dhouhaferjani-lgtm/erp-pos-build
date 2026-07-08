<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use App\Modules\Procurement\Application\StandaloneReceiptService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StandaloneReceiptIdempotencyRecoveryTest extends TestCase
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

        $this->tenant = Tenant::create([
            'name' => 'Standalone Receipt Recovery Tenant',
            'slug' => 'standalone-receipt-recovery-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Standalone Receipt Recovery Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Standalone Recovery Receiver',
            'email' => 'standalone-recovery-'.Str::random(8).'@test.example',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SRR-WH',
            'name' => 'Standalone Receipt Recovery Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Standalone Receipt Recovery Supplier',
            'type' => PartnerType::Supplier,
        ]);

        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
            'allow_receipt_first' => true,
            'allow_invoice_first' => false,
            'invoice_first_requires_approval' => true,
        ]);
    }

    #[Test]
    public function replay_heals_missing_receipt_id_after_posted_receipt_was_created(): void
    {
        $product = $this->createProduct('SRR-HEAL');
        $service = app(StandaloneReceiptService::class);

        $first = $service->execute($this->input($product, 'srr-heal-key'));

        DB::table('procurement_idempotency_keys')
            ->where('company_id', $this->company->id)
            ->where('idempotency_key', 'srr-heal-key')
            ->update(['goods_receipt_id' => null]);

        $second = $service->execute($this->input($product, 'srr-heal-key'));

        $this->assertSame($first->receipt->id, $second->receipt->id);
        $this->assertSame($first->purchaseOrder->id, $second->purchaseOrder->id);
        $this->assertSame(GoodsReceiptStatus::Posted, $second->receipt->fresh()->status);
        $this->assertSame(DocumentStatus::Received, $second->purchaseOrder->fresh()->status);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, Document::query()->where('type', DocumentType::PurchaseOrder)->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(
            $first->receipt->id,
            DB::table('procurement_idempotency_keys')
                ->where('company_id', $this->company->id)
                ->where('idempotency_key', 'srr-heal-key')
                ->value('goods_receipt_id'),
        );
    }

    #[Test]
    public function compensation_preserves_purchase_order_and_key_when_posted_receipt_exists(): void
    {
        $product = $this->createProduct('SRR-COMP');
        $service = app(StandaloneReceiptService::class);
        $input = $this->input($product, 'srr-comp-key');

        $result = $service->execute($input);
        DB::table('procurement_idempotency_keys')
            ->where('company_id', $this->company->id)
            ->where('idempotency_key', 'srr-comp-key')
            ->update(['goods_receipt_id' => null]);

        $method = new \ReflectionMethod(StandaloneReceiptService::class, 'compensateFailedReceiptCreation');
        $method->invoke($service, $result->purchaseOrder->fresh(), $this->company->id, $input);

        $this->assertSame(DocumentStatus::Received, $result->purchaseOrder->fresh()->status);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, DB::table('procurement_idempotency_keys')->count());
        $this->assertNull(
            DB::table('procurement_idempotency_keys')
                ->where('company_id', $this->company->id)
                ->where('idempotency_key', 'srr-comp-key')
                ->value('goods_receipt_id'),
        );
    }

    private function input(Product $product, string $idempotencyKey): StandaloneReceiptInput
    {
        return new StandaloneReceiptInput(
            companyId: $this->company->id,
            supplierId: $this->supplier->id,
            locationId: $this->warehouse->id,
            actorId: $this->user->id,
            idempotencyKey: $idempotencyKey,
            source: 'standalone_receipt',
            externalReference: 'BL-SRR-001',
            externalDate: '2026-07-05',
            postImmediately: true,
            lines: [
                new StandaloneReceiptLineInput(
                    productId: $product->id,
                    variantId: null,
                    quantity: '4.0000',
                    freeQuantity: '0.0000',
                    unitPrice: '5.200',
                    batch: null,
                ),
            ],
        );
    }

    private function createProduct(string $sku): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $sku.' product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'purchase_price' => '5.200',
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }
}
