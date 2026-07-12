<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Bank;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\BanksSeeder;
use Database\Seeders\PaymentRepositorySeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class PaymentRepositorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_repositories_use_the_resolved_gl_account_as_account_id(): void
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

        /** @var PaymentRepositorySeeder $seeder */
        $seeder = $this->app->make(PaymentRepositorySeeder::class);
        $command = new class extends Command
        {
            protected $signature = 'test:payment-repository-seeder';
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $seeder->setCommand($command);
        $this->app->make(BanksSeeder::class)->run($company);
        $seeder->run($company);

        $repositories = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->get();

        $this->assertNotEmpty($repositories);

        foreach ($repositories as $repository) {
            $this->assertNotNull($repository->account_id);
            $this->assertSame($repository->gl_account_id, $repository->account_id);
        }

        $seededBankRepositories = $repositories->where('type.value', 'bank_account');
        $this->assertNotEmpty($seededBankRepositories);

        foreach ($seededBankRepositories as $repository) {
            $this->assertNull($repository->account_number);
            $this->assertNull($repository->iban);
            $this->assertNotNull($repository->bank_id);
            $this->assertTrue(Bank::query()->whereKey($repository->bank_id)->exists());
        }
    }
}
