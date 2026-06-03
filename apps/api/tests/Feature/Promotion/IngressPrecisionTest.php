<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use App\Modules\Promotion\Presentation\Requests\StorePromotionRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Promotion module ingress precision ceiling tests.
 *
 * Binds to the REAL production rules from StorePromotionRequest (no DI
 * dependencies) so these tests FAIL if a production decimal scale changes.
 */
final class IngressPrecisionTest extends TestCase
{
    /** @return array<string, mixed> */
    private function rules(): array
    {
        return (new StorePromotionRequest)->rules();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $payload
     */
    private function fieldPasses(array $rules, string $field, array $payload): bool
    {
        $this->assertArrayHasKey(
            $field,
            $rules,
            "Field {$field} is missing from StorePromotionRequest rules — the test no longer binds to production."
        );

        return Validator::make($payload, [$field => $rules[$field]])->errors()->get($field) === [];
    }

    // ── discount_value (decimal 12,4) ─────────────────────────────────────────

    /** @dataProvider overPreciseDiscountValueProvider */
    public function test_discount_value_rejects_over_precise(string $value): void
    {
        $this->assertFalse(
            $this->fieldPasses($this->rules(), 'discount_value', ['discount_value' => $value]),
            "Expected discount_value={$value} to fail"
        );
    }

    /** @dataProvider validDiscountValueProvider */
    public function test_discount_value_accepts_valid(string $value): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->rules(), 'discount_value', ['discount_value' => $value]),
            "Expected discount_value={$value} to pass"
        );
    }

    /** @return array<string, array{string}> */
    public static function overPreciseDiscountValueProvider(): array
    {
        return [
            '5-decimal' => ['15.12345'],
            '6-decimal' => ['15.000001'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validDiscountValueProvider(): array
    {
        return [
            'integer' => ['15'],
            '2-decimal' => ['15.25'],
            '4-decimal' => ['15.1234'],
        ];
    }

    // ── max_discount_amount (money scale 3) ──────────────────────────────────

    public function test_max_discount_amount_rejects_4_decimal(): void
    {
        $this->assertFalse(
            $this->fieldPasses($this->rules(), 'max_discount_amount', ['max_discount_amount' => '200.1234']),
            'Expected 200.1234 to fail'
        );
    }

    public function test_max_discount_amount_accepts_3_decimal(): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->rules(), 'max_discount_amount', ['max_discount_amount' => '200.123']),
            'Expected 200.123 to pass'
        );
    }

    // ── conditions.min_amount (JSONB money scale 3) ──────────────────────────

    public function test_conditions_min_amount_rejects_4_decimal(): void
    {
        $this->assertFalse(
            $this->fieldPasses($this->rules(), 'conditions.min_amount', ['conditions' => ['min_amount' => '50.1234']]),
            'Expected 50.1234 to fail'
        );
    }

    public function test_conditions_min_amount_accepts_3_decimal(): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->rules(), 'conditions.min_amount', ['conditions' => ['min_amount' => '50.123']]),
            'Expected 50.123 to pass'
        );
    }
}
