<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Presentation\Requests\AdjustPointsRequest;
use App\Modules\Loyalty\Presentation\Requests\CreateEarningRuleRequest;
use App\Modules\Loyalty\Presentation\Requests\CreateRewardRequest;
use App\Modules\Loyalty\Presentation\Requests\CreateTierRequest;
use App\Modules\Loyalty\Presentation\Requests\EnrollMemberRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateEarningRuleRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateRewardRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateTierRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Loyalty module ingress precision ceiling tests.
 *
 * These tests bind to the REAL production FormRequest rules (via each
 * request's rules() method) rather than hand-copied literals, so they FAIL
 * if someone changes the production decimal scale to the wrong value.
 *
 * Covers: EarningRule, Reward, Tier, EnrollMember, AdjustPoints requests.
 */
final class IngressPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Validate a single field's value against the production rules pulled from
     * the given FormRequest, returning whether validation passed for that field.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $payload
     */
    private function fieldPasses(array $rules, string $field, array $payload): bool
    {
        $this->assertArrayHasKey(
            $field,
            $rules,
            "Field {$field} is missing from the production request rules — the test no longer binds to production."
        );

        $v = Validator::make($payload, [$field => $rules[$field]]);

        return $v->errors()->get($field) === [];
    }

    // ── EarningRule: reward_value (decimal 15,4 → 4 dp) ──────────────────────

    public function test_earning_rule_reward_value_rejects_5_decimal(): void
    {
        $rules = (new CreateEarningRuleRequest)->rules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'reward_value', ['reward_value' => '1.12345']),
            'Expected reward_value=1.12345 to fail (column is decimal 15,4)'
        );
    }

    public function test_earning_rule_reward_value_accepts_4_decimal(): void
    {
        $rules = (new CreateEarningRuleRequest)->rules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'reward_value', ['reward_value' => '1.1234']),
            'Expected reward_value=1.1234 to pass (column is decimal 15,4)'
        );
    }

    // ── EarningRule: max_earn_per_transaction / max_earn_per_day (15,2 → 2 dp) ──

    /** @dataProvider earningRuleMoneyFieldsProvider */
    public function test_earning_rule_money_field_rejects_3_decimal(string $field): void
    {
        $rules = (new CreateEarningRuleRequest)->rules();

        $this->assertFalse(
            $this->fieldPasses($rules, $field, [$field => '100.123']),
            "Expected {$field}=100.123 to fail (column is decimal 15,2)"
        );
    }

    /** @dataProvider earningRuleMoneyFieldsProvider */
    public function test_earning_rule_money_field_accepts_2_decimal(string $field): void
    {
        $rules = (new UpdateEarningRuleRequest)->rules();

        $this->assertTrue(
            $this->fieldPasses($rules, $field, [$field => '100.12']),
            "Expected {$field}=100.12 to pass (column is decimal 15,2)"
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
        $rules = (new CreateEarningRuleRequest)->rules();
        $key = "conditions.{$field}";

        $this->assertFalse(
            $this->fieldPasses($rules, $key, ['conditions' => [$field => '500.1234']]),
            "Expected {$key}=500.1234 to fail (JSONB house scale 3)"
        );
    }

    /** @dataProvider earningRuleConditionAmountsProvider */
    public function test_earning_rule_condition_amount_accepts_3_decimal(string $field): void
    {
        $rules = (new CreateEarningRuleRequest)->rules();
        $key = "conditions.{$field}";

        $this->assertTrue(
            $this->fieldPasses($rules, $key, ['conditions' => [$field => '500.123']]),
            "Expected {$key}=500.123 to pass (JSONB house scale 3)"
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

    // ── Reward: points_cost, reward_value, max_discount, min_order_value (15,2 → 2 dp) ──

    /** @dataProvider rewardMoneyFieldsProvider */
    public function test_reward_money_field_rejects_3_decimal(string $field): void
    {
        $rules = (new CreateRewardRequest)->rules();

        $this->assertFalse(
            $this->fieldPasses($rules, $field, [$field => '250.123']),
            "Expected {$field}=250.123 to fail (column is decimal 15,2)"
        );
    }

    /** @dataProvider rewardMoneyFieldsProvider */
    public function test_reward_money_field_accepts_2_decimal(string $field): void
    {
        $rules = (new UpdateRewardRequest)->rules();

        $this->assertTrue(
            $this->fieldPasses($rules, $field, [$field => '250.12']),
            "Expected {$field}=250.12 to pass (column is decimal 15,2)"
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

    // ── Tier: qualification_threshold (15,2 → 2 dp) ──────────────────────────

    public function test_tier_qualification_threshold_rejects_3_decimal(): void
    {
        $rules = (new CreateTierRequest)->rules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'qualification_threshold', ['qualification_threshold' => '1000.123']),
            'Expected qualification_threshold=1000.123 to fail (column is decimal 15,2)'
        );
    }

    public function test_tier_qualification_threshold_accepts_2_decimal(): void
    {
        $rules = (new UpdateTierRequest)->rules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'qualification_threshold', ['qualification_threshold' => '1000.12']),
            'Expected qualification_threshold=1000.12 to pass (column is decimal 15,2)'
        );
    }

    // ── Tier: earning_multiplier (decimal 5,2 → 2 dp) ─────────────────────────

    public function test_tier_earning_multiplier_rejects_3_decimal(): void
    {
        $rules = (new CreateTierRequest)->rules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'earning_multiplier', ['earning_multiplier' => '1.234']),
            'Expected earning_multiplier=1.234 to fail (column is decimal 5,2)'
        );
    }

    public function test_tier_earning_multiplier_accepts_2_decimal(): void
    {
        $rules = (new CreateTierRequest)->rules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'earning_multiplier', ['earning_multiplier' => '1.50']),
            'Expected earning_multiplier=1.50 to pass (column is decimal 5,2)'
        );
    }

    // ── Tier: benefits.birthday_bonus_multiplier (JSONB multiplier scale 2) ──

    public function test_birthday_bonus_multiplier_rejects_3_decimal(): void
    {
        $rules = (new CreateTierRequest)->rules();
        $key = 'benefits.birthday_bonus_multiplier';

        $this->assertFalse(
            $this->fieldPasses($rules, $key, ['benefits' => ['birthday_bonus_multiplier' => '2.123']]),
            'Expected birthday_bonus_multiplier=2.123 to fail'
        );
    }

    public function test_birthday_bonus_multiplier_accepts_2_decimal(): void
    {
        $rules = (new CreateTierRequest)->rules();
        $key = 'benefits.birthday_bonus_multiplier';

        $this->assertTrue(
            $this->fieldPasses($rules, $key, ['benefits' => ['birthday_bonus_multiplier' => '2.50']]),
            'Expected birthday_bonus_multiplier=2.50 to pass'
        );
    }

    // ── EnrollMember: welcome_bonus (15,2 → 2 dp) ────────────────────────────
    //
    // EnrollMemberRequest::rules() calls CompanyContext::requireCompany(),
    // which hits the DB, so we seed a tenant+company and bind the context.

    /** @return array<string, mixed> */
    private function enrollMemberRules(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $context = app(CompanyContext::class);
        $context->setCompanyId($company->id);

        // Construct the request directly with its dependency so rules() resolves
        // against the bound CompanyContext without triggering auto-validation.
        return (new EnrollMemberRequest($context))->rules();
    }

    public function test_welcome_bonus_rejects_3_decimal(): void
    {
        $rules = $this->enrollMemberRules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'welcome_bonus', ['welcome_bonus' => '100.123']),
            'Expected welcome_bonus=100.123 to fail (column is decimal 15,2)'
        );
    }

    public function test_welcome_bonus_accepts_2_decimal(): void
    {
        $rules = $this->enrollMemberRules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'welcome_bonus', ['welcome_bonus' => '100.12']),
            'Expected welcome_bonus=100.12 to pass (column is decimal 15,2)'
        );
    }

    // ── AdjustPoints: points (signed 15,2 → 2 dp) ────────────────────────────

    public function test_adjust_points_rejects_3_decimal(): void
    {
        $rules = (new AdjustPointsRequest)->rules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'points', ['points' => '50.123']),
            'Expected points=50.123 to fail (column is decimal 15,2)'
        );
    }

    public function test_adjust_points_accepts_negative_2_decimal(): void
    {
        $rules = (new AdjustPointsRequest)->rules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'points', ['points' => '-50.12']),
            'Expected points=-50.12 to pass (column is decimal 15,2)'
        );
    }

    public function test_adjust_points_accepts_positive_integer(): void
    {
        $rules = (new AdjustPointsRequest)->rules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'points', ['points' => '100']),
            'Expected points=100 to pass'
        );
    }

    public function test_adjust_points_rejects_zero(): void
    {
        $rules = (new AdjustPointsRequest)->rules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'points', ['points' => '0']),
            'Expected points=0 to fail (not_in:0)'
        );
    }
}
