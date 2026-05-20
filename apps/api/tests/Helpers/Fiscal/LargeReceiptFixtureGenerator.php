<?php

declare(strict_types=1);

namespace Tests\Helpers\Fiscal;

/**
 * Test-side fixture generator for F-15 (large-receipt acceptance) per
 * synthesis v5 §10. NOT production code — produces a deterministic
 * 50-line / 10-payment / 8-vat-rate Candidate C-v3 payload that
 * `FiscalPayloadConstraintValidator` accepts.
 *
 * **Invariants enforced by the generator itself:**
 *   - Partition rule: line_items aggregate into vat_breakdown rows by
 *     (vat_rate, tax_category_code) — generator splits the 50 lines into
 *     8 distinct (rate, category) groups and emits one breakdown row per
 *     group.
 *   - Per-group amount equality: net = SUM(line_subtotal), vat = SUM(line_vat),
 *     gross = net + vat — via BCMath at currency_scale.
 *   - Total arithmetic: subtotal + vat_total == total + transaction_discount_amount.
 *   - Scale invariant: every bcformat field exact at its declared scale.
 *
 * Hand-authoring this fixture would take ~8 hours; the generator
 * mechanically authors it in milliseconds. The OUTPUT is committed as
 * `tests/Fixtures/fiscal/sale-receipt-golden/v4/F-15-large/{payload.json,
 * expected.json}` (auditable artifact); the generator is the explanation
 * of how that file was produced.
 *
 * Deterministic — same inputs produce identical bytes (no randomness).
 * Strict typing throughout per CLAUDE.md rule 3.
 */
final class LargeReceiptFixtureGenerator
{
    /** Phase-1 fixed quantity scale per synthesis v5 §6.B. */
    private const QUANTITY_SCALE = 3;

    /** Phase-1 fixed VAT-rate scale per synthesis v5 §6.B. */
    private const VAT_RATE_SCALE = 2;

    /**
     * The 8 (vat_rate, tax_category_code) partition groups used by F-15.
     *
     * @var list<array{rate: string, category: string}>
     */
    private const PARTITION_GROUPS = [
        ['rate' => '20.00', 'category' => ''],
        ['rate' => '5.50',  'category' => ''],
        ['rate' => '10.00', 'category' => ''],
        ['rate' => '2.10',  'category' => ''],
        ['rate' => '0.00',  'category' => 'Z'],
        ['rate' => '0.00',  'category' => 'E'],
        ['rate' => '0.00',  'category' => 'O'],
        ['rate' => '15.00', 'category' => 'S'],
    ];

