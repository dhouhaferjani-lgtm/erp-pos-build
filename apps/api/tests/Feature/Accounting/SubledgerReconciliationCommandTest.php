<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SubledgerReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_partnerless_subledger_discrepancies(): void
    {
        $tenant = Tenant::create([
            'name' => 'Reconciliation Tenant',
            'slug' => 'reconciliation-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

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
}
