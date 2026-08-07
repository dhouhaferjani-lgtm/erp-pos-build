<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * R2-B — quote header totals must be NET of per-line discounts.
 *
 * Pre-fix defect (gate-confirmed, `2026-08-07-discount-lane-out-of-lane-findings.md`
 * §2): `QuoteController::store()`/`update()` computed `subtotal`/`tax_amount`/
 * `total` — and each line's stored `line_total` — with a bare
 * `bcmul(quantity, unit_price)` while PERSISTING `discount_percent` /
 * `discount_amount`. A quote's own stored totals therefore ignored its own
 * line discounts, and every downstream consumer that DOES honour them
 * (`TaxCalculationService` at `confirm()`, which sums
 * `DocumentLine::calculateTotal()`; the invoice/sales-order controllers)
 * produced different money for the same document.
 *
 * Every assertion here compares decimal STRINGS (the `decimal:3` model casts)
 * or goes through `bccomp` — never a float compare.
 */
class QuoteDiscountTotalsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The scale the money columns are PERSISTED at (`decimal(15,3)` — see the
     * `decimal:3` casts on Document/DocumentLine), independent of any
     * currency's own display scale. All money comparisons in this file run at
     * this scale so a 3rd-decimal regression cannot hide behind a 2-dp
     * currency.
     */
    private const STORAGE_SCALE = 3;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Quote Totals Tenant',
            'slug' => 'quote-totals-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Quote Totals Company',
            'legal_name' => 'Quote Totals Company LLC',
            'tax_id' => 'TAX-QT-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Quote Totals User',
            'email' => 'quote-totals@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.convert',
            'orders.view', 'orders.create',
            // POST /orders/{order}/convert-to-invoice is gated on invoices.create
            // (routes.php:110-112) — needed for the full quote→order→invoice leg.
            'invoices.view', 'invoices.create',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Quote Totals Partner',
            'type' => PartnerType::Customer,
            'email' => 'quote-totals-partner@example.com',
        ]);
    }

    /**
     * Two lines exercising BOTH W-3 Option-A discount toggle shapes:
     *  - line 1: `discount_percent` 10.00 on 2 × 100.000 = 200.000 gross → 180.000 net
     *  - line 2: `discount_amount` 30.000 (line-level flat) on 3 × 50.000 = 150.000 gross → 120.000 net
     * Both at 20% VAT ⇒ subtotal 300.000, tax 60.000, total 360.000.
     *
     * The discount-BLIND numbers the pre-fix controller produced were
     * 350.000 / 70.000 / 420.000.
     *
     * @return list<array{description: string, quantity: numeric-string, unit_price: numeric-string, discount_percent?: numeric-string, discount_amount?: numeric-string, tax_rate: numeric-string}>
     */
    private function discountLinesPayload(): array
    {
        return [
            [
                'description' => 'Percent-discounted line',
                'quantity' => '2.0000',
                'unit_price' => '100.000',
                'discount_percent' => '10.00',
                'tax_rate' => '20.00',
            ],
            [
                'description' => 'Amount-discounted line',
                'quantity' => '3.0000',
                'unit_price' => '50.000',
                'discount_amount' => '30.000',
                'tax_rate' => '20.00',
            ],
        ];
    }

    private function scale(): int
    {
        return app(CurrencyScaleResolverInterface::class)->getScale('EUR');
    }

    /**
     * The canonical header the document pipeline must produce for a line
     * payload: subtotal is the sum of {@see DocumentLine::computeLineTotal()}
     * (the single source of truth for line-level discount arithmetic), tax is
     * derived from that NET base.
     *
     * @param  list<array{description: string, quantity: numeric-string, unit_price: numeric-string, discount_percent?: numeric-string, discount_amount?: numeric-string, tax_rate: numeric-string}>|null  $lines
     * @return array{subtotal: numeric-string, tax_amount: numeric-string, total: numeric-string}
     */
    private function canonicalHeader(?array $lines = null, ?int $scale = null): array
    {
        $lines ??= $this->discountLinesPayload();
        $scale ??= $this->scale();
        $subtotal = '0';
        $taxAmount = '0';

        foreach ($lines as $line) {
            $net = DocumentLine::computeLineTotal(
                $line['quantity'],
                $line['unit_price'],
                $line['discount_percent'] ?? null,
                $line['discount_amount'] ?? null,
                $scale,
            );
            $subtotal = bcadd($subtotal, $net, $scale);
            $taxAmount = bcadd($taxAmount, bcmul($net, bcdiv($line['tax_rate'], '100', 4), $scale), $scale);
        }

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => bcadd($subtotal, $taxAmount, $scale),
        ];
    }

    /**
     * Assert a persisted money column equals an expected decimal string.
     *
     * Compares at {@see self::STORAGE_SCALE} — the scale the columns are
     * actually PERSISTED at (`decimal(15,3)`), NOT the document currency's
     * scale. Comparing at the EUR scale (2) let a 3rd-decimal regression pass
     * silently even though the database round-trips it (precision gate m-2).
     * bccomp zero-pads the shorter operand, so a 2-dp canonical expectation
     * still matches a 3-dp stored value exactly.
     *
     * @param  numeric-string  $expected
     * @param  numeric-string  $actual
     */
    private function assertMoneyEquals(string $expected, string $actual, string $message): void
    {
        $this->assertSame(
            0,
            bccomp($expected, $actual, self::STORAGE_SCALE),
            $message." (expected {$expected}, got {$actual}, compared at scale ".self::STORAGE_SCALE.')',
        );
    }

    // ---------------------------------------------------------------- store

    public function test_quote_store_header_totals_are_net_of_line_discounts(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-01-15',
            'lines' => $this->discountLinesPayload(),
        ]);

        $response->assertStatus(201);

        /** @var Document $quote */
        $quote = Document::findOrFail($response->json('data.id'));
        $canonical = $this->canonicalHeader();

        $this->assertMoneyEquals($canonical['subtotal'], $quote->subtotal ?? '0', 'Quote subtotal ignores line discounts');
        $this->assertMoneyEquals($canonical['tax_amount'], $quote->tax_amount ?? '0', 'Quote tax_amount is computed on a discount-blind base');
        $this->assertMoneyEquals($canonical['total'], $quote->total ?? '0', 'Quote total ignores line discounts');
    }

    public function test_quote_store_persists_line_total_net_of_discount(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-01-15',
            'lines' => $this->discountLinesPayload(),
        ]);

        $response->assertStatus(201);

        /** @var Document $quote */
        $quote = Document::with('lines')->findOrFail($response->json('data.id'));
        /** @var list<DocumentLine> $lines */
        $lines = $quote->lines->sortBy('line_number')->values()->all();

        $this->assertCount(2, $lines);
        // 2 × 100.000 − 10% = 180.000
        $this->assertMoneyEquals('180', $lines[0]->line_total, 'Percent-discounted line_total is gross, not net');
        // 3 × 50.000 − 30.000 = 120.000
        $this->assertMoneyEquals('120', $lines[1]->line_total, 'Amount-discounted line_total is gross, not net');
    }

    public function test_quote_store_header_equals_sum_of_persisted_line_totals(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-01-15',
            'lines' => $this->discountLinesPayload(),
        ]);

        $response->assertStatus(201);

        /** @var Document $quote */
        $quote = Document::with('lines')->findOrFail($response->json('data.id'));

        $scale = $this->scale();
        $sum = '0';
        foreach ($quote->lines as $line) {
            $sum = bcadd($sum, $line->line_total, $scale);
        }

        $this->assertMoneyEquals($sum, $quote->subtotal ?? '0', 'Quote subtotal diverges from the sum of its own line totals');
    }

    // --------------------------------------------------------------- update

    public function test_quote_update_line_replace_applies_line_discounts_to_header_totals(): void
    {
        // Create a plain, discount-free quote first.
        $create = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-01-15',
            'lines' => [[
                'description' => 'Plain line',
                'quantity' => '1.0000',
                'unit_price' => '10.000',
                'tax_rate' => '20.00',
            ]],
        ]);
        $create->assertStatus(201);
        $quoteId = $create->json('data.id');

        // Replace the lines with the discounted set.
        $update = $this->actingAs($this->user)->patchJson("/api/v1/quotes/{$quoteId}", [
            'lines' => $this->discountLinesPayload(),
        ]);
        $update->assertStatus(200);

        /** @var Document $quote */
        $quote = Document::with('lines')->findOrFail($quoteId);
        $canonical = $this->canonicalHeader();

        $this->assertMoneyEquals($canonical['subtotal'], $quote->subtotal ?? '0', 'Updated quote subtotal ignores line discounts');
        $this->assertMoneyEquals($canonical['tax_amount'], $quote->tax_amount ?? '0', 'Updated quote tax_amount is computed on a discount-blind base');
        $this->assertMoneyEquals($canonical['total'], $quote->total ?? '0', 'Updated quote total ignores line discounts');

        /** @var list<DocumentLine> $lines */
        $lines = $quote->lines->sortBy('line_number')->values()->all();
        $this->assertMoneyEquals('180', $lines[0]->line_total, 'Updated percent-discounted line_total is gross, not net');
        $this->assertMoneyEquals('120', $lines[1]->line_total, 'Updated amount-discounted line_total is gross, not net');
    }

    // -------------------------------------------------------------- confirm

    public function test_confirming_a_discounted_quote_does_not_move_its_totals(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-01-15',
            'lines' => $this->discountLinesPayload(),
        ]);
        $create->assertStatus(201);
        $quoteId = $create->json('data.id');

        /** @var Document $draft */
        $draft = Document::findOrFail($quoteId);
        $draftSubtotal = $draft->subtotal ?? '0';
        $draftTax = $draft->tax_amount ?? '0';
        $draftTotal = $draft->total ?? '0';

        $confirm = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quoteId}/confirm");
        $confirm->assertStatus(200);

        // Fetched fresh from the DB, not the in-memory draft model.
        /** @var Document $confirmed */
        $confirmed = Document::findOrFail($quoteId);

        // Byte-identical decimal strings: confirm() recomputes tax/total from
        // DocumentLine::calculateTotal() (discount-AWARE), so a discount-blind
        // draft header silently moves the moment the quote is confirmed.
        // These two DO have teeth — they were the red that proved the defect.
        $this->assertSame($draftTax, $confirmed->tax_amount ?? '0', 'Confirming a quote moved its tax_amount');
        $this->assertSame($draftTotal, $confirmed->total ?? '0', 'Confirming a quote moved its total');

        // `subtotal` is a NEVER-WRITTEN invariant of confirm(): the update at
        // QuoteController.php:545-548 sets only tax_amount and total, so this
        // equality is structural, not evidence that the subtotal is right.
        // Asserted explicitly so the invariant is pinned — if confirm() ever
        // starts writing subtotal, this test must be revisited rather than
        // silently keep passing.
        $this->assertSame($draftSubtotal, $confirmed->subtotal ?? '0', 'confirm() unexpectedly wrote the subtotal column');

        // THIS is the assertion with teeth on the subtotal: a confirmed quote
        // must be internally consistent. A legacy discount-blind quote fails
        // here — its store-time gross subtotal plus its confirm-time NET tax
        // does not add up to its confirm-time NET total.
        $this->assertMoneyEquals(
            bcadd($confirmed->subtotal ?? '0', $confirmed->tax_amount ?? '0', self::STORAGE_SCALE),
            $confirmed->total ?? '0',
            'Confirmed quote is internally inconsistent: subtotal + tax_amount != total',
        );
    }

    // ------------------------------------------------- 3-decimal currency

    /**
     * The EUR fixtures above all land on clean 2-dp money, so they cannot see
     * a regression in the 3rd decimal even though the columns store one
     * (precision gate m-2). This case runs the same code path under a TND
     * company — a genuinely 3-dp currency — with prices and a flat discount
     * that force truncation at the 3rd decimal on BOTH toggle shapes:
     *
     *   line 1: 3 × 10.333 = 30.999 gross, −10% (3.0999 → 3.099) = 27.900 net,
     *           19% VAT (5.3010 → 5.301)
     *   line 2: 7 × 3.777 = 26.439 gross, −2.111 flat = 24.328 net,
     *           19% VAT (4.62232 → 4.622)
     *   ⇒ subtotal 52.228, tax 9.923, total 62.151
     *
     * The expected values are written out literally (not re-derived from the
     * helper under test) so the assertion is independent evidence, not a
     * restatement of the implementation.
     */
    public function test_quote_totals_are_exact_at_the_third_decimal_under_a_tnd_company(): void
    {
        $tndCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Quote Totals TN Company',
            'legal_name' => 'Quote Totals TN Company SARL',
            'tax_id' => 'TAX-QT-TN-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $tndCompany->id,
            'role' => 'admin',
        ]);

        $tndPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $tndCompany->id,
            'name' => 'Quote Totals TN Partner',
            'type' => PartnerType::Customer,
            'email' => 'quote-totals-tn@example.com',
        ]);

        $lines = [
            [
                'description' => 'Percent-discounted, 3-dp price',
                'quantity' => '3.0000',
                'unit_price' => '10.333',
                'discount_percent' => '10.00',
                'tax_rate' => '19.00',
            ],
            [
                'description' => 'Flat-discounted, 3-dp price and discount',
                'quantity' => '7.0000',
                'unit_price' => '3.777',
                'discount_amount' => '2.111',
                'tax_rate' => '19.00',
            ],
        ];

        $response = $this->actingAs($this->user)
            ->withHeader('X-Company-Id', $tndCompany->id)
            ->postJson('/api/v1/quotes', [
                'partner_id' => $tndPartner->id,
                'document_date' => '2026-01-15',
                'currency' => 'TND',
                'lines' => $lines,
            ]);

        $response->assertStatus(201);

        /** @var Document $quote */
        $quote = Document::with('lines')->findOrFail($response->json('data.id'));

        $this->assertMoneyEquals('52.228', $quote->subtotal ?? '0', 'TND quote subtotal is wrong in the 3rd decimal');
        $this->assertMoneyEquals('9.923', $quote->tax_amount ?? '0', 'TND quote tax_amount is wrong in the 3rd decimal');
        $this->assertMoneyEquals('62.151', $quote->total ?? '0', 'TND quote total is wrong in the 3rd decimal');

        /** @var list<DocumentLine> $quoteLines */
        $quoteLines = $quote->lines->sortBy('line_number')->values()->all();
        $this->assertMoneyEquals('27.900', $quoteLines[0]->line_total, 'TND percent-discounted line_total is wrong in the 3rd decimal');
        $this->assertMoneyEquals('24.328', $quoteLines[1]->line_total, 'TND flat-discounted line_total is wrong in the 3rd decimal');

        // Cross-check against the canonical helper at the TND scale too, so a
        // future change to computeLineTotal cannot drift away from the literals
        // above without one of the two assertions firing.
        $canonical = $this->canonicalHeader($lines, 3);
        $this->assertMoneyEquals($canonical['subtotal'], $quote->subtotal ?? '0', 'TND quote subtotal diverges from the canonical pipeline');
        $this->assertMoneyEquals($canonical['total'], $quote->total ?? '0', 'TND quote total diverges from the canonical pipeline');
    }

    // ----------------------------------------------------------- conversion

    public function test_quote_to_sales_order_conversion_preserves_totals_byte_identically(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-01-15',
            'lines' => $this->discountLinesPayload(),
        ]);
        $create->assertStatus(201);
        $quoteId = $create->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quoteId}/confirm")->assertStatus(200);

        /** @var Document $quote */
        $quote = Document::findOrFail($quoteId);

        $convert = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quoteId}/convert-to-order");
        $convert->assertStatus(201);

        /** @var Document $order */
        $order = Document::findOrFail($convert->json('data.id'));
        $this->assertSame(DocumentType::SalesOrder, $order->type);

        // Decimal-string equality — the converted document must carry exactly
        // the money the quote it came from carried.
        $this->assertSame($quote->subtotal ?? '0', $order->subtotal ?? '0', 'Quote→order conversion changed the subtotal');
        $this->assertSame($quote->tax_amount ?? '0', $order->tax_amount ?? '0', 'Quote→order conversion changed the tax_amount');
        $this->assertSame($quote->total ?? '0', $order->total ?? '0', 'Quote→order conversion changed the total');

        // …and it must be the CANONICAL money, not merely a consistent copy of
        // a wrong number.
        $canonical = $this->canonicalHeader();
        $this->assertMoneyEquals($canonical['subtotal'], $order->subtotal ?? '0', 'Converted order subtotal ignores the quote line discounts');
        $this->assertMoneyEquals($canonical['total'], $order->total ?? '0', 'Converted order total ignores the quote line discounts');
    }

    public function test_quote_conversion_chain_reaches_an_invoice_with_unchanged_totals(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-01-15',
            'lines' => [[
                // Services-only so the order can be invoiced directly (no
                // delivery-note precondition), keeping this test about money.
                'description' => 'Percent-discounted service',
                'quantity' => '2.0000',
                'unit_price' => '100.000',
                'discount_percent' => '10.00',
                'tax_rate' => '20.00',
            ]],
        ]);
        $create->assertStatus(201);
        $quoteId = $create->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quoteId}/confirm")->assertStatus(200);

        /** @var Document $quote */
        $quote = Document::findOrFail($quoteId);

        // 2 × 100.000 − 10% = 180.000 net, 20% VAT = 36.000, total 216.000.
        $this->assertMoneyEquals('180', $quote->subtotal ?? '0', 'Confirmed quote subtotal ignores the line discount');
        $this->assertMoneyEquals('36', $quote->tax_amount ?? '0', 'Confirmed quote tax is computed on a discount-blind base');
        $this->assertMoneyEquals('216', $quote->total ?? '0', 'Confirmed quote total ignores the line discount');

        $order = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quoteId}/convert-to-order");
        $order->assertStatus(201);

        /** @var Document $orderModel */
        $orderModel = Document::findOrFail($order->json('data.id'));
        $orderModel->update(['status' => DocumentStatus::Confirmed]);

        $this->assertSame($quote->subtotal ?? '0', $orderModel->subtotal ?? '0', 'Quote→order changed the subtotal');
        $this->assertSame($quote->total ?? '0', $orderModel->total ?? '0', 'Quote→order changed the total');

        // …and the leg the test is NAMED for: order → invoice. The fixture is
        // services-only, so SalesOrderToInvoiceConverter takes the direct
        // invoicing path (no delivery-note precondition).
        $invoice = $this->actingAs($this->user)->postJson("/api/v1/orders/{$orderModel->id}/convert-to-invoice");
        $invoice->assertStatus(201);

        /** @var Document $invoiceModel */
        $invoiceModel = Document::findOrFail($invoice->json('data.id'));
        $this->assertSame(DocumentType::Invoice, $invoiceModel->type);

        // The invoice at the end of the chain must carry exactly the money the
        // quote at the start of it carried. Decimal-string equality.
        $this->assertSame($quote->subtotal ?? '0', $invoiceModel->subtotal ?? '0', 'Quote→order→invoice changed the subtotal');
        $this->assertSame($quote->tax_amount ?? '0', $invoiceModel->tax_amount ?? '0', 'Quote→order→invoice changed the tax_amount');
        $this->assertSame($quote->total ?? '0', $invoiceModel->total ?? '0', 'Quote→order→invoice changed the total');

        // …and it must be the CANONICAL money, not a consistent copy of a
        // wrong number: 2 × 100.000 − 10% = 180.000 net, 20% VAT = 36.000.
        $this->assertMoneyEquals('180', $invoiceModel->subtotal ?? '0', 'Invoice subtotal ignores the originating quote line discount');
        $this->assertMoneyEquals('36', $invoiceModel->tax_amount ?? '0', 'Invoice tax is computed on a discount-blind base');
        $this->assertMoneyEquals('216', $invoiceModel->total ?? '0', 'Invoice total ignores the originating quote line discount');
    }
}
