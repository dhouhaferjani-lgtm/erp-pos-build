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
    // Gate r1 finding 1 — v5 is a SALE/TRAINING version, nothing else
    // =================================================================

    /**
     * Adding 5 to the parseable set while narrowing the five refund guards to
     * `=== 4` left v5 with NO invoice-type restriction: a v5 declaring REFUND
     * was accepted and skipped every v4 invariant — the VOID prohibition,
     * `original_line_references`, the refund destination/settlement contract,
     * the single-cash-leg rule and the zero-discount rule. Downstream
     * `PosCoreReceiptProjection::resolveReceiptType()` keys off
     * `invoice_type_code`, so it would have projected as a RETURN: negative
     * revenue, a restocking movement, and an unvalidated original.
     */
    public function test_v5_refuses_a_refund_invoice_type(): void
    {
        $payload = $this->workedExamplePayload();
        $payload['invoice_type_code'] = 'REFUND';
        // A WELL-FORMED original reference, exactly as the gate's probe had it:
        // without one the payload is refused by `validateOriginalReceiptReference`
        // and the test would pass for the wrong reason, proving nothing about the
        // version gate.
        $payload['original_receipt_reference'] = [
            'fiscal_event_id' => '55555555-5555-4555-8555-555555555555',
            'original_business_date' => '2026-08-24',
            'original_receipt_uuid' => '66666666-6666-4666-8666-666666666666',
            'refund_reason' => 'customer asked',
        ];

        self::assertMatchesRegularExpression(
            '/payload_invoice_type_invalid/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    public function test_v5_refuses_a_void_invoice_type(): void
    {
        $payload = $this->workedExamplePayload();
        $payload['invoice_type_code'] = 'VOID';
        $payload['training_flag'] = false;
        // A WELL-FORMED original reference, exactly as the gate's probe had it:
        // without one the payload is refused by `validateOriginalReceiptReference`
        // and the test would pass for the wrong reason, proving nothing about the
        // version gate.
        $payload['original_receipt_reference'] = [
            'fiscal_event_id' => '55555555-5555-4555-8555-555555555555',
            'original_business_date' => '2026-08-24',
            'original_receipt_uuid' => '66666666-6666-4666-8666-666666666666',
            'refund_reason' => 'customer asked',
        ];

        self::assertMatchesRegularExpression(
            '/payload_invoice_type_invalid|payload_void_authoring_prohibited/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    public function test_v5_accepts_a_training_receipt(): void
    {
        $payload = $this->workedExamplePayload();
        $payload['invoice_type_code'] = 'TRAINING';
        $payload['training_flag'] = true;

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 5);
        $this->addToAssertionCount(1);
    }

    /** The v4 REFUND path is untouched: its own invoice-type rule still governs. */
    public function test_v4_still_requires_a_refund_invoice_type(): void
    {
        $payload = $this->preDiscountBasePayload();
        $payload['transaction_discount_amount'] = '0.000';
        $payload['transaction_discount_reason'] = null;
        $payload['total'] = '640.000';
        $payload['payments'][0]['amount'] = '640.000';
        $payload['vat_breakdown'] = array_map(
            static function (array $row): array {
                unset($row['discount_allocated']);

                return $row;
            },
            $payload['vat_breakdown'],
        );

        // A v4 payload that says SALE is refused by the v4 rule, not the v5 one.
        self::assertMatchesRegularExpression(
            '/event_version=4 requires invoice_type_code=REFUND|payload_missing_required/',
            (string) $this->constraintFailure($payload, 4),
        );
    }

    // =================================================================
    // Gate r1 finding 2 — the net/VAT split of the remise is PINNED
    // =================================================================

    /**
     * The mis-split the gate demonstrated: same lines, same `total`, same
     * `Σ discount_allocated`, same group grosses — but the whole allocated
     * remise is taken out of the VAT half wherever the group can carry it.
     * Every aggregate identity still holds, and the receipt under-declares
     * 30.703 TND of output VAT.
     *
     * Nothing but a pin on the SPLIT itself can catch this, which is why the
     * expected halves are re-derived from `TransactionRemiseSplit` — a pure
     * function of the allocated share, the rate and the group's own sealed line
     * sums. The group's VAT is still never recomputed from its rate.
     */
    public function test_v5_refuses_a_remise_carved_entirely_out_of_the_vat_half(): void
    {
        $payload = $this->misSplitPayload();

        // The aggregates the weaker check looked at are all still exact …
        self::assertSame('590.000', $payload['total']);
        self::assertSame(
            0,
            bccomp(bcadd($payload['subtotal'], $payload['vat_total'], 3), '590.000', 3),
        );
        // … and the receipt under-declares output VAT by tens of dinars against
        // the golden ventilation (65.453). The exact figure depends on how much
        // VAT each group can absorb; the gate's own variant moved 30.703, this
        // one moves more. What matters is that it is large and was ACCEPTED.
        self::assertSame(0, bccomp($payload['vat_total'], '27.750', 3));
        self::assertSame('37.703', bcsub('65.453', $payload['vat_total'], 3));

        self::assertMatchesRegularExpression(
            '/payload_partition_discount_vat_split_out_of_band/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    /** A single-ulp nudge of the split is refused too — the pin is EXACT. */
    public function test_v5_refuses_a_one_ulp_nudge_of_the_split(): void
    {
        $payload = $this->workedExamplePayload();
        // Move one millime from base to VAT inside the 19 % group. Group gross,
        // Σ net + Σ vat and the total identity all stay exact.
        $payload['vat_breakdown'][2]['net_amount'] = '184.374';
        $payload['vat_breakdown'][2]['vat_amount'] = '35.032';
        $payload['subtotal'] = '524.546';
        $payload['vat_total'] = '65.454';

        self::assertMatchesRegularExpression(
            '/payload_partition_discount_vat_split_out_of_band/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    /** The 100 %-comp clamp is inside the band the pin allows. */
    public function test_v5_still_accepts_the_clamped_split_of_a_full_comp(): void
    {
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $this->fullyCompedPayload(),
            eventVersion: 5,
        );
        $this->addToAssertionCount(1);
    }

    // =================================================================
    // Gate r2 finding 1 — the ALLOCATION across rate groups is pinned too
    // =================================================================

    /**
     * r1 pinned each group's net/VAT SPLIT; nothing pinned each group's SHARE.
     * Because `discNet + discVat == allocated` holds per group, moving the whole
     * remise onto a different rate group leaves EVERY aggregate identity intact
     * (`subtotal + vat_total == total`, `Σ discount_allocated == discount`,
     * per-group `gross == lineGross − allocated`) while the DECLARED VAT moves.
     *
     * The sharp case: pushing the whole 50.000 onto the EXEMPT group seals VAT
     * **71.000** — exactly the pre-D-1 figure the owner ruling exists to remove
     * — on a v5 receipt whose totals all reconcile.
     */
    public function test_v5_refuses_the_whole_remise_pushed_onto_the_exempt_group(): void
    {
        $payload = $this->remiseOnOneGroupPayload('0.00');

        // The pre-D-1 VAT total, sealed at v5, with every aggregate exact.
        self::assertSame(0, bccomp($payload['vat_total'], '71.000', 3));
        self::assertSame(
            0,
            bccomp(bcadd($payload['subtotal'], $payload['vat_total'], 3), '590.000', 3),
        );

        self::assertMatchesRegularExpression(
            '/payload_partition_discount_allocation_out_of_band/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    /** The mirror case: the whole remise onto the 19 % group under-declares 2.436. */
    public function test_v5_refuses_the_whole_remise_pushed_onto_the_top_rate_group(): void
    {
        $payload = $this->remiseOnOneGroupPayload('19.00');

        self::assertSame(0, bccomp($payload['vat_total'], '63.017', 3));

        self::assertMatchesRegularExpression(
            '/payload_partition_discount_allocation_out_of_band/',
            (string) $this->constraintFailure($payload, 5),
        );
    }

    /**
     * The band is a BAND, not an equality: it must never make the server a
     * co-author of the largest-remainder tie-break, or a future device/server
     * drift would quarantine real sales. The golden ventilation — whose residue
     * puts one ulp ABOVE the exact pro-rata share on two groups — is accepted.
     */
    public function test_v5_accepts_the_pro_rata_allocation_including_its_residue_ulps(): void
    {
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $this->workedExamplePayload(),
            eventVersion: 5,
        );
        $this->addToAssertionCount(1);
    }

    /** A 100 %-comp allocates every group its whole gross — inside the band. */
    public function test_v5_accepts_a_full_comp_allocation(): void
    {
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $this->fullyCompedPayload(),
            eventVersion: 5,
        );
        $this->addToAssertionCount(1);
    }

    /**
     * The whole remise on ONE rate group, with every other identity preserved:
     * that group's share is its full gross-capped amount, all others zero, and
     * each group's net/VAT split is still the honest one for its own share (so
     * the r1 split pin cannot catch it).
     *
     * @return array<string, mixed>
     */
    private function remiseOnOneGroupPayload(string $onRate): array
    {
        // [rate, category, lineNet, lineVat]
        $groups = [
            ['0.00', 'EXEMPT', '69.000', '0.000'],
            ['13.00', '', '200.000', '26.000'],
            ['19.00', '', '200.000', '38.000'],
            ['7.00', '', '100.000', '7.000'],
        ];

        $rows = [];
        $subtotal = '0.000';
        $vatTotal = '0.000';
        foreach ($groups as [$rate, $category, $lineNet, $lineVat]) {
            $allocated = $rate === $onRate ? '50.000' : '0.000';
            // The honest split for THIS share — so `discVat` still matches
            // `TransactionRemiseSplit::split()` and the r1 pin passes.
            $divisor = bcadd('1', bcdiv($rate, '100', 7), 7);
            $discNet = bccomp($allocated, '0', 3) === 0
                ? '0.000'
                : bcadd(bcadd(bcdiv($allocated, $divisor, 7), '0.0005', 7), '0', 3);
            $discVat = bcsub($allocated, $discNet, 3);
            $net = bcsub($lineNet, $discNet, 3);
            $vat = bcsub($lineVat, $discVat, 3);
            $subtotal = bcadd($subtotal, $net, 3);
            $vatTotal = bcadd($vatTotal, $vat, 3);
            $rows[] = [$rate, $category, $allocated, $net, $vat, $lineNet, $lineVat];
        }

        return $this->basePayload(
            total: '590.000',
            subtotal: $subtotal,
            vatTotal: $vatTotal,
            discount: '50.000',
            discountReason: 'Geste commercial',
            vatBreakdown: $rows,
            payments: [['amount' => '590.000', 'method_code' => 'CASH']],
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
     * Gate r1 finding 2's payload: the golden ventilation re-carved so each
     * group's allocated remise comes out of the VAT half wherever the group can
     * carry it (`discVat = min(allocated, line_vat)`), the rest out of the net.
     *
     * @return array<string, mixed>
     */
    private function misSplitPayload(): array
    {
        // [rate, category, allocated, lineNet, lineVat]
        $groups = [
            ['0.00', 'EXEMPT', '5.391', '69.000', '0.000'],
            ['13.00', '', '17.656', '200.000', '26.000'],
            ['19.00', '', '18.594', '200.000', '38.000'],
            ['7.00', '', '8.359', '100.000', '7.000'],
        ];

        $rows = [];
        $subtotal = '0.000';
        $vatTotal = '0.000';
        foreach ($groups as [$rate, $category, $allocated, $lineNet, $lineVat]) {
            $discVat = bccomp($allocated, $lineVat, 3) > 0 ? $lineVat : $allocated;
            $discNet = bcsub($allocated, $discVat, 3);
            $net = bcsub($lineNet, $discNet, 3);
            $vat = bcsub($lineVat, $discVat, 3);
            $subtotal = bcadd($subtotal, $net, 3);
            $vatTotal = bcadd($vatTotal, $vat, 3);
            $rows[] = [$rate, $category, $allocated, $net, $vat, $lineNet, $lineVat];
        }

        return $this->basePayload(
            total: '590.000',
            subtotal: $subtotal,
            vatTotal: $vatTotal,
            discount: '50.000',
            discountReason: 'Geste commercial',
            vatBreakdown: $rows,
            payments: [['amount' => '590.000', 'method_code' => 'CASH']],
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
