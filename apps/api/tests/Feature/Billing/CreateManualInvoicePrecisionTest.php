<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\SuperAdmin;
use App\Modules\Billing\Application\Services\InvoiceService;
use App\Modules\Billing\Domain\Enums\InvoiceStatus;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\InvoiceItem;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Precision regression: InvoiceService::createManualInvoice subtotal accumulation.
 *
 * Before the fix:
 *   $subtotal = 0;
 *   $itemAmount = (float) $item['amount'] * $quantity;
 *   $subtotal += $itemAmount;
 *
 * After the fix: bcmath accumulation (bcmul at scale+1, bcadd accumulate, bcformat at boundary).
 *
 * Gold assertion (EUR, scale 2, intermediate scale 3):
 *   Items: 3 × [amount='33.3335', qty=1]
 *
 *   bcmath line  = bcmul('33.3335', '1', 3) = '33.333'   (truncates at scale 3)
 *   bcmath total = bcadd(bcadd('33.333', '33.333', 3), '33.333', 3) = '99.999'
 *
 *   float line   = (float)'33.3335' * 1 = 33.3335  → decimal:3 stored = '33.334' (rounds up)
 *   float total  = 33.3335 + 33.3335 + 33.3335 = 100.0005 → decimal:3 stored = '100.001'
 *
 * The two paths diverge at the item level ('33.333' vs '33.334') and at the
 * subtotal level ('99.999' vs '100.001').
 */
