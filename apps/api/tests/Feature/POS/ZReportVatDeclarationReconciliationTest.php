<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B-6(ii) / Option A4 — THE reconciliation test.
 *
 * Pins the §3.1 identity the whole lane exists to make true, on ONE body of
 * data, for `r > 0`:
 *
 *     Σ over every Z of company C in period P, across ALL terminals
 *        ( z.vat_breakdown[r].vat_amount )
 *     ==
 *     VatAggregation(OUTPUT, r).vat_amount for period P
 *
 * Both sides are net of refunds. The left side is what a Z prints; the right
 * side is what gets declared. Before A3 the left side was SALE-ONLY on
 * server-authored (legacy v1/v2) Zs, so the two disagreed by the refund VAT on
 * every shift that took a return — the arithmetic half of the ruling.
 *
 * The stated wedges from §3.2 are asserted as PRECONDITIONS here, not assumed:
 * the identity is restricted to `r > 0` (the declaration filters zero-rated
 * rows, the Z's own table does not), summed across ALL terminals (a Z is
 * terminal-scoped, the declaration company-scoped), and the sale-only headline
 * `tax_amount` is asserted to be EXCLUDED from it.
 *
 * The known last-day-of-period boundary defect
 * (docs/superpowers/tickets/2026-08-21-vat-period-last-day-boundary.md) is
 * deliberately NOT exercised: this fixture sits well inside the period.
 */
class ZReportVatDeclarationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD_FROM = '2026-02-01';

    private const PERIOD_TO = '2026-02-28';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private ReportGenerationService $reports;

    private VatDataRepositoryInterface $vat;

    protected function setUp(): void
    {
        parent::setUp();

        // `currency_decimal_places` is NOT decoration: CurrencyScaleResolver
        // prefers the COUNTRY record over the company currency, and the column
        // defaults to 2 — so omitting it silently runs this TND fixture at
        // scale 2 and every service-emitted figure comes back short a digit.
        // The migration that added the column backfills TN to 3
        // (2026_03_11_100000_add_currency_decimal_places_to_countries.php:21).
        DB::table('countries')->insert([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_decimal_places' => 3,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Z Reconciliation Tenant',
            'slug' => 'z-reconciliation-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Z Reconciliation Company',
            'legal_name' => 'Z Reconciliation SARL',
            'tax_id' => 'VAT987654',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->reports = $this->app->make(ReportGenerationService::class);
        $this->vat = $this->app->make(VatDataRepositoryInterface::class);
    }

    public function test_z_per_rate_net_vat_equals_the_declaration_for_the_same_period(): void
    {
        $terminalA = $this->createTerminal('POS01');
        $terminalB = $this->createTerminal('POS02');

        // Terminal A: two sales at 19%, one refund at 19% (positive-signed era).
        $a1 = $this->createSale($terminalA, '2026-02-10 09:00:00', [['19.00', '1000.000', '190.000']]);
        $this->createSale($terminalA, '2026-02-10 11:00:00', [['19.00', '500.000', '95.000']]);
        $this->createReturn($terminalA, $a1, '2026-02-10 15:00:00', [['19.00', '200.000', '38.000']]);

        // Terminal B: one sale at 19%, one refund at 19% (legacy NEGATIVE-signed
        // era) — the two writers really do store opposite signs for the same
        // event, which is why both sides normalise with ABS().
        $b1 = $this->createSale($terminalB, '2026-02-11 10:00:00', [['19.00', '300.000', '57.000']]);
        $this->createReturn($terminalB, $b1, '2026-02-11 16:00:00', [['19.00', '-100.000', '-19.000']]);

        // A zero-rated sale: present on the Z's table, EXCLUDED from the
        // declaration (§3.2 wedge 1). The identity holds only for r > 0.
        $this->createSale($terminalB, '2026-02-11 12:00:00', [['0.00', '80.000', '0.000']]);

        // Excluded on BOTH sides — pinned rather than assumed (§3.2 wedge 4).
        $this->createReturn($terminalB, $b1, '2026-02-11 17:00:00', [['19.00', '999.000', '189.810']], ['is_training' => true]);

        // ── LEFT SIDE: what every terminal's Z prints, summed. ────────────────
        $zVatByRate = [];
        $zSaleOnlyHeadline = '0.000';
        foreach ([$terminalA, $terminalB] as $terminal) {
            $totals = $this->shiftTotalsFor($terminal);
            $zSaleOnlyHeadline = bcadd($zSaleOnlyHeadline, (string) $totals['tax_amount'], 3);

            /** @var list<array<string, mixed>> $breakdown */
            $breakdown = $totals['vat_breakdown'];
            foreach ($breakdown as $row) {
                $rate = bcadd((string) $row['tax_rate'], '0', 2);
                $zVatByRate[$rate] = bcadd($zVatByRate[$rate] ?? '0.000', (string) $row['vat_amount'], 3);
            }
        }

        // ── RIGHT SIDE: what gets declared. ──────────────────────────────────
        $declared = $this->declaredOutputByRate();

        // ── THE IDENTITY, r > 0. ─────────────────────────────────────────────
        $this->assertArrayHasKey('19.00', $declared, 'The declaration must carry the 19% OUTPUT row');
        $this->assertSame(
            $declared['19.00'],
            $zVatByRate['19.00'],
            'Σ Z vat_breakdown[19%].vat_amount across all terminals must equal the declared OUTPUT VAT for the period',
        );
        // 190 + 95 + 57 − 38 − 19 = 285
        $this->assertSame('285.000', $zVatByRate['19.00']);

        // §3.2 wedge 1 — the zero-rated group exists on the Z and is absent from
        // the declaration. Restricting the identity to r > 0 is not optional.
        $this->assertArrayHasKey('0.00', $zVatByRate, 'The Z table keeps its zero-rated group');
        $this->assertArrayNotHasKey('0.00', $declared, 'The declaration filters tax_rate > 0');

        // §3.2 wedge 6 — the sale-only headline is NOT the declaration figure and
        // must never be used as one. It stays gross of refunds by design.
        $this->assertSame('342.000', $zSaleOnlyHeadline, '190 + 95 + 57, sale-only');
        $this->assertNotSame(
            $zSaleOnlyHeadline,
            $declared['19.00'],
            'tax_amount is sale-only — if this ever matches, the headline has silently become net and the wedge comment is stale',
        );
    }

    /**
     * The per-rate BASE identity: `Σ z.vat_breakdown[r].net_amount` must equal
     * the declaration's `base_amount` for the same rate.
     */
    public function test_z_per_rate_net_base_equals_the_declared_base(): void
    {
        $terminal = $this->createTerminal('POS01');

        $sale = $this->createSale($terminal, '2026-02-12 09:00:00', [['19.00', '1000.000', '190.000']]);
        $this->createReturn($terminal, $sale, '2026-02-12 14:00:00', [['19.00', '250.000', '47.500']]);

        $totals = $this->shiftTotalsFor($terminal);
        /** @var list<array<string, mixed>> $breakdown */
        $breakdown = $totals['vat_breakdown'];

        $this->assertCount(1, $breakdown);
        $this->assertSame('750.000', bcadd((string) $breakdown[0]['net_amount'], '0', 3));

        $declaredBase = null;
        foreach ($this->vat->aggregateByRateAndDirection($this->company->id, self::PERIOD_FROM, self::PERIOD_TO) as $row) {
            if ($row->direction === 'OUTPUT' && $row->taxRate === '19.00') {
                $declaredBase = $row->baseAmount;
            }
        }

        $this->assertSame('750.000', $declaredBase);
    }

    /**
     * The sale-only headline minus the declaration IS the refund VAT.
     *
     * GATE r1 F-6 — renamed and split. This assertion is a property of
     * `calculateShiftTotals` + the declaration arm only; it never called
     * `refundVatDisclosureFor()` despite its old name, and the reviewer's revert
     * probe confirmed it was the one test of the four that PASSED at base. The
     * disclosure arm it claimed to cover is now its own test below.
     */
    public function test_the_sale_only_headline_minus_the_declaration_is_the_refund_vat(): void
    {
        $terminal = $this->createTerminal('POS01');

        $sale = $this->createSale($terminal, '2026-02-13 09:00:00', [['19.00', '1000.000', '190.000']]);
        $this->createReturn($terminal, $sale, '2026-02-13 14:00:00', [['19.00', '200.000', '38.000']]);

        $totals = $this->shiftTotalsFor($terminal);
        $declared = $this->declaredOutputByRate();

        // sales VAT − declared VAT == the disclosure's refund magnitude.
        $this->assertSame(
            '38.000',
            bcsub((string) $totals['tax_amount'], $declared['19.00'], 3),
            'The refund-VAT disclosure is exactly the wedge between the sale-only headline and the declaration',
        );
    }

    /**
     * GATE r1 F-6 — the arm the renamed test above never exercised: the DERIVED
     * disclosure itself must equal the declaration's deduction, so the figure a
     * Z prints and the figure that gets declared are the same number.
     */
    public function test_the_derived_disclosure_equals_the_declarations_deduction(): void
    {
        $terminal = $this->createTerminal('POS01');

        $sale = $this->createSale($terminal, '2026-02-14 09:00:00', [['19.00', '1000.000', '190.000']]);
        $this->createReturn($terminal, $sale, '2026-02-14 14:00:00', [['19.00', '200.000', '38.000']]);

        $zReport = $this->createZReportFor($terminal, salesVat: '190.000', vatBreakdown: [
            ['tax_rate' => '19.00', 'net_amount' => '800.000', 'vat_amount' => '152.000', 'gross_amount' => '952.000'],
        ]);

        $disclosure = $this->reports->refundVatDisclosureFor($zReport);
        $declared = $this->declaredOutputByRate();

        $this->assertSame('38.000', $disclosure->refund_vat);
        $this->assertSame(
            $disclosure->refund_vat,
            bcsub($disclosure->sales_vat, $declared['19.00'], 3),
            'The disclosed refund VAT must equal the amount the declaration deducted',
        );
        $this->assertSame($declared['19.00'], $disclosure->net_vat);
        $this->assertTrue($disclosure->is_reconciled);
    }

    /**
     * @param  list<array<string, string>>  $vatBreakdown
     */
    private function createZReportFor(Terminal $terminal, string $salesVat, array $vatBreakdown): ZReport
    {
        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opened_at' => self::PERIOD_FROM.' 00:00:00',
            'opening_cash' => '0.000',
            'status' => 'CLOSED',
            // PostgreSQL `pos_shifts_closed_logic` requires both on a CLOSED shift.
            'closed_at' => self::PERIOD_TO.' 23:59:59',
            'closed_by' => $this->cashier->id,
        ]);

        return ZReport::create([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'fiscal_hash' => hash('sha256', 'z-reconciliation-'.$terminal->id),
            'previous_z_hash' => null,
            'report_data' => [
                'schema_version' => 3,
                'period_start' => self::PERIOD_FROM.' 00:00:00',
                'period_end' => self::PERIOD_TO.' 23:59:59',
                'tax_amount' => $salesVat,
                'vat_breakdown' => $vatBreakdown,
            ],
            'generated_by' => $this->cashier->id,
            'generated_at' => self::PERIOD_TO.' 23:59:59',
        ]);
    }

    /**
     * @return array<string, string> rate => declared OUTPUT vat_amount
     */
    private function declaredOutputByRate(): array
    {
        $out = [];
        foreach ($this->vat->aggregateByRateAndDirection($this->company->id, self::PERIOD_FROM, self::PERIOD_TO) as $row) {
            /** @var VatAggregation $row */
            if ($row->direction !== 'OUTPUT') {
                continue;
            }
            $out[$row->taxRate] = bcadd($out[$row->taxRate] ?? '0.000', $row->vatAmount, 3);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftTotalsFor(Terminal $terminal): array
    {
        $method = new \ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        /** @var array<string, mixed> $totals */
        $totals = $method->invoke(
            $this->reports,
            $terminal,
            self::PERIOD_FROM.' 00:00:00',
            self::PERIOD_TO.' 23:59:59',
        );

        return $totals;
    }

    private function createTerminal(string $code): Terminal
    {
        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => $code,
        ]);
    }

    /**
     * A sale receipt whose headline columns are derived from its VAT rows, both
     * written in ONE insert.
     *
     * The receipt's own totals must agree with its VAT rows or the sale-only
     * `tax_amount` assertions would be testing the fixture rather than the
     * aggregation. They are computed BEFORE the insert rather than patched
     * afterwards because the PostgreSQL `prevent_receipt_modification()` trigger
     * rejects any UPDATE to a fiscalized receipt — the same reason
     * `ReportGenerationServiceTest::createReceipt()` writes `created_at` at
     * insert time.
     *
     * @param  list<array{0: numeric-string, 1: numeric-string, 2: numeric-string}>  $vatRows  [rate, net, vat]
     */
    private function createSale(Terminal $terminal, string $postedAt, array $vatRows): Receipt
    {
        return $this->createReceiptWithVat($terminal, $vatRows, [
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => $postedAt,
        ]);
    }

    /**
     * @param  list<array{0: numeric-string, 1: numeric-string, 2: numeric-string}>  $vatRows
     * @param  array<string, mixed>  $overrides
     */
    private function createReturn(Terminal $terminal, Receipt $original, string $postedAt, array $vatRows, array $overrides = []): Receipt
    {
        return $this->createReceiptWithVat($terminal, $vatRows, array_merge([
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => ReturnReason::Defective,
            'posted_at' => $postedAt,
        ], $overrides));
    }

    /**
     * @param  list<array{0: numeric-string, 1: numeric-string, 2: numeric-string}>  $vatRows
     * @param  array<string, mixed>  $attributes
     */
    private function createReceiptWithVat(Terminal $terminal, array $vatRows, array $attributes): Receipt
    {
        $subtotal = '0.000';
        $taxAmount = '0.000';
        foreach ($vatRows as [$rate, $net, $vat]) {
            unset($rate);
            $subtotal = bcadd($subtotal, $net, 3);
            $taxAmount = bcadd($taxAmount, $vat, 3);
        }

        $receipt = Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'currency' => 'TND',
            'discount_amount' => '0.000',
            'is_training' => false,
            'is_voided' => false,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => bcadd($subtotal, $taxAmount, 3),
        ], $attributes));

        foreach ($vatRows as [$rate, $net, $vat]) {
            ReceiptVatDetail::create([
                'receipt_id' => $receipt->id,
                'tax_rate' => $rate,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => bcadd($net, $vat, 3),
            ]);
        }

        return $receipt;
    }
}
