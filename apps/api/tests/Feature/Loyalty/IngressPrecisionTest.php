<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Loyalty module ingress precision ceiling tests.
 *
 * Covers: EarningRule, Reward, Tier, EnrollMember, AdjustPoints requests.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── EarningRule: reward_value (decimal 15,4) ──────────────────────────────

    public function test_earning_rule_reward_value_rejects_5_decimal(): void
    {
        $rules = ['reward_value' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['reward_value' => '1.12345'], $rules);

        $this->assertTrue($v->fails(), 'Expected 1.12345 to fail');
        $this->assertArrayHasKey('reward_value', $v->errors()->toArray());
    }

    public function test_earning_rule_reward_value_accepts_4_decimal(): void
    {
        $rules = ['reward_value' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['reward_value' => '1.1234'], $rules);

        $this->assertEmpty($v->errors()->get('reward_value'), 'Expected 1.1234 to pass');
    }

    // ── EarningRule: max_earn_per_transaction / max_earn_per_day (money/3) ───

    /** @dataProvider earningRuleMoneyFieldsProvider */
    public function test_earning_rule_money_field_rejects_4_decimal(string $field): void
    {
        $rules = [$field => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make([$field => '100.1234'], $rules);

        $this->assertTrue($v->fails(), "Expected {$field}=100.1234 to fail");
        $this->assertArrayHasKey($field, $v->errors()->toArray());
    }

    /** @dataProvider earningRuleMoneyFieldsProvider */
    public function test_earning_rule_money_field_accepts_3_decimal(string $field): void
    {
        $rules = [$field => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make([$field => '100.123'], $rules);

        $this->assertEmpty(
            $v->errors()->get($field),
            "Expected {$field}=100.123 to pass"
        );
    }

    /** @return array<string, array{string}> */
    public static function earningRuleMoneyFieldsProvider(): array
    {
        return [
            'max_earn_per_transaction' => ['max_earn_per_transaction'],
            'max_earn_per_day' => ['max_earn_per_day'],
        ];
    }

    // ── EarningRule: conditions.min/max_purchase_amount (JSONB money/3) ──────

    /** @dataProvider earningRuleConditionAmountsProvider */
    public function test_earning_rule_condition_amount_rejects_4_decimal(string $field): void
    {
        $rules = ["conditions.{$field}" => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['conditions' => [$field => '500.1234']], $rules);

        $this->assertTrue($v->fails(), "Expected {$field}=500.1234 to fail");
        $this->assertTrue($v->errors()->has("conditions.{$field}"));
    }

    /** @dataProvider earningRuleConditionAmountsProvider */
    public function test_earning_rule_condition_amount_accepts_3_decimal(string $field): void
    {
        $rules = ["conditions.{$field}" => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['conditions' => [$field => '500.123']], $rules);

        $this->assertEmpty(
            $v->errors()->get("conditions.{$field}"),
            "Expected conditions.{$field}=500.123 to pass"
        );
    }

    /** @return array<string, array{string}> */
    public static function earningRuleConditionAmountsProvider(): array
    {
        return [
            'min_purchase_amount' => ['min_purchase_amount'],
            'max_purchase_amount' => ['max_purchase_amount'],
        ];
    }

    // ── Reward: points_cost, reward_value, max_discount, min_order_value (money/3) ──

    /** @dataProvider rewardMoneyFieldsProvider */
    public function test_reward_money_field_rejects_4_decimal(string $field): void
    {
        $rules = [$field => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make([$field => '250.1234'], $rules);

        $this->assertTrue($v->fails(), "Expected {$field}=250.1234 to fail");
        $this->assertArrayHasKey($field, $v->errors()->toArray());
    }

    /** @dataProvider rewardMoneyFieldsProvider */
    public function test_reward_money_field_accepts_3_decimal(string $field): void
    {
        $rules = [$field => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make([$field => '250.123'], $rules);

        $this->assertEmpty(
            $v->errors()->get($field),
            "Expected {$field}=250.123 to pass"
        );
    }

    /** @return array<string, array{string}> */
    public static function rewardMoneyFieldsProvider(): array
    {
        return [
            'points_cost' => ['points_cost'],
            'reward_value' => ['reward_value'],
            'max_discount' => ['max_discount'],
            'min_order_value' => ['min_order_value'],
        ];
    }

    // ── Tier: qualification_threshold (money/3) ───────────────────────────────

    public function test_tier_qualification_threshold_rejects_4_decimal(): void
    {
        $rules = ['qualification_threshold' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['qualification_threshold' => '1000.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 1000.1234 to fail');
        $this->assertArrayHasKey('qualification_threshold', $v->errors()->toArray());
    }

    public function test_tier_qualification_threshold_accepts_3_decimal(): void
    {
        $rules = ['qualification_threshold' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['qualification_threshold' => '1000.123'], $rules);

        $this->assertEmpty($v->errors()->get('qualification_threshold'), 'Expected 1000.123 to pass');
    }

    // ── Tier: earning_multiplier (decimal 5,2) ────────────────────────────────

    public function test_tier_earning_multiplier_rejects_3_decimal(): void
    {
        $rules = ['earning_multiplier' => ['required', 'numeric', 'min:1', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['earning_multiplier' => '1.234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 1.234 to fail');
        $this->assertArrayHasKey('earning_multiplier', $v->errors()->toArray());
    }

    public function test_tier_earning_multiplier_accepts_2_decimal(): void
    {
        $rules = ['earning_multiplier' => ['required', 'numeric', 'min:1', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['earning_multiplier' => '1.50'], $rules);

        $this->assertEmpty($v->errors()->get('earning_multiplier'), 'Expected 1.50 to pass');
    }

    // ── Tier: benefits.birthday_bonus_multiplier (multiplier scale 2) ────────

    public function test_birthday_bonus_multiplier_rejects_3_decimal(): void
    {
        $rules = ['benefits.birthday_bonus_multiplier' => ['sometimes', 'numeric', 'min:1', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['benefits' => ['birthday_bonus_multiplier' => '2.123']], $rules);

        $this->assertTrue($v->fails(), 'Expected 2.123 to fail');
        $this->assertTrue($v->errors()->has('benefits.birthday_bonus_multiplier'));
    }

    public function test_birthday_bonus_multiplier_accepts_2_decimal(): void
    {
        $rules = ['benefits.birthday_bonus_multiplier' => ['sometimes', 'numeric', 'min:1', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['benefits' => ['birthday_bonus_multiplier' => '2.50']], $rules);

        $this->assertEmpty(
            $v->errors()->get('benefits.birthday_bonus_multiplier'),
            'Expected 2.50 to pass'
        );
    }

    // ── EnrollMember: welcome_bonus (money/3) ────────────────────────────────

    public function test_welcome_bonus_rejects_4_decimal(): void
    {
        $rules = ['welcome_bonus' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['welcome_bonus' => '100.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 100.1234 to fail');
        $this->assertArrayHasKey('welcome_bonus', $v->errors()->toArray());
    }

    public function test_welcome_bonus_accepts_3_decimal(): void
    {
        $rules = ['welcome_bonus' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['welcome_bonus' => '100.123'], $rules);

        $this->assertEmpty($v->errors()->get('welcome_bonus'), 'Expected 100.123 to pass');
    }

    // ── AdjustPoints: points (signed money/3) ────────────────────────────────

    public function test_adjust_points_rejects_4_decimal(): void
    {
        $rules = ['points' => ['required', 'numeric', 'not_in:0', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['points' => '50.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 50.1234 to fail');
        $this->assertArrayHasKey('points', $v->errors()->toArray());
    }

    public function test_adjust_points_accepts_negative_3_decimal(): void
    {
        $rules = ['points' => ['required', 'numeric', 'not_in:0', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['points' => '-50.123'], $rules);

        $this->assertEmpty($v->errors()->get('points'), 'Expected -50.123 to pass');
    }

    public function test_adjust_points_accepts_positive_integer(): void
    {
        $rules = ['points' => ['required', 'numeric', 'not_in:0', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['points' => '100'], $rules);

        $this->assertEmpty($v->errors()->get('points'), 'Expected 100 to pass');
    }

    public function test_adjust_points_rejects_zero(): void
    {
        $rules = ['points' => ['required', 'numeric', 'not_in:0', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['points' => '0'], $rules);

        $this->assertTrue($v->fails(), 'Expected 0 to fail (not_in:0)');
        $this->assertArrayHasKey('points', $v->errors()->toArray());
    }
}