final class CreateManualInvoicePrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Override tax rate to 0% via config so tax calculation does not
        // complicate the subtotal assertion. No CountryTaxRate insert needed
        // (avoids the FK constraint on countries.code).
        Config::set('billing.default_tax_rate', 0.0);
        Config::set('billing.default_country', 'XT');  // fictional — no DB row

        $this->tenant = Tenant::create([
            'name' => 'EUR Billing Precision Tenant',
            'slug' => 'eur-billing-precision-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'country_code' => null,  // triggers config fallback → 'XT' → no DB rate → 0%
            'email' => 'billing-test-'.uniqid().'@example.com',
            'address' => ['line1' => '1 Rue de la Paix', 'city' => 'Paris'],
        ]);
    }

    /**
     * Three EUR items at '33.3335' each must accumulate via bcmath.
     *
     * Float path (BROKEN):
     *   itemAmount = (float)'33.3335' * 1 = 33.3335
     *   → InvoiceItem.amount decimal:3 cast = '33.334' (rounds up)
     *   → subtotal decimal:3 cast = '100.001'  (100.0005 rounded)
     *
     * Bcmath path (CORRECT, EUR scale 2, intermediate scale 3):
     *   lineAmount = bcmul('33.3335', '1', 3) = '33.333'  (truncates at scale 3)
     *   subtotal   = bcadd(bcadd('33.333', '33.333', 3), '33.333', 3) = '99.999'
     *   → InvoiceItem.amount decimal:3 stored = '33.333'
     *   → Invoice.subtotal decimal:3 stored   = '99.999'
     */
    public function test_manual_invoice_subtotal_uses_bcmath_accumulation(): void
    {
        $service = app(InvoiceService::class);

        $invoice = $service->createManualInvoice(
            tenant: $this->tenant,
            items: [
                ['description' => 'Item A', 'amount' => '33.3335', 'quantity' => 1],
                ['description' => 'Item B', 'amount' => '33.3335', 'quantity' => 1],
                ['description' => 'Item C', 'amount' => '33.3335', 'quantity' => 1],
            ],
            notes: 'bcmath precision regression test',
        );

        // Force reload from DB to verify the stored string (not in-memory object)
        $invoice->refresh();

        // subtotal must be '99.999' (bcmath), NOT '100.001' (float+decimal:3-rounds-up)
        $this->assertSame(
            '99.999',
            $invoice->subtotal,
            'subtotal is "100.001" — service still uses float cast. '
            .'Fix: use bcmul+bcadd accumulation with scaleResolver.'
        );

        // total = subtotal + tax (0% → 0) = 99.999
        $this->assertSame(
            '99.999',
            $invoice->total,
            'total must equal subtotal when tax rate is 0%.'
        );

        // Verify each InvoiceItem.amount was computed with bcmath (truncated, not rounded)
        $items = $invoice->items()->orderBy('sort_order')->get();
        $this->assertCount(3, $items);

        // bcmul('33.3335', '1', 3) = '33.333' — bcmath truncates the 4th decimal
        // (float)'33.3335' * 1 → decimal:3 = '33.334' — float rounds up
        foreach ($items as $index => $item) {
            $this->assertSame(
                '33.333',
                $item->amount,
                "Item {$index} amount is '33.334' (float-rounded) instead of '33.333' (bcmath-truncated)."
            );
        }
    }

    public function test_subscription_invoice_formats_amounts_to_currency_scale_before_persisting(): void
    {
        $service = app(InvoiceService::class);

        $plan = Plan::create([
            'code' => 'SUB-PRECISION-'.strtoupper(substr(Str::uuid()->toString(), 0, 8)),
            'name' => 'Subscription Precision Plan',
            'limits' => [],
            'price_monthly' => '10.999',
            'currency' => 'EUR',
            'trial_days' => 0,
            'is_active' => true,
            'is_public' => false,
            'display_order' => 100,
        ]);
        $subscription = TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => 'monthly',
            'price' => '10.999',
            'currency' => 'EUR',
            'metadata' => [],
        ]);

        $invoice = $service->createSubscriptionInvoice($subscription);
        $invoice->refresh();

        // Invoice columns are decimal:3, so the DB exposes the service's EUR
        // scale-2 write as 10.990. The old raw-price write would be 10.999.
        $this->assertSame('10.990', $invoice->subtotal);
        $this->assertSame('10.990', $invoice->total);
        $this->assertSame('10.990', $invoice->amount_due);
        $this->assertSame('10.990', $invoice->items()->sole()->amount);
    }

    /**
     * Invoice::recordPayment must accept a string amount (P0-3).
     *
     * The old signature was `recordPayment(float $amount)`. Calling it with a
     * string literal from a declare(strict_types=1) context throws TypeError —
     * that TypeError IS the failing-test signal (red phase).
     *
     * After the fix, `recordPayment(string $amount)` accepts strings and uses
     * bcmath so the status gate `bccomp($due,'0',$scale) <= 0` flips to Paid
     * without IEEE-754 drift.
     *
     * Discriminating arithmetic: three payments of '33.330' on a '99.990' invoice.
     *   bcadd: '0.000'+'33.330'=>'33.33', +'33.330'=>'66.66', +'33.330'=>'99.99'
     *   bcsub('99.990','99.99',2) = '0.00' → bccomp('0.00','0',2)=0 ≤ 0 → Paid.
     */
    public function test_record_payment_accepts_string_and_status_gate_uses_bccomp(): void
    {
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'number' => 'GATE-'.uniqid('', true),
            'status' => InvoiceStatus::Sent,
            'subtotal' => '99.990',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '99.990',
            'amount_paid' => '0.000',
            'amount_due' => '99.990',
            'currency' => 'EUR',
            'tax_rate' => '0.00',
            'billing_address' => [],
            'billing_email' => 'gate@example.com',
            'billing_name' => 'Gate Test',
            'invoice_date' => now(),
            'due_date' => now()->addDays(14),
        ]);

        // strict_types=1 in this file: old float param → TypeError (test FAIL).
        // New string param: accepted, bcmath gate fires.
        $invoice->recordPayment('33.330');
        $invoice->recordPayment('33.330');
        $invoice->recordPayment('33.330');

        $invoice->refresh();

        $this->assertSame(
            InvoiceStatus::Paid,
            $invoice->status,
            'Invoice stuck at PartiallyPaid: recordPayment still uses float arithmetic / float param.'
        );
        $this->assertSame('0.000', $invoice->amount_due);
        $this->assertSame('99.990', $invoice->amount_paid);
    }

    /**
     * Payment::getRefundableAmount() must return a string, not a float (P0-3).
     *
     * The old signature was `: float`. assertIsString() fails on old code.
     * After fix: `bcsub((string)amount,(string)refunded,scale)` returns a numeric-string.
     */
    public function test_get_refundable_amount_returns_string_and_is_exact(): void
    {
        $payment = new Payment;
        $payment->forceFill([
            'amount' => '100.000',
            'refunded_amount' => '30.000',
            'currency' => 'EUR',
            'status' => PaymentStatus::Succeeded,
        ]);

        $result = $payment->getRefundableAmount();

        $this->assertIsString(
            $result,
            'getRefundableAmount() must return string (numeric), not float.'
        );
        // bcsub('100.000','30.000',2) = '70.00' — exact at EUR scale 2
        $this->assertSame('70.00', $result);
    }

    /**
     * Invoice::recalculateTotals() must accumulate via bcmath, not float SQL SUM (P0-3).
     *
     * Before the fix:
     *   $subtotal = $this->items()->sum('amount');   // Builder::sum() → PHP float
     *   $total    = $subtotal + $taxAmount - ...;    // native float arithmetic
     *   amount_due = $total - (float) $this->amount_paid;
     *
     * After the fix: bcadd accumulation at EUR scale 2, bcsub for amount_due.
     *
     * Discriminating values — 3 items each with amount '33.333' (EUR, scale 2):
     *
     *   Float / SQL SUM path:
     *     Builder::sum('amount') → PHP float 99.999
     *     amount_due = 99.999 − (float)'0.000' = 99.999 → stored '99.999'
     *
     *   bcmath path (scale 2):
     *     bcadd('0.00', '33.333', 2) = '33.33'
     *     bcadd('33.33', '33.333', 2) = '66.66'
     *     bcadd('66.66', '33.333', 2) = '99.99'   ← truncates to currency scale
     *     bcsub('99.99', '0.000', 2)  = '99.99'
     *     → stored '99.990'  (decimal:3 column pads to 3 places)
     *
     * subtotal/total/amount_due = '99.990' (bcmath) ≠ '99.999' (float SQL SUM).
     * Must fail before the fix, pass after.
     */
    public function test_recalculate_totals_uses_bcmath_not_float_sql_sum(): void
    {
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'number' => 'RECALC-'.uniqid('', true),
            'status' => InvoiceStatus::Sent,
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '0.000',
            'amount_paid' => '0.000',
            'amount_due' => '0.000',
            'currency' => 'EUR',
            'tax_rate' => '0.00',
            'billing_address' => [],
            'billing_email' => 'recalc@example.com',
            'billing_name' => 'Recalc Test',
            'invoice_date' => now(),
            'due_date' => now()->addDays(14),
        ]);

        // Three items, each with amount='33.333'.
        // At EUR scale 2, bcadd truncates to 2 decimal places per step → total '99.99'.
        // Builder::sum() returns PHP float 99.999 (3 decimal places retained via SQL SUM).
        foreach (range(1, 3) as $i) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => "Bcmath Test Item {$i}",
                'quantity' => '1.00',
                'unit_price' => '33.333',
                'amount' => '33.333',
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'discount_percent' => '0.00',
                'discount_amount' => '0.000',
                'sort_order' => $i,
                'metadata' => [],
            ]);
        }

        $invoice->recalculateTotals();
        $invoice->refresh();

        // bcadd at scale 2 truncates '33.333' to '33.33' per step → sum '99.99'
        // → decimal:3 column pads to '99.990'.
        // Float SQL SUM: 99.999 → decimal:3 → '99.999' (before fix).
        $this->assertSame(
            '99.990',
            $invoice->subtotal,
            'subtotal: float SQL SUM gives "99.999"; bcmath at EUR scale 2 gives "99.990". '
            .'recalculateTotals() still uses Builder::sum() / native float.',
        );

        $this->assertSame(
            '99.990',
            $invoice->total,
            'total must equal subtotal when tax=0 and discount=0.',
        );

        // amount_due: old code → float 99.999 − (float)"0.000" = 99.999 → "99.999"
        // new code  → bcsub("99.99","0.000",2) = "99.99" → stored "99.990"
        $this->assertSame(
            '99.990',
            $invoice->amount_due,
            'amount_due: float path gives "99.999"; bcmath gives "99.990". '
            .'Confirms the bccomp gate in recordPayment operates on a bcmath amount_due.',
        );
    }

    /**
     * AdminBillingController::calculateMRR() must accumulate prices via bcadd, not float SQL SUM (P0-3).
     *
     * Before the fix:
     *   $monthly = (string) TenantSubscription::...->sum('price');  // Builder::sum() → PHP float
     *   $yearly  = (string) TenantSubscription::...->sum('price');  // same
     *
     * After the fix: rows are pluck()'d and bcadd-reduced at scale+1=3 (sub-cent precision),
     * with a final bcadd normalisation to EUR scale 2.
     *
     * Test mix:
     *   2 × monthly @ '50.00'  →  $monthly = bcadd('50.000','50.000',3) = '100.000'
     *   1 × yearly  @ '600.00' →  $yearly  = '600.000'
     *   monthlyFromYearly = bcdiv('600.000','12',3) = '50.000'
     *   MRR = bcadd('100.000','50.000',2) = '150.00'
     *
     * Returning a PHP float via sum() and (string)-casting to '150' or '150.0' would
     * fail the '150.00' assertion, proving the path was changed.
     */
    public function test_calculate_mrr_uses_bcadd_not_float_sql_sum(): void
    {
        $superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'MRR Precision Admin',
            'email' => 'mrr-precision-'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'code' => 'MRR-TEST-'.strtoupper(substr(Str::uuid()->toString(), 0, 8)),
            'name' => 'MRR Precision Plan',
            'limits' => [],
            'currency' => 'EUR',
            'trial_days' => 0,
            'is_active' => true,
            'is_public' => false,
            'display_order' => 99,
        ]);

        // Two monthly subscriptions @ EUR 50.00
        foreach (range(1, 2) as $i) {
            $tenant = Tenant::create([
                'name' => "MRR Monthly Tenant {$i}",
                'slug' => 'mrr-monthly-'.uniqid(),
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Professional,
                'email' => 'mrr-monthly-'.uniqid().'@example.com',
            ]);

            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => '50.00',
                'currency' => 'EUR',
                'metadata' => [],
            ]);
        }

        // One yearly subscription @ EUR 600.00 (= EUR 50.00/month equivalent)
        $yearlyTenant = Tenant::create([
            'name' => 'MRR Yearly Tenant',
            'slug' => 'mrr-yearly-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'email' => 'mrr-yearly-'.uniqid().'@example.com',
        ]);

        TenantSubscription::create([
            'tenant_id' => $yearlyTenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => 'yearly',
            'price' => '600.00',
            'currency' => 'EUR',
            'metadata' => [],
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/billing/dashboard');

        $response->assertOk();

        // bcadd path: monthly='100.000', monthlyFromYearly='50.000', MRR='150.00'
        // float path: (string)(100.0 + 50.0) = '150', not '150.00' — scale-unaware
        $this->assertSame(
            '150.00',
            $response->json('data.mrr'),
            'MRR must be "150.00" (EUR scale 2 via bcadd), not a float-cast string. '
            .'If "150" is returned, calculateMRR() still uses Builder::sum() + (string)float.',
        );

        // ARR = MRR × 12 — also a bcmath result
        $this->assertSame(
            '1800.00',
            $response->json('data.arr'),
            'ARR must be "1800.00" = bcmul("150.00","12",2).',
        );
    }

    /**
     * InvoiceItem::calculateAmount() must return a string via bcmul, not a float (P0-3).
     *
     * The old return type was float. assertIsString() fails on old code.
     * After fix: bcmul at intermediate scale returns a numeric-string; the value
     * differs from the float path for sub-cent unit prices.
     */
    public function test_invoice_item_calculate_amount_returns_string_not_float(): void
    {
        $item = new InvoiceItem;
        $item->forceFill([
            'quantity' => '7.00',
            'unit_price' => '0.100',
            'tax_rate' => '0.00',
            'discount_percent' => '0.00',
        ]);

        $result = $item->calculateAmount();

        $this->assertIsString(
            $result,
            'calculateAmount() must return string (bcmul result), not float.'
        );
        // bcmul('7.00','0.100', CurrencyScale::for('EUR')+1=3) = '0.700'
        // float path: (float)'7.00' * (float)'0.100' = 0.7 (returned as float, not '0.700')
        $this->assertSame('0.700', $result);
    }
}
