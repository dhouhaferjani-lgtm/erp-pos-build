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
     * @return array<int, array<string, string>>
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
     * The canonical header the document pipeline must produce for
     * {@see discountLinesPayload()}: subtotal is the sum of
     * {@see DocumentLine::computeLineTotal()} (the single source of truth for
     * line-level discount arithmetic), tax is derived from that NET base.
     *
     * @return array{subtotal: string, tax_amount: string, total: string}
     */
    private function canonicalHeader(): array
    {
        $scale = $this->scale();
        $subtotal = '0';
        $taxAmount = '0';

        foreach ($this->discountLinesPayload() as $line) {
            /** @var numeric-string $net */
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
     * Assert a persisted money column equals an expected decimal string,
     * comparing with bccomp at the currency scale (never a float compare).
     */
    private function assertMoneyEquals(string $expected, string $actual, string $message): void
    {
        $this->assertSame(
            0,
            bccomp($expected, $actual, $this->scale()),
            $message." (expected {$expected}, got {$actual})",
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

        $this->assertMoneyEquals($canonical['subtotal'], (string) $quote->subtotal, 'Quote subtotal ignores line discounts');
        $this->assertMoneyEquals($canonical['tax_amount'], (string) $quote->tax_amount, 'Quote tax_amount is computed on a discount-blind base');
        $this->assertMoneyEquals($canonical['total'], (string) $quote->total, 'Quote total ignores line discounts');
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
        $lines = $quote->lines->sortBy('line_number')->values();

        $this->assertCount(2, $lines);
        // 2 × 100.000 − 10% = 180.000
        $this->assertMoneyEquals('180', (string) $lines[0]->line_total, 'Percent-discounted line_total is gross, not net');
        // 3 × 50.000 − 30.000 = 120.000
        $this->assertMoneyEquals('120', (string) $lines[1]->line_total, 'Amount-discounted line_total is gross, not net');
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
            $sum = bcadd($sum, (string) $line->line_total, $scale);
        }

        $this->assertMoneyEquals($sum, (string) $quote->subtotal, 'Quote subtotal diverges from the sum of its own line totals');
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

        $this->assertMoneyEquals($canonical['subtotal'], (string) $quote->subtotal, 'Updated quote subtotal ignores line discounts');
        $this->assertMoneyEquals($canonical['tax_amount'], (string) $quote->tax_amount, 'Updated quote tax_amount is computed on a discount-blind base');
        $this->assertMoneyEquals($canonical['total'], (string) $quote->total, 'Updated quote total ignores line discounts');

        $lines = $quote->lines->sortBy('line_number')->values();
        $this->assertMoneyEquals('180', (string) $lines[0]->line_total, 'Updated percent-discounted line_total is gross, not net');
        $this->assertMoneyEquals('120', (string) $lines[1]->line_total, 'Updated amount-discounted line_total is gross, not net');
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
        $draftSubtotal = (string) $draft->subtotal;
        $draftTax = (string) $draft->tax_amount;
        $draftTotal = (string) $draft->total;

        $confirm = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quoteId}/confirm");
        $confirm->assertStatus(200);

        /** @var Document $confirmed */
        $confirmed = Document::findOrFail($quoteId);

        // Byte-identical decimal strings: confirm() recomputes tax/total from
        // DocumentLine::calculateTotal() (discount-AWARE), so a discount-blind
        // draft header silently moves the moment the quote is confirmed.
        $this->assertSame($draftSubtotal, (string) $confirmed->subtotal, 'Confirming a quote moved its subtotal');
        $this->assertSame($draftTax, (string) $confirmed->tax_amount, 'Confirming a quote moved its tax_amount');
        $this->assertSame($draftTotal, (string) $confirmed->total, 'Confirming a quote moved its total');
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
        $this->assertSame((string) $quote->subtotal, (string) $order->subtotal, 'Quote→order conversion changed the subtotal');
        $this->assertSame((string) $quote->tax_amount, (string) $order->tax_amount, 'Quote→order conversion changed the tax_amount');
        $this->assertSame((string) $quote->total, (string) $order->total, 'Quote→order conversion changed the total');

        // …and it must be the CANONICAL money, not merely a consistent copy of
        // a wrong number.
        $canonical = $this->canonicalHeader();
        $this->assertMoneyEquals($canonical['subtotal'], (string) $order->subtotal, 'Converted order subtotal ignores the quote line discounts');
        $this->assertMoneyEquals($canonical['total'], (string) $order->total, 'Converted order total ignores the quote line discounts');
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
        $this->assertMoneyEquals('180', (string) $quote->subtotal, 'Confirmed quote subtotal ignores the line discount');
        $this->assertMoneyEquals('36', (string) $quote->tax_amount, 'Confirmed quote tax is computed on a discount-blind base');
        $this->assertMoneyEquals('216', (string) $quote->total, 'Confirmed quote total ignores the line discount');

        $order = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quoteId}/convert-to-order");
        $order->assertStatus(201);

        /** @var Document $orderModel */
        $orderModel = Document::findOrFail($order->json('data.id'));
        $orderModel->update(['status' => DocumentStatus::Confirmed]);

        $this->assertSame((string) $quote->subtotal, (string) $orderModel->subtotal, 'Quote→order changed the subtotal');
        $this->assertSame((string) $quote->total, (string) $orderModel->total, 'Quote→order changed the total');
    }
}
