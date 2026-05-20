<?php

declare(strict_types=1);

namespace Tests\Helpers\Fiscal;

/**
 * Builds the F-1..F-14 SALE_RECEIPT golden vector payloads per synthesis
 * v5 §10 matrix.
 *
 * Each fixture is a TYPED associative array — encoded to JCS-canonical
 * JSON (sorted keys, no insignificant whitespace) when written to disk
 * by `tests/Fixtures/fiscal/sale-receipt-golden/v4/F-N-<slug>/payload.json`.
 *
 * Test-side helper — NOT production code.
 *
 * **Hand-authored**: each fixture's numbers were computed by hand so
 * total-arithmetic + partition equality hold at the chosen scale.
 *
 * Fixture-naming convention: keys returned by `all()` map slug → payload.
 * Slug is the on-disk directory name (sortable + readable).
 */
final class GoldenFixtureBuilder
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'F-01-baseline-eur' => self::f01BaselineEur(),
            'F-02-multi-payment-eur' => self::f02MultiPaymentEur(),
            'F-03-split-foreign-currency-eur-usd' => self::f03SplitForeignCurrency(),
            'F-04-voucher-redemption-eur' => self::f04VoucherRedemption(),
            'F-05-multi-line-mixed-categories-eur' => self::f05MultiLineMixedCategories(),
            'F-06-multi-vat-rate-eur' => self::f06MultiVatRate(),
            'F-07-b2b-buyer-eur' => self::f07B2bBuyer(),
            'F-08-it-non-collected-lottery-eur' => self::f08ItNonCollected(),
            'F-09-refund-eur' => self::f09Refund(),
            'F-10-void-eur' => self::f10Void(),
            'F-11-training-flag-eur' => self::f11Training(),
            'F-12-hospitality-dine-in-eur' => self::f12Hospitality(),
            'F-13-non-ascii-eur' => self::f13NonAscii(),
            'F-14-null-optionals-exhaustive-eur' => self::f14NullOptionals(),
        ];
    }

    /**
     * Minimal accepted baseline — 1 line, 1 VAT rate, 1 cash payment.
     *
     * @return array<string, mixed>
     */
    private static function f01BaselineEur(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'line_items' => [self::lineItem(['unit_price' => '10.00', 'line_subtotal' => '10.00', 'line_vat' => '2.00'])],
            'payments' => [self::payment(['amount' => '12.00'])],
            'subtotal' => '10.00',
            'vat_total' => '2.00',
            'total' => '12.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '10.00', 'vat_amount' => '2.00', 'gross_amount' => '12.00'])],
        ]);
    }

    /**
     * Multi-payment cash + card with the same total.
     *
     * @return array<string, mixed>
     */
    private static function f02MultiPaymentEur(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000002',
            'line_items' => [self::lineItem(['unit_price' => '15.00', 'line_subtotal' => '15.00', 'line_vat' => '3.00'])],
            'payments' => [
                self::payment(['amount' => '10.00', 'method_code' => 'CASH']),
                self::payment(['amount' => '8.00', 'method_code' => 'CARD', 'instrument_type' => 'visa', 'instrument_serial' => '4242']),
            ],
            'subtotal' => '15.00',
            'vat_total' => '3.00',
            'total' => '18.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '15.00', 'vat_amount' => '3.00', 'gross_amount' => '18.00'])],
        ]);
    }

    /**
     * Split payment with foreign currency leg (EUR primary + USD secondary).
     *
     * @return array<string, mixed>
     */
    private static function f03SplitForeignCurrency(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000003',
            'line_items' => [self::lineItem(['unit_price' => '50.00', 'line_subtotal' => '50.00', 'line_vat' => '10.00'])],
            'payments' => [
                self::payment(['amount' => '30.00', 'method_code' => 'CARD', 'instrument_type' => 'visa', 'instrument_serial' => '4111']),
                self::payment([
                    'amount' => '30.00',
                    'method_code' => 'CASH_FX',
                    'foreign_currency_amount' => '32.50',
                    'foreign_currency_code' => 'USD',
                ]),
            ],
            'subtotal' => '50.00',
            'vat_total' => '10.00',
            'total' => '60.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '50.00', 'vat_amount' => '10.00', 'gross_amount' => '60.00'])],
        ]);
    }

    /**
     * Voucher redemption.
     *
     * @return array<string, mixed>
     */
    private static function f04VoucherRedemption(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000004',
            'line_items' => [self::lineItem(['unit_price' => '20.00', 'line_subtotal' => '20.00', 'line_vat' => '4.00'])],
            'payments' => [self::payment(['amount' => '24.00', 'method_code' => 'VOUCHER', 'instrument_type' => 'gift_card', 'instrument_serial' => 'GC-ABC-123'])],
            'subtotal' => '20.00',
            'vat_total' => '4.00',
            'total' => '24.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '20.00', 'vat_amount' => '4.00', 'gross_amount' => '24.00'])],
            'vouchers_redeemed' => [
                ['redeemed_amount' => '24.00', 'voucher_code' => 'GC-ABC-123'],
            ],
        ]);
    }

    /**
     * Multi-line with mixed (vat_rate, tax_category_code) groups.
     *
     * @return array<string, mixed>
     */
    private static function f05MultiLineMixedCategories(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000005',
            'line_items' => [
                self::lineItem(['unit_price' => '10.00', 'line_subtotal' => '10.00', 'line_vat' => '2.00', 'vat_rate' => '20.00', 'tax_category_code' => '', 'name' => 'Standard A']),
                self::lineItem(['unit_price' => '20.00', 'line_subtotal' => '20.00', 'line_vat' => '4.00', 'vat_rate' => '20.00', 'tax_category_code' => '', 'name' => 'Standard B']),
                self::lineItem(['unit_price' => '5.00',  'line_subtotal' => '5.00',  'line_vat' => '0.00', 'vat_rate' => '0.00', 'tax_category_code' => 'Z', 'name' => 'Zero-rated']),
            ],
            'payments' => [self::payment(['amount' => '41.00'])],
            'subtotal' => '35.00',
            'vat_total' => '6.00',
            'total' => '41.00',
            'vat_breakdown' => [
                self::vatBreakdown(['net_amount' => '30.00', 'vat_amount' => '6.00', 'gross_amount' => '36.00', 'rate' => '20.00', 'tax_category_code' => '']),
                self::vatBreakdown(['net_amount' => '5.00',  'vat_amount' => '0.00', 'gross_amount' => '5.00',  'rate' => '0.00',  'tax_category_code' => 'Z']),
            ],
        ]);
    }

    /**
     * Multi-VAT-rate breakdown: standard / zero / exempt / out-of-scope.
     *
     * @return array<string, mixed>
     */
    private static function f06MultiVatRate(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000006',
            'line_items' => [
                self::lineItem(['unit_price' => '10.00', 'line_subtotal' => '10.00', 'line_vat' => '2.00', 'vat_rate' => '20.00', 'tax_category_code' => '', 'name' => 'Standard taxable']),
                self::lineItem(['unit_price' => '5.00',  'line_subtotal' => '5.00',  'line_vat' => '0.00', 'vat_rate' => '0.00', 'tax_category_code' => 'Z', 'name' => 'Zero-rated', 'sku' => 'SKU-Z']),
                self::lineItem(['unit_price' => '3.00',  'line_subtotal' => '3.00',  'line_vat' => '0.00', 'vat_rate' => '0.00', 'tax_category_code' => 'E', 'name' => 'Exempt', 'sku' => 'SKU-E']),
                self::lineItem(['unit_price' => '2.00',  'line_subtotal' => '2.00',  'line_vat' => '0.00', 'vat_rate' => '0.00', 'tax_category_code' => 'O', 'name' => 'Out-of-scope', 'sku' => 'SKU-O']),
            ],
            'payments' => [self::payment(['amount' => '22.00'])],
            'subtotal' => '20.00',
            'vat_total' => '2.00',
            'total' => '22.00',
            'vat_breakdown' => [
                self::vatBreakdown(['net_amount' => '10.00', 'vat_amount' => '2.00', 'gross_amount' => '12.00', 'rate' => '20.00', 'tax_category_code' => '']),
                self::vatBreakdown(['net_amount' => '5.00',  'vat_amount' => '0.00', 'gross_amount' => '5.00',  'rate' => '0.00', 'tax_category_code' => 'Z']),
                self::vatBreakdown(['net_amount' => '3.00',  'vat_amount' => '0.00', 'gross_amount' => '3.00',  'rate' => '0.00', 'tax_category_code' => 'E']),
                self::vatBreakdown(['net_amount' => '2.00',  'vat_amount' => '0.00', 'gross_amount' => '2.00',  'rate' => '0.00', 'tax_category_code' => 'O']),
            ],
        ]);
    }

    /**
     * B2B buyer with full address + tax_number.
     *
     * @return array<string, mixed>
     */
    private static function f07B2bBuyer(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000007',
            'buyer' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de Rivoli'],
                'codice_fiscale' => null,
                'contact_id' => null,
                'customer_id' => 'cust-007',
                'name' => 'Acme B2B SARL',
                'tax_number' => 'FR12345678901',
            ],
            'line_items' => [self::lineItem(['unit_price' => '100.00', 'line_subtotal' => '100.00', 'line_vat' => '20.00'])],
            'payments' => [self::payment(['amount' => '120.00'])],
            'subtotal' => '100.00',
            'vat_total' => '20.00',
            'total' => '120.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '100.00', 'vat_amount' => '20.00', 'gross_amount' => '120.00'])],
        ]);
    }

    /**
     * IT-style non_collected_subtype + lottery_code populated.
     *
     * @return array<string, mixed>
     */
    private static function f08ItNonCollected(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000008',
            'lottery_code' => 'ABCD1234EFGH',
            'line_items' => [
                self::lineItem(['unit_price' => '10.00', 'line_subtotal' => '10.00', 'line_vat' => '0.00', 'vat_rate' => '0.00', 'tax_category_code' => 'E', 'non_collected_subtype' => 'omaggio', 'name' => 'Gift item']),
            ],
            'payments' => [self::payment(['amount' => '10.00'])],
            'subtotal' => '10.00',
            'vat_total' => '0.00',
            'total' => '10.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '10.00', 'vat_amount' => '0.00', 'gross_amount' => '10.00', 'rate' => '0.00', 'tax_category_code' => 'E'])],
        ]);
    }

    /**
     * Refund with original_receipt_reference.
     *
     * @return array<string, mixed>
     */
    private static function f09Refund(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000009',
            'invoice_type_code' => 'REFUND',
            'original_receipt_reference' => [
                'fiscal_event_id' => '99999999-9999-4999-8999-999999999999',
                'original_business_date' => '2026-05-19',
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'customer return',
            ],
            'line_items' => [self::lineItem(['unit_price' => '10.00', 'line_subtotal' => '10.00', 'line_vat' => '2.00'])],
            'payments' => [self::payment(['amount' => '12.00'])],
            'subtotal' => '10.00',
            'vat_total' => '2.00',
            'total' => '12.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '10.00', 'vat_amount' => '2.00', 'gross_amount' => '12.00'])],
        ]);
    }

    /**
     * VOID with minimal payment block.
     *
     * @return array<string, mixed>
     */
    private static function f10Void(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000010',
            'invoice_type_code' => 'VOID',
            'original_receipt_reference' => [
                'fiscal_event_id' => '88888888-8888-4888-8888-888888888888',
                'original_business_date' => '2026-05-19',
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000002',
                'refund_reason' => 'cashier error',
            ],
            'line_items' => [self::lineItem(['unit_price' => '5.00', 'line_subtotal' => '5.00', 'line_vat' => '1.00'])],
            'payments' => [self::payment(['amount' => '6.00'])],
            'subtotal' => '5.00',
            'vat_total' => '1.00',
            'total' => '6.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '5.00', 'vat_amount' => '1.00', 'gross_amount' => '6.00'])],
        ]);
    }

    /**
     * Training-mode (training_flag: true).
     *
     * @return array<string, mixed>
     */
    private static function f11Training(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000011',
            'training_flag' => true,
            'invoice_type_code' => 'TRAINING',
            'line_items' => [self::lineItem(['unit_price' => '10.00', 'line_subtotal' => '10.00', 'line_vat' => '2.00'])],
            'payments' => [self::payment(['amount' => '12.00'])],
            'subtotal' => '10.00',
            'vat_total' => '2.00',
            'total' => '12.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '10.00', 'vat_amount' => '2.00', 'gross_amount' => '12.00'])],
        ]);
    }

    /**
     * Hospitality (consumption_mode=dine_in + table_id set).
     *
     * @return array<string, mixed>
     */
    private static function f12Hospitality(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000012',
            'consumption_mode' => 'dine_in',
            'table_id' => 'T-007',
            'line_items' => [self::lineItem(['unit_price' => '15.00', 'line_subtotal' => '15.00', 'line_vat' => '3.00', 'name' => 'Dine-in meal'])],
            'payments' => [self::payment(['amount' => '18.00'])],
            'subtotal' => '15.00',
            'vat_total' => '3.00',
            'total' => '18.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '15.00', 'vat_amount' => '3.00', 'gross_amount' => '18.00'])],
        ]);
    }

    /**
     * Non-ASCII names — Arabic, French, German chars.
     *
     * @return array<string, mixed>
     */
    private static function f13NonAscii(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000013',
            'cashier_name' => 'François Müller الكاشير',
            'seller' => self::seller(['name' => 'Café Größenwahn al-Tunisi', 'tax_jurisdiction_country_code' => 'TN', 'tax_number' => '1234567A/A/A/000']),
            'line_items' => [
                self::lineItem(['name' => 'Crème brûlée', 'unit_price' => '8.00', 'line_subtotal' => '8.00', 'line_vat' => '0.00', 'vat_rate' => '0.00', 'tax_category_code' => 'Z']),
            ],
            'payments' => [self::payment(['amount' => '8.00'])],
            'subtotal' => '8.00',
            'vat_total' => '0.00',
            'total' => '8.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '8.00', 'vat_amount' => '0.00', 'gross_amount' => '8.00', 'rate' => '0.00', 'tax_category_code' => 'Z'])],
        ]);
    }

    /**
     * Null-optionals exhaustive — every nullable field set null.
     *
     * @return array<string, mixed>
     */
    private static function f14NullOptionals(): array
    {
        return self::baseEnvelope([
            'receipt_uuid' => '00000000-0000-4000-8000-000000000014',
            'buyer' => null,
            'consumption_mode' => null,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'table_id' => null,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'line_items' => [self::lineItem([
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'non_collected_subtype' => null,
                'unit_price' => '10.00',
                'line_subtotal' => '10.00',
                'line_vat' => '2.00',
            ])],
            'payments' => [self::payment([
                'amount' => '12.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
            ])],
            'subtotal' => '10.00',
            'vat_total' => '2.00',
            'total' => '12.00',
            'vat_breakdown' => [self::vatBreakdown(['net_amount' => '10.00', 'vat_amount' => '2.00', 'gross_amount' => '12.00'])],
            'vouchers_redeemed' => [],
        ]);
    }

    // ---------------- helpers ----------------

    /**
     * Default-populated SALE_RECEIPT envelope; overrides merge on top.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function baseEnvelope(array $overrides): array
    {
        $defaults = [
            'business_date' => '2026-05-20',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000000',
            'seller' => self::seller([]),
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '0.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '0.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        return array_replace($defaults, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function seller(array $overrides): array
    {
        return array_replace([
            'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
            'name' => 'Default Seller S.A.',
            'tax_jurisdiction_country_code' => 'FR',
            'tax_number' => '12345678901234',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function lineItem(array $overrides): array
    {
        return array_replace([
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => '10.00',
            'line_vat' => '2.00',
            'name' => 'Default item',
            'non_collected_subtype' => null,
            'product_id' => 'prod-default',
            'quantity' => '1.000',
            'sku' => 'SKU-DEFAULT',
            'tax_category_code' => '',
            'unit_price' => '10.00',
            'vat_rate' => '20.00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function payment(array $overrides): array
    {
        return array_replace([
            'amount' => '12.00',
            'foreign_currency_amount' => null,
            'foreign_currency_code' => null,
            'instrument_serial' => null,
            'instrument_type' => null,
            'method_code' => 'CASH',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function vatBreakdown(array $overrides): array
    {
        return array_replace([
            'gross_amount' => '12.00',
            'net_amount' => '10.00',
            'rate' => '20.00',
            'tax_category_code' => '',
            'vat_amount' => '2.00',
        ], $overrides);
    }

    /**
     * JCS-canonical JSON encoder: sorted keys at every depth, no
     * insignificant whitespace, integer-only numbers, UTF-8 strings
     * with backslash escapes only for forbidden chars (control bytes +
     * U+2028 / U+2029).
     *
     * Phase 1 §4 contract. Mirrors `StrictCanonicalParser` accept-side.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function jcsCanonicalEncode(array $payload): string
    {
        $sorted = self::sortRecursive($payload);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json;
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        // JSON list — preserve order, recurse into each element.
        if (count($value) > 0 && array_is_list($value)) {
            return array_map([self::class, 'sortRecursive'], $value);
        }
        // Empty array — preserve as object (no sort needed but recurse N/A).
        if (count($value) === 0) {
            return $value;
        }
        // Assoc object — recurse + sort by key.
        $sorted = [];
        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $k) {
            $sorted[$k] = self::sortRecursive($value[$k]);
        }

        return $sorted;
    }
}
