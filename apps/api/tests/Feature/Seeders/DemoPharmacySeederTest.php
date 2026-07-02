<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Database\Seeders\DemoPharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TDD test for DemoPharmacySeeder — Tunisia tenant + company + COA + tax config.
 */
final class DemoPharmacySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_tunisia_tenant_with_coa_and_tax(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () {
            $company = Company::firstOrFail();
            $this->assertSame('TN', $company->country_code);
            $this->assertSame('TND', $company->currency);

            // Tunisia COA system-purpose accounts present
            $this->assertTrue(Account::where('code', '411')->exists(), 'Account 411 (Clients) must exist');
            $this->assertTrue(Account::where('code', '401')->exists(), 'Account 401 (Fournisseurs) must exist');
            $this->assertTrue(Account::where('code', '419')->exists(), 'Account 419 (Clients créditeurs) must exist');
        });
    }

    public function test_company_has_tunisia_identity(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () {
            $company = Company::firstOrFail();
            $this->assertSame('PharmaBio Tunisie SARL', $company->name);
            $this->assertSame('TN', $company->country_code);
            $this->assertSame('TND', $company->currency);
        });
    }

    public function test_warehouse_location_created_for_tunisia(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () {
            // Task 3: 1 warehouse + 4 shops = 5 total locations
            $this->assertSame(5, Location::count(), '5 locations: 1 warehouse + 4 shops');
            $warehouse = Location::where('code', 'WH-01')->firstOrFail();
            $this->assertSame('WH-01', $warehouse->code);
            $this->assertFalse((bool) $warehouse->pos_enabled, 'Warehouse must NOT be POS-enabled');
        });
    }

    public function test_seeds_warehouse_and_four_shops_with_valid_matricule(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $locations = Location::all();
            $this->assertCount(5, $locations);
            $wh = $locations->firstWhere('code', 'WH-01');
            $this->assertSame('warehouse', $wh->type->value);
            $this->assertFalse($wh->pos_enabled);
            $this->assertNull($wh->tax_id); // inherits company

            $tunisRegex = '/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/';
            foreach (['STORE-TUN1', 'STORE-TUN2', 'STORE-SOU', 'STORE-SFA'] as $code) {
                $shop = $locations->firstWhere('code', $code);
                $this->assertNotNull($shop, "Shop location {$code} must exist");
                $this->assertSame('shop', $shop->type->value);
                $this->assertTrue($shop->pos_enabled);
                $this->assertMatchesRegularExpression($tunisRegex, str_replace('/', '', (string) $shop->tax_id));
            }
        });
    }

    public function test_tenant_central_row_has_tunisia_identity(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        // Central-DB fields — must be asserted OUTSIDE $tenant->run() which
        // swaps the default connection to the per-tenant DB.
        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();
        $this->assertSame('PharmaBio Tunisie SARL', $tenant->name);
        $this->assertSame('1234567AM000', $tenant->tax_id);
    }

    public function test_test_users_have_tunisia_email_domain(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () use ($tenant) {
            $this->assertTrue(
                User::where('email', 'owner@pharmabio.tn')->where('tenant_id', $tenant->id)->exists(),
                'owner@pharmabio.tn must exist for this tenant'
            );
            $this->assertFalse(
                User::where('email', 'owner@pharmabio.fr')->where('tenant_id', $tenant->id)->exists(),
                'owner@pharmabio.fr must NOT exist for this tenant'
            );
        });
    }

    public function test_catalog_has_a_barcode_mix(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $withBarcode = Product::whereNotNull('barcode')->count();
            $withoutBarcode = Product::whereNull('barcode')->count();
            $this->assertGreaterThan(0, $withBarcode, 'need scan-resolves demo set');
            $this->assertGreaterThan(0, $withoutBarcode, 'need no-barcode demo set');
            $sample = Product::whereNotNull('barcode')->first();
            $this->assertStringStartsWith('619', $sample->barcode); // Tunisia GS1
        });
    }

    public function test_seeds_active_unclaimed_terminals_and_scoped_cashiers(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            // Finding 2: scope terminal query by code for robustness
            $terminals = Terminal::where('code', 'POS01')->get();
            $this->assertCount(4, $terminals);
            foreach ($terminals as $t) {
                $this->assertTrue($t->is_active);
                $this->assertNull($t->hardware_identifier); // unclaimed → POS-claimable
                $this->assertNotNull($t->genesis_seed);
                $this->assertSame('POS01', $t->code);
            }

            // Finding 1: verify ALL 4 cashiers are scoped to EXACTLY their own shop
            $cashierShopPairs = [
                'tunis1.cashier@pharmabio.tn' => 'STORE-TUN1',
                'tunis2.cashier@pharmabio.tn' => 'STORE-TUN2',
                'sousse.cashier@pharmabio.tn' => 'STORE-SOU',
                'sfax.cashier@pharmabio.tn' => 'STORE-SFA',
            ];

            foreach ($cashierShopPairs as $email => $shopCode) {
                $shop = Location::where('code', $shopCode)->firstOrFail();
                $cashier = User::where('email', $email)->firstOrFail();
                $membership = UserCompanyMembership::where('user_id', $cashier->id)->firstOrFail();

                $this->assertCount(
                    1,
                    $membership->allowed_location_ids,
                    "{$email} must be scoped to exactly 1 location (got ".count($membership->allowed_location_ids).')'
                );
                $this->assertContains(
                    $shop->id,
                    $membership->allowed_location_ids,
                    "{$email} must be scoped to {$shopCode} (id={$shop->id})"
                );
            }
        });
    }

    public function test_distributes_stock_across_locations(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $wh = Location::where('code', 'WH-01')->firstOrFail();
            $tun1 = Location::where('code', 'STORE-TUN1')->firstOrFail();

            $whLevels = StockLevel::where('location_id', $wh->id)->count();
            $shopLevels = StockLevel::where('location_id', $tun1->id)->count();

            $this->assertGreaterThan($shopLevels, $whLevels, 'warehouse holds more SKUs than a shop');
            $this->assertGreaterThan(0, $shopLevels, 'STORE-TUN1 must have at least 1 stock level');

            // All 4 shops must have stock
            foreach (['STORE-TUN1', 'STORE-TUN2', 'STORE-SOU', 'STORE-SFA'] as $code) {
                $shop = Location::where('code', $code)->firstOrFail();
                $count = StockLevel::where('location_id', $shop->id)->count();
                $this->assertGreaterThan(0, $count, "{$code} must have stock levels");
            }
        });
    }

    public function test_seeds_gl_consistent_partner_balances(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $debtor = Partner::where('code', 'CUST-DEBTOR-01')->firstOrFail();
            $this->assertTrue(bccomp($debtor->receivable_balance, '0', 3) === 1, 'CUST-DEBTOR-01 has outstanding receivable > 0');

            // CUST-CREDIT-01 (CustomerAdvance / store credit) is intentionally NOT seeded:
            // PartnerBalanceService stores credit_balance as (debit - credit) on the
            // CustomerAdvance account (negative for a credit advance), but PartnerListPage
            // getNetBalance() computes (receivable - credit_balance) which inverts the sign
            // and renders a store credit as a red debt. Pre-existing production bug — tracked
            // separately. Not included in demo fixtures to avoid confusing demo users.

            // PartnerBalanceService stores payable_balance as (debit - credit) on the
            // SupplierPayable account. A normal payable is a credit entry → balance < 0
            // (negative = we owe them). The PartnerListPage renders this as green.
            $supplier = Partner::where('code', 'SUPP-PAYABLE-01')->firstOrFail();
            $this->assertTrue(bccomp($supplier->payable_balance, '0', 3) === -1, 'SUPP-PAYABLE-01 payable_balance < 0 (we owe them)');

            // GL-consistency: recompute and confirm the cached receivable column still holds
            app(PartnerBalanceService::class)
                ->refreshPartnerBalance($debtor->company_id, $debtor->id);
            $this->assertTrue(bccomp($debtor->fresh()->receivable_balance, '0', 3) === 1);
        });
    }

    public function test_seeds_purchase_order_pipeline(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $byStatus = Document::where('type', 'purchase_order')
                ->get()->groupBy(fn ($d) => $d->status->value);
            $this->assertArrayHasKey('draft', $byStatus->toArray());
            $this->assertArrayHasKey('confirmed', $byStatus->toArray()); // incl. the partially-received one
            $this->assertArrayHasKey('received', $byStatus->toArray());
            // fully-received PO has sum(quantity_received) > 0 on its lines
            $received = Document::where('type', 'purchase_order')
                ->where('status', 'received')->firstOrFail();
            $this->assertGreaterThan(0, (float) $received->lines()->sum('quantity_received'));
        });
    }

    public function test_seeds_are_idempotent_on_double_run(): void
    {
        // ---- RUN 1 ----
        $this->seed(DemoPharmacySeeder::class);

        $tenantAfterRun1 = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();
        $tenantIdAfterRun1 = $tenantAfterRun1->id;

        // Snapshot counts after run 1 — all idempotency checks below compare against these.
        $productCountRun1 = $tenantAfterRun1->run(fn () => Product::count());

        // ---- RUN 2 ----
        $this->seed(DemoPharmacySeeder::class);

        // ---- Assertions ----

        // (A) Same tenant id: the tenant was NOT deleted + recreated.
        $tenantAfterRun2 = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();
        $this->assertSame($tenantIdAfterRun1, $tenantAfterRun2->id, 'tenant must NOT be deleted+recreated on re-run');

        $tenantAfterRun2->run(function () use ($productCountRun1): void {
            // (B) Exactly 5 locations (1 warehouse + 4 shops) — no duplicates.
            $this->assertSame(5, Location::count(), 'exactly 5 locations after double run');

            // (C) Exactly 4 DEMO-PO-* purchase orders — not doubled.
            $this->assertSame(
                4,
                Document::where('document_number', 'like', 'DEMO-PO-%')->count(),
                'exactly 4 DEMO-PO-* purchase orders after double run'
            );

            // (D) Exactly 3 DEMO-TR-* transfers — not doubled.
            $this->assertSame(
                3,
                StockTransfer::where('transfer_number', 'like', 'DEMO-TR-%')->count(),
                'exactly 3 DEMO-TR-* transfers after double run'
            );

            // (E) Exactly 3 DEMO-BAL-* journal entries — not doubled.
            $this->assertSame(
                3,
                JournalEntry::where('entry_number', 'like', 'DEMO-BAL-%')->count(),
                'second run must skip — exactly 3 DEMO-BAL entries expected'
            );

            // (F) Product count is stable — no duplication.
            $productCountRun2 = Product::count();
            $this->assertSame($productCountRun1, $productCountRun2, 'product count must not grow on re-run');
        });
    }

    public function test_seeds_completed_and_in_transit_transfers(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $transfers = StockTransfer::all();
            $this->assertTrue(
                $transfers->contains(fn ($t) => $t->status->value === 'completed'),
                'at least one completed transfer must exist'
            );
            $this->assertTrue(
                $transfers->contains(fn ($t) => $t->status->value === 'in_transit'),
                'at least one in_transit transfer must exist'
            );
            // At least one transfer line exists (non-variant warehouse→shop transfer)
            $this->assertTrue(
                $transfers->flatMap->lines->isNotEmpty(),
                'at least one transfer line exists'
            );
        });
    }

    // ==================== Task 1: per-shop cashier discount authority ====================

    public function test_per_shop_cashiers_have_discount_authority(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            foreach ([
                'tunis1.cashier@pharmabio.tn',
                'tunis2.cashier@pharmabio.tn',
                'sousse.cashier@pharmabio.tn',
                'sfax.cashier@pharmabio.tn',
            ] as $email) {
                $cashier = User::where('email', $email)->firstOrFail();
                $this->assertTrue(
                    (bool) $cashier->can_discount,
                    "{$email} must be allowed to grant discounts"
                );
                $this->assertSame(
                    '10.00',
                    (string) $cashier->max_discount_percent,
                    "{$email} must carry the 10% cashier discount ceiling"
                );
            }
        });
    }

    // ==================== Task 2: recent trading activity (sales invoices + payments) ====================

    public function test_seeds_recent_sales_invoices_across_last_30_days(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $invoices = Document::where('type', DocumentType::Invoice)
                ->where('document_number', 'like', 'DEMO-INV-%')
                ->get();

            $this->assertGreaterThanOrEqual(8, $invoices->count(), 'at least 8 demo sales invoices');
            $this->assertLessThanOrEqual(12, $invoices->count(), 'at most 12 demo sales invoices');

            // Every invoice must carry real product lines and coherent money.
            foreach ($invoices as $inv) {
                $this->assertGreaterThanOrEqual(1, $inv->lines()->count(), "{$inv->document_number} must have lines");
                $this->assertLessThanOrEqual(4, $inv->lines()->count(), "{$inv->document_number} must have <=4 lines");
                // total = subtotal + VAT + stamp (all scale-3 strings).
                $expectedTotal = bcadd((string) $inv->subtotal, (string) $inv->tax_amount, 3);
                $this->assertSame($expectedTotal, (string) $inv->total, "{$inv->document_number} total = subtotal + tax");
                // tax_amount = line VAT + stamp duty.
                $expectedTax = bcadd((string) $inv->line_tax_amount, (string) $inv->stamp_duty_amount, 3);
                $this->assertSame($expectedTax, (string) $inv->tax_amount, "{$inv->document_number} tax = line VAT + stamp");
                // Tunisia commercial-invoice stamp is a flat 1.000 TND.
                $this->assertSame('1.000', (string) $inv->stamp_duty_amount, "{$inv->document_number} carries 1.000 TND stamp");
                $this->assertSame('TND', $inv->currency);
            }

            // Revenue KPI (current month) requires >=1 Posted invoice dated this month.
            $startOfMonth = Carbon::now()->startOfMonth();
            $postedThisMonth = $invoices->filter(
                fn ($i) => $i->status === DocumentStatus::Posted
                    && Carbon::parse($i->document_date)->gte($startOfMonth)
            );
            $this->assertTrue($postedThisMonth->isNotEmpty(), 'at least one Posted invoice dated in the current month');

            // Previous-month comparison requires >=1 Posted invoice before this month.
            $postedBeforeMonth = $invoices->filter(
                fn ($i) => $i->status === DocumentStatus::Posted
                    && Carbon::parse($i->document_date)->lt($startOfMonth)
            );
            $this->assertTrue($postedBeforeMonth->isNotEmpty(), 'at least one Posted invoice dated before the current month');

            // AR realism: at least one fully paid (status Paid) and 2-3 outstanding (Posted).
            $paid = $invoices->where('status', DocumentStatus::Paid);
            $posted = $invoices->where('status', DocumentStatus::Posted);
            $this->assertTrue($paid->isNotEmpty(), 'at least one fully-paid invoice');
            $this->assertGreaterThanOrEqual(2, $posted->count(), 'at least two outstanding (Posted) invoices');
        });
    }

    public function test_seeds_completed_incoming_payments_for_dashboard(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $payments = Payment::where('reference', 'like', 'DEMO-PAY-%')->get();
            $this->assertTrue($payments->isNotEmpty(), 'demo payments must exist');

            foreach ($payments as $p) {
                $this->assertSame('completed', $p->status->value, 'demo payments are completed');
                $this->assertSame('document_payment', $p->payment_type->value, 'demo payments are document payments');
                $this->assertTrue($p->payment_type->isIncoming(), 'demo payments count as incoming');
            }

            // Payments-received KPI (current month) needs >=1 payment dated this month.
            $startOfMonth = Carbon::now()->startOfMonth();
            $thisMonth = $payments->filter(fn ($p) => Carbon::parse($p->payment_date)->gte($startOfMonth));
            $this->assertTrue($thisMonth->isNotEmpty(), 'at least one payment dated in the current month');

            // Every payment is allocated to exactly the invoice it settles.
            foreach ($payments as $p) {
                $this->assertGreaterThanOrEqual(
                    1,
                    PaymentAllocation::where('payment_id', $p->id)->count(),
                    "payment {$p->reference} must have an allocation"
                );
            }
        });
    }

    public function test_recent_invoices_do_not_corrupt_seeded_debtor_balance(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $debtor = Partner::where('code', 'CUST-DEBTOR-01')->firstOrFail();

            // No DEMO-INV invoice may be attached to the GL-consistent debtor.
            $touchesDebtor = Document::where('type', DocumentType::Invoice)
                ->where('document_number', 'like', 'DEMO-INV-%')
                ->where('partner_id', $debtor->id)
                ->exists();
            $this->assertFalse($touchesDebtor, 'demo sales invoices must use dedicated customers, not CUST-DEBTOR-01');

            // The seeded receivable balance is still the untouched 900 TND (1500 invoiced − 600 paid).
            $this->assertSame(0, bccomp((string) $debtor->receivable_balance, '900', 3), 'debtor receivable stays 900 TND');
        });
    }

    public function test_recent_trading_activity_is_idempotent_on_double_run(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        [$invCount1, $payCount1] = $tenant->run(fn () => [
            Document::where('document_number', 'like', 'DEMO-INV-%')->count(),
            Payment::where('reference', 'like', 'DEMO-PAY-%')->count(),
        ]);

        $this->seed(DemoPharmacySeeder::class);

        $tenant->run(function () use ($invCount1, $payCount1) {
            $this->assertSame(
                $invCount1,
                Document::where('document_number', 'like', 'DEMO-INV-%')->count(),
                'sales invoices must not duplicate on re-run'
            );
            $this->assertSame(
                $payCount1,
                Payment::where('reference', 'like', 'DEMO-PAY-%')->count(),
                'payments must not duplicate on re-run'
            );
        });
    }
}
