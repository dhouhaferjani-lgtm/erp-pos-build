<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Services\InvoiceService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
}
