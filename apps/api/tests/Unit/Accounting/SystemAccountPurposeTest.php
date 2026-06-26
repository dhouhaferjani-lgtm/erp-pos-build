<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use PHPUnit\Framework\TestCase;

class SystemAccountPurposeTest extends TestCase
{
    public function test_all_required_purposes_exist(): void
    {
        $required = SystemAccountPurpose::requiredPurposes();

        $this->assertContains(SystemAccountPurpose::CustomerReceivable, $required);
        $this->assertContains(SystemAccountPurpose::CustomerAdvance, $required);
        $this->assertContains(SystemAccountPurpose::SupplierPayable, $required);
        $this->assertContains(SystemAccountPurpose::SupplierAdvance, $required);
        $this->assertContains(SystemAccountPurpose::VatCollected, $required);
        $this->assertContains(SystemAccountPurpose::VatDeductible, $required);
        $this->assertContains(SystemAccountPurpose::ProductRevenue, $required);
        $this->assertContains(SystemAccountPurpose::ServiceRevenue, $required);
        $this->assertContains(SystemAccountPurpose::Bank, $required);
        $this->assertContains(SystemAccountPurpose::Cash, $required);
    }

    public function test_all_purposes_have_labels(): void
    {
        foreach (SystemAccountPurpose::cases() as $purpose) {
            $label = $purpose->label();
            $this->assertNotEmpty($label, "Purpose {$purpose->value} should have a label");
            $this->assertIsString($label);
        }
    }

    public function test_all_purposes_have_expected_account_types(): void
    {
        foreach (SystemAccountPurpose::cases() as $purpose) {
            $type = $purpose->expectedAccountType();
            $this->assertInstanceOf(AccountType::class, $type);
        }
    }

    public function test_asset_purposes_have_asset_type(): void
    {
        $assetPurposes = [
            SystemAccountPurpose::Bank,
            SystemAccountPurpose::Cash,
            SystemAccountPurpose::CustomerReceivable,
            SystemAccountPurpose::SupplierAdvance,
            SystemAccountPurpose::Inventory,
            SystemAccountPurpose::VatDeductible,
        ];

        foreach ($assetPurposes as $purpose) {
            $this->assertSame(
                AccountType::Asset,
                $purpose->expectedAccountType(),
                "Purpose {$purpose->value} should have Asset type"
            );
        }
    }

    public function test_liability_purposes_have_liability_type(): void
    {
        $liabilityPurposes = [
            SystemAccountPurpose::SupplierPayable,
            SystemAccountPurpose::CustomerAdvance,
            SystemAccountPurpose::VatCollected,
        ];

        foreach ($liabilityPurposes as $purpose) {
            $this->assertSame(
                AccountType::Liability,
                $purpose->expectedAccountType(),
                "Purpose {$purpose->value} should have Liability type"
            );
        }
    }

    public function test_revenue_purposes_have_revenue_type(): void
    {
        $revenuePurposes = [
            SystemAccountPurpose::ProductRevenue,
            SystemAccountPurpose::ServiceRevenue,
        ];

        foreach ($revenuePurposes as $purpose) {
            $this->assertSame(
                AccountType::Revenue,
                $purpose->expectedAccountType(),
                "Purpose {$purpose->value} should have Revenue type"
            );
        }
    }

    public function test_expense_purposes_have_expense_type(): void
    {
        $expensePurposes = [
            SystemAccountPurpose::CostOfGoodsSold,
            SystemAccountPurpose::PurchaseExpenses,
        ];

        foreach ($expensePurposes as $purpose) {
            $this->assertSame(
                AccountType::Expense,
                $purpose->expectedAccountType(),
                "Purpose {$purpose->value} should have Expense type"
            );
        }
    }

    public function test_equity_purposes_have_equity_type(): void
    {
        $equityPurposes = [
            SystemAccountPurpose::RetainedEarnings,
            SystemAccountPurpose::OpeningBalanceEquity,
        ];

        foreach ($equityPurposes as $purpose) {
            $this->assertSame(
                AccountType::Equity,
                $purpose->expectedAccountType(),
                "Purpose {$purpose->value} should have Equity type"
            );
        }
    }

    public function test_purposes_can_be_created_from_value(): void
    {
        $purpose = SystemAccountPurpose::from('customer_receivable');
        $this->assertSame(SystemAccountPurpose::CustomerReceivable, $purpose);

        $purpose = SystemAccountPurpose::from('vat_collected');
        $this->assertSame(SystemAccountPurpose::VatCollected, $purpose);
    }

    public function test_grir_and_timbre_purposes_have_correct_account_types(): void
    {
        $this->assertSame(AccountType::Liability, SystemAccountPurpose::GoodsReceivedNotInvoiced->expectedAccountType());
        $this->assertSame(AccountType::Expense, SystemAccountPurpose::PurchaseStampDuty->expectedAccountType());
        $this->assertNotEmpty(SystemAccountPurpose::GoodsReceivedNotInvoiced->label());
        $this->assertNotEmpty(SystemAccountPurpose::PurchaseStampDuty->label());
    }
}
