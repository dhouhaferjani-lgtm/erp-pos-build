<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

final class FiscalEventTypeTest extends TestCase
{
    public function test_phase1_implemented_types(): void
    {
        $this->assertTrue(FiscalEventType::SALE_RECEIPT->isImplementedInPhase1());
        $this->assertTrue(FiscalEventType::CHAIN_BREAK_DETECTED->isImplementedInPhase1());
        $this->assertTrue(FiscalEventType::CHAIN_RESTART->isImplementedInPhase1());
        $this->assertTrue(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->isImplementedInPhase1());
    }

    public function test_reserved_types_are_not_implemented(): void
    {
        $this->assertFalse(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST->isImplementedInPhase1());
        $this->assertFalse(FiscalEventType::ACCOUNT_PAYMENT->isImplementedInPhase1());
        $this->assertFalse(FiscalEventType::SALE_VOID->isImplementedInPhase1());
        $this->assertFalse(FiscalEventType::REFUND_RECEIPT->isImplementedInPhase1());
    }

    public function test_check_constraint_list_matches_appendix_a(): void
    {
        $this->assertCount(28, FiscalEventType::cases());
    }

    public function test_check_constraint_list_quotes_every_case_and_uses_comma_space_separator(): void
    {
        $list = FiscalEventType::checkConstraintList();

        foreach (FiscalEventType::cases() as $case) {
            $this->assertStringContainsString("'".$case->value."'", $list);
        }

        $this->assertSame(28, substr_count($list, "'") / 2);
        $this->assertSame(27, substr_count($list, ', '));
        $this->assertStringStartsWith("'SALE_RECEIPT'", $list);
        $this->assertStringEndsWith("'REPRINT_COPY'", $list);
    }
}
