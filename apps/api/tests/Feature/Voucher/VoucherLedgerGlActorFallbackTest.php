<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VoucherLedgerGlActorFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Voucher GL Tenant',
            'slug' => 'voucher-gl-actor-fallback',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Voucher GL Company',
            'legal_name' => 'Voucher GL Company LLC',
            'tax_id' => 'TAX-VGL',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Voucher GL Cashier',
            'email' => 'voucher-gl-cashier@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_redeemed_voucher_ledger_with_unresolvable_operator_posts_as_system_generated(): void
    {
        $voucher = $this->createVoucher(VoucherSource::Refund);
        $ledger = $this->createLedger(
            voucher: $voucher,
            event: VoucherEvent::Redeemed,
            amount: '-20.00000',
            userId: (string) Str::uuid(),
        );

        $entry = $this->makeService()->createVoucherLedgerEntry($ledger, $voucher);

        $this->assertPostedSystemGeneratedEntry($entry, $ledger);
    }

    public function test_voided_voucher_ledger_with_unresolvable_operator_posts_as_system_generated(): void
    {
        $voucher = $this->createVoucher(VoucherSource::Refund);
        $ledger = $this->createLedger(
            voucher: $voucher,
            event: VoucherEvent::Voided,
            amount: '-50.00000',
            userId: (string) Str::uuid(),
        );

        $entry = $this->makeService()->createVoucherLedgerEntry($ledger, $voucher);

        $this->assertPostedSystemGeneratedEntry($entry, $ledger);
    }

    public function test_voucher_ledger_with_resolvable_operator_preserves_posted_by(): void
    {
        $voucher = $this->createVoucher(VoucherSource::Refund);
        $ledger = $this->createLedger(
            voucher: $voucher,
            event: VoucherEvent::Redeemed,
            amount: '-15.00000',
            userId: $this->cashier->id,
        );

        $entry = $this->makeService()->createVoucherLedgerEntry($ledger, $voucher);
        $entry->refresh();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->cashier->id, $entry->posted_by);
    }

    private function makeService(): GeneralLedgerService
    {
        return app(GeneralLedgerService::class);
    }

    private function createVoucher(VoucherSource $source): Voucher
    {
        return Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'initial_balance' => '50.00000',
            'current_balance' => '50.00000',
            'currency' => 'EUR',
            'status' => VoucherStatus::Issued,
            'redemption_mode' => RedemptionMode::Bearer,
            'voucher_kind' => VoucherKind::MPV,
            'source' => $source,
            'issued_by_user_id' => $this->cashier->id,
        ]);
    }

    /**
     * @param  numeric-string  $amount
     */
    private function createLedger(
        Voucher $voucher,
        VoucherEvent $event,
        string $amount,
        string $userId,
    ): VoucherLedger {
        return VoucherLedger::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'event' => $event,
            'amount' => $amount,
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => null,
            'user_id' => $userId,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => now(),
        ]);
    }

    private function assertPostedSystemGeneratedEntry(JournalEntry $entry, VoucherLedger $ledger): void
    {
        $entry->refresh()->load('lines');

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertNull($entry->posted_by);
        $this->assertSame('voucher_ledger', $entry->source_type);
        $this->assertSame($ledger->id, $entry->source_id);
        $this->assertCount(2, $entry->lines);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->fiscal_hash);
    }
}
