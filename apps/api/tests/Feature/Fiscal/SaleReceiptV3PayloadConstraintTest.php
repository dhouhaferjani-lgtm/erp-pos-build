<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SaleReceiptCanonicalView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SellerDTO;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * SALE_RECEIPT v3 cash-rounding binds (spec §4.1 + §4.4).
 *
 * Shape-only: Tests\TestCase, no RefreshDatabase.
 */
final class SaleReceiptV3PayloadConstraintTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FiscalPayloadConstraintValidator;
    }

    /**
     * TND (scale 3), zero-VAT, one line, one CASH leg.
     * exact_total 9.973 → D 0.050 → rounded 9.950, adj -0.023.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function v3Payload(array $overrides = []): array
    {
        $payload = [
            'approval_references' => [],
            'business_date' => '2026-07-27',
            'buyer' => null,
            'cash_rounding_adjustment' => '-0.023',
            'cash_rounding_denomination' => '0.050',
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Cashier V3',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-07-27T10:15:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [$this->lineItem()],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [$this->payment('9.950')],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000003',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue de Rome'],
                'name' => 'Pharma Bio SARL',
                'tax_jurisdiction_country_code' => 'TN',
                // TN compact form is <7-8 digits><2 letters><3 digits>
                // (`FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS`).
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '9.973',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '9.950',
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [$this->vatBreakdown('9.973')],
            'vat_total' => '0.000',
            'vouchers_redeemed' => [],
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lineItem(array $overrides = []): array
    {
        return array_replace([
            'gtin' => null,
            'line_discount_amount' => '0.000',
            'line_discount_reason' => null,
            'line_subtotal' => '9.973',
            'line_vat' => '0.000',
            'name' => 'Paracétamol 500mg',
            'non_collected_subtype' => null,
            'product_id' => 'prod-v3-1',
            'quantity' => '1.000',
            'sku' => 'SKU-V3-1',
            'tax_category_code' => 'Z',
            'unit_price' => '9.973',
            'variant_id' => null,
            'variant_name' => null,
            'variant_sku' => null,
            'vat_rate' => '0.00',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $amount): array
    {
        return [
            'amount' => $amount,
            'foreign_currency_amount' => null,
            'foreign_currency_code' => null,
            'instrument_serial' => null,
            'instrument_type' => null,
            'method_code' => 'CASH',
        ];
    }

    /**
     * Zero-VAT TND (scale 3) breakdown row: gross == net, vat == 0.
     *
     * @return array<string, mixed>
     */
    private function vatBreakdown(string $net): array
    {
        return [
            'gross_amount' => $net,
            'net_amount' => $net,
            'rate' => '0.00',
            'tax_category_code' => 'Z',
            'vat_amount' => '0.000',
        ];
    }

    public function test_v3_rounded_payload_is_accepted(): void
    {
        $payload = $this->v3Payload();

        self::assertNull($this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3
        ));

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
        $this->addToAssertionCount(1);
    }

    public function test_v3_unrounded_payload_uses_canonical_zeroes(): void
    {
        $payload = $this->v3Payload([
            'cash_rounding_adjustment' => '0.000',
            'cash_rounding_denomination' => '0.000',
            'total' => '9.973',
            'payments' => [$this->payment('9.973')],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
        $this->addToAssertionCount(1);
    }

    public function test_identity_fails_when_total_was_not_replaced(): void
    {
        // adj set but total left at the exact value — the V3 builder skipped
        // its "replace the total key" step.
        $payload = $this->v3Payload(['total' => '9.973']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_total_arithmetic_mismatch/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_suppression_payload_is_quarantined(): void
    {
        // total suppressed to 5.000 with a -95.000 "adjustment": the identity
        // still balances, so the BINDS are what catch it.
        $payload = $this->v3Payload([
            'total' => '5.000',
            'cash_rounding_adjustment' => '-95.000',
            'subtotal' => '100.000',
            'line_items' => [$this->lineItem([
                'line_subtotal' => '100.000',
                'name' => 'Suppressed',
                'unit_price' => '100.000',
            ])],
            'vat_breakdown' => [$this->vatBreakdown('100.000')],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_cash_rounding_adjustment_exceeds_half_denomination/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_zero_denominator_with_nonzero_adjustment_quarantines_and_never_divides_by_zero(): void
    {
        $payload = $this->v3Payload(['cash_rounding_denomination' => '0.000']);

        try {
            $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
            $this->fail('Expected a RuntimeException before any bcmod call.');
        } catch (\DivisionByZeroError $e) {
            $this->fail('A DivisionByZeroError escaped the quarantine path and would kill the worker: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payload_cash_rounding_denomination_not_positive', $e->getMessage());
        }
    }

    public function test_total_must_be_a_multiple_of_the_denomination(): void
    {
        // 9.951 is not a multiple of 0.050; identity kept consistent.
        $payload = $this->v3Payload([
            'total' => '9.951',
            'cash_rounding_adjustment' => '-0.022',
            'payments' => [$this->payment('9.951')],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_cash_rounding_total_not_multiple/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_denomination_above_the_static_cap_is_rejected(): void
    {
        $payload = $this->v3Payload([
            'cash_rounding_denomination' => '5.000',
            'cash_rounding_adjustment' => '0.000',
            'total' => '9.973',
            'payments' => [$this->payment('9.973')],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_cash_rounding_denomination_above_cap/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_negative_zero_adjustment_is_rejected(): void
    {
        $payload = $this->v3Payload([
            'cash_rounding_adjustment' => '-0.000',
            'cash_rounding_denomination' => '0.000',
            'total' => '9.973',
            'payments' => [$this->payment('9.973')],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_money_negative_zero/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_negative_zero_adjustment_is_rejected_at_currency_scale_zero(): void
    {
        // The scale-0 signed branch has NO decimal point, so `-0` is the
        // whole spelling — it must still be rejected as a second byte-distinct
        // encoding of canonical zero.
        $payload = $this->v3Payload([
            'currency_code' => 'JPY',
            'currency_scale' => 0,
            'subtotal' => '1003',
            'vat_total' => '0',
            'transaction_discount_amount' => '0',
            'total' => '1003',
            'cash_rounding_adjustment' => '-0',
            'cash_rounding_denomination' => '0',
            'line_items' => [$this->lineItem([
                'line_discount_amount' => '0',
                'line_subtotal' => '1003',
                'line_vat' => '0',
                'name' => 'Yen item',
                'product_id' => 'prod-jpy',
                'sku' => 'SKU-JPY',
                'unit_price' => '1003',
            ])],
            'payments' => [$this->payment('1003')],
            'vat_breakdown' => [[
                'gross_amount' => '1003',
                'net_amount' => '1003',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0',
            ]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_money_negative_zero:field=cash_rounding_adjustment:value=-0/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    /**
     * v1 AND v2 must both reject the keys — the forbidden rule is
     * "every version below 3", not "version 2".
     */
    public static function belowV3VersionProvider(): \Generator
    {
        yield 'version 1' => [1];
        yield 'version 2' => [2];
    }

    #[DataProvider('belowV3VersionProvider')]
    public function test_v2_payload_carrying_a_rounding_field_is_rejected_by_both_layers(int $eventVersion): void
    {
        $payload = $this->v3Payload();

        $keySetError = $this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT, $payload, 'operational', $eventVersion
        );
        $this->assertNotNull($keySetError);
        $this->assertStringStartsWith('payload_extra_field:', $keySetError);
        $this->assertStringContainsString('cash_rounding_adjustment', $keySetError);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/payload_cash_rounding_forbidden_for_version:event_version='.$eventVersion.'/'
        );
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT, $payload, 'operational', $eventVersion
        );
    }

    public function test_v3_payload_missing_a_rounding_field_is_rejected(): void
    {
        $payload = $this->v3Payload();
        unset($payload['cash_rounding_denomination']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_missing_required:cash_rounding_denomination/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_dto_round_trips_v3_keys_and_omits_them_on_a_v2_payload(): void
    {
        $v3 = $this->v3Payload();
        $v3Dto = SaleReceiptPayload::fromArray($v3);

        $this->assertSame('-0.023', $v3Dto->cashRoundingAdjustment);
        $this->assertSame('0.050', $v3Dto->cashRoundingDenomination);
        $this->assertSame($v3, $v3Dto->toArray());

        $view = new SaleReceiptCanonicalView(
            payload: $v3Dto,
            seller: SellerDTO::fromArray($v3['seller']),
            buyer: null,
            lineItems: [],
            payments: [],
            vatBreakdown: [],
            originalReceiptReference: null,
            vouchersRedeemed: [],
        );
        $this->assertSame('-0.023', $view->cashRoundingAdjustmentOrZero());
        $this->assertSame('0.050', $view->cashRoundingDenominationOrZero());

        // A v2 payload (no rounding keys) keeps its exact 28-key shape and
        // the accessors report the canonical zero.
        $v2 = $v3;
        unset($v2['cash_rounding_adjustment'], $v2['cash_rounding_denomination']);
        $v2Dto = SaleReceiptPayload::fromArray($v2);

        $this->assertNull($v2Dto->cashRoundingAdjustment);
        $this->assertNull($v2Dto->cashRoundingDenomination);
        $this->assertSame($v2, $v2Dto->toArray());
        $this->assertCount(28, $v2Dto->toArray());
    }

    public function test_scale_zero_signed_regex_accepts_a_negative_integer_adjustment(): void
    {
        // JPY-style: scale 0, 10-yen rounding.
        $payload = $this->v3Payload([
            'currency_code' => 'JPY',
            'currency_scale' => 0,
            'subtotal' => '1003',
            'vat_total' => '0',
            'transaction_discount_amount' => '0',
            'total' => '1000',
            'cash_rounding_adjustment' => '-3',
            'cash_rounding_denomination' => '10',
            'line_items' => [$this->lineItem([
                'line_discount_amount' => '0',
                'line_subtotal' => '1003',
                'line_vat' => '0',
                'name' => 'Yen item',
                'product_id' => 'prod-jpy',
                'sku' => 'SKU-JPY',
                'unit_price' => '1003',
            ])],
            'payments' => [$this->payment('1000')],
            'vat_breakdown' => [[
                'gross_amount' => '1003',
                'net_amount' => '1003',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0',
            ]],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
        $this->addToAssertionCount(1);
    }
}
