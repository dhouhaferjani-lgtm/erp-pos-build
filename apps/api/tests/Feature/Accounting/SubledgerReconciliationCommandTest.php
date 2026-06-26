<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Console\TenantScopedCommand;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SubledgerReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_for_each_tenant_enters_and_ends_tenant_context_in_db_per_tenant_mode(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenantA = $this->createTenant('reconciliation-tenant-a');
        $tenantB = $this->createTenant('reconciliation-tenant-b');
        $command = $this->tenantScopedProbeCommand();

        $seen = [];

        $exitCode = $command->runForEachTenant(function (Tenant $tenant) use (&$seen): int {
            $seen[] = [
                'argument' => $tenant->id,
                'helper' => tenant('id'),
                'initialized' => tenancy()->initialized,
            ];

            return 0;
        });

        $this->assertSame(0, $exitCode);
        $this->assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'argument'));
        $this->assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'helper'));
        $this->assertSame([true, true], array_column($seen, 'initialized'));
        $this->assertFalse(tenancy()->initialized);
        $this->assertNull(tenancy()->tenant);
    }

    public function test_for_each_tenant_stays_in_shared_context_when_db_per_tenant_is_disabled(): void
    {
        config(['tenancy_resolver.db_per_tenant' => false]);

        $tenantA = $this->createTenant('shared-reconciliation-tenant-a');
        $tenantB = $this->createTenant('shared-reconciliation-tenant-b');
        $command = $this->tenantScopedProbeCommand();

        $seen = [];

        $exitCode = $command->runForEachTenant(function (Tenant $tenant) use (&$seen): int {
            $seen[] = [
                'argument' => $tenant->id,
                'helper' => tenant('id'),
                'initialized' => tenancy()->initialized,
            ];

            return 0;
        });

        $this->assertSame(0, $exitCode);
        $this->assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'argument'));
        $this->assertSame([null, null], array_column($seen, 'helper'));
        $this->assertSame([false, false], array_column($seen, 'initialized'));
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_command_reports_partnerless_subledger_discrepancies(): void
    {
        $tenant = $this->createTenant('reconciliation-tenant');

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reconciliation Company',
            'legal_name' => 'Reconciliation Company LLC',
            'tax_id' => 'REC123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($company);

        $receivable = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::CustomerReceivable);
        $revenue = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::ProductRevenue);

        $entry = JournalEntry::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'entry_number' => 'JE-SUB-001',
            'entry_date' => now(),
            'description' => 'Partnerless AR discrepancy',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'test',
            'source_id' => 'subledger-discrepancy',
            'posted_at' => now(),
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivable->id,
            'partner_id' => null,
            'debit' => '119.000',
            'credit' => '0.000',
            'description' => 'Partnerless receivable',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenue->id,
            'partner_id' => null,
            'debit' => '0.000',
            'credit' => '119.000',
            'description' => 'Revenue offset',
            'line_order' => 1,
        ]);

        $reconciliation = app(PartnerBalanceService::class)
            ->reconcileSubledger($company->id, SystemAccountPurpose::CustomerReceivable);
        $this->assertSame(1, $reconciliation['entries_without_partner']);

        $exitCode = Artisan::call('accounting:check-subledger-reconciliation');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('purpose=customer_receivable', $output);
        $this->assertStringContainsString('entries_without_partner=1', $output);
    }

    public function test_command_is_registered_with_scheduler(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('accounting:check-subledger-reconciliation', $output);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function tenantScopedProbeCommand(): SubledgerTenantScopedProbeCommand
    {
        return new SubledgerTenantScopedProbeCommand(app(CompanyContext::class));
    }
}

final class SubledgerTenantScopedProbeCommand extends TenantScopedCommand
{
    protected function executeCommand(): int
    {
        return self::SUCCESS;
    }

    /**
     * @param  callable(Tenant): int  $fn
     */
    public function runForEachTenant(callable $fn): int
    {
        return $this->forEachTenant($fn);
    }
}
