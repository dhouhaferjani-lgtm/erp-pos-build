<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Presentation\Requests\AddOrderLineRequest;
use App\Modules\POS\Presentation\Requests\ModifyOrderLineRequest;
use App\Modules\POS\Presentation\Requests\StoreReceiptRequest;
use App\Modules\POS\Presentation\Requests\StoreReturnRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.1 — POS ingress precision ceiling tests.
 *
 * Proves that over-precise values are rejected (regex ceiling) and
 * valid values (at or within scale) are accepted.
 *
 * Uses Laravel's Validator::make() against the request's rules() directly,
 * so no full HTTP stack or DB is needed.  The CompanyContext dependency is
 * stubbed so rules() can return the decimal-ceiling array in isolation.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── StoreReceiptRequest ───────────────────────────────────────────────────

    /**
     * @dataProvider overPreciseQuantityProvider
     */
    public function test_store_receipt_rejects_over_precise_quantity(string $qty): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['lines.0.quantity' => $qty]);
        $v = Validator::make($data, $rules);

        $this->assertTrue(
            $v->fails(),
            "Expected validation to fail for quantity={$qty} but it passed"
        );
        $this->assertArrayHasKey('lines.0.quantity', $v->errors()->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overPreciseQuantityProvider(): array
    {
        return [
            '5-decimal quantity' => ['1.12345'],
            '6-decimal quantity' => ['2.000001'],
        ];
    }

    /**
     * @dataProvider validQuantityProvider
     */
    public function test_store_receipt_accepts_valid_quantity(string $qty): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['lines.0.quantity' => $qty]);
        $v = Validator::make($data, $rules);

        $quantityErrors = $v->errors()->get('lines.0.quantity');
        $this->assertEmpty(
            $quantityErrors,
            "Expected quantity={$qty} to pass but got error: ".$v->errors()->first('lines.0.quantity')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validQuantityProvider(): array
    {
        return [
            'integer' => ['1'],
            '1-decimal' => ['1.5'],
            '2-decimal' => ['1.25'],
            '3-decimal' => ['1.125'],
            '4-decimal' => ['1.1234'],
        ];
    }

    /**
     * @dataProvider overPreciseUnitPriceProvider
     */
    public function test_store_receipt_rejects_over_precise_unit_price(string $price): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['lines.0.unit_price' => $price]);
        $v = Validator::make($data, $rules);

        $this->assertTrue(
            $v->fails(),
            "Expected validation to fail for unit_price={$price} but it passed"
        );
        $this->assertArrayHasKey('lines.0.unit_price', $v->errors()->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overPreciseUnitPriceProvider(): array
    {
        return [
            '4-decimal unit_price' => ['9.9999'],
            '5-decimal unit_price' => ['10.00001'],
        ];
    }

    public function test_store_receipt_accepts_valid_unit_price(): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['lines.0.unit_price' => '9.999']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('lines.0.unit_price');
        $this->assertEmpty($errors, 'Expected 3-decimal unit_price to pass');
    }

    public function test_store_receipt_rejects_over_precise_modifier_price_adjustment(): void
    {
        $rules = $this->storeReceiptFullRules();
        $data = $this->baseModifierData('1.23456');  // 5 decimals
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('lines.0.modifiers.0.price_adjustment', $v->errors()->toArray());
    }

    public function test_store_receipt_accepts_valid_negative_modifier_price_adjustment(): void
    {
        $rules = $this->storeReceiptFullRules();
        $data = $this->baseModifierData('-1.500');   // 3 decimals, negative
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('lines.0.modifiers.0.price_adjustment');
        $this->assertEmpty($errors, 'Expected 3-decimal negative price_adjustment to pass');
    }

    public function test_store_receipt_rejects_over_precise_discount_amount(): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['lines.0.discount_amount' => '1.1234']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('lines.0.discount_amount', $v->errors()->toArray());
    }

    public function test_store_receipt_rejects_over_precise_discount_percent(): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['lines.0.discount_percent' => '10.123']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('lines.0.discount_percent', $v->errors()->toArray());
    }

    public function test_store_receipt_rejects_over_precise_transaction_discount_amount(): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['transaction_discount_amount' => '5.1234']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('transaction_discount_amount', $v->errors()->toArray());
    }

    public function test_store_receipt_accepts_valid_transaction_discount_amount(): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['transaction_discount_amount' => '5.123']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('transaction_discount_amount');
        $this->assertEmpty($errors, 'Expected 3-decimal transaction_discount_amount to pass');
    }

    public function test_store_receipt_rejects_over_precise_loyalty_discount_amount(): void
    {
        $rules = $this->storeReceiptBaseRules();
        $data = $this->storeReceiptBaseData(['loyalty_discount_amount' => '2.9999']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('loyalty_discount_amount', $v->errors()->toArray());
    }

    // ── AddOrderLineRequest ───────────────────────────────────────────────────

    public function test_add_order_line_rejects_5_decimal_quantity(): void
    {
        $rules = $this->addOrderLineRules();
        $v = Validator::make(['quantity' => '2.12345', 'unit_price' => '5.000', 'tax_rate' => '19.00'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_add_order_line_accepts_4_decimal_quantity(): void
    {
        $rules = $this->addOrderLineRules();
        $v = Validator::make(['quantity' => '2.1234', 'unit_price' => '5.000', 'tax_rate' => '19.00'], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors, 'Expected 4-decimal quantity to pass');
    }

    public function test_add_order_line_rejects_4_decimal_unit_price(): void
    {
        $rules = $this->addOrderLineRules();
        $v = Validator::make(['quantity' => '1', 'unit_price' => '5.9999', 'tax_rate' => '19.00'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('unit_price', $v->errors()->toArray());
    }

    public function test_add_order_line_accepts_3_decimal_unit_price(): void
    {
        $rules = $this->addOrderLineRules();
        $v = Validator::make(['quantity' => '1', 'unit_price' => '5.999', 'tax_rate' => '19.00'], $rules);

        $errors = $v->errors()->get('unit_price');
        $this->assertEmpty($errors, 'Expected 3-decimal unit_price to pass');
    }

    public function test_add_order_line_rejects_3_decimal_tax_rate(): void
    {
        $rules = $this->addOrderLineRules();
        $v = Validator::make(['quantity' => '1', 'unit_price' => '5.000', 'tax_rate' => '19.001'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    public function test_add_order_line_rejects_4_decimal_discount_amount(): void
    {
        $rules = $this->addOrderLineRules();
        $v = Validator::make([
            'quantity' => '1',
            'unit_price' => '5.000',
            'tax_rate' => '19.00',
            'discount_amount' => '1.2345',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_amount', $v->errors()->toArray());
    }

    // ── ModifyOrderLineRequest ────────────────────────────────────────────────

    public function test_modify_order_line_rejects_5_decimal_quantity(): void
    {
        $rules = $this->modifyOrderLineRules();
        $v = Validator::make(['quantity' => '3.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_modify_order_line_accepts_4_decimal_quantity(): void
    {
        $rules = $this->modifyOrderLineRules();
        $v = Validator::make(['quantity' => '3.1234'], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors);
    }

    public function test_modify_order_line_rejects_4_decimal_discount_amount(): void
    {
        $rules = $this->modifyOrderLineRules();
        $v = Validator::make(['discount_amount' => '0.5678'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_amount', $v->errors()->toArray());
    }

    // ── StoreReturnRequest ────────────────────────────────────────────────────

    public function test_store_return_rejects_5_decimal_quantity(): void
    {
        $rules = $this->storeReturnLineRules();
        $v = Validator::make(['quantity' => '1.23456'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_store_return_accepts_4_decimal_quantity(): void
    {
        $rules = $this->storeReturnLineRules();
        $v = Validator::make(['quantity' => '1.2345'], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors, 'Expected 4-decimal return quantity to pass');
    }

    // ── ZReportSync opening_cash / expected_cash ──────────────────────────────

    public function test_zreport_sync_rejects_5_decimal_opening_cash(): void
    {
        $rules = [
            'opening_cash' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
        $v = Validator::make(['opening_cash' => '100.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('opening_cash', $v->errors()->toArray());
    }

    public function test_zreport_sync_accepts_4_decimal_opening_cash(): void
    {
        $rules = [
            'opening_cash' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
        $v = Validator::make(['opening_cash' => '100.1234'], $rules);

        $errors = $v->errors()->get('opening_cash');
        $this->assertEmpty($errors, 'Expected 4-decimal opening_cash to pass');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Extract rules that don't require CompanyContext/DB from StoreReceiptRequest
     * (the numeric/regex rules only — we skip ScopedExists rules here).
     *
     * @return array<string, mixed>
     */
    private function storeReceiptBaseRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'gte:0', 'lte:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'transaction_discount_amount' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'loyalty_discount_amount' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /**
     * Rules including modifier sub-rules (also don't require DB lookups).
     *
     * @return array<string, mixed>
     */
    private function storeReceiptFullRules(): array
    {
        return array_merge($this->storeReceiptBaseRules(), [
            'lines.*.modifiers' => ['nullable', 'array'],
            'lines.*.modifiers.*.price_adjustment' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
        ]);
    }

    /**
     * Build a minimal valid StoreReceiptRequest data array, with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storeReceiptBaseData(array $overrides = []): array
    {
        $data = [
            'lines' => [
                [
                    'quantity' => '1',
                    'unit_price' => '10.000',
                ],
            ],
        ];

        foreach ($overrides as $key => $value) {
            // Support dot-notation overrides for nested keys
            if (str_starts_with($key, 'lines.0.')) {
                $field = substr($key, strlen('lines.0.'));
                $data['lines'][0][$field] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Build modifier data for the full-rules test.
     *
     * @return array<string, mixed>
     */
    private function baseModifierData(string $priceAdjustment): array
    {
        return [
            'lines' => [
                [
                    'quantity' => '1',
                    'unit_price' => '10.000',
                    'modifiers' => [
                        [
                            'price_adjustment' => $priceAdjustment,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Minimal AddOrderLineRequest rules (numeric/regex only — no ScopedExists).
     *
     * @return array<string, mixed>
     */
    private function addOrderLineRules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'unit_price' => ['required', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'tax_rate' => ['required', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'discount_amount' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /**
     * ModifyOrderLineRequest numeric/regex rules.
     *
     * @return array<string, mixed>
     */
    private function modifyOrderLineRules(): array
    {
        return [
            'quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'discount_amount' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /**
     * StoreReturnRequest line-level quantity rules.
     *
     * @return array<string, mixed>
     */
    private function storeReturnLineRules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0.001', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }
}
