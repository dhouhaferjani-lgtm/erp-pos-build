<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\FiscalPeriodResolverService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\DTOs\CreatePOSChargeJournalEntryCommand;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

final class POSAccountChargeJournalEntryTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    // journal_entries.source_id is a uuid column; PostgreSQL rejects non-UUID
    // strings. These fixed UUIDs stand in for the fiscal event id.
    private const FISCAL_EVENT_ID = '0193b001-0000-7000-8000-000000000001';

    private const DISCOUNTED_FISCAL_EVENT_ID = '0193b001-0000-7000-8000-000000000002';

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
            'country_code' => 'TN',
        ]);
        $this->customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createSystemAccount(SystemAccountPurpose::CustomerReceivable, AccountType::Asset, '411000');
        $this->createSystemAccount(SystemAccountPurpose::ProductRevenue, AccountType::Revenue, '707000');
        $this->createSystemAccount(SystemAccountPurpose::VatCollected, AccountType::Liability, '436700');
        $this->createSystemAccount(SystemAccountPurpose::SalesDiscount, AccountType::Expense, '709000');
    }

    public function test_pos_charge_posts_ar_revenue_and_vat_with_partner(): void
    {
        $balanceService = $this->mockPartnerBalanceRefreshOnce();

        $entry = $this->service($balanceService)->createPOSChargeEntry($this->command());

        $this->assertSame($this->tenant->id, $entry->tenant_id);
        $this->assertSame($this->company->id, $entry->company_id);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame('pos_account_charge', $entry->source_type);
        $this->assertSame(self::FISCAL_EVENT_ID, $entry->source_id);
        $this->assertSame('POS Account Charge account-charge-001', $entry->description);
        $this->assertSame('2026-05-21', $entry->entry_date->toDateString());

        $entry->load('lines.account');
        $this->assertCount(3, $entry->lines);

        $receivable = $this->lineForPurpose($entry, SystemAccountPurpose::CustomerReceivable);
        $this->assertSame($this->customer->id, $receivable->partner_id);
        $this->assertSame('119.000', $receivable->debit);
        $this->assertSame('0.000', $receivable->credit);

        $revenue = $this->lineForPurpose($entry, SystemAccountPurpose::ProductRevenue);
        $this->assertNull($revenue->partner_id);
        $this->assertSame('0.000', $revenue->debit);
        $this->assertSame('100.000', $revenue->credit);

        $vat = $this->lineForPurpose($entry, SystemAccountPurpose::VatCollected);
        $this->assertNull($vat->partner_id);
        $this->assertSame('0.000', $vat->debit);
        $this->assertSame('19.000', $vat->credit);
    }

    public function test_discounted_pos_charge_posts_sales_discount_and_balances(): void
    {
        $entry = $this->service($this->mockPartnerBalanceRefreshOnce())->createPOSChargeEntry($this->command([
            'subtotal' => '105.000',
            'vatTotal' => '19.000',
            'total' => '119.000',
            'transactionDiscountAmount' => '5.000',
            'accountChargeUuid' => 'discounted-charge-001',
            'fiscalEventId' => self::DISCOUNTED_FISCAL_EVENT_ID,
        ]));

        $entry->load('lines.account');
        $this->assertCount(4, $entry->lines);

        $receivable = $this->lineForPurpose($entry, SystemAccountPurpose::CustomerReceivable);
        $this->assertSame('119.000', $receivable->debit);
        $this->assertSame($this->customer->id, $receivable->partner_id);

        $discount = $this->lineForPurpose($entry, SystemAccountPurpose::SalesDiscount);
        $this->assertSame('5.000', $discount->debit);
        $this->assertSame('0.000', $discount->credit);

        $revenue = $this->lineForPurpose($entry, SystemAccountPurpose::ProductRevenue);
        $this->assertSame('0.000', $revenue->debit);
        $this->assertSame('105.000', $revenue->credit);

        $vat = $this->lineForPurpose($entry, SystemAccountPurpose::VatCollected);
        $this->assertSame('0.000', $vat->debit);
        $this->assertSame('19.000', $vat->credit);

        $this->assertSame('124.000', $this->sumDebit($entry));
        $this->assertSame('124.000', $this->sumCredit($entry));
    }

    public function test_pos_charge_never_calls_create_pos_payment_entry(): void
    {
        $this->service($this->mockPartnerBalanceRefreshOnce())->createPOSChargeEntry($this->command());

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('pos_receipt_payments', 0);
    }

    public function test_pos_charge_fails_loud_when_sales_discount_account_missing_for_discount(): void
    {
        Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::SalesDiscount->value)
            ->delete();

        $balanceService = $this->createMock(PartnerBalanceService::class);
        $balanceService->expects($this->never())->method('refreshPartnerBalance');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing GL account: no account with system purpose 'sales_discount'");

        try {
            $this->service($balanceService)->createPOSChargeEntry($this->command([
                'transactionDiscountAmount' => '5.000',
            ]));
        } finally {
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('journal_lines', 0);
        }
    }

    public function test_pos_charge_rejects_customer_from_another_company_before_journal_write(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);
        $otherCustomer = Partner::factory()->customer()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $balanceService = $this->createMock(PartnerBalanceService::class);
        $balanceService->expects($this->never())->method('refreshPartnerBalance');

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->service($balanceService)->createPOSChargeEntry($this->command([
                'partnerId' => $otherCustomer->id,
            ]));
        } finally {
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('journal_lines', 0);
        }
    }

    public function test_zero_vat_pos_charge_does_not_require_vat_collected_account(): void
    {
        Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::VatCollected->value)
            ->delete();

        $entry = $this->service($this->mockPartnerBalanceRefreshOnce())->createPOSChargeEntry($this->command([
            'subtotal' => '100.000',
            'vatTotal' => '0.000',
            'total' => '100.000',
            'vatBreakdown' => [],
            'lineVatSummary' => [
                [
                    'line_id' => 'line-001',
                    'vat_rate' => '0.000',
                    'vat_amount' => '0.000',
                    'taxable_amount' => '100.000',
                ],
            ],
        ]));

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);
        $this->lineForPurpose($entry, SystemAccountPurpose::CustomerReceivable);
        $this->lineForPurpose($entry, SystemAccountPurpose::ProductRevenue);
        $this->assertFalse($entry->lines->contains(
            fn (JournalLine $line): bool => $line->account->system_purpose === SystemAccountPurpose::VatCollected
        ));
    }

    public function test_pos_charge_command_receives_canonical_vat_breakdown_and_line_summary(): void
    {
        $vatBreakdown = [
            [
                'rate' => '19.000',
                'taxable_amount' => '100.000',
                'tax_amount' => '19.000',
                'tax_category' => 'S',
                'tax_regime' => 'TN_VAT',
            ],
        ];
        $lineVatSummary = [
            [
                'line_id' => 'line-001',
                'vat_rate' => '19.000',
                'vat_amount' => '19.000',
                'taxable_amount' => '100.000',
            ],
        ];

        $command = $this->command([
            'vatBreakdown' => $vatBreakdown,
            'lineVatSummary' => $lineVatSummary,
        ]);

        $this->assertSame($vatBreakdown, $command->vatBreakdown);
        $this->assertSame($lineVatSummary, $command->lineVatSummary);
    }

    public function test_pos_charge_refreshes_partner_receivable_balance(): void
    {
        $balanceService = $this->mockPartnerBalanceRefreshOnce();

        $this->service($balanceService)->createPOSChargeEntry($this->command());
    }

    public function test_pos_charge_bubbles_partner_balance_refresh_failure(): void
    {
        $balanceService = $this->createMock(PartnerBalanceService::class);
        $balanceService->expects($this->once())
            ->method('refreshPartnerBalance')
            ->with($this->company->id, $this->customer->id)
            ->willThrowException(new \RuntimeException('partner balance refresh failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('partner balance refresh failed');

        $this->service($balanceService)->createPOSChargeEntry($this->command());
    }

    private function createSystemAccount(
        SystemAccountPurpose $purpose,
        AccountType $type,
        string $code,
    ): Account {
        return Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $purpose->label(),
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
        ]);
    }

    private function service(PartnerBalanceService $balanceService): GeneralLedgerService
    {
        return new GeneralLedgerService(
            $balanceService,
            $this->mockCurrencyScale(3),
            new GeneralLedgerHashService($this->mockCurrencyScale(3)),
            new FiscalPeriodResolverService,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function command(array $overrides = []): CreatePOSChargeJournalEntryCommand
    {
        $values = array_merge([
            'tenantId' => $this->tenant->id,
            'companyId' => $this->company->id,
            'partnerId' => $this->customer->id,
            'fiscalEventId' => self::FISCAL_EVENT_ID,
            'accountChargeUuid' => 'account-charge-001',
            'businessDate' => '2026-05-21',
            'currencyCode' => 'TND',
            'currencyScale' => 3,
            'subtotal' => '100.000',
            'vatTotal' => '19.000',
            'total' => '119.000',
            'transactionDiscountAmount' => '0.000',
            'vatBreakdown' => [
                [
                    'rate' => '19.000',
                    'taxable_amount' => '100.000',
                    'tax_amount' => '19.000',
                    'tax_category' => 'S',
                    'tax_regime' => 'TN_VAT',
                ],
            ],
            'lineVatSummary' => [
                [
                    'line_id' => 'line-001',
                    'vat_rate' => '19.000',
                    'vat_amount' => '19.000',
                    'taxable_amount' => '100.000',
                ],
            ],
            'actorUserId' => null,
        ], $overrides);

        return new CreatePOSChargeJournalEntryCommand(
            tenantId: $values['tenantId'],
            companyId: $values['companyId'],
            partnerId: $values['partnerId'],
            fiscalEventId: $values['fiscalEventId'],
            accountChargeUuid: $values['accountChargeUuid'],
            businessDate: $values['businessDate'],
            currencyCode: $values['currencyCode'],
            currencyScale: $values['currencyScale'],
            subtotal: $values['subtotal'],
            vatTotal: $values['vatTotal'],
            total: $values['total'],
            transactionDiscountAmount: $values['transactionDiscountAmount'],
            vatBreakdown: $values['vatBreakdown'],
            lineVatSummary: $values['lineVatSummary'],
            actorUserId: $values['actorUserId'],
        );
    }

    private function lineForPurpose(JournalEntry $entry, SystemAccountPurpose $purpose): JournalLine
    {
        $lines = $entry->lines->filter(
            fn (JournalLine $line): bool => $line->account->system_purpose === $purpose
        )->values();

        $this->assertCount(1, $lines);

        /** @var JournalLine $line */
        $line = $lines->first();

        return $line;
    }

    private function sumDebit(JournalEntry $entry): string
    {
        return $entry->lines->reduce(
            fn (string $carry, JournalLine $line): string => bcadd($carry, $line->debit, 3),
            '0.000',
        );
    }

    private function sumCredit(JournalEntry $entry): string
    {
        return $entry->lines->reduce(
            fn (string $carry, JournalLine $line): string => bcadd($carry, $line->credit, 3),
            '0.000',
        );
    }

    private function mockPartnerBalanceRefreshOnce(): PartnerBalanceService&MockObject
    {
        $balanceService = $this->createMock(PartnerBalanceService::class);
        $balanceService->expects($this->once())
            ->method('refreshPartnerBalance')
            ->with($this->company->id, $this->customer->id);

        return $balanceService;
    }
}
