<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

final class FiscalEventTypeTest extends TestCase
{
    public function test_implemented_types(): void
    {
        $this->assertTrue(FiscalEventType::SALE_RECEIPT->isImplemented());
        $this->assertTrue(FiscalEventType::CHAIN_BREAK_DETECTED->isImplemented());
        $this->assertTrue(FiscalEventType::CHAIN_RESTART->isImplemented());
        $this->assertTrue(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->isImplemented());
        $this->assertTrue(FiscalEventType::ACCOUNT_PAYMENT->isImplemented());
        $this->assertTrue(FiscalEventType::ACCOUNT_CHARGE->isImplemented());
        $this->assertTrue(FiscalEventType::ACCOUNT_STATUS_CHANGED->isImplemented());
        $this->assertTrue(FiscalEventType::OPERATOR_APPROVAL_GRANTED->isImplemented());
        $this->assertTrue(FiscalEventType::OVERRIDE_CREDIT_LIMIT->isImplemented());
        $this->assertTrue(FiscalEventType::OVERRIDE_ACCOUNT_STATUS->isImplemented());
        $this->assertTrue(FiscalEventType::OVERRIDE_DISCOUNT_LIMIT->isImplemented());
        $this->assertTrue(FiscalEventType::OVERRIDE_TENDER_TOLERANCE->isImplemented());
        $this->assertTrue(FiscalEventType::OVERRIDE_VOID_OR_RETURN->isImplemented());
    }

    public function test_reserved_types_are_not_implemented(): void
    {
        $this->assertFalse(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST->isImplemented());
        $this->assertFalse(FiscalEventType::SALE_VOID->isImplemented());
        $this->assertFalse(FiscalEventType::REFUND_RECEIPT->isImplemented());
        $this->assertFalse(FiscalEventType::OPENING_FLOAT->isImplemented());
        $this->assertFalse(FiscalEventType::CASH_IN->isImplemented());
        $this->assertFalse(FiscalEventType::CASH_OUT->isImplemented());
        $this->assertFalse(FiscalEventType::SAFE_DROP->isImplemented());
        $this->assertFalse(FiscalEventType::CASH_CORRECTION->isImplemented());
    }

    public function test_server_only_types(): void
    {
        $this->assertTrue(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->isServerOnly());
        $this->assertTrue(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST->isServerOnly());
        $this->assertTrue(FiscalEventType::ACCOUNT_STATUS_CHANGED->isServerOnly());

        $this->assertFalse(FiscalEventType::SALE_RECEIPT->isServerOnly());
        $this->assertFalse(FiscalEventType::ACCOUNT_PAYMENT->isServerOnly());
        $this->assertFalse(FiscalEventType::ACCOUNT_CHARGE->isServerOnly());
        $this->assertFalse(FiscalEventType::OPERATOR_APPROVAL_GRANTED->isServerOnly());
        $this->assertFalse(FiscalEventType::OVERRIDE_CREDIT_LIMIT->isServerOnly());
    }

    public function test_legacy_phase1_helper_aliases_current_implemented_set(): void
    {
        foreach (FiscalEventType::cases() as $case) {
            $this->assertSame($case->isImplemented(), $case->isImplementedInPhase1());
        }
    }

    public function test_check_constraint_list_matches_appendix_a(): void
    {
        $this->assertCount(35, FiscalEventType::cases());
    }

    public function test_check_constraint_list_quotes_every_case_and_uses_comma_space_separator(): void
    {
        $list = FiscalEventType::checkConstraintList();

        foreach (FiscalEventType::cases() as $case) {
            $this->assertStringContainsString("'".$case->value."'", $list);
        }

        $this->assertSame(35, substr_count($list, "'") / 2);
        $this->assertSame(34, substr_count($list, ', '));
        $this->assertStringStartsWith("'SALE_RECEIPT'", $list);
        $this->assertStringEndsWith("'REPRINT_COPY'", $list);
    }
}
