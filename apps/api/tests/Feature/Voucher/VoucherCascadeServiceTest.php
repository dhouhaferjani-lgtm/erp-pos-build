<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Events\ReceiptVoided;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherCascadeService;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherVoided;
use App\Modules\Voucher\Domain\Exceptions\VoucherCascadeBlockedException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for VoucherCascadeService (Task 16).
 *
 * Tests cover:
 *   - Cascade voids unredeemed voucher when credit note is voided
 *   - Cascade blocks if any voucher has been redeemed
 *   - Exception message contains runbook path
 *   - No-op when no voucher linked to the credit note
 *   - Multiple vouchers voided in the same transaction
 *   - Blocks even if only one of multiple vouchers has a redemption
 *   - Listener is wired to ReceiptVoided event
 */
final class VoucherCascadeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Terminal $terminal;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cascade Tenant',
            'slug' => 'test-voucher-cascade',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Company',
            'legal_name' => 'Cascade Company LLC',
            'tax_id' => 'TAXCSC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Cashier',
            'email' => 'cashier-cascade@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = $this->createTerminal('POS-CSC');
    }

    // -------------------------------------------------------------------------
    // No-op path
    // -------------------------------------------------------------------------

    public function test_cascade_no_op_when_no_voucher_issued_from_credit_note(): void
    {
        Event::fake([VoucherVoided::class]);

        // A credit note with NO linked vouchers
        $creditNote = $this->makeCreditNoteReceipt();

        $this->makeService()->onCreditNoteVoided($creditNote);

        // No exception + no event dispatched
        Event::assertNotDispatched(VoucherVoided::class);
    }

    // -------------------------------------------------------------------------
    // Happy path — unredeemed cascade void
    // -------------------------------------------------------------------------

    public function test_cascade_voids_unredeemed_voucher_when_credit_note_voided(): void
    {
        Event::fake([VoucherVoided::class]);

        $creditNote = $this->makeCreditNoteReceipt();
        $voucher = $this->issueVoucherFromCreditNote('50.00000', $creditNote);

        $this->makeService()->onCreditNoteVoided($creditNote);

        // Voucher status updated to Voided, balance zeroed
        $fresh = $voucher->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(VoucherStatus::Voided, $fresh->status);
        $this->assertEquals(0, bccomp($fresh->current_balance, '0', 5));

        // Voided ledger entry written
        $voidedEntry = VoucherLedger::where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Voided->value)
            ->first();
        $this->assertNotNull($voidedEntry, 'Expected Voided ledger entry');
        $this->assertNotNull($voidedEntry->gl_journal_entry_id, 'Voided entry must have a GL reference');

        // GL journal entry has reversal legs
        $entry = JournalEntry::with('lines')->find($voidedEntry->gl_journal_entry_id);
        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines);

        // VoucherVoided domain event dispatched
        Event::assertDispatched(VoucherVoided::class, function (VoucherVoided $event) use ($voucher): bool {
            return $event->voucherId === $voucher->id
                && $event->voidReason === 'cascade_credit_note_void';
        });
    }

    public function test_cascade_voids_multiple_vouchers_in_same_transaction(): void
    {
        Event::fake([VoucherVoided::class]);

        $creditNote = $this->makeCreditNoteReceipt();
        $voucher1 = $this->issueVoucherFromCreditNote('30.00000', $creditNote);
        $voucher2 = $this->issueVoucherFromCreditNote('20.00000', $creditNote);

        $this->makeService()->onCreditNoteVoided($creditNote);

        // Both vouchers voided
        foreach ([$voucher1, $voucher2] as $v) {
            $fresh = $v->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame(VoucherStatus::Voided, $fresh->status, "Voucher {$v->id} should be voided");
            $this->assertEquals(0, bccomp($fresh->current_balance, '0', 5));
        }

        // Both VoucherVoided events dispatched
        Event::assertDispatchedTimes(VoucherVoided::class, 2);
    }

    // -------------------------------------------------------------------------
    // Block path — redeemed vouchers
    // -------------------------------------------------------------------------

    public function test_cascade_blocks_when_voucher_has_redemption(): void
    {
        $creditNote = $this->makeCreditNoteReceipt();
        $voucher = $this->issueVoucherFromCreditNote('50.00000', $creditNote);

        // Simulate a redemption ledger entry (direct insert — bypasses redemption service)
        VoucherLedger::create([
            'tenant_id' => $voucher->tenant_id,
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-20.00000',
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => Carbon::now(),
        ]);

        $this->expectException(VoucherCascadeBlockedException::class);

        $this->makeService()->onCreditNoteVoided($creditNote);
    }

    public function test_cascade_blocks_when_voucher_has_redemption_voucher_status_unchanged(): void
    {
        $creditNote = $this->makeCreditNoteReceipt();
        $voucher = $this->issueVoucherFromCreditNote('50.00000', $creditNote);

        VoucherLedger::create([
            'tenant_id' => $voucher->tenant_id,
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-20.00000',
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => Carbon::now(),
        ]);

        try {
            $this->makeService()->onCreditNoteVoided($creditNote);
        } catch (VoucherCascadeBlockedException) {
            // Expected
        }

        // Voucher status must be UNCHANGED
        $fresh = $voucher->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(VoucherStatus::Issued, $fresh->status);
    }

    public function test_cascade_blocks_with_runbook_reference_in_message(): void
    {
        $creditNote = $this->makeCreditNoteReceipt();
        $voucher = $this->issueVoucherFromCreditNote('50.00000', $creditNote);

        VoucherLedger::create([
            'tenant_id' => $voucher->tenant_id,
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-10.00000',
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => Carbon::now(),
        ]);

        try {
            $this->makeService()->onCreditNoteVoided($creditNote);
            $this->fail('Expected VoucherCascadeBlockedException');
        } catch (VoucherCascadeBlockedException $e) {
            $this->assertStringContainsString(
                VoucherCascadeBlockedException::RUNBOOK_PATH,
                $e->getMessage()
            );
        }
    }

    public function test_cascade_blocks_if_any_one_voucher_has_redemption(): void
    {
        $creditNote = $this->makeCreditNoteReceipt();
        $voucher1 = $this->issueVoucherFromCreditNote('30.00000', $creditNote);
        $voucher2 = $this->issueVoucherFromCreditNote('20.00000', $creditNote);

        // Only voucher2 has a redemption
        VoucherLedger::create([
            'tenant_id' => $voucher2->tenant_id,
            'company_id' => $voucher2->company_id,
            'voucher_id' => $voucher2->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-10.00000',
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => Carbon::now(),
        ]);

        $this->expectException(VoucherCascadeBlockedException::class);

        // Must still block (atomic semantics)
        $this->makeService()->onCreditNoteVoided($creditNote);
    }

    public function test_cascade_blocks_if_any_one_voucher_has_redemption_neither_voided(): void
    {
        $creditNote = $this->makeCreditNoteReceipt();
        $voucher1 = $this->issueVoucherFromCreditNote('30.00000', $creditNote);
        $voucher2 = $this->issueVoucherFromCreditNote('20.00000', $creditNote);

        VoucherLedger::create([
            'tenant_id' => $voucher2->tenant_id,
            'company_id' => $voucher2->company_id,
            'voucher_id' => $voucher2->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-10.00000',
            'currency' => 'EUR',
            'receipt_id' => null,
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'gl_journal_entry_id' => null,
            'authorized_by_user_id' => null,
            'policy_trigger' => null,
            'reverses_voucher_ledger_id' => null,
            'occurred_at' => Carbon::now(),
        ]);

        try {
            $this->makeService()->onCreditNoteVoided($creditNote);
        } catch (VoucherCascadeBlockedException) {
            // Expected
        }

        // NEITHER voucher should be voided (full block)
        $this->assertSame(VoucherStatus::Issued, $voucher1->fresh()?->status);
        $this->assertSame(VoucherStatus::Issued, $voucher2->fresh()?->status);
    }

    // -------------------------------------------------------------------------
    // Listener wiring
    // -------------------------------------------------------------------------

    public function test_cascade_listener_wired_to_credit_note_void_event(): void
    {
        // Set up fixtures FIRST (this internally calls Event::fake() to suppress issuance events)
        $creditNote = $this->makeCreditNoteReceipt();
        $voucher = $this->issueVoucherFromCreditNote('50.00000', $creditNote);

        // Now install the "real" fake for just VoucherVoided AFTER fixtures are set up.
        // The ReceiptVoided listener will still fire (real dispatch) and the cascade will
        // run, dispatching VoucherVoided (which we capture here).
        Event::fake([VoucherVoided::class]);

        // Fire the ReceiptVoided event (the listener should trigger the cascade)
        event(new ReceiptVoided(
            receiptId: $creditNote->id,
            companyId: $creditNote->company_id,
            receiptNumber: $creditNote->receipt_number,
            voidReason: 'test_void',
            voidedBy: $this->cashier->id,
            voidedAt: Carbon::now()->toIso8601String(),
        ));

        // Voucher should now be voided (cascade ran via listener)
        $fresh = $voucher->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(VoucherStatus::Voided, $fresh->status);

        Event::assertDispatched(VoucherVoided::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): VoucherCascadeService
    {
        return app(VoucherCascadeService::class);
    }

    /**
     * Issue a voucher tied to a specific credit note (source_receipt_id = $creditNote->id).
     *
     * @param  numeric-string  $amount
     */
    private function issueVoucherFromCreditNote(string $amount, Receipt $creditNote): Voucher
    {
        Event::fake(); // suppress issuance events

        $request = new VoucherIssuanceRequest(
            amount: $amount,
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: $creditNote->id,
            issuedToPartnerId: null,
            issuedAtTerminalId: $this->terminal->id,
            expiresAt: null,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::Bearer,
        );

        return app(VoucherIssuanceService::class)->issueFromRefund($request);
    }

    /**
     * Make a credit-note (Return-type) POS receipt.
     */
    private function makeCreditNoteReceipt(): Receipt
    {
        // A return receipt requires original_receipt_id + return_reason
        // (pos_receipts_return_logic, PostgreSQL); original_receipt_id is an FK
        // to a real sale receipt.
        $sale = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);

        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $sale->id,
            'return_reason' => ReturnReason::Defective,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'voided_by' => $this->cashier->id,
        ]);
    }

    private function createTerminal(string $code): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => $code,
            'name' => "Terminal {$code}",
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'fiscal_schema_version' => 2,
        ]);
    }
}
