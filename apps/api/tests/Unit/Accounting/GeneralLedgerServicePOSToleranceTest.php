<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 / Task 7 — POS-shaped tolerance journal entry.
 *
 * The existing createPaymentToleranceJournalEntry is B2B-AR shaped (Dr 658 / Cr AR
 * with a non-null partner). POS walk-in sales have no partner and are direct-to-
 * revenue, so they need a partner-less variant that posts Dr 658 / Cr Revenue.
 */
final class GeneralLedgerServicePOSToleranceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-pos-tol',
            'domain' => 'test-pos-tol',
        ]);

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company FR',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Product Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);
    }

    public function test_creates_dr_658_cr_revenue_entry_with_no_partner(): void
    {
        /** @var GeneralLedgerService $service */
        $service = $this->app->make(GeneralLedgerService::class);

        $entry = $service->createPOSPaymentToleranceEntry(
            companyId: $this->company->id,
            receiptId: 'rcpt-uuid-123',
            amount: '0.020',
            date: new \DateTimeImmutable('2026-04-25T10:00:00Z'),
        );

        $this->assertNotNull($entry->id);
        $this->assertSame($this->company->id, $entry->company_id);
        $this->assertSame($this->tenant->id, $entry->tenant_id);
        $this->assertSame('pos_payment_tolerance', $entry->source_type);
        $this->assertSame('rcpt-uuid-123', $entry->source_id);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertCount(2, $entry->lines);

        $debit = $entry->lines->firstWhere(fn ($line) => bccomp((string) $line->debit, '0', 3) > 0);
        $credit = $entry->lines->firstWhere(fn ($line) => bccomp((string) $line->credit, '0', 3) > 0);

        $this->assertNotNull($debit);
        $this->assertNotNull($credit);

        // Dr line: 658 PaymentToleranceExpense, no partner.
        $this->assertSame(SystemAccountPurpose::PaymentToleranceExpense, $debit->account->system_purpose);
        $this->assertSame('0.020', (string) $debit->debit);
        $this->assertSame('0.000', (string) $debit->credit);
        $this->assertNull($debit->partner_id);

        // Cr line: ProductRevenue, no partner.
        $this->assertSame(SystemAccountPurpose::ProductRevenue, $credit->account->system_purpose);
        $this->assertSame('0.000', (string) $credit->debit);
        $this->assertSame('0.020', (string) $credit->credit);
        $this->assertNull($credit->partner_id);
    }

    public function test_entry_is_balanced_for_arbitrary_amounts(): void
    {
        /** @var GeneralLedgerService $service */
        $service = $this->app->make(GeneralLedgerService::class);

        $entry = $service->createPOSPaymentToleranceEntry(
            companyId: $this->company->id,
            receiptId: 'rcpt-uuid-456',
            amount: '0.300',
            date: new \DateTimeImmutable('2026-04-25T11:00:00Z'),
        );

        $totalDebit = $entry->lines->reduce(
            fn (string $sum, $line): string => bcadd($sum, (string) $line->debit, 3),
            '0.000',
        );
        $totalCredit = $entry->lines->reduce(
            fn (string $sum, $line): string => bcadd($sum, (string) $line->credit, 3),
            '0.000',
        );

        $this->assertSame($totalDebit, $totalCredit);
        $this->assertSame('0.300', $totalDebit);
    }
}
