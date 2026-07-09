<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Tests\TestCase;

class PaymentRepositoryEntityTest extends TestCase
{
    public function test_payment_repository_class_exists(): void
    {
        $this->assertTrue(class_exists(PaymentRepository::class));
    }

    public function test_repository_type_enum_exists(): void
    {
        $this->assertTrue(enum_exists(RepositoryType::class));
    }

    public function test_repository_type_has_required_cases(): void
    {
        $cases = RepositoryType::cases();
        $caseValues = array_map(fn ($case) => $case->value, $cases);

        $this->assertContains('cash_register', $caseValues);
        $this->assertContains('safe', $caseValues);
        $this->assertContains('bank_account', $caseValues);
        $this->assertContains('virtual', $caseValues);
    }

    public function test_payment_repository_has_required_properties(): void
    {
        $repository = new PaymentRepository;
        $fillable = $repository->getFillable();

        $this->assertContains('tenant_id', $fillable);
        $this->assertContains('company_id', $fillable);
        $this->assertContains('code', $fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('type', $fillable);
        // Task 22 cutover: `balance` is port-managed (TreasuryMovementService
        // only) and deliberately NOT fillable — a pgsql trigger forbids direct
        // writes.
        $this->assertNotContains('balance', $fillable);
    }

    public function test_payment_repository_has_bank_properties(): void
    {
        $repository = new PaymentRepository;
        $fillable = $repository->getFillable();

        $this->assertContains('bank_name', $fillable);
        $this->assertContains('account_number', $fillable);
        $this->assertContains('iban', $fillable);
        $this->assertContains('bic', $fillable);
    }
}
