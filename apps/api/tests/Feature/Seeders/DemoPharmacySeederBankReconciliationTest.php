<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\BankReconciliation;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\ReconciliationStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\DemoPharmacySeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class DemoPharmacySeederBankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_tunisia_bank_reconciliation_creates_completed_and_draft_sessions(): void
    {
        [$company, $repository] = $this->seedBankReconciliationFixture();

        $this->invokeSeedTunisiaBankReconciliation($company);

        $reconciliations = BankReconciliation::query()
            ->where('company_id', $company->id)
            ->where('repository_id', $repository->id)
            ->with('items.payment')
            ->orderBy('statement_date')
            ->get();

        $this->assertCount(2, $reconciliations);

        $completed = $reconciliations->firstWhere('status', ReconciliationStatus::Completed);
        $draft = $reconciliations->firstWhere('status', ReconciliationStatus::Draft);

        $this->assertNotNull($completed);
        $this->assertNotNull($draft);
        $this->assertSame('0.000', $completed->difference);
        $this->assertGreaterThan(0, $completed->items->where('is_matched', true)->count());
        $this->assertGreaterThan(0, $draft->items->where('is_matched', false)->count());

        foreach ($completed->items->where('is_matched', true) as $item) {
            $this->assertTrue($item->payment->fresh()?->is_reconciled);
        }
    }

    /**
     * @return array{Company, PaymentRepository}
     */
    private function seedBankReconciliationFixture(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'demo-pharmacy-tn',
        ]);
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'owner@pharmabio.tn',
        ]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
        ]);
        $paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'BANK',
            'name' => 'Bank transfer',
        ]);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'BANK-01',
            'name' => 'Banque de Tunisie - Current Account',
            'type' => RepositoryType::BankAccount,
            'balance' => '5000.000',
        ]);

        foreach (['100.000', '200.000', '300.000', '400.000'] as $index => $amount) {
            Payment::create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'payment_method_id' => $paymentMethod->id,
                'repository_id' => $repository->id,
                'amount' => $amount,
                'currency' => 'TND',
                'payment_date' => now()->subDays(8 - $index)->toDateString(),
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::DocumentPayment,
                'origin' => PaymentOrigin::WebAdmin,
                'reference' => 'DEMO-PAY-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'created_by' => $owner->id,
            ]);
        }

        $this->app->make(CompanyContext::class)->setCompanyId($company->id);

        return [$company, $repository];
    }

    private function invokeSeedTunisiaBankReconciliation(Company $company): void
    {
        /** @var DemoPharmacySeeder $seeder */
        $seeder = $this->app->make(DemoPharmacySeeder::class);
        $command = new class extends Command
        {
            protected $signature = 'test:demo-pharmacy-seeder-bank-reconciliation';
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $seeder->setContainer($this->app);
        $seeder->setCommand($command);

        $method = new ReflectionMethod($seeder, 'seedTunisiaBankReconciliation');
        $method->setAccessible(true);
        $method->invoke($seeder, $company);
    }
}
