<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * R-10 — PER-SITE CATCH NARROWING at `ReceiptController::processReturn()`.
 *
 * enforcement-P3's M1 census (`docs/handoff/reviews/enforcement-p3/M1-census.md`
 * §6 R-10, row 17 of the §5.5 catcher census) recorded that the GL posting
 * chokepoint's balance refusal — `UnbalancedJournalEntryPostException`, an
 * `\InvalidArgumentException` by parentage — was intercepted by this method's
 * broad `catch (\InvalidArgumentException)` arm and rendered as
 * **400 `INVALID_RETURN_DATA`**. That tells a cashier their return payload is
 * wrong when the truth is that the system refused an unbalanced GL write: a
 * fiscal-integrity fault no client can fix and none should retry. The census
 * was explicit that no exception hierarchy can fix a catch-site downgrade —
 * only per-site narrowing can.
 *
 * The refusal is REACHABLE here, and this test proves it on the REAL path
 * rather than by simulation — the same path the census named: with
 * `refund_destination=store_voucher`,
 * `ReceiptReturnService::executeVoucherIssuance()` (`:882`) reaches
 * `VoucherIssuanceService` -> `GeneralLedgerService::createVoucherLedgerEntry()`
 * (`:2641`), which posts through `postEntryAndDispatchPostedEventAfterCommit`.
 * `DB::afterCommit` runs INSIDE `Connection::transaction()` with no exception
 * isolation (census §5.5, M1-D8), so the refusal still lands in this
 * controller's `try`. (The inventory GL batch at `ReceiptReturnService:514-525`
 * is the opposite case — swallowed there, and reported separately as R-11 for
 * the projection-discipline lane.)
 *
 * The imbalance itself is injected with a `JournalLine::created` model hook —
 * the same real-path technique `DocumentConversionScenarioTest` uses to drive
 * the chokepoint. Nothing is mocked: the controller, the service, the refund
 * proration and the chokepoint are all the production classes.
 */
