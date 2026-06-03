<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Presentation\Requests\StoreCompositeItemRequest;
use App\Modules\Catalog\Presentation\Requests\StoreModifierRequest;
use App\Modules\Catalog\Presentation\Requests\StoreRecipeLineRequest;
use App\Modules\Catalog\Presentation\Requests\StoreRecipeRequest;
use App\Modules\Catalog\Presentation\Requests\StoreVariantRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateCompositeItemRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateRecipeRequest;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.11 — Catalog ingress precision ceiling tests.
 *
 * These tests bind to the REAL production FormRequest rules (via each
 * request's rules() method) so they FAIL if someone changes a production
 * decimal scale to the wrong value. Requests that depend on CompanyContext
 * are constructed with a bound context backed by a seeded tenant + company.
 */
final class IngressPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a real CompanyContext (seeded tenant + company) so that
     * CompanyContext-dependent FormRequests resolve their rules().
     */
    private function bindCompanyContext(): CompanyContext
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $context = app(CompanyContext::class);
        $context->setCompanyId($company->id);

        return $context;
    }
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
     * Production rules from StoreRecipeRequest (CompanyContext bound).
     *
     * @return array<string, mixed>
     */
    private function catalogRecipeRules(): array
    {
        return (new StoreRecipeRequest($this->bindCompanyContext()))->rules();
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogUpdateRecipeRules(): array
    {
        return (new UpdateRecipeRequest($this->bindCompanyContext()))->rules();
    }

    /**
     * Production rules from StoreRecipeLineRequest (CompanyContext bound).
     *
     * @return array<string, mixed>
     */
    private function catalogRecipeLineRules(): array
    {
        return (new StoreRecipeLineRequest($this->bindCompanyContext()))->rules();
    }

    /**
     * Production rules from StoreModifierRequest (CompanyContext bound).
     *
     * @return array<string, mixed>
     */
    private function catalogModifierRules(): array
    {
        return (new StoreModifierRequest($this->bindCompanyContext()))->rules();
    }

    /**
     * Production rules from StoreCompositeItemRequest (CompanyContext bound).
     *
     * @return array<string, mixed>
     */
    private function catalogCompositeRules(): array
    {
        return (new StoreCompositeItemRequest($this->bindCompanyContext()))->rules();
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogUpdateCompositeRules(): array
    {
        return (new UpdateCompositeItemRequest($this->bindCompanyContext()))->rules();
    }
}
