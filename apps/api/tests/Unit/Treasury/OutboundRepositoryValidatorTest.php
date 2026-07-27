<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\OutboundRepositoryValidator;
use App\Modules\Treasury\Domain\Bank;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OutboundRepositoryValidatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Account $bankAccount;

    private Bank $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $this->bank = Bank::query()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'name' => 'Clearing Bank',
            'is_active' => true,
            'is_custom' => true,
            'position' => 1,
        ]);
    }

    public function test_accepts_an_active_same_company_bank_repository_with_gl_currency_and_bank_match(): void
    {
        $repository = $this->repository();

        $validated = $this->validator()->validate(
            $repository->id,
            $this->tenant->id,
            $this->company->id,
            'TND',
            $this->bank->id,
        );

        self::assertTrue($validated->is($repository));
    }

    #[DataProvider('invalidRepositoryProvider')]
    public function test_rejects_invalid_repository_contract(string $mutation): void
    {
        $repository = $this->repository();
        $instrumentBankId = $this->bank->id;

        match ($mutation) {
            'cash-register' => $repository->forceFill(['type' => RepositoryType::CashRegister])->save(),
            'inactive' => $repository->forceFill(['is_active' => false])->save(),
            'null-gl' => $repository->forceFill(['gl_account_id' => null])->save(),
            'wrong-currency' => $repository->forceFill(['currency' => 'EUR'])->save(),
            'wrong-bank' => $instrumentBankId = Bank::query()->create([
                'tenant_id' => $this->tenant->id,
                'country_code' => 'TN',
                'name' => 'Other Bank',
                'is_active' => true,
                'is_custom' => true,
                'position' => 2,
            ])->id,
            'cross-company' => $repository->forceFill([
                'company_id' => Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id])->id,
            ])->save(),
            'cross-tenant' => $repository->forceFill([
                'tenant_id' => Tenant::factory()->create()->id,
            ])->save(),
            default => throw new \InvalidArgumentException("Unknown repository mutation {$mutation}."),
        };

        $this->expectException(DomainException::class);
        $this->validator()->validate(
            $repository->id,
            $this->tenant->id,
            $this->company->id,
            'TND',
            $instrumentBankId,
        );
    }

    /** @return array<string, array{string}> */
    public static function invalidRepositoryProvider(): array
    {
        return [
            'cash registers are not settlement banks' => ['cash-register'],
            'inactive repositories cannot receive lifecycle writes' => ['inactive'],
            'repositories need an exact GL account' => ['null-gl'],
            'repository currency must equal instrument currency' => ['wrong-currency'],
            'instrument bank must equal repository bank' => ['wrong-bank'],
            'repository must belong to the requested company' => ['cross-company'],
            'repository must belong to the requested tenant' => ['cross-tenant'],
        ];
    }

    private function repository(): PaymentRepository
    {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
            'bank_id' => $this->bank->id,
            'gl_account_id' => $this->bankAccount->id,
            'currency' => 'TND',
            'balance' => '0.000',
            'is_active' => true,
        ]);
    }

    private function validator(): OutboundRepositoryValidator
    {
        return app(OutboundRepositoryValidator::class);
    }
}
