<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop;

use App\Modules\Workshop\Bundle\Presentation\Requests\AddComponentRequest;
use App\Modules\Workshop\Bundle\Presentation\Requests\PatchComponentRequest;
use App\Modules\Workshop\Bundle\Presentation\Requests\StoreBundleRequest;
use App\Modules\Workshop\Bundle\Presentation\Requests\UpdateBundleRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\AddBundleRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\AddLineRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\UpdateLineRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.9 — Workshop ingress precision ceiling tests.
 *
 * Validates that the decimal-ceiling regex rules on Workshop request classes
 * reject over-precise inputs and accept values within the column scale.
 *
 * Uses Validator::make() against rules() directly — no HTTP stack or DB needed.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── AddLineRequest ────────────────────────────────────────────────────────

    public function test_add_line_rejects_5_decimal_quantity(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['quantity' => '2.12345']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_add_line_accepts_4_decimal_quantity(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['quantity' => '2.1234']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors, 'Expected 4-decimal quantity to pass');
    }

    public function test_add_line_rejects_4_decimal_unit_price(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['unit_price' => '99.9999']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('unit_price', $v->errors()->toArray());
    }

    public function test_add_line_accepts_3_decimal_unit_price(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['unit_price' => '99.999']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('unit_price');
        $this->assertEmpty($errors, 'Expected 3-decimal unit_price to pass');
    }

    public function test_add_line_rejects_4_decimal_tax_rate(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['tax_rate' => '19.1234']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    public function test_add_line_accepts_3_decimal_tax_rate(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['tax_rate' => '19.125']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('tax_rate');
        $this->assertEmpty($errors, 'Expected 3-decimal tax_rate to pass');
    }

    public function test_add_line_rejects_3_decimal_discount_percent(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['discount_percent' => '10.123']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_percent', $v->errors()->toArray());
    }

    public function test_add_line_accepts_2_decimal_discount_percent(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['discount_percent' => '10.25']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('discount_percent');
        $this->assertEmpty($errors, 'Expected 2-decimal discount_percent to pass');
    }

    public function test_add_line_rejects_3_decimal_labor_hours(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['labor_hours_estimated' => '1.125']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('labor_hours_estimated', $v->errors()->toArray());
    }

    public function test_add_line_accepts_2_decimal_labor_hours(): void
    {
        $rules = (new AddLineRequest)->rules();
        $data = $this->validAddLineData(['labor_hours_estimated' => '1.50']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('labor_hours_estimated');
        $this->assertEmpty($errors, 'Expected 2-decimal labor_hours_estimated to pass');
    }

    // ── UpdateLineRequest ─────────────────────────────────────────────────────

    public function test_update_line_rejects_5_decimal_quantity(): void
    {
        $rules = (new UpdateLineRequest)->rules();
        $v = Validator::make(['quantity' => '5.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_update_line_accepts_4_decimal_quantity(): void
    {
        $rules = (new UpdateLineRequest)->rules();
        $v = Validator::make(['quantity' => '5.1234'], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors);
    }

    public function test_update_line_rejects_4_decimal_unit_price(): void
    {
        $rules = (new UpdateLineRequest)->rules();
        $v = Validator::make(['unit_price' => '9.9999'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('unit_price', $v->errors()->toArray());
    }

    public function test_update_line_rejects_3_decimal_discount_percent(): void
    {
        $rules = (new UpdateLineRequest)->rules();
        $v = Validator::make(['discount_percent' => '5.678'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_percent', $v->errors()->toArray());
    }

    public function test_update_line_rejects_3_decimal_labor_hours_actual(): void
    {
        $rules = (new UpdateLineRequest)->rules();
        $v = Validator::make(['labor_hours_actual' => '2.125'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('labor_hours_actual', $v->errors()->toArray());
    }

    public function test_update_line_accepts_2_decimal_labor_hours_actual(): void
    {
        $rules = (new UpdateLineRequest)->rules();
        $v = Validator::make(['labor_hours_actual' => '2.25'], $rules);

        $errors = $v->errors()->get('labor_hours_actual');
        $this->assertEmpty($errors);
    }

    // ── AddBundleRequest ──────────────────────────────────────────────────────

    public function test_add_bundle_rejects_5_decimal_quantity(): void
    {
        $rules = (new AddBundleRequest)->rules();
        $v = Validator::make([
            'bundle_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '1.12345',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_add_bundle_accepts_4_decimal_quantity(): void
    {
        $rules = (new AddBundleRequest)->rules();
        $v = Validator::make([
            'bundle_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '1.1234',
        ], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors);
    }

    // ── StoreBundleRequest ────────────────────────────────────────────────────

    public function test_store_bundle_rejects_4_decimal_base_price(): void
    {
        $rules = (new StoreBundleRequest)->rules();
        $data = $this->validStoreBundleData(['base_price' => '99.9999']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('base_price', $v->errors()->toArray());
    }

    public function test_store_bundle_accepts_3_decimal_base_price(): void
    {
        $rules = (new StoreBundleRequest)->rules();
        $data = $this->validStoreBundleData(['base_price' => '99.999']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('base_price');
        $this->assertEmpty($errors, 'Expected 3-decimal base_price to pass');
    }

    public function test_store_bundle_rejects_4_decimal_tax_rate(): void
    {
        $rules = (new StoreBundleRequest)->rules();
        $data = $this->validStoreBundleData(['tax_rate' => '19.1234']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    public function test_store_bundle_rejects_3_decimal_labor_hours(): void
    {
        $rules = (new StoreBundleRequest)->rules();
        $data = $this->validStoreBundleData(['estimated_labor_hours' => '2.125']);
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('estimated_labor_hours', $v->errors()->toArray());
    }

    public function test_store_bundle_accepts_2_decimal_labor_hours(): void
    {
        $rules = (new StoreBundleRequest)->rules();
        $data = $this->validStoreBundleData(['estimated_labor_hours' => '2.50']);
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('estimated_labor_hours');
        $this->assertEmpty($errors);
    }

    // ── UpdateBundleRequest ───────────────────────────────────────────────────

    public function test_update_bundle_rejects_4_decimal_base_price(): void
    {
        $rules = (new UpdateBundleRequest)->rules();
        $v = Validator::make(['base_price' => '50.0001'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('base_price', $v->errors()->toArray());
    }

    public function test_update_bundle_accepts_3_decimal_base_price(): void
    {
        $rules = (new UpdateBundleRequest)->rules();
        $v = Validator::make(['base_price' => '50.001'], $rules);

        $errors = $v->errors()->get('base_price');
        $this->assertEmpty($errors);
    }

    // ── AddComponentRequest ───────────────────────────────────────────────────

    public function test_add_component_rejects_5_decimal_quantity(): void
    {
        $rules = (new AddComponentRequest)->rules();
        $data = [
            'component_type' => 'product',
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '3.12345',
            'unit_id' => '00000000-0000-0000-0000-000000000002',
        ];
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_add_component_accepts_4_decimal_quantity(): void
    {
        $rules = (new AddComponentRequest)->rules();
        $data = [
            'component_type' => 'product',
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '3.1234',
            'unit_id' => '00000000-0000-0000-0000-000000000002',
        ];
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors);
    }

    public function test_add_component_rejects_4_decimal_override_unit_price(): void
    {
        $rules = (new AddComponentRequest)->rules();
        $data = [
            'component_type' => 'product',
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '1',
            'unit_id' => '00000000-0000-0000-0000-000000000002',
            'override_unit_price' => '9.9999',
        ];
        $v = Validator::make($data, $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('override_unit_price', $v->errors()->toArray());
    }

    public function test_add_component_accepts_3_decimal_override_unit_price(): void
    {
        $rules = (new AddComponentRequest)->rules();
        $data = [
            'component_type' => 'product',
            'component_id' => '00000000-0000-0000-0000-000000000001',
            'quantity' => '1',
            'unit_id' => '00000000-0000-0000-0000-000000000002',
            'override_unit_price' => '9.999',
        ];
        $v = Validator::make($data, $rules);

        $errors = $v->errors()->get('override_unit_price');
        $this->assertEmpty($errors);
    }

    // ── PatchComponentRequest ─────────────────────────────────────────────────

    public function test_patch_component_rejects_5_decimal_quantity(): void
    {
        $rules = (new PatchComponentRequest)->rules();
        $v = Validator::make(['quantity' => '2.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_patch_component_rejects_4_decimal_override_unit_price(): void
    {
        $rules = (new PatchComponentRequest)->rules();
        $v = Validator::make(['override_unit_price' => '5.9999'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('override_unit_price', $v->errors()->toArray());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validAddLineData(array $overrides = []): array
    {
        return array_merge([
            'line_type' => 'part',
            'display_name' => 'Oil Filter',
            'quantity' => '1',
            'unit' => 'pcs',
            'unit_price' => '25.000',
            'tax_rate' => '19.000',
            'discount_percent' => '0.00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validStoreBundleData(array $overrides = []): array
    {
        return array_merge([
            'code' => 'OIL-CHANGE',
            'name' => 'Oil Change Bundle',
            'pricing_mode' => 'fixed',
            'currency' => 'EUR',
        ], $overrides);
    }
}
