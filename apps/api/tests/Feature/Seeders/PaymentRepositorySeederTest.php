<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Database\Seeders\BanksSeeder;
use Database\Seeders\DemoPaymentRepositorySeeder;
use Database\Seeders\PaymentRepositorySeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class PaymentRepositorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_repositories_use_the_resolved_gl_account_as_account_id(): void
    {
        $company = $this->seedCompany();

        foreach ($this->repositoriesFor($company) as $repository) {
            $this->assertNotNull($repository->account_id);
            $this->assertSame($repository->gl_account_id, $repository->account_id);
        }
    }

    public function test_seeder_creates_exactly_one_cash_register_and_one_safe(): void
    {
        $company = $this->seedCompany();

        $repositories = $this->repositoriesFor($company);

        $this->assertCount(2, $repositories);
        $this->assertSame(['CASH-01', 'SAFE-01'], $repositories->pluck('code')->all());
        $this->assertSame(
            [RepositoryType::CashRegister, RepositoryType::Safe],
            $repositories->pluck('type')->all(),
        );
    }

    /**
     * DPA lane H-3: the seeder used to mint BANK-01/02/03 + VIRT-01 with real
     * bank identities the tenant has no relationship with.
     */
    public function test_seeder_creates_no_bank_identity_of_any_kind(): void
    {
        $company = $this->seedCompany();

        foreach ($this->repositoriesFor($company) as $repository) {
            $this->assertNotSame(RepositoryType::BankAccount, $repository->type);
            $this->assertNotSame(RepositoryType::Virtual, $repository->type);
            $this->assertNull($repository->bank_id);
            $this->assertNull($repository->bank_name);
            $this->assertNull($repository->account_number);
            $this->assertNull($repository->iban);
            $this->assertNull($repository->bic);
        }
    }

    /**
     * DPA lane H-3: the seeder used to push 57 700 of fabricated cash through
     * the treasury movement port as real `opening_balance` movements.
     */
    public function test_seeder_records_no_balance_movement_or_journal_entry(): void
    {
        $company = $this->seedCompany();

        foreach ($this->repositoriesFor($company) as $repository) {
            $this->assertSame(0, bccomp($repository->balance, '0', 3));
        }

        $this->assertSame(0, DB::table('repository_movements')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('journal_entries')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->count());
    }

    /**
     * The demo overlay is what still creates the rich fixture — and it must be
     * reachable only from demo seeders, never from provisioning.
     */
    public function test_demo_overlay_funds_the_tills_and_adds_the_demo_bank_accounts(): void
    {
        $company = $this->seedCompany();
        $this->runSeeder(new DemoPaymentRepositorySeeder, $company);

        $repositories = $this->repositoriesFor($company);

        $this->assertCount(5, $repositories, 'TN demo overlay adds BANK-01, BANK-02 and VIRT-01.');

        $cash = $repositories->firstWhere('code', 'CASH-01');
        $this->assertNotNull($cash);
        $this->assertSame(0, bccomp($cash->balance, '500.000', 3));

        $bank = $repositories->firstWhere('code', 'BANK-01');
        $this->assertNotNull($bank);
        $this->assertSame(RepositoryType::BankAccount, $bank->type);
        $this->assertNotNull($bank->bank_id, 'Demo bank repositories resolve to a seeded Bank row.');
        $this->assertSame(0, bccomp($bank->balance, '25000.000', 3));

        $this->assertSame(
            5,
            DB::table('repository_movements')->where('company_id', $company->id)->count(),
            'Every demo balance is backed by exactly one opening_balance movement.',
        );
    }

    public function test_demo_overlay_is_idempotent(): void
    {
        $company = $this->seedCompany();
        $this->runSeeder(new DemoPaymentRepositorySeeder, $company);
        $this->runSeeder(new DemoPaymentRepositorySeeder, $company);

        $this->assertCount(5, $this->repositoriesFor($company));
        $this->assertSame(5, DB::table('repository_movements')->where('company_id', $company->id)->count());
    }

    /**
     * Gate finding M-3: the re-run skip must be keyed on the existing
     * `opening_balance` movement, NOT on the current balance. A demo till spent
     * back down to exactly zero used to look "never opened" and would be handed
     * a second opening leg.
     */
    public function test_demo_overlay_does_not_refund_a_repository_spent_back_to_zero(): void
    {
        $company = $this->seedCompany();
        $this->runSeeder(new DemoPaymentRepositorySeeder, $company);

        $till = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('code', 'CASH-01')
            ->firstOrFail();

        // Spend the till back to exactly zero through the port, the way a demo
        // expense story would.
        $this->app->make(TreasuryMovementServiceInterface::class);
        DB::transaction(function () use ($till, $company): void {
            $this->app->make(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
                repositoryId: $till->id,
                tenantId: $till->tenant_id,
                companyId: $till->company_id,
                direction: MovementDirection::Out,
                amount: '500.000',
                currency: $company->currency,
                sourceType: MovementSourceType::Adjustment,
                sourceId: $till->id,
                idempotencyLeg: 'drain',
                journalEntryId: null,
                occurredAt: null,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: null,
                notes: 'Test drain',
                allowWhileFrozen: false,
            ));
        });

        $till->refresh();
        $this->assertSame(0, bccomp($till->balance, '0', 3));

        $movementsBefore = DB::table('repository_movements')->where('company_id', $company->id)->count();
        $this->runSeeder(new DemoPaymentRepositorySeeder, $company);

        $this->assertSame(
            $movementsBefore,
            DB::table('repository_movements')->where('company_id', $company->id)->count(),
            'A repository already carrying an opening_balance movement must never be opened twice.',
        );
        $till->refresh();
        $this->assertSame(0, bccomp($till->balance, '0', 3));
    }

    private function seedCompany(): Company
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);

        Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '54',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
        ]);
        Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '532',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Bank,
        ]);

        $this->app->make(BanksSeeder::class)->run($company);
        $this->runSeeder(app(PaymentRepositorySeeder::class), $company);

        return $company;
    }

    /**
     * @return Collection<int, PaymentRepository>
     */
    private function repositoriesFor(Company $company): Collection
    {
        return PaymentRepository::query()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();
    }

    private function runSeeder(PaymentRepositorySeeder|DemoPaymentRepositorySeeder $seeder, Company $company): void
    {
        $command = new class extends Command
        {
            protected $signature = 'test:payment-repository-seeder';
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $seeder->setCommand($command);
        $seeder->run($company);
    }
}
