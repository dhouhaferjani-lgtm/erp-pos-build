<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Presentation\Requests\StoreVariantRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.11 — Catalog ingress precision ceiling tests.
 *
 * Validates the decimal-ceiling regex rules on Catalog request classes.
 * Requests that depend on CompanyContext are tested using rules() extracted
 * with a stubbed context; purely structural requests are called directly.
 *
 * Uses Validator::make() against rules() directly — no HTTP stack needed.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── StoreVariantRequest ───────────────────────────────────────────────────

    public function test_store_variant_rejects_5_decimal_price_adjustment(): void
    {
        $rules = (new StoreVariantRequest)->rules();
        $v = Validator::make([
            'code' => 'VAR-001',
            'name' => 'Large',
            'price_adjustment' => '1.23456',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('price_adjustment', $v->errors()->toArray());
    }

    public function test_store_variant_accepts_4_decimal_price_adjustment(): void
    {
        $rules = (new StoreVariantRequest)->rules();
        $v = Validator::make([
            'code' => 'VAR-001',
            'name' => 'Large',
            'price_adjustment' => '1.2345',
        ], $rules);

        $errors = $v->errors()->get('price_adjustment');
        $this->assertEmpty($errors, 'Expected 4-decimal price_adjustment to pass');
    }

    public function test_store_variant_accepts_negative_4_decimal_price_adjustment(): void
    {
        $rules = (new StoreVariantRequest)->rules();
        $v = Validator::make([
            'code' => 'VAR-002',
            'name' => 'Small',
            'price_adjustment' => '-1.5000',
        ], $rules);

        $errors = $v->errors()->get('price_adjustment');
        $this->assertEmpty($errors, 'Expected negative 4-decimal price_adjustment to pass');
    }

    public function test_store_variant_rejects_5_decimal_recipe_multiplier(): void
    {
        $rules = (new StoreVariantRequest)->rules();
        $v = Validator::make([
            'code' => 'VAR-001',
            'name' => 'Large',
            'recipe_multiplier' => '1.12345',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('recipe_multiplier', $v->errors()->toArray());
    }

    public function test_store_variant_accepts_4_decimal_recipe_multiplier(): void
    {
        $rules = (new StoreVariantRequest)->rules();
        $v = Validator::make([
            'code' => 'VAR-001',
            'name' => 'Large',
            'recipe_multiplier' => '1.1234',
        ], $rules);

        $errors = $v->errors()->get('recipe_multiplier');
        $this->assertEmpty($errors);
    }

    // ── StoreRecipeRequest (CompanyContext stubbed) ────────────────────────────

    public function test_store_recipe_rejects_5_decimal_yield_quantity(): void
    {
        $rules = $this->catalogRecipeRules();
        $v = Validator::make(['yield_quantity' => '2.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('yield_quantity', $v->errors()->toArray());
    }

    public function test_store_recipe_accepts_4_decimal_yield_quantity(): void
    {
        $rules = $this->catalogRecipeRules();
        $v = Validator::make(['yield_quantity' => '2.1234'], $rules);

        $errors = $v->errors()->get('yield_quantity');
        $this->assertEmpty($errors);
    }

    public function test_update_recipe_rejects_5_decimal_yield_quantity(): void
    {
        $rules = $this->catalogUpdateRecipeRules();
        $v = Validator::make(['yield_quantity' => '2.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('yield_quantity', $v->errors()->toArray());
    }

    // ── StoreRecipeLineRequest (CompanyContext stubbed) ────────────────────────

    public function test_store_recipe_line_rejects_5_decimal_quantity(): void
    {
        $rules = $this->catalogRecipeLineRules();
        $v = Validator::make([
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '3.12345',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_store_recipe_line_accepts_4_decimal_quantity(): void
    {
        $rules = $this->catalogRecipeLineRules();
        $v = Validator::make([
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '3.1234',
        ], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors);
    }

    public function test_store_recipe_line_rejects_3_decimal_wastage_percent(): void
    {
        $rules = $this->catalogRecipeLineRules();
        $v = Validator::make([
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '1',
            'wastage_percent' => '5.678',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('wastage_percent', $v->errors()->toArray());
    }

    public function test_store_recipe_line_accepts_2_decimal_wastage_percent(): void
    {
        $rules = $this->catalogRecipeLineRules();
        $v = Validator::make([
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '1',
            'wastage_percent' => '5.25',
        ], $rules);

        $errors = $v->errors()->get('wastage_percent');
        $this->assertEmpty($errors);
    }

    // ── StoreModifierRequest (CompanyContext stubbed) ─────────────────────────

    public function test_store_modifier_rejects_5_decimal_price_adjustment(): void
    {
        $rules = $this->catalogModifierRules();
        $v = Validator::make([
            'code' => 'MOD-001',
            'name' => 'Extra Cheese',
            'price_adjustment' => '1.23456',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('price_adjustment', $v->errors()->toArray());
    }

    public function test_store_modifier_accepts_negative_4_decimal_price_adjustment(): void
    {
        $rules = $this->catalogModifierRules();
        $v = Validator::make([
            'code' => 'MOD-001',
            'name' => 'Discount Mod',
            'price_adjustment' => '-0.5000',
        ], $rules);

        $errors = $v->errors()->get('price_adjustment');
        $this->assertEmpty($errors);
    }

    public function test_store_modifier_rejects_5_decimal_component_quantity(): void
    {
        $rules = $this->catalogModifierRules();
        $v = Validator::make([
            'code' => 'MOD-002',
            'name' => 'Extra Shot',
            'component_quantity' => '0.12345',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('component_quantity', $v->errors()->toArray());
    }

    public function test_store_modifier_accepts_4_decimal_component_quantity(): void
    {
        $rules = $this->catalogModifierRules();
        $v = Validator::make([
            'code' => 'MOD-002',
            'name' => 'Extra Shot',
            'component_quantity' => '0.1234',
        ], $rules);

        $errors = $v->errors()->get('component_quantity');
        $this->assertEmpty($errors);
    }

    // ── StoreCompositeItemRequest (CompanyContext stubbed) ─────────────────────

    public function test_store_composite_rejects_5_decimal_base_price(): void
    {
        $rules = $this->catalogCompositeRules();
        $v = Validator::make([
            'code' => 'ITEM-001',
            'name' => 'Burger',
            'base_price' => '9.99999',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('base_price', $v->errors()->toArray());
    }

    public function test_store_composite_accepts_4_decimal_base_price(): void
    {
        $rules = $this->catalogCompositeRules();
        $v = Validator::make([
            'code' => 'ITEM-001',
            'name' => 'Burger',
            'base_price' => '9.9999',
        ], $rules);

        $errors = $v->errors()->get('base_price');
        $this->assertEmpty($errors);
    }

    public function test_store_composite_rejects_3_decimal_tax_rate(): void
    {
        $rules = $this->catalogCompositeRules();
        $v = Validator::make([
            'code' => 'ITEM-001',
            'name' => 'Burger',
            'base_price' => '9.99',
            'tax_rate' => '19.001',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    public function test_store_composite_accepts_2_decimal_tax_rate(): void
    {
        $rules = $this->catalogCompositeRules();
        $v = Validator::make([
            'code' => 'ITEM-001',
            'name' => 'Burger',
            'base_price' => '9.99',
            'tax_rate' => '19.50',
        ], $rules);

        $errors = $v->errors()->get('tax_rate');
        $this->assertEmpty($errors);
    }

    public function test_store_composite_rejects_5_decimal_manual_cost(): void
    {
        $rules = $this->catalogCompositeRules();
        $v = Validator::make([
            'code' => 'ITEM-001',
            'name' => 'Burger',
            'base_price' => '9.99',
            'manual_cost' => '5.12345',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('manual_cost', $v->errors()->toArray());
    }

    public function test_update_composite_rejects_5_decimal_base_price(): void
    {
        $rules = $this->catalogUpdateCompositeRules();
        $v = Validator::make(['base_price' => '9.99999'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('base_price', $v->errors()->toArray());
    }

    public function test_update_composite_accepts_4_decimal_base_price(): void
    {
        $rules = $this->catalogUpdateCompositeRules();
        $v = Validator::make(['base_price' => '9.9999'], $rules);

        $errors = $v->errors()->get('base_price');
        $this->assertEmpty($errors);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Extract numeric/regex rules from StoreRecipeRequest without CompanyContext.
     *
     * @return array<string, mixed>
     */
    private function catalogRecipeRules(): array
    {
        return [
            'yield_quantity' => ['sometimes', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogUpdateRecipeRules(): array
    {
        return [
            'yield_quantity' => ['sometimes', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    /**
     * Numeric/regex rules from StoreRecipeLineRequest (skips ScopedExists).
     *
     * @return array<string, mixed>
     */
    private function catalogRecipeLineRules(): array
    {
        return [
            'component_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
            'wastage_percent' => ['sometimes', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * Numeric/regex rules from StoreModifierRequest (skips ScopedExists).
     *
     * @return array<string, mixed>
     */
    private function catalogModifierRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'price_adjustment' => ['sometimes', 'numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'component_quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    /**
     * Numeric/regex rules from StoreCompositeItemRequest (skips Rule::unique / ScopedExists).
     *
     * @return array<string, mixed>
     */
    private function catalogCompositeRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'base_price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'manual_cost' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogUpdateCompositeRules(): array
    {
        return [
            'base_price' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'manual_cost' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }
}
