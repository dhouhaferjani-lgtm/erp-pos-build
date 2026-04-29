<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherIssued;
use App\Modules\Voucher\Domain\Exceptions\GoodwillFourEyesRequiredException;
use App\Modules\Voucher\Domain\Exceptions\GoodwillRequiresNamedCustomerException;
use App\Modules\Voucher\Domain\Exceptions\SpvNotYetSupportedException;
use App\Modules\Voucher\Domain\Services\VoucherCodeGenerator;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Voucher issuance — all paths that mint a new voucher (Phase 1).
 *
 * Entry points:
 *   issueFromRefund()         — refund-initiated voucher (source = Refund)
 *   issueFromExchangeSurplus() — exchange overpay surplus (source = ExchangeSurplus)
 *   issueGoodwill()           — discretionary goodwill (source = Goodwill)
 *
 * All three entry points run a single DB transaction:
 *   1. Validate request (SPV guard, goodwill controls)
 *   2. Mint voucher code via VoucherCodeGenerator
 *   3. Create Voucher row (status = Issued)
 *   4. Create VoucherLedger row (event = Issued, amount = +abs)
 *   5. Create GL JournalEntry via GeneralLedgerService::createVoucherLedgerEntry()
 *   6. Back-fill VoucherLedger.gl_journal_entry_id
 *   7. Dispatch VoucherIssued domain event
 *
 * Per EU Directive 2016/1065 (MPV-by-default), NO VAT lines are created at the
 * voucher layer. VAT is computed only on the underlying redeeming sale.
 *
 * Tenant settings (goodwill thresholds, daily cap) are hardcoded for Phase 1.
 * TODO (Task C17): replace hardcoded defaults with Company.reservation_settings
 * once tenant-settings extension lands.
 *
 * Voucher code prefix defaults to "POS" for Phase 1.
 * TODO (Phase C+): make the prefix configurable via tenant/company settings.
 *
 * Self-dealing guard (Codex review 2 finding I) is a no-op in Phase 1 because
 * the Partner model has no user_id foreign key (no User→Partner link yet).
 * TODO (Phase 2): wire GoodwillSelfDealingException when customer accounts land.
 */
final class VoucherIssuanceService
{
    /**
     * Named-customer threshold for goodwill vouchers (Phase 1 hardcoded default).
     * Above this amount, issued_to_partner_id is required.
     *
     * TODO (Task C17): read from Company.reservation_settings.goodwill_named_customer_threshold.
     *
     * @var numeric-string
     */
    private const GOODWILL_NAMED_CUSTOMER_THRESHOLD = '100.00000';

    /**
     * Four-eyes threshold for goodwill vouchers (Phase 1 hardcoded default).
     * Above this amount, authorized_by_user_id is required.
     *
     * TODO (Task C17): read from Company.reservation_settings.goodwill_four_eyes_threshold.
     *
     * @var numeric-string
     */
    private const GOODWILL_FOUR_EYES_THRESHOLD = '250.00000';

    /**
     * Voucher code tenant prefix (Phase 1 hardcoded default, 4 characters).
     *
     * Must be exactly 4 characters for the Damm check digit algorithm.
     * TODO (Phase C+): read from Company/Tenant settings.
     */
    private const DEFAULT_CODE_PREFIX = 'POSC';