final class ReceiptReturnUnbalancedGlNarrowingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
        Permission::findOrCreate('pos.process_returns', 'sanctum');
        $this->user->givePermissionTo('pos.view_receipts');
        $this->user->givePermissionTo('pos.process_returns');

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        // An open shift is required before any return is processed.
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        Sanctum::actingAs($this->user);
    }

    /**
     * RED-FIRST TARGET. Before the narrowing this returned
     * `400 {error.code: INVALID_RETURN_DATA}`; the chokepoint's refusal must
     * instead escape the controller and render through the global handler's
     * catch-all as `500 {error.code: INTERNAL_ERROR}` — the "unmapped 500 +
     * alert, never a 4xx" disposition pinned by the unbalanced types' docblocks
     * and by `ChokepointUnbalancedGuardTest`.
     */
    public function test_chokepoint_balance_refusal_is_not_downgraded_to_400_invalid_return_data(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $line = $this->createReceiptLine($saleReceipt);

        $this->unbalanceVoucherLedgerEntries();

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::CustomerChangedMind->value,
                'lines' => [['line_id' => $line->id, 'quantity' => '1']],
                'refund_destination' => 'store_voucher',
            ]),
        );

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'INTERNAL_ERROR');

        self::assertNotSame(
            'INVALID_RETURN_DATA',
            $response->json('error.code'),
            'A GL balance refusal must never be reported as a client-fixable return-payload error.',
        );

        // The chokepoint really did refuse: the voucher entry never reached Posted.
        self::assertSame(
            0,
            JournalEntry::where('source_type', 'voucher_ledger')
                ->where('status', JournalEntryStatus::Posted)
                ->count(),
            'The unbalanced voucher ledger entry must never have been sealed.',
        );
    }

    /**
     * REGRESSION: the broad arm's genuine tenants keep the existing contract.
     *
     * `ReceiptReturnService::validateReturnLines()` (`:1124`) throws a plain
     * `\InvalidArgumentException` when a requested `line_id` is not on the
     * original receipt — real argument validation, and exactly what
     * `400 INVALID_RETURN_DATA` is for. Narrowing must not touch it.
     */
    public function test_genuine_invalid_return_payload_still_returns_400_invalid_return_data(): void
    {
        $saleReceipt = $this->createSaleReceipt();
        $this->createReceiptLine($saleReceipt);

        $foreignLineId = Str::uuid()->toString();

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            $this->withVoidReturnApproval($saleReceipt, [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::CustomerChangedMind->value,
                'lines' => [['line_id' => $foreignLineId, 'quantity' => '1']],
            ]),
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'INVALID_RETURN_DATA');
        self::assertStringContainsString(
            'not found on original receipt',
            (string) $response->json('error.message'),
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Make every `voucher_ledger` draft entry unbalanced the moment its first
     * leg is written, so the chokepoint refuses it at post time.
     *
     * This is the technique `DocumentConversionScenarioTest` established: no
     * service is replaced, the production code path runs end to end, and the
     * only interference is one extra journal line — precisely the condition the
     * chokepoint exists to refuse.
     */
    private function unbalanceVoucherLedgerEntries(): void
    {
        /** @var Account $suspense */
        $suspense = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);

        JournalLine::created(function (JournalLine $line) use ($suspense): void {
            // Only the FIRST leg of a fresh entry triggers the injection, and the
            // injected leg carries line_order 99 — so exactly one extra leg is
            // written per entry and the hook cannot recurse into itself.
            if ((int) $line->line_order !== 0) {
                return;
            }

            $entry = JournalEntry::find($line->journal_entry_id);

            if ($entry === null
                || $entry->source_type !== 'voucher_ledger'
                || $entry->status !== JournalEntryStatus::Draft) {
                return;
            }

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $suspense->id,
                'debit' => '7.000',
                'credit' => '0',
                'description' => 'Injected unbalancing leg',
                'line_order' => 99,
            ]);
        });
    }

    private function createSaleReceipt(): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'currency' => 'TND',
        ]);
    }

    private function createReceiptLine(Receipt $receipt): ReceiptLine
    {
        return ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    private function withVoidReturnApproval(Receipt $receipt, array $requestData): array
    {
        /** @var list<array<string, mixed>> $requestLines */
        $requestLines = $requestData['lines'] ?? [];
        $lineIds = collect($requestLines)
            ->pluck('line_id')
            ->map(static fn (mixed $lineId): string => (string) $lineId)
            ->sort()
            ->values()
            ->all();
        $reason = (string) ($requestData['notes'] ?? '');
        $target = [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'line_ids' => $lineIds,
            'reason' => $reason,
        ];

        $approvalId = Str::uuid()->toString();
        $approvalEventId = Str::uuid()->toString();
        $overrideEventId = Str::uuid()->toString();

        $this->storeFiscalEvent($approvalEventId, FiscalEventType::OPERATOR_APPROVAL_GRANTED, [
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'cashier_user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $this->user->id,
            'supervisor_user_snapshot' => ['name' => $this->user->name, 'roles' => ['manager']],
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => (string) $requestData['terminal_id'],
            'training_flag' => false,
        ]);

        $this->storeFiscalEvent($overrideEventId, FiscalEventType::OVERRIDE_VOID_OR_RETURN, [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'override_context' => [
                'target_event_type' => 'POS_RECEIPT_RETURN',
                'target_reference_id' => $receipt->id,
            ],
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $reason === '' ? null : $reason,
            'supervisor_user_id' => $this->user->id,
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => (string) $requestData['terminal_id'],
            'training_flag' => false,
        ], $approvalEventId);

        return $requestData + [
            'refund_request_id' => Str::uuid()->toString(),
            'approval_id' => $approvalId,
            'approval_fiscal_event_id' => $approvalEventId,
            'approval_scope' => 'void_or_return_override',
            'approval_supervisor_user_id' => $this->user->id,
            'approval_override_event_id' => $overrideEventId,
            'authorized_by_user_id' => $this->user->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeFiscalEvent(
        string $id,
        FiscalEventType $eventType,
        array $payload,
        ?string $referenceEventId = null,
    ): void {
        FiscalEvent::query()->create([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => DB::table('fiscal_events')->count() + 1,
            'event_time_device' => now(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now(),
            'reference_event_id' => $referenceEventId,
            'canonical_bytes' => json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $id),
            'payload' => $payload,
            'payload_parse_status' => 'parsed',
        ]);
    }
}
