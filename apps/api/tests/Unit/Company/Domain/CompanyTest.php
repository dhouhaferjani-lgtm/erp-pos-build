<?php

declare(strict_types=1);

namespace Tests\Unit\Company\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    public function test_is_fiscal_year_validated_returns_false_when_not_validated(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => null,
        ]);

        $this->assertFalse($company->isFiscalYearValidated());
    }

    public function test_is_fiscal_year_validated_returns_true_when_validated(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => now(),
        ]);

        $this->assertTrue($company->isFiscalYearValidated());
    }

    public function test_has_fiscal_year_locked_returns_false_when_no_transaction_posted(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_transaction_posted_at' => null,
        ]);

        $this->assertFalse($company->hasFiscalYearLocked());
    }

    public function test_has_fiscal_year_locked_returns_true_when_transaction_posted(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_transaction_posted_at' => now(),
        ]);

        $this->assertTrue($company->hasFiscalYearLocked());
    }

    public function test_can_change_fiscal_year_returns_false_when_locked(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_transaction_posted_at' => now(),
        ]);

        $this->assertFalse($company->canChangeFiscalYear());
    }

    public function test_can_change_fiscal_year_returns_true_when_not_locked(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_transaction_posted_at' => null,
        ]);

        $this->assertTrue($company->canChangeFiscalYear());
    }

    public function test_can_post_transactions_returns_false_when_not_validated(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => null,
        ]);

        $this->assertFalse($company->canPostTransactions());
    }

    public function test_can_post_transactions_returns_true_when_validated(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => now(),
        ]);

        $this->assertTrue($company->canPostTransactions());
    }
}