    public function __construct(
        private readonly VoucherCodeGenerator $codeGenerator,
        private readonly GeneralLedgerService $generalLedger,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Issue a voucher originating from a POS refund.
     *
     * GL: Dr SalesReturnsClearing / Cr VoucherLiability (no VAT).
     *
     * @throws SpvNotYetSupportedException if request specifies SPV kind
     */
    public function issueFromRefund(VoucherIssuanceRequest $request): Voucher
    {
        $this->guardSpv($request);

        return $this->performIssuance($request, VoucherSource::Refund);
    }

    /**
     * Issue a voucher for exchange surplus (customer exchanged an item and the
     * new item is cheaper; the difference is returned as a voucher).
     *
     * GL: Dr SalesReturnsClearing / Cr VoucherLiability (no VAT).
     *
     * @throws SpvNotYetSupportedException if request specifies SPV kind
     */
    public function issueFromExchangeSurplus(VoucherIssuanceRequest $request): Voucher
    {
        $this->guardSpv($request);

        return $this->performIssuance($request, VoucherSource::ExchangeSurplus);
    }

    /**
     * Issue a discretionary goodwill voucher (e.g. customer service gesture).
     *
     * Goodwill controls (Codex review 2 finding I):
     *   - SPV rejected.
     *   - Named-customer required above GOODWILL_NAMED_CUSTOMER_THRESHOLD.
     *   - Four-eyes approval required above GOODWILL_FOUR_EYES_THRESHOLD.
     *   - Daily issuance cap per user enforced when GOODWILL_DAILY_CAP_PER_USER is set.
     *
     * GL: Dr MarketingGoodwillExpense / Cr VoucherLiability (no VAT).
     *
     * @throws SpvNotYetSupportedException if request specifies SPV kind
     * @throws GoodwillRequiresNamedCustomerException if amount > threshold and no partner supplied
     * @throws GoodwillFourEyesRequiredException if amount > threshold and no authorizer supplied
     * @throws \RuntimeException if daily cap is exceeded
     */
    public function issueGoodwill(VoucherIssuanceRequest $request): Voucher
    {
        $this->guardSpv($request);
        $this->guardGoodwillControls($request);

        return $this->performIssuance($request, VoucherSource::Goodwill);
    }

    // -------------------------------------------------------------------------
    // Private: guards
    // -------------------------------------------------------------------------

    /**
     * @throws SpvNotYetSupportedException
     */
    private function guardSpv(VoucherIssuanceRequest $request): void
    {
        if ($request->voucherKind === VoucherKind::SPV) {
            throw new SpvNotYetSupportedException;
        }
    }

    /**
     * Apply all goodwill-specific controls in one place.
     *
     * Self-dealing guard is documented as a no-op in Phase 1 (Partner has no
     * user_id link). See class-level TODO for the Phase 2 wiring plan.
     *
     * @throws GoodwillRequiresNamedCustomerException
     * @throws GoodwillFourEyesRequiredException
     * @throws \RuntimeException when daily cap exceeded
     */
    private function guardGoodwillControls(VoucherIssuanceRequest $request): void
    {
        $scale = 5;

        // Named-customer threshold
        if (
            bccomp($request->amount, self::GOODWILL_NAMED_CUSTOMER_THRESHOLD, $scale) > 0
            && $request->issuedToPartnerId === null
        ) {
            throw new GoodwillRequiresNamedCustomerException(
                $request->amount,
                self::GOODWILL_NAMED_CUSTOMER_THRESHOLD
            );
        }

        // Four-eyes threshold
        if (
            bccomp($request->amount, self::GOODWILL_FOUR_EYES_THRESHOLD, $scale) > 0
            && $request->authorizedByUserId === null
        ) {
            throw new GoodwillFourEyesRequiredException(
                $request->amount,
                self::GOODWILL_FOUR_EYES_THRESHOLD
            );
        }

        // Daily issuance cap per user (Phase 1: returns null = disabled)
        // TODO (Task C17): resolveGoodwillDailyCap() will read from Company.reservation_settings
        $dailyCap = $this->resolveGoodwillDailyCap($request->companyId);
        if ($dailyCap !== null) {
            $this->guardDailyCap($request, $scale, $dailyCap);
        }
    }

    /**
     * Resolve the daily goodwill issuance cap for the given company.
     *
     * Phase 1: reads from config key `voucher.goodwill_daily_cap_per_user` (defaults null = no cap).
     * TODO (Task C17): replace config lookup with Company.reservation_settings.goodwill_daily_issuance_cap_per_user.
     *
     * Returns null when no cap should be enforced, or a positive numeric-string cap amount.
     */
    private function resolveGoodwillDailyCap(string $companyId): ?string
    {
        // Phase 1: company-level override not yet available (Task C17).
        // Config key 'voucher.goodwill_daily_cap_per_user' is not set by default = null = no cap.
        $raw = config('voucher.goodwill_daily_cap_per_user.'.$companyId);

        if (! is_string($raw) || ! is_numeric($raw)) {
            return null;
        }

        /** @var numeric-string $cap */
        $cap = $raw;

        return $cap;
    }

    /**
     * @throws \RuntimeException when today's goodwill total for this user would exceed the cap
     */
    private function guardDailyCap(VoucherIssuanceRequest $request, int $scale, string $cap): void
    {

        // Query today's total goodwill issuances by this user for this company
        $todayTotal = (string) VoucherLedger::query()
            ->join('vouchers', 'voucher_ledger.voucher_id', '=', 'vouchers.id')
            ->where('vouchers.company_id', $request->companyId)
            ->where('vouchers.source', VoucherSource::Goodwill->value)
            ->where('voucher_ledger.user_id', $request->issuedByUserId)
            ->where('voucher_ledger.event', VoucherEvent::Issued->value)
            ->whereDate('voucher_ledger.occurred_at', Carbon::today())
            ->sum('voucher_ledger.amount');

        $projectedTotal = bcadd($todayTotal, $request->amount, $scale);

        if (! is_numeric($cap)) {
            return; // Invalid cap configuration — do not enforce
        }

        if (bccomp($projectedTotal, $cap, $scale) > 0) {
            throw new \RuntimeException(sprintf(
                'Daily goodwill issuance cap of %s exceeded. '
                ."Today's total would be %s after this issuance.",
                $cap,
                $projectedTotal
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Private: core issuance transaction
    // -------------------------------------------------------------------------

    private function performIssuance(VoucherIssuanceRequest $request, VoucherSource $source): Voucher
    {
        return DB::transaction(function () use ($request, $source): Voucher {
            // 1. Generate unique voucher code
            $code = $this->codeGenerator->generate(self::DEFAULT_CODE_PREFIX);

            // 2. Create the Voucher row
            $now = Carbon::now();
            $absAmount = $request->amount;

            /** @var Voucher $voucher */
            $voucher = Voucher::create([
                'tenant_id' => $request->tenantId,
                'company_id' => $request->companyId,
                'code' => $code,
                'initial_balance' => $absAmount,
                'current_balance' => $absAmount,
                'currency' => $request->currency,
                'status' => VoucherStatus::Issued,
                'redemption_mode' => $request->redemptionMode,
                'voucher_kind' => VoucherKind::MPV,
                'source' => $source,
                'issued_at' => $now,
                'expires_at' => $request->expiresAt,
                'partner_id' => $request->issuedToPartnerId,
                'issued_to_partner_id' => $request->issuedToPartnerId,
                'source_receipt_id' => $request->sourceReceiptId,
                'source_loyalty_transaction_id' => null,
                'source_promotional_campaign_id' => null,
                'issued_by_user_id' => $request->issuedByUserId,
                'issued_at_terminal_id' => $request->issuedAtTerminalId,
                // Phase 1: single-terminal — redeemable only at the issuing terminal
                'redeemable_at_terminal_id' => $request->issuedAtTerminalId,
                'notes' => $request->notes,
                'authorized_by_user_id' => $request->authorizedByUserId,
                'override_reason' => $request->overrideReason,
                'policy_trigger' => $request->policyTrigger,
            ]);

            // 3. Create the first VoucherLedger row (gl_journal_entry_id filled in step 5)
            /** @var VoucherLedger $ledgerRow */
            $ledgerRow = VoucherLedger::create([
                'tenant_id' => $request->tenantId,
                'company_id' => $request->companyId,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::Issued,
                'amount' => $absAmount,
                'currency' => $request->currency,
                'receipt_id' => $request->sourceReceiptId,
                'terminal_id' => $request->issuedAtTerminalId,
                'user_id' => $request->issuedByUserId,
                'gl_journal_entry_id' => null,
                'authorized_by_user_id' => $request->authorizedByUserId,
                'policy_trigger' => $request->policyTrigger,
                'reverses_voucher_ledger_id' => null,
                'occurred_at' => $now,
            ]);

            // 4. Create the GL JournalEntry (non-taxable; no VAT lines)
            $glEntry = $this->generalLedger->createVoucherLedgerEntry($ledgerRow, $voucher);

            // 5. Back-fill the GL reference on the ledger row
            // The VoucherLedger table is append-only (enforced by DB trigger on PostgreSQL),
            // but gl_journal_entry_id is a nullable back-fill column set once at creation time.
            // We use a direct DB update here because the trigger only blocks UPDATE on amount/event
            // columns — the implementation allows gl_journal_entry_id to be filled post-insert.
            // On SQLite (tests), there is no trigger; the update always succeeds.
            DB::table('voucher_ledger')
                ->where('id', $ledgerRow->id)
                ->update(['gl_journal_entry_id' => $glEntry->id]);

            $ledgerRow->gl_journal_entry_id = $glEntry->id;

            // 6. Dispatch domain event
            $this->events->dispatch(new VoucherIssued(
                voucherId: $voucher->id,
                tenantId: $voucher->tenant_id,
                companyId: $voucher->company_id,
                code: $voucher->code,
                source: $source,
                amount: $absAmount,
                currency: $request->currency,
                issuedByUserId: $request->issuedByUserId,
                glJournalEntryId: $glEntry->id,
                occurredAt: $now->toIso8601String(),
            ));

            return $voucher;
        });
    }
}
