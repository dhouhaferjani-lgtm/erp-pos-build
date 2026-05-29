<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Voucher module ingress precision ceiling tests.
 *
 * Vouchers use DECIMAL(20,5) for amount (TND scale 3, EUR scale 2, but stored at scale 5).
 * The ingress regex is tightened to `/^\d+(\.\d{1,5})?$/` to ceiling at 5 dp.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── IssueGoodwill: amount (decimal 20,5) ─────────────────────────────────

    public function test_amount_rejects_6_decimal(): void
    {
        $rules = ['amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,5})?$/']];
        $v = Validator::make(['amount' => '100.123456'], $rules);

        $this->assertTrue($v->fails(), 'Expected 100.123456 (6 dp) to fail');
        $this->assertArrayHasKey('amount', $v->errors()->toArray());
    }

    public function test_amount_rejects_unbounded_decimal(): void
    {
        // Old regex was /^\d+(\.\d+)?$/ — allowed unlimited dp; new ceiling is 5.
        $rules = ['amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,5})?$/']];
        $v = Validator::make(['amount' => '10.12345678901234'], $rules);

        $this->assertTrue($v->fails(), 'Expected unbounded decimal to fail');
        $this->assertArrayHasKey('amount', $v->errors()->toArray());
    }

    /** @dataProvider validVoucherAmountsProvider */
    public function test_amount_accepts_valid(string $value): void
    {
        $rules = ['amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,5})?$/']];
        $v = Validator::make(['amount' => $value], $rules);

        $this->assertEmpty(
            $v->errors()->get('amount'),
            'Expected amount='.$value.' to pass: '.$v->errors()->first('amount')
        );
    }

    /** @return array<string, array{string}> */
    public static function validVoucherAmountsProvider(): array
    {
        return [
            'integer' => ['100'],
            '2-decimal (EUR)' => ['100.50'],
            '3-decimal (TND)' => ['100.500'],
            '5-decimal (max)' => ['100.12345'],
        ];
    }
}
