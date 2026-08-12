<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Voucher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T11c ruled-red production trace for pairs 9/10. This class deliberately
 * stays outside the shared PG merge-gate class allowlist until T16e makes the
 * assertions green in M2.
 */
final class InventoryGlVoucherLockOrderTraceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('[PG] T11c voucher lock-order traces require PostgreSQL advisory locks.');
        }

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $this->companyId = $company->id;
        $location = Location::factory()->create(['company_id' => $company->id]);
        $this->locationId = $location->id;
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
            'fiscal_schema_version' => 3,
        ]);
        $this->terminalId = $terminal->id;
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->operatorId = $operator->id;
        app(CompanyContext::class)->setCompanyId($company->id);

        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'STORE_VOUCHER',
            'name' => 'Store Voucher',
        ]);
        Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '4196',
            'name' => 'Voucher Liability',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VoucherLiability,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '5801',
            'name' => 'POS Tender Clearing',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::PosTenderClearing,
            'is_active' => true,
        ]);
        Voucher::factory()->forTerminal($terminal)->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'T11C-VOUCHER',
            'currency' => 'EUR',
            'initial_balance' => '50.00',
            'current_balance' => '50.00',
            'issued_by_user_id' => $operator->id,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function t11cVoucherPairs(): iterable
    {
        yield 'pair 9 — voucher POS sale x DN confirm' => ['voucher-pos-sale_x_dn-confirm'];
        yield 'pair 10 — voucher POS sale x POS sale' => ['voucher-pos-sale_x_pos-sale'];
    }

    #[DataProvider('t11cVoucherPairs')]
    public function test_voucher_company_advisory_is_terminal_to_stock_projection(string $pair): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        StockLevel::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);
        $sale = $this->saleEvent($product->id);

        /** @var list<array{sql: string, bindings: array<int, mixed>}> $trace */
        $trace = [];
        DB::listen(static function (QueryExecuted $query) use (&$trace): void {
            $trace[] = ['sql' => strtolower($query->sql), 'bindings' => array_values($query->bindings)];
        });

        app(PosCoreReceiptProjection::class)->apply($sale);

        $firstCompanyAdvisory = null;
        $lastInventoryStatement = null;
        foreach ($trace as $index => $query) {
            if ($firstCompanyAdvisory === null
                && str_contains($query['sql'], 'pg_advisory_xact_lock(hashtextextended')
                && ($query['bindings'][0] ?? null) === $this->companyId) {
                $firstCompanyAdvisory = $index;
            }
            if (str_contains($query['sql'], '"stock_levels"')
                || str_contains($query['sql'], '"stock_movements"')) {
                $lastInventoryStatement = $index;
            }
        }

        self::assertNotNull($firstCompanyAdvisory, "{$pair}: production trace did not reach voucher GL.");
        self::assertNotNull($lastInventoryStatement, "{$pair}: production trace did not reach stock projection.");
        self::assertGreaterThan(
            $lastInventoryStatement,
            $firstCompanyAdvisory,
            "{$pair}: T16e missing — production redeemed the voucher and acquired company GL before stock projection. "
            ."first_company_advisory={$firstCompanyAdvisory}, last_inventory={$lastInventoryStatement}",
        );
    }

    private function saleEvent(string $productId): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => $this->operatorId,
            'cashier_name' => 'T11c Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '0.00',
                'name' => 'Voucher trace item',
                'non_collected_subtype' => null,
                'product_id' => $productId,
                'quantity' => '1.000',
                'sku' => 'T11C-SKU',
                'tax_category_code' => 'Z',
                'unit_price' => '10.00',
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '10.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => 'T11C-VOUCHER',
                'instrument_type' => 'store_voucher',
                'method_code' => 'STORE_VOUCHER',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000009',
            'seller' => [
                'address' => [
                    'city' => 'Paris',
                    'country_code' => 'FR',
                    'postal_code' => '75001',
                    'street' => '1 rue de la Paix',
                ],
                'name' => 'T11c Seller',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => '10.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '10.00',
                'net_amount' => '10.00',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];
        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);

        return FiscalEvent::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 3,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'server_received_at' => $eventTime,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', $canonicalBytes.'1'),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }
}
