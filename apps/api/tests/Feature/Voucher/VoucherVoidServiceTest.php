<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Application\DTOs\VoucherVoidRequest;
use App\Modules\Voucher\Application\Services\VoucherVoidService;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherVoided;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for VoucherVoidService — the single write path for the
 * voucher void edge (Session B lane Q-5, sweep findings #22 + #24).
 *
 * The three historical void paths (manual back-office void, credit-note cascade
 * void, fraud auto-void) all route through this service, so the invariants are
 * asserted once here:
 *   - a positive balance always produces exactly one GL reversal, referenced
 *     from the appended ledger row;
 *   - a zero balance produces a ledger row and NO GL entry;
 *   - the void edge is only open from Issued / PartiallyRedeemed;
 *   - a re-entered void is idempotent (no second ledger row, no second GL entry);
 *   - each caller's policy_trigger survives the consolidation.
 */
final class VoucherVoidServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Void Tenant',
            'slug' => 'test-voucher-void',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Void Company',
            'legal_name' => 'Void Company LLC',
            'tax_id' => 'TAXVOID',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Void Operator',
            'email' => 'operator-void@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // -------------------------------------------------------------------------
    // GL contract
    // -------------------------------------------------------------------------

    public function test_void_of_positive_balance_posts_exactly_one_gl_reversal(): void
    {
        Event::fake([VoucherVoided::class]);

        $voucher = $this->makeVoucher('50.00000');

        $result = $this->service()->void(new VoucherVoidRequest(
            voucherId: $voucher->id,
            userId: $this->user->id,
            policyTrigger: 'manual_void',
            reason: 'Issued in error',
        ));

        $this->assertFalse($result->alreadyVoided);
        $this->assertNotNull($result->ledgerRow);
        $this->assertNotNull($result->glJournalEntryId);
        $this->assertSame($result->glJournalEntryId, $result->ledgerRow->gl_journal_entry_id);
        $this->assertSame('manual_void', $result->ledgerRow->policy_trigger);
        $this->assertEquals(0, bccomp((string) $result->ledgerRow->amount, '-50.00000', 5));

        $entry = JournalEntry::with('lines')->find($result->glJournalEntryId);
        $this->assertNotNull($entry);
        $this->assertSame('voucher_ledger', $entry->source_type);
        $this->assertSame($result->ledgerRow->id, $entry->source_id);

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Voided, $voucher->status);
        $this->assertEquals(0, bccomp($voucher->current_balance, '0', 5));
        $this->assertSame('Issued in error', $voucher->override_reason);
        $this->assertStringContainsString('[VOID ', (string) $voucher->notes);

        Event::assertDispatched(VoucherVoided::class, function (VoucherVoided $event) use ($voucher, $result): bool {
            return $event->voucherId === $voucher->id
                && $event->voidReason === 'manual_void'
                && $event->glJournalEntryId === $result->glJournalEntryId;
        });
    }

    public function test_void_of_zero_balance_posts_no_gl_entry(): void
    {
        Event::fake([VoucherVoided::class]);

        $voucher = $this->makeVoucher('0.00000');

        $result = $this->service()->void(new VoucherVoidRequest(
            voucherId: $voucher->id,
            userId: $this->user->id,
            policyTrigger: 'auto_fraud_void',
        ));

        $this->assertNotNull($result->ledgerRow);
        $this->assertNull($result->glJournalEntryId);
        $this->assertNull($result->ledgerRow->gl_journal_entry_id);
        $this->assertSame(0, JournalEntry::where('source_type', 'voucher_ledger')->count());
    }

    // -------------------------------------------------------------------------
    // Status precondition (finding #24)
    // -------------------------------------------------------------------------

    public function test_void_of_fully_redeemed_voucher_is_refused(): void
    {
        $voucher = $this->makeVoucher('0.00000', VoucherStatus::FullyRedeemed);

        $this->expectException(VoucherInvalidStatusException::class);

        try {
            $this->service()->void(new VoucherVoidRequest(
                voucherId: $voucher->id,
                userId: $this->user->id,
                policyTrigger: 'manual_void',
            ));
        } finally {
            $voucher->refresh();
            $this->assertSame(VoucherStatus::FullyRedeemed, $voucher->status);
            $this->assertSame(0, VoucherLedger::where('voucher_id', $voucher->id)->count());
            $this->assertSame(0, JournalEntry::where('source_type', 'voucher_ledger')->count());
        }
    }

    public function test_void_of_expired_voucher_is_refused(): void
    {
        $voucher = $this->makeVoucher('50.00000', VoucherStatus::Expired);

        $this->expectException(VoucherInvalidStatusException::class);

        try {
            $this->service()->void(new VoucherVoidRequest(
                voucherId: $voucher->id,
                userId: $this->user->id,
                policyTrigger: 'manual_void',
            ));
        } finally {
            $voucher->refresh();
            $this->assertSame(VoucherStatus::Expired, $voucher->status);
            $this->assertEquals(0, bccomp($voucher->current_balance, '50.00000', 5));
            $this->assertSame(0, VoucherLedger::where('voucher_id', $voucher->id)->count());
        }
    }

    /**
     * Redemption-vs-void interleave: a redemption that committed FullyRedeemed
     * before the void took the row lock must make the void refuse.
     */
    public function test_void_refuses_after_a_committed_full_redemption(): void
    {
        $voucher = $this->makeVoucher('50.00000');

        // The redemption commits first: ledger row + terminal status.
        $this->appendLedgerRow($voucher, VoucherEvent::Redeemed, '-50.00000');
        $voucher->update([
            'status' => VoucherStatus::FullyRedeemed,
            'current_balance' => '0.00000',
        ]);

        $this->expectException(VoucherInvalidStatusException::class);

        $this->service()->void(new VoucherVoidRequest(
            voucherId: $voucher->id,
            userId: $this->user->id,
            policyTrigger: 'manual_void',
        ));
    }

    /**
     * PINS THE EFFECTIVE VOIDABLE SET (Session B lane Q-5 micro-round, treasury
     * F-1 / fiscal F-5). `VOIDABLE_STATUSES` names PartiallyRedeemed, but a
     * voucher only reaches that status through VoucherRedemptionService, which
     * writes a `Redeemed` ledger row in the same transaction — so the redemption
     * guard behind the status guard always fires and the effective voidable set
     * is `{Issued}`.
     *
     * The discriminator matters operationally: the refusal MUST carry the
     * redemption guard's `VOUCHER_HAS_REDEMPTIONS`, never the status guard's
     * `VOUCHER_NOT_VOIDABLE`. If a future change narrowed the constant to
     * `[Issued]` the code would silently flip and this test would go red.
     */
    public function test_void_of_partially_redeemed_voucher_is_refused_by_the_redemption_guard(): void
    {
        $voucher = $this->makeVoucher('30.00000', VoucherStatus::PartiallyRedeemed);
        $this->appendLedgerRow($voucher, VoucherEvent::Redeemed, '-20.00000');

        $caught = null;

        try {
            $this->service()->void(new VoucherVoidRequest(
                voucherId: $voucher->id,
                userId: $this->user->id,
                policyTrigger: 'manual_void',
            ));
        } catch (VoucherInvalidStatusException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VoucherInvalidStatusException::class, $caught);
        $this->assertSame('VOUCHER_HAS_REDEMPTIONS', $caught->errorCode);

        $voucher->refresh();
        $this->assertSame(VoucherStatus::PartiallyRedeemed, $voucher->status);
        $this->assertSame(0, bccomp($voucher->current_balance, '30.00000', 5));
        $this->assertSame(
            0,
            VoucherLedger::where('voucher_id', $voucher->id)
                ->where('event', VoucherEvent::Voided->value)
                ->count()
        );
    }

    /**
     * The companion half of the pin: the two statuses that genuinely trip the
     * STATUS guard carry `VOUCHER_NOT_VOIDABLE`, so the two refusals stay
     * distinguishable to an operator and to the API contract.
     */
    public function test_terminal_statuses_are_refused_by_the_status_guard(): void
    {
        foreach ([VoucherStatus::FullyRedeemed, VoucherStatus::Expired] as $status) {
            $voucher = $this->makeVoucher('30.00000', $status);

            $caught = null;

            try {
                $this->service()->void(new VoucherVoidRequest(
                    voucherId: $voucher->id,
                    userId: $this->user->id,
                    policyTrigger: 'manual_void',
                ));
            } catch (VoucherInvalidStatusException $e) {
                $caught = $e;
            }

            $this->assertInstanceOf(VoucherInvalidStatusException::class, $caught);
            $this->assertSame('VOUCHER_NOT_VOIDABLE', $caught->errorCode, $status->value);
        }
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_second_void_is_an_idempotent_no_op(): void
    {
        Event::fake([VoucherVoided::class]);

        $voucher = $this->makeVoucher('50.00000');

        $request = new VoucherVoidRequest(
            voucherId: $voucher->id,
            userId: $this->user->id,
            policyTrigger: 'manual_void',
            reason: 'Issued in error',
        );

        $first = $this->service()->void($request);
        $second = $this->service()->void($request);

        $this->assertFalse($first->alreadyVoided);
        $this->assertTrue($second->alreadyVoided);
        $this->assertNull($second->ledgerRow);
        $this->assertNull($second->glJournalEntryId);

        $this->assertSame(
            1,
            VoucherLedger::where('voucher_id', $voucher->id)
                ->where('event', VoucherEvent::Voided->value)
                ->count()
        );
        $this->assertSame(1, JournalEntry::where('source_type', 'voucher_ledger')->count());

        Event::assertDispatchedTimes(VoucherVoided::class, 1);
    }

    // -------------------------------------------------------------------------
    // Caller provenance
    // -------------------------------------------------------------------------

    public function test_policy_trigger_and_provenance_columns_survive_each_caller(): void
    {
        Event::fake([VoucherVoided::class]);

        foreach (['manual_void', 'cascade_credit_note_void', 'auto_fraud_void'] as $trigger) {
            $voucher = $this->makeVoucher('10.00000');

            $result = $this->service()->void(new VoucherVoidRequest(
                voucherId: $voucher->id,
                userId: $this->user->id,
                policyTrigger: $trigger,
            ));

            $this->assertNotNull($result->ledgerRow);
            $this->assertSame($trigger, $result->ledgerRow->policy_trigger);
            $this->assertNotNull($result->ledgerRow->gl_journal_entry_id);
        }
    }

    public function test_void_scoped_to_a_foreign_company_refuses(): void
    {
        $voucher = $this->makeVoucher('50.00000');

        $this->expectException(VoucherInvalidStatusException::class);

        $this->service()->void(new VoucherVoidRequest(
            voucherId: $voucher->id,
            userId: $this->user->id,
            policyTrigger: 'manual_void',
            companyId: (string) Str::uuid(),
        ));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): VoucherVoidService
    {
        return app(VoucherVoidService::class);
    }

    /**
     * @param  numeric-string  $balance
     */
    private function makeVoucher(string $balance, VoucherStatus $status = VoucherStatus::Issued): Voucher
    {
        return Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
            'status' => $status,
            'initial_balance' => '50.00000',
            'current_balance' => $balance,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  numeric-string  $amount
     */
    private function appendLedgerRow(Voucher $voucher, VoucherEvent $event, string $amount): void
    {
        VoucherLedger::forceCreate([
            'id' => (string) Str::uuid(),
            'tenant_id' => $voucher->tenant_id,
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'event' => $event,
            'amount' => $amount,
            'currency' => $voucher->currency,
            'receipt_id' => null,
            'terminal_id' => null,
            'user_id' => $this->user->id,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => Carbon::now(),
        ]);
    }
}
