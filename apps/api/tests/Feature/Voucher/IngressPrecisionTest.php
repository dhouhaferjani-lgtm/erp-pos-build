<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Voucher\Presentation\Requests\IssueGoodwillRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Voucher module ingress precision ceiling tests.
 *
 * Vouchers use DECIMAL(20,5) for amount (TND scale 3, EUR scale 2, but stored
 * at scale 5). The ingress regex ceilings at 5 dp. Binds to the REAL production
 * rules from IssueGoodwillRequest (no DI dependencies) so these tests FAIL if
 * the production scale changes.
 */
final class IngressPrecisionTest extends TestCase
{
    /** @return array<string, mixed> */
    private function rules(): array
    {
        return (new IssueGoodwillRequest)->rules();
    }

    private function amountPasses(string $value): bool
    {
        $rules = $this->rules();
        $this->assertArrayHasKey(
            'amount',
            $rules,
            'IssueGoodwillRequest no longer exposes amount — the test no longer binds to production.'
        );

        return Validator::make(['amount' => $value], ['amount' => $rules['amount']])
            ->errors()->get('amount') === [];
    }

    // ── IssueGoodwill: amount (decimal 20,5) ─────────────────────────────────

    public function test_amount_rejects_6_decimal(): void
    {
        $this->assertFalse($this->amountPasses('100.123456'), 'Expected 100.123456 (6 dp) to fail');
    }

    public function test_amount_rejects_unbounded_decimal(): void
    {
        $this->assertFalse(
            $this->amountPasses('10.12345678901234'),
            'Expected unbounded decimal to fail'
        );
    }

    /** @dataProvider validVoucherAmountsProvider */
    public function test_amount_accepts_valid(string $value): void
    {
        $this->assertTrue($this->amountPasses($value), "Expected amount={$value} to pass");
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
