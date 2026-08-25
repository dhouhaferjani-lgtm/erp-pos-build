<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * D-1 — the server side of the post-remise VAT base (owner ruling 2026-08-25).
 *
 * The device is the fiscal source of truth: the server RE-VALIDATES the sealed
 * ventilation and never recomputes it. This suite pins what "re-validates"
 * means — exact, recomputation-free identities that a fabricated base cannot
 * satisfy — plus the forward-only version matrix (v3 stays accepted forever;
 * a v5-declared payload carrying the pre-discount base is refused).
 */
final class SaleReceiptV5PostRemiseVatBaseTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FiscalPayloadConstraintValidator;
    }

    // =================================================================
    // Version admissibility
    // =================================================================

    public function test_registry_authors_v5_and_still_parses_v1_through_v4(): void
    {
        $registry = new FiscalEventPayloadRegistry;

        self::assertSame(5, $registry->eventVersionFor(FiscalEventType::SALE_RECEIPT));
        self::assertSame([1, 2, 3, 4, 5], $registry->supportedVersionsFor(FiscalEventType::SALE_RECEIPT));
    }

    public function test_v5_uses_the_thirty_key_sale_set_not_the_refund_set(): void
    {
        self::assertSame(
            FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V5,
            $this->validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 5),
        );
        // v4 is the REFUND fan-out and must still resolve to its own 33-key set.
        self::assertSame(
            FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V4,
            $this->validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 4),
        );
    }

    // =================================================================
    // The ruling itself
    // =================================================================

    public function test_v5_accepts_a_remise_ventilated_pro_rata_across_seven_thirteen_nineteen_and_exempt(): void
    {
        $payload = $this->workedExamplePayload();

        self::assertNull($this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        ));

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 5);
        $this->addToAssertionCount(1);
    }

    /**
     * The defect D-1 exists to close: the PRE-discount base declared under v5.
     * `subtotal + vat_total` is then 640.000, not the 590.000 the customer paid.
     */
    public function test_v5_refuses_the_pre_discount_base(): void
    {
        $payload = $this->preDiscountBasePayload();
        $payload['cash_rounding_adjustment'] = '0.000';
        $payload['cash_rounding_denomination'] = '0.000';

        $this->expectException(RuntimeException::class);
        // Refused at the FIRST of the two identity evaluations (§5 total
        // arithmetic); the aggregate-consistency copy is the second belt.
        $this->expectExceptionMessageMatches(
            '/payload_total_arithmetic_mismatch|payload_aggregate_consistency:subtotal_plus_vat_ne_total/'
        );

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 5);
    }

    /**
     * FORWARD-ONLY: the very same pre-discount shape, declared at v3, is still
     * accepted — a device that has not shipped the new build has no other shape
     * to author and its sales are real.
     */
    public function test_v3_still_accepts_the_pre_discount_base_forever(): void
    {
        $payload = $this->preDiscountBasePayload();
        $payload['cash_rounding_adjustment'] = '0.000';
        $payload['cash_rounding_denomination'] = '0.000';
        // v3 rows carry the frozen five-key breakdown.
        $payload['vat_breakdown'] = array_map(
            static function (array $row): array {
                unset($row['discount_allocated']);

                return $row;
            },
            $payload['vat_breakdown'],
        );

        self::assertNull($this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 3,
        ));

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 3);
        $this->addToAssertionCount(1);
    }

    public function test_v5_refuses_a_breakdown_row_missing_discount_allocated(): void
    {
        $payload = $this->workedExamplePayload();
        unset($payload['vat_breakdown'][0]['discount_allocated']);

        self::assertMatchesRegularExpression(
            '/payload_vat_breakdown_missing_keys/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    public function test_v5_refuses_when_the_allocated_shares_do_not_sum_to_the_remise(): void
    {
        $payload = $this->workedExamplePayload();
        $payload['vat_breakdown'][0]['discount_allocated'] = '18.595';

        self::assertMatchesRegularExpression(
            '/payload_partition_discount_split_mismatch|vat_breakdown_discount_sum_ne_transaction_discount/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    /**
     * The partition check must PIN the sealed base to the sealed lines: a group
     * whose declared base is BELOW its line roll-up by more than its allocated
     * share is a fabricated base, even though every aggregate still adds up.
     */
    public function test_v5_refuses_a_base_that_is_not_a_true_split_of_the_lines(): void
    {
        $payload = $this->workedExamplePayload();
        // Move 1.000 of base from the 19 % group to the 13 % group. Σ net,
        // Σ vat, Σ gross, Σ discount_allocated and the receipt total ALL stay
        // exact — every aggregate check passes. Only the per-group anchoring to
        // the sealed lines can catch it.
        $payload['vat_breakdown'][2]['net_amount'] = '183.375';
        $payload['vat_breakdown'][2]['gross_amount'] = '218.406';
        $payload['vat_breakdown'][1]['net_amount'] = '185.375';
        $payload['vat_breakdown'][1]['gross_amount'] = '209.344';

        self::assertMatchesRegularExpression(
            '/payload_partition_discount_split_mismatch/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    public function test_v5_refuses_a_group_whose_base_exceeds_its_own_lines(): void
    {
        $payload = $this->workedExamplePayload();
        $payload['vat_breakdown'][3]['net_amount'] = '100.188';
        $payload['vat_breakdown'][3]['gross_amount'] = '106.641';
        $payload['subtotal'] = '532.547';
        $payload['total'] = '598.000';
        $payload['payments'][0]['amount'] = '598.000';

        self::assertMatchesRegularExpression(
            '/payload_partition_net_exceeds_lines|payload_partition_discount_split_mismatch/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    // =================================================================
    // 100 %-comp (G3-A fold-in)
    // =================================================================

    public function test_v5_accepts_a_fully_comped_receipt_with_no_tender_row(): void
    {
        $payload = $this->fullyCompedPayload();

        self::assertNull($this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        ));

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 5);
        $this->addToAssertionCount(1);
    }

    public function test_v5_still_requires_a_tender_row_when_the_ticket_is_not_fully_comped(): void
    {
        $payload = $this->workedExamplePayload();
        $payload['payments'] = [];

        self::assertMatchesRegularExpression(
            '/payload_payments_empty/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    public function test_v3_never_accepts_an_empty_payments_list(): void
    {
        // A LEGAL v3 ticket (pre-discount base, identity satisfied) with its
        // tender row removed. v3 has no comp allowance, so the empty list is
        // refused on its own merits — the v5 carve-out never leaks backwards.
        $payload = $this->preDiscountBasePayload();
        $payload['payments'] = [];
        $payload['vat_breakdown'] = array_map(
            static function (array $row): array {
                unset($row['discount_allocated']);

                return $row;
            },
            $payload['vat_breakdown'],
        );

        self::assertMatchesRegularExpression(
            '/payload_payments_empty/',
            (string) $this->constraintFailure($payload, 3),
        );
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function constraintFailure(array $payload, int $eventVersion): ?string
    {
        $keySetFailure = $this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: $eventVersion,
        );
        if ($keySetFailure !== null) {
            return $keySetFailure;
        }

        try {
            $this->validator->validatePerEventConstraints(
                FiscalEventType::SALE_RECEIPT,
                $payload,
                eventVersion: $eventVersion,
            );
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        self::fail('Expected the payload to be refused, but it was accepted.');
    }

    /**
     * 7 / 13 / 19 % plus an exempt group, Σ gross 640.000, a 50.000 remise at
     * TND scale 3 — the ruling's own worked example, and the SAME numbers the
     * device suite (`SaleReceiptV5Payload.test.ts`) and the PHP allocator suite
     * pin. Sealed base 524.547 + VAT 65.453 = 590.000 paid.
     *
     * @return array<string, mixed>
     */
    private function workedExamplePayload(): array
    {
        return $this->basePayload(
            total: '590.000',
            subtotal: '524.547',
            vatTotal: '65.453',
            discount: '50.000',
            discountReason: 'Geste commercial',
            vatBreakdown: [
                ['0.00', 'EXEMPT', '5.391', '63.609', '0.000', '69.000', '0.000'],
                ['13.00', '', '17.656', '184.375', '23.969', '200.000', '26.000'],
                ['19.00', '', '18.594', '184.375', '35.031', '200.000', '38.000'],
                ['7.00', '', '8.359', '92.188', '6.453', '100.000', '7.000'],
            ],
            payments: [['amount' => '590.000', 'method_code' => 'CASH']],
        );
    }

    /**
     * The pre-D-1 shape: the same cart and the same 50.000 remise, but the base
     * and VAT are the PRE-discount line roll-up.
     *
     * @return array<string, mixed>
     */
    private function preDiscountBasePayload(): array
    {
        return $this->basePayload(
            total: '590.000',
            subtotal: '569.000',
            vatTotal: '71.000',
            discount: '50.000',
            discountReason: 'Geste commercial',
            vatBreakdown: [
                ['0.00', 'EXEMPT', '0.000', '69.000', '0.000', '69.000', '0.000'],
                ['13.00', '', '0.000', '200.000', '26.000', '200.000', '26.000'],
                ['19.00', '', '0.000', '200.000', '38.000', '200.000', '38.000'],
                ['7.00', '', '0.000', '100.000', '7.000', '100.000', '7.000'],
            ],
            payments: [['amount' => '590.000', 'method_code' => 'CASH']],
        );
    }

    /** @return array<string, mixed> */
    private function fullyCompedPayload(): array
    {
        return $this->basePayload(
            total: '0.000',
            subtotal: '0.000',
            vatTotal: '0.000',
            discount: '640.000',
            discountReason: 'Comp direction',
            vatBreakdown: [
                ['0.00', 'EXEMPT', '69.000', '0.000', '0.000', '69.000', '0.000'],
                ['13.00', '', '226.000', '0.000', '0.000', '200.000', '26.000'],
                ['19.00', '', '238.000', '0.000', '0.000', '200.000', '38.000'],
                ['7.00', '', '107.000', '0.000', '0.000', '100.000', '7.000'],
            ],
            payments: [],
        );
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>  $vatBreakdown  [rate, category, allocated, net, vat, lineNet, lineVat]
     * @param  list<array{amount: string, method_code: string}>  $payments
     * @return array<string, mixed>
     */
    private function basePayload(
        string $total,
        string $subtotal,
        string $vatTotal,
        string $discount,
        ?string $discountReason,
        array $vatBreakdown,
        array $payments,
    ): array {
        $lines = [];
        $rows = [];
        foreach ($vatBreakdown as [$rate, $category, $allocated, $net, $vat, $lineNet, $lineVat]) {
            // Each group gets ONE line carrying the group's PRE-remise roll-up.
            // The sealed row is the post-remise figure; the difference between
            // the two is what the validator checks is a true split of the
            // allocated share — by subtraction, never by dividing by a rate.
            $lines[] = [
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => $lineNet,
                'line_vat' => $lineVat,
                'name' => 'Article '.$rate,
                'non_collected_subtype' => null,
                'product_id' => '00000000-0000-4000-8000-00000000000'.count($lines),
                'quantity' => '1.000',
                'sku' => 'SKU-'.$rate,
                'tax_category_code' => $category,
                'unit_price' => bcadd($lineNet, $lineVat, 3),
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => $rate,
            ];
            $rows[] = [
                'discount_allocated' => $allocated,
                'gross_amount' => bcadd($net, $vat, 3),
                'net_amount' => $net,
                'rate' => $rate,
                'tax_category_code' => $category,
                'vat_amount' => $vat,
            ];
        }

        return [
            'approval_references' => [],
            'business_date' => '2026-08-25',
            'buyer' => null,
            'cash_rounding_adjustment' => '0.000',
            'cash_rounding_denomination' => '0.000',
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Alice',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-08-25T10:00:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => $lines,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => array_map(static fn (array $p): array => [
                'amount' => $p['amount'],
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => $p['method_code'],
            ], $payments),
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => [
                    'city' => 'Tunis',
                    'country_code' => 'TN',
                    'postal_code' => '1000',
                    'street' => '1 avenue Habib Bourguiba',
                ],
                'name' => 'Cafe Tunis SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => $discount,
            'transaction_discount_reason' => $discountReason,
            'vat_breakdown' => $rows,
            'vat_total' => $vatTotal,
            'vouchers_redeemed' => [],
        ];
    }
}