    /**
     * Generate F-15 payload + expected-acceptance metadata.
     *
     * @return array{
     *   payload: array<string, mixed>,
     *   expected: array{accept: true}
     * }
     */
    public static function generate(): array
    {
        $currencyCode = 'EUR';
        $currencyScale = 2;
        $businessDate = '2026-05-20';
        $eventTime = '2026-05-20T14:30:00Z';
        $terminalUuid = '11111111-2222-3333-4444-555555555555';
        $cashierUuid = '22222222-3333-4444-5555-666666666666';
        $shiftUuid = '33333333-4444-5555-6666-777777777777';
        $receiptUuid = '44444444-5555-6666-7777-888888888888';

        // -- Generate 50 line items distributed across 8 partition groups.
        // -- Use a deterministic round-robin distribution; groups[0] gets
        // -- lines 0,8,16,24,32,40,48 (7 lines); groups[i] for i=1..7 gets
        // -- 6 lines except some get one extra to make total 50. Spread:
        // -- group 0: 7 lines (indices 0,8,16,24,32,40,48)
        // -- group 1: 7 lines (indices 1,9,17,25,33,41,49)
        // -- group 2..7: 6 lines each
        // -- Total: 7+7+6+6+6+6+6+6 = 50 ✓

        $lineItems = [];
        $groupTotals = []; // ['key' => ['net'=>..., 'vat'=>...]]
        foreach (self::PARTITION_GROUPS as $g) {
            $groupTotals[$g['rate'].'|'.$g['category']] = ['net' => '0', 'vat' => '0'];
        }

        for ($i = 0; $i < 50; $i++) {
            $groupIndex = $i % 8;
            $group = self::PARTITION_GROUPS[$groupIndex];

            // Deterministic per-line values — unit_price varies, quantity
            // varies, computed line_subtotal + line_vat respect both scales.
            $unitPriceMinor = 100 + $i * 10; // 100, 110, 120, ... — minor units
            $unitPrice = self::bcformat((string) ($unitPriceMinor / 100), $currencyScale);

            // Use whole quantities to keep partition math exact.
            $qty = self::bcformat((string) (1 + ($i % 3)), self::QUANTITY_SCALE);

            // line_subtotal = unit_price * quantity (using whole-qty)
            $lineSubtotal = self::bcmulRounded($unitPrice, $qty, $currencyScale);

            // line_vat = line_subtotal * (vat_rate / 100), rounded to scale.
            // For exact arithmetic, choose unit_price + qty so result lands
            // cleanly. With rate "20.00" + line_subtotal multiples of 5/cent,
            // result is exact at scale=2. We pre-compute using bcmath.
            $vatFactor = bcdiv($group['rate'], '100', 4); // e.g. 20.00 / 100 = 0.2000
            $lineVat = self::bcmulRounded($lineSubtotal, $vatFactor, $currencyScale);

            // Accumulate group totals (precise BCMath; no rounding drift
            // because each line is rounded separately and the partition
            // rule compares sum of per-line values to breakdown row).
            $key = $group['rate'].'|'.$group['category'];
            $groupTotals[$key]['net'] = bcadd($groupTotals[$key]['net'], $lineSubtotal, $currencyScale);
            $groupTotals[$key]['vat'] = bcadd($groupTotals[$key]['vat'], $lineVat, $currencyScale);

            $lineItems[] = [
                'gtin' => null,
                'line_discount_amount' => self::bcformat('0', $currencyScale),
                'line_discount_reason' => null,
                'line_subtotal' => $lineSubtotal,
                'line_vat' => $lineVat,
                'name' => 'F-15 item '.$i,
                'non_collected_subtype' => null,
                'product_id' => 'prod-f15-'.$i,
                'quantity' => $qty,
                'sku' => 'SKU-F15-'.$i,
                'tax_category_code' => $group['category'],
                'unit_price' => $unitPrice,
                'vat_rate' => $group['rate'],
            ];
        }

        // -- vat_breakdown — one row per partition group, totals from group sums.
        $vatBreakdown = [];
        $subtotal = '0';
        $vatTotal = '0';
        foreach (self::PARTITION_GROUPS as $g) {
            $key = $g['rate'].'|'.$g['category'];
            $net = $groupTotals[$key]['net'];
            $vat = $groupTotals[$key]['vat'];
            $gross = bcadd($net, $vat, $currencyScale);
            $vatBreakdown[] = [
                'gross_amount' => $gross,
                'net_amount' => $net,
                'rate' => $g['rate'],
                'tax_category_code' => $g['category'],
                'vat_amount' => $vat,
            ];
            $subtotal = bcadd($subtotal, $net, $currencyScale);
            $vatTotal = bcadd($vatTotal, $vat, $currencyScale);
        }

        // total = subtotal + vat_total (zero invoice-level discount).
        $total = bcadd($subtotal, $vatTotal, $currencyScale);
        $transactionDiscountAmount = self::bcformat('0', $currencyScale);

        // -- 10 payments summing to total. Split evenly.
        // -- Use bcdiv-then-distribute-remainder pattern to keep exact at scale.
        $payments = [];
        $perPayment = bcdiv($total, '10', $currencyScale);
        $running = '0';
        for ($i = 0; $i < 10; $i++) {
            $isLast = $i === 9;
            $amount = $isLast ? bcsub($total, $running, $currencyScale) : $perPayment;
            $running = bcadd($running, $amount, $currencyScale);
            $payments[] = [
                'amount' => $amount,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ];
        }

        // -- Seller block (full).
        $seller = [
            'address' => [
                'city' => 'Paris',
                'country_code' => 'FR',
                'postal_code' => '75001',
                'street' => '1 rue de la Paix',
            ],
            'name' => 'Test Seller S.A.',
            'tax_jurisdiction_country_code' => 'FR',
            'tax_number' => '12345678901234',
        ];

        // -- Buyer block (full).
        $buyer = [
            'address' => [
                'city' => 'Lyon',
                'country_code' => 'FR',
                'postal_code' => '69001',
                'street' => '10 place Bellecour',
            ],
            'codice_fiscale' => null,
            'contact_id' => 'contact-f15-001',
            'customer_id' => 'customer-f15-001',
            'name' => 'Test Buyer Inc.',
            'tax_number' => 'FR12345678901',
        ];

        $payload = [
            'business_date' => $businessDate,
            'buyer' => $buyer,
            'cashier_id' => $cashierUuid,
            'cashier_name' => 'F-15 Cashier',
            'consumption_mode' => null,
            'currency_code' => $currencyCode,
            'currency_scale' => $currencyScale,
            'event_time_device' => $eventTime,
            'invoice_type_code' => 'SALE',
            'line_items' => $lineItems,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => $payments,
            'receipt_uuid' => $receiptUuid,
            'seller' => $seller,
            'shift_id' => $shiftUuid,
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => $terminalUuid,
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => $transactionDiscountAmount,
            'transaction_discount_reason' => null,
            'vat_breakdown' => $vatBreakdown,
            'vat_total' => $vatTotal,
            'vouchers_redeemed' => [],
        ];

        return [
            'payload' => $payload,
            'expected' => ['accept' => true],
        ];
    }

    /**
     * Format a value to currency scale via bcadd (mirrors CurrencyScale::bcformat).
     */
    private static function bcformat(string $value, int $scale): string
    {
        return bcadd($value, '0', $scale);
    }

    /**
     * BCMath multiply with explicit rounding to `$scale`. bcmul truncates
     * at the requested scale; here we round half-away-from-zero by
     * computing at scale+1 then adjusting. For our F-15 generator inputs
     * all products land exactly at scale=2 so truncation == rounding.
     */
    private static function bcmulRounded(string $a, string $b, int $scale): string
    {
        // Compute at scale + 1 then round.
        $high = bcmul($a, $b, $scale + 1);
        // Round half-away-from-zero: add 0.5 (or -0.5 if negative) at scale+1
        // then truncate to scale. All F-15 values non-negative.
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($high, $half, $scale);

        return $rounded;
    }
}
