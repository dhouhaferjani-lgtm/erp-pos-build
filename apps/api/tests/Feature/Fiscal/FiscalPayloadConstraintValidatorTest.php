<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\DTOs\Canonical\BuyerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\LineItemDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\OriginalReceiptReferenceDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SellerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\VatBreakdownDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\VoucherRedemptionDTO;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use RuntimeException;
use Tests\Helpers\Fiscal\GoldenFixtureBuilder;
use Tests\Helpers\Fiscal\LargeReceiptFixtureGenerator;
use Tests\TestCase;

/**
 * Tests for `FiscalPayloadConstraintValidator::validateSaleReceiptPayload`
 * under the Pass 2A.PHP.1 27-key Candidate C-v3 contract (synthesis v5 §6).
 *
 * Covers the 12 negative cases from §6.E + positive invariants from spec
 * v7 §11.2 (PAYLOAD_KEYS equality / partition rule / scale invariant /
 * total arithmetic / discount-reason consistency) + extras + missing-key
 * + nested-shape malformed tests.
 *
 * **Tests\TestCase, not RefreshDatabase** — the validator is shape-only;
 * the in-memory FiscalEvent instances used by the CanonicalPayloadReader
 * tests bind Spatie EventSubscriber via the container, so the Laravel
 * app must be booted. No DB hit; no migrations needed.
 */
final class FiscalPayloadConstraintValidatorTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FiscalPayloadConstraintValidator;
    }

    // =================================================================
    // PAYLOAD_KEYS set equality (synthesis v5 §6.A + spec v7 §11.2)
    // =================================================================

    public function test_baseline_27_key_payload_is_accepted(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload));

        // No throw == accepted.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_payload_with_extra_28th_key_is_rejected_with_extra_field_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['unknown_extra_field'] = 'rogue';

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);

        self::assertNotNull($error);
        self::assertStringStartsWith('payload_extra_field:', $error);
        self::assertStringContainsString('unknown_extra_field', $error);
    }

    public function test_payload_missing_required_key_is_rejected_with_missing_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['seller']);

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);

        self::assertNotNull($error);
        self::assertStringStartsWith('payload_missing_required:', $error);
        self::assertStringContainsString('seller', $error);
    }

    public function test_legacy_10_key_payload_is_rejected_as_missing_required(): void
    {
        // Reject the OLD shape per synthesis v5 §8.A — every Pass 2A.PHP.2
        // consumer test that still hard-codes this shape gets per-method
        // markTestSkipped in Pass 2A.PHP.1; Pass 2A.PHP.2 migrates them.
        $legacy = [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => '0.00',
            'lines' => [],
            'payment_lines' => [],
            'subtotal' => '0.00',
            'tax_total' => '0.00',
            'total' => '0.00',
            'vat_breakdown' => [],
            'voucher_redemptions' => [],
        ];

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $legacy);

        self::assertNotNull($error);
        self::assertStringStartsWith('payload_missing_required:', $error);
    }

    // =================================================================
    // §6.E negative cases — 12 numbered scenarios
    // =================================================================

    /** §6.E.1 — duplicate partition row */
    public function test_negative_1_duplicate_partition_row_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Two breakdown rows at the same (rate, category).
        $payload['vat_breakdown'][] = $payload['vat_breakdown'][0];

        $this->expectExceptionMessageMatches('/^payload_partition_duplicate:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.2 — missing partition row */
    public function test_negative_2_missing_partition_row_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Drop the single breakdown row but keep the line.
        $payload['vat_breakdown'] = [
            [
                'gross_amount' => '0.00', 'net_amount' => '0.00', 'rate' => '0.00',
                'tax_category_code' => 'Z', 'vat_amount' => '0.00',
            ],
        ];

        $this->expectExceptionMessageMatches('/^payload_partition_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.3 — extra partition row */
    public function test_negative_3_extra_partition_row_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['vat_breakdown'][] = [
            'gross_amount' => '0.00', 'net_amount' => '0.00', 'rate' => '0.00',
            'tax_category_code' => 'O', 'vat_amount' => '0.00',
        ];

        $this->expectExceptionMessageMatches('/^payload_partition_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.4 — one-cent drift between line sum and breakdown net.
     *
     * The breakdown row stays internally consistent (net + vat == gross)
     * to dodge the per-row arithmetic check; the drift is between the
     * line_subtotal sum and the breakdown net.
     */
    public function test_negative_4_one_cent_drift_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // line sums to 10.00 / 2.00; bump breakdown net+gross by 0.01 each.
        $payload['vat_breakdown'][0]['net_amount'] = '10.01';
        $payload['vat_breakdown'][0]['gross_amount'] = '12.01';
        // Keep total arithmetic balanced: subtotal=10.01, vat_total=2.00, total=12.01.
        $payload['subtotal'] = '10.01';
        $payload['total'] = '12.01';
        $payload['payments'][0]['amount'] = '12.01';

        $this->expectExceptionMessageMatches('/^payload_partition_net_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.5 — mixed 0% categories with distinct tax_category_code (must succeed). */
    public function test_negative_5_mixed_zero_percent_categories_are_accepted_when_partitioned_correctly(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-06-multi-vat-rate-eur'];
        // F-06 already has multiple 0%-* rows + matched lines → must pass.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    /** §6.E.6 — wrong scale on unit_price */
    public function test_negative_6_wrong_money_scale_is_rejected_with_scale_mismatch_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // currency_scale = 2 but unit_price emitted at scale 3.
        $payload['line_items'][0]['unit_price'] = '10.000';

        $this->expectExceptionMessageMatches('/^payload_money_scale_mismatch:field=line_items\[0\]\.unit_price/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.7 — total arithmetic mismatch */
    public function test_negative_7_total_arithmetic_mismatch_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Bump total without bumping subtotal/vat_total.
        $payload['total'] = '15.00';
        // Re-balance partition so partition-mismatch doesn't fire first
        // (we want total_arithmetic to fire). subtotal+vat_total=10+2=12
        // != total+discount=15+0=15 → reject.

        $this->expectExceptionMessageMatches('/^payload_total_arithmetic_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.8 — negative transaction_discount (regex rejects leading minus) */
    public function test_negative_8_negative_transaction_discount_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['transaction_discount_amount'] = '-5.00';

        $this->expectExceptionMessageMatches('/^payload_money_scale_mismatch:field=transaction_discount_amount/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.9 — discount-reason consistency at scale=2 zero, reason set (reject) */
    public function test_negative_9_discount_zero_at_scale_2_with_reason_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['transaction_discount_amount'] = '0.00';
        $payload['transaction_discount_reason'] = 'seasonal';

        $this->expectExceptionMessageMatches('/^payload_discount_reason_mismatch:amount=0\.00:reason_present=true/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.10 — discount-reason consistency at scale=2 zero, reason null (pass) */
    public function test_negative_10_discount_zero_at_scale_2_with_null_reason_passes(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['transaction_discount_amount'] = '0.00';
        $payload['transaction_discount_reason'] = null;

        // No throw expected.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    /** §6.E.11 — discount-reason consistency non-zero with null reason (reject) */
    public function test_negative_11_discount_non_zero_with_null_reason_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Rebalance: discount=5.00, total=7.00 → 10+2 == 7+5 ✓
        $payload['transaction_discount_amount'] = '5.00';
        $payload['transaction_discount_reason'] = null;
        $payload['total'] = '7.00';

        $this->expectExceptionMessageMatches('/^payload_discount_reason_mismatch:amount=5\.00:reason_present=false/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.12 — discount-reason consistency at scale=0 zero TND, null reason (pass) */
    public function test_negative_12_discount_zero_at_scale_0_tnd_with_null_reason_passes(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Convert to a scale-0 TND-style currency.
        $payload['currency_code'] = 'JPY';
        $payload['currency_scale'] = 0;
        $payload['transaction_discount_amount'] = '0';
        $payload['transaction_discount_reason'] = null;
        $payload['subtotal'] = '10';
        $payload['vat_total'] = '2';
        $payload['total'] = '12';
        $payload['line_items'] = [
            array_replace($payload['line_items'][0], [
                'unit_price' => '10',
                'line_subtotal' => '10',
                'line_vat' => '2',
                'line_discount_amount' => '0',
            ]),
        ];
        $payload['vat_breakdown'] = [
            [
                'gross_amount' => '12',
                'net_amount' => '10',
                'rate' => '20.00',
                'tax_category_code' => '',
                'vat_amount' => '2',
            ],
        ];
        $payload['payments'] = [
            array_replace($payload['payments'][0], ['amount' => '12']),
        ];

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    // =================================================================
    // Positive invariants from spec v7 §11.2
    // =================================================================

    public function test_invariant_partition_rule_holds_when_lines_correctly_aggregate(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-05-multi-line-mixed-categories-eur'];
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_invariant_scale_invariant_holds_at_currency_scale_2(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_invariant_total_arithmetic_holds_with_zero_discount(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    // =================================================================
    // Nested-shape malformed tests (per-event)
    // =================================================================

    public function test_malformed_seller_missing_tax_number_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['seller']['tax_number']);

        $this->expectExceptionMessageMatches('/^payload_seller_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_seller_with_extra_key_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['extra_field'] = 'rogue';

        $this->expectExceptionMessageMatches('/^payload_seller_extra_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_buyer_with_extra_key_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];
        $payload['buyer']['extra_field'] = 'rogue';

        $this->expectExceptionMessageMatches('/^payload_buyer_extra_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_line_item_missing_sku_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['line_items'][0]['sku']);

        $this->expectExceptionMessageMatches('/^payload_line_item_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_line_item_with_extra_key_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['line_items'][0]['extra_field'] = 'rogue';

        $this->expectExceptionMessageMatches('/^payload_line_item_extra_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_payment_missing_method_code_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['payments'][0]['method_code']);

        $this->expectExceptionMessageMatches('/^payload_payment_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_payment_foreign_currency_pair_half_null_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-03-split-foreign-currency-eur-usd'];
        $payload['payments'][1]['foreign_currency_code'] = null;

        $this->expectExceptionMessageMatches('/^payload_payment_foreign_currency_pair_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_vat_breakdown_missing_rate_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['vat_breakdown'][0]['rate']);

        $this->expectExceptionMessageMatches('/^payload_vat_breakdown_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_original_receipt_reference_present_on_sale_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['original_receipt_reference'] = [
            'fiscal_event_id' => '00000000-0000-4000-8000-000000000001',
            'original_business_date' => '2026-05-19',
            'original_receipt_uuid' => '00000000-0000-4000-8000-000000000002',
            'refund_reason' => 'should not be here',
        ];

        $this->expectExceptionMessageMatches('/^payload_invoice_type_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_refund_without_original_receipt_reference_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-09-refund-eur'];
        $payload['original_receipt_reference'] = null;

        $this->expectExceptionMessageMatches('/^payload_invoice_type_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_invoice_type_code_invalid_enum_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['invoice_type_code'] = 'BOGUS';

        $this->expectExceptionMessageMatches('/^payload_field_invalid:invoice_type_code/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_consumption_mode_invalid_enum_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['consumption_mode'] = 'drive-through';

        $this->expectExceptionMessageMatches('/^payload_field_invalid:consumption_mode/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_country_code_lowercase_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['tax_jurisdiction_country_code'] = 'fr';

        $this->expectExceptionMessageMatches('/^payload_seller_tax_jurisdiction_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_seller_tax_number_with_control_byte_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['tax_number'] = "1234\x01567";

        $this->expectExceptionMessageMatches('/^payload_tax_number_invalid:seller\.tax_number/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_seller_tax_number_with_trailing_whitespace_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['tax_number'] = '12345678901234 ';

        $this->expectExceptionMessageMatches('/^payload_tax_number_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_line_items_list_empty_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['line_items'] = [];

        $this->expectExceptionMessage('payload_line_items_empty');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_payments_list_empty_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['payments'] = [];

        $this->expectExceptionMessage('payload_payments_empty');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_vat_breakdown_list_empty_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['vat_breakdown'] = [];

        $this->expectExceptionMessage('payload_vat_breakdown_empty');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    // =================================================================
    // F-15 large-receipt acceptance — synthesis v5 §10
    // =================================================================

    public function test_f15_large_receipt_50_lines_10_payments_8_breakdown_is_accepted_under_100ms(): void
    {
        $gen = LargeReceiptFixtureGenerator::generate();
        $payload = $gen['payload'];

        // Sanity — generator produces 50 lines + 10 payments + 8 breakdown rows.
        self::assertCount(50, $payload['line_items']);
        self::assertCount(10, $payload['payments']);
        self::assertCount(8, $payload['vat_breakdown']);

        $start = hrtime(true);
        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);
        self::assertNull($error, 'F-15 key set must be exactly 27');

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(100, $durationMs, "F-15 validator took {$durationMs}ms; expected < 100ms");
    }

    public function test_f15_large_receipt_matches_committed_fixture_bytes(): void
    {
        $gen = LargeReceiptFixtureGenerator::generate();
        $generatedBytes = GoldenFixtureBuilder::jcsCanonicalEncode($gen['payload']);
        $committedPath = __DIR__.'/../../Fixtures/Fiscal/sale-receipt-golden/v4/F-15-large/payload.json';

        self::assertFileExists($committedPath);
        $committedBytes = file_get_contents($committedPath);
        self::assertSame($committedBytes, $generatedBytes, 'Committed F-15 payload.json must match generator output byte-for-byte (deterministic re-generation invariant).');
    }

    // =================================================================
    // Defensive — invalid currency_scale at the boundary
    // =================================================================

    public function test_invalid_currency_scale_negative_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['currency_scale'] = -1;

        $this->expectExceptionMessageMatches('/^payload_currency_scale_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_invalid_currency_code_lowercase_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['currency_code'] = 'eur';

        $this->expectExceptionMessageMatches('/^payload_currency_code_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_invalid_training_flag_not_bool_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['training_flag'] = 'false';

        $this->expectExceptionMessageMatches('/^payload_field_invalid:training_flag/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    // =================================================================
    // CanonicalPayloadReader integration — typed DTO round-trip
    // =================================================================

    public function test_canonical_payload_reader_builds_typed_view_from_baseline_payload(): void
    {
        // The reader operates on a FiscalEvent with an already-verified
        // payload; here we exercise the static SaleReceiptPayload+sub-DTO
        // construction path without a DB-backed FiscalEvent. That path is
        // the same code the reader's foreach loops invoke.
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];

        $dto = SaleReceiptPayload::fromArray($payload);
        $seller = SellerDTO::fromArray($dto->seller);
        $buyer = $dto->buyer === null ? null : BuyerDTO::fromArray($dto->buyer);

        self::assertSame('SALE', $dto->invoiceTypeCode);
        self::assertSame('12345678901234', $seller->taxNumber);
        self::assertSame('FR', $seller->taxJurisdictionCountryCode);
        self::assertSame('Paris', $seller->address->city);
        self::assertNotNull($buyer);
        self::assertSame('FR12345678901', $buyer->taxNumber);
        self::assertNotNull($buyer->address);
        self::assertSame('FR', $buyer->address->countryCode);

        // Line items + payments + vat_breakdown DTO construction.
        foreach ($dto->lineItems as $row) {
            $line = LineItemDTO::fromArray($row);
            self::assertNotSame('', $line->productId);
        }
        foreach ($dto->payments as $row) {
            $p = PaymentDTO::fromArray($row);
            self::assertNotSame('', $p->methodCode);
        }
        foreach ($dto->vatBreakdown as $row) {
            $v = VatBreakdownDTO::fromArray($row);
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $v->netAmount);
        }
    }

    public function test_canonical_payload_reader_builds_original_receipt_reference_dto_on_refund(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-09-refund-eur'];
        $dto = SaleReceiptPayload::fromArray($payload);
        self::assertNotNull($dto->originalReceiptReference);
        $ref = OriginalReceiptReferenceDTO::fromArray($dto->originalReceiptReference);
        self::assertSame('REFUND', $dto->invoiceTypeCode);
        self::assertSame('customer return', $ref->refundReason);
    }

    public function test_canonical_payload_reader_builds_voucher_redemption_dtos(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-04-voucher-redemption-eur'];
        $dto = SaleReceiptPayload::fromArray($payload);
        self::assertCount(1, $dto->vouchersRedeemed);
        $v = VoucherRedemptionDTO::fromArray($dto->vouchersRedeemed[0]);
        self::assertSame('GC-ABC-123', $v->voucherCode);
        self::assertSame('24.00', $v->redeemedAmount);
    }

    public function test_canonical_payload_reader_for_sale_receipt_assembles_view_over_fiscal_event(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];

        // Build an in-memory FiscalEvent (no DB) with the payload casted.
        $event = new FiscalEvent;
        $event->id = '11111111-1111-4111-8111-111111111111';
        $event->event_type = FiscalEventType::SALE_RECEIPT;
        $event->payload = $payload;

        $reader = new CanonicalPayloadReader;
        $view = $reader->forSaleReceipt($event);

        self::assertSame('SALE', $view->payload->invoiceTypeCode);
        self::assertSame('Default Seller S.A.', $view->seller()->name);
        self::assertNotNull($view->buyer());
        self::assertSame('Acme B2B SARL', $view->buyer()?->name);
        self::assertCount(1, $view->lineItems());
        self::assertCount(1, $view->payments());
        self::assertCount(1, $view->vatBreakdown());
        self::assertNull($view->originalReceiptReference());
    }

    public function test_canonical_payload_reader_rejects_wrong_event_type(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $event = new FiscalEvent;
        $event->id = '22222222-2222-4222-8222-222222222222';
        $event->event_type = FiscalEventType::CHAIN_BREAK_DETECTED;
        $event->payload = $payload;

        $reader = new CanonicalPayloadReader;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CanonicalPayloadReader::forSaleReceipt called with event_type=/');
        $reader->forSaleReceipt($event);
    }

    public function test_canonical_payload_reader_rejects_null_payload(): void
    {
        $event = new FiscalEvent;
        $event->id = '33333333-3333-4333-8333-333333333333';
        $event->event_type = FiscalEventType::SALE_RECEIPT;
        $event->payload = null;

        $reader = new CanonicalPayloadReader;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CanonicalPayloadReader::forSaleReceipt called on fiscal_event_id=/');
        $reader->forSaleReceipt($event);
    }

    // =================================================================
    // All golden fixtures F-1..F-14 are accepted (round-trip guard)
    // =================================================================

    public function test_all_golden_fixtures_f1_to_f14_pass_validator(): void
    {
        $fixtures = GoldenFixtureBuilder::all();
        self::assertCount(14, $fixtures, 'Builder must produce exactly F-1 through F-14.');

        foreach ($fixtures as $slug => $payload) {
            try {
                $keysetError = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);
                self::assertNull($keysetError, "Fixture {$slug} key-set rejected: {$keysetError}");
                $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
            } catch (RuntimeException $e) {
                self::fail("Fixture {$slug} rejected by validator: {$e->getMessage()}");
            }
        }
        $this->addToAssertionCount(1);
    }
}
