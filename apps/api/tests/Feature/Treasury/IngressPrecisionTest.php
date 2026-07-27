<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.4 — Treasury module ingress precision ceiling tests.
 *
 * The Treasury endpoints validate inline (no FormRequests). The two highest-risk
 * fields — Payment `amount` (money scale 3) and `withholding_rate`
 * (decimal(5,4)) — are bound to the REAL PaymentController::store() validator via
 * true HTTP 422 tests in tests/Feature/Treasury/PaymentTest.php
 * (test_store_rejects_over_precise_amount /
 *  test_store_rejects_over_precise_withholding_rate /
 *  test_store_accepts_4_decimal_withholding_rate).
 *
 * The remaining fields below MIRROR (do NOT bind to) their inline production
 * callsites with explicit pointers; payment-method CRUD is costly to provision
 * for a focused precision assertion.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── PaymentMethod fee_fixed (money/3) + fee_percent (percent/2) ───────────
    //
    // Mirrors PaymentMethodController inline validator
    // (app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php).

    public function test_fee_fixed_rejects_4_decimal(): void
    {
        $rules = ['fee_fixed' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['fee_fixed' => '2.5001'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('fee_fixed', $v->errors()->toArray());
    }

    public function test_fee_percent_rejects_3_decimal(): void
    {
        $rules = ['fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['fee_percent' => '2.555'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('fee_percent', $v->errors()->toArray());
    }

    public function test_fee_percent_accepts_2_decimal(): void
    {
        $rules = ['fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['fee_percent' => '2.55'], $rules);

        $this->assertEmpty($v->errors()->get('fee_percent'));
    }
}
