<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\ReceiptCompleted;
use App\Modules\POS\Domain\Exceptions\InstrumentRequiredException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Shared\Contracts\Treasury\Enums\ToleranceType;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service for processing receipt payments with Treasury and GL integration.
 *
 * Orchestrates the creation of:
 * 1. Treasury Payment records
 * 2. Receipt Payment records (linked to Treasury)
 * 3. General Ledger entries
 *
 * CRITICAL: POS payments are DIRECT TO REVENUE (no AR account).
 *
 * Task 21 status — the `ReceiptPayment::create` portion (`:306` in this
 * file, post-docblock) has been **relocated to `PosCoreReceiptProjection`**
 * for the device-authored fiscal-event path. The Treasury `Payment` + GL
 * portion remains here until Task 22 (`TreasuryReceiptBridge`) moves it
 * to the Treasury module's projector. The legacy online POS endpoint that
 * calls this service stays functional through Task 29 (web POS + Tauri-
 * online new-sale-authoring disposition) and is fully retired by Task 30
 * (the §14.3 two-chokepoint CI grep gate). Per spec v7 §14.2 the legacy
 * surface is the knowingly-retained server-authoring path for `void` /
 * `processReturn` only; new-sale `SALE_RECEIPT` authoring is closed for
 * all callers by Task 29. New write surfaces MUST go through the
 * projector — do not extend the `ReceiptPayment::create` branch here.
 */
final class ReceiptPaymentService
{
    private const SCALE = 3;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly PaymentToleranceCheckerContract $toleranceChecker,
        private readonly ReceiptFinalizationService $finalizationService,
        private readonly VoucherRedemptionService $voucherRedemptionService,
    ) {}

    /**
     * Process split payments for a receipt.
     *
     * Codex review B3 (2026-04-30): `instrument_type` and `instrument_serial`
     * are accepted per-payment so voucher-bearing tenders persist the actual
     * voucher serial into `pos_receipt_payments`. Both fields together (or both
     * absent) — the request validator enforces this. The v3 fiscal hash binds
     * these fields, so dropping them silently would defeat the chain.
     *
     * @param  array<int, array{payment_method_id: string, amount: numeric-string, repository_id: string, card_last_four?: string|null, transaction_reference?: string|null, authorization_code?: string|null, instrument_type?: string|null, instrument_serial?: string|null}>  $payments
     * @return array{receipt: Receipt, receipt_payments: array<int, ReceiptPayment>, treasury_payments: array<int, Payment>, change_due: numeric-string, tolerance_writeoff: numeric-string}
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function processReceiptPayments(
        string $receiptId,
        array $payments,
        ?string $customerId = null
    ): array {
        if (count($payments) === 0) {
            throw new \InvalidArgumentException('At least one payment method is required');
        }

        return DB::transaction(function () use ($receiptId, $payments, $customerId): array {
            $receipt = Receipt::with(['lines', 'vatDetails'])->findOrFail($receiptId);
            $companyId = $this->companyContext->requireCompanyId();

            // Codex review #4 (2026-05-01): receipt and resolved company-context
            // must agree. If a caller's CompanyContext was set to company X but
            // they hand in a receipt belonging to company Y, the previous
            // service did not catch it — it would scope downstream lookups by
            // X while the receipt itself was from Y, leaking semantics across
            // companies. Tear down with the same exception type Eloquent uses
            // for missing rows so the controller renders a clean 404 (not a
            // 500 leak).
            if ($companyId !== $receipt->company_id) {
                throw (new ModelNotFoundException)->setModel(Receipt::class, [$receiptId]);
            }

            // Codex review #5 (2026-05-01): scope the customer/partner against
            // the receipt's tenant + company before persisting it as
            // treasury_payments.partner_id. The HTTP validator
            // (StoreReceiptPaymentsRequest) rejects a cross-tenant customer_id
            // with 422, but programmatic callers (queue jobs, internal flows,
            // future controllers, console commands) bypass FormRequest
            // validation. Without this guard, a tenant B caller could persist
            // tenant A's partner onto tenant B's payment row — exactly the
            // exploit class this sweep closes. ModelNotFoundException trips
            // the same 404 surface as a missing receipt id.
            $scopedCustomerId = null;
            if ($customerId !== null) {
                Partner::query()
                    ->where('tenant_id', $receipt->tenant_id)
                    ->where('company_id', $receipt->company_id)
                    ->findOrFail($customerId);

                $scopedCustomerId = $customerId;
            }

            // Guard: fiscalized receipts are immutable — payments are already recorded.
            // Guard: voided receipts cannot be paid.
            // Guard: pending_seal receipts with existing payments (e.g., concurrent call)
            // are also blocked to prevent double-payment.
            if ($receipt->fiscal_status === FiscalStatus::Fiscalized || $receipt->payments()->exists()) {
                throw new \RuntimeException('Receipt has already been paid');
            }

            if ($receipt->fiscal_status === FiscalStatus::Voided) {
                throw new \RuntimeException('Cannot record payment on a voided receipt');
            }

            // Calculate total paid
            $totalPaid = '0.000';
            foreach ($payments as &$payment) {
                $payment['amount'] = CurrencyScale::bcformat($payment['amount'], self::SCALE);
                if (bccomp($payment['amount'], '0', self::SCALE) <= 0) {
                    throw new \InvalidArgumentException('Payment amount must be greater than zero');
                }
                $totalPaid = bcadd($totalPaid, $payment['amount'], self::SCALE);
            }
            unset($payment);

            // Three-way branch (A1): exact / overpay / short-pay-within-tolerance.
            // Outside-tolerance short-pay still rejects.
            // change_due is guaranteed ≥ 0 post-A1 — the previous unconditional bcsub
            // would produce a negative when short-pay was admitted.
            $totalsCompare = bccomp($totalPaid, $receipt->total, self::SCALE);
            $changeDue = '0.000';
            $toleranceAmount = '0.000';

            if ($totalsCompare > 0) {
                $changeDue = bcsub($totalPaid, $receipt->total, self::SCALE);
            } elseif ($totalsCompare < 0) {
                // Resolve country code for the typed contract surface — A1 lives behind
                // PaymentToleranceCheckerContract, which is country/currency-keyed.
                /** @var Company $company */
                $company = Company::query()->findOrFail($companyId);

                // Shortfall is the absolute gap, rebased at scale 4 to match the contract.
                $shortfall = bcsub((string) $receipt->total, $totalPaid, 4);

                $check = $this->toleranceChecker->check(
                    shortfall: $shortfall,
                    invoiceTotal: (string) $receipt->total,
                    currencyCode: (string) $receipt->currency,
                    countryCode: (string) $company->country_code,
                    strict: false,
                );

                if (! $check->qualifies || $check->type !== ToleranceType::Underpayment) {
                    throw new \InvalidArgumentException(
                        "Total paid ({$totalPaid}) is less than receipt total ({$receipt->total}) "
                        .'and exceeds the configured payment-tolerance threshold.'
                    );
                }

                $toleranceAmount = CurrencyScale::bcformat($check->difference, self::SCALE);

                // Fiscal silent-loss guard: the contract computes at scale 4, but we
                // persist GL entries and shift aggregates at scale 3. If the qualifier
                // says yes but the scale-3 result rounds to 0.000 (e.g. 0.5% of 0.05
                // = 0.00025), the writeoff is real but unrepresentable. Refuse to
                // silently drop it — fail loud so the caller can see a real number.
                if (bccomp($toleranceAmount, '0', self::SCALE) === 0) {
                    throw new \RuntimeException(
                        'Tolerance qualifier accepted the shortfall but it rounds to zero at scale 3. '
                        .'This is a fiscal precision edge case (e.g., 0.5% of 0.05 = 0.00025). '
                        ."Refusing to silently drop the writeoff. receipt={$receipt->id} difference={$check->difference}"
                    );
                }
            }

            // Process each payment method
            $receiptPayments = [];
            $treasuryPayments = [];

            foreach ($payments as $index => $paymentData) {
                // Tenant-isolation sweep (2026-05-01): scope the lookup to the
                // receipt's tenant and the resolved company so a programmatic
                // caller (queue job, internal flow, future controller, or test)
                // that bypasses StoreReceiptPaymentsRequest cannot bind another
                // tenant's repository onto this receipt's payment chain. The
                // request validator's `Rule::exists(...)->where(tenant/company)`
                // rejects this shape with 422 at the HTTP boundary; this is
                // defense in depth.
                $repository = PaymentRepository::query()
                    ->where('tenant_id', $receipt->tenant_id)
                    ->where('company_id', $receipt->company_id)
                    ->findOrFail($paymentData['repository_id']);

                if ($repository->gl_account_id === null) {
                    throw new \RuntimeException(
                        "Cannot process payment: the cash register/bank account '{$repository->name}' ({$repository->code}) "
                        .'is not linked to a General Ledger account. Every payment repository must be linked to a GL account '
                        .'(e.g., Cash account for cash registers, Bank account for bank accounts) to record transactions. '
                        .'Go to Settings → Treasury → Payment Repositories and assign a GL account to this repository.'
                    );
                }

                // Tenant-isolation sweep (2026-05-01): same scoping as the
                // PaymentRepository lookup above. Without this, a cross-tenant
                // payment_method_id bypassing the validator would still land
                // its `code` snapshot into pos_receipt_payments and the v3
                // fiscal hash would bind another tenant's method semantics
                // into this tenant's chain.
                $paymentMethod = PaymentMethod::query()
                    ->where('tenant_id', $receipt->tenant_id)
                    ->where('company_id', $receipt->company_id)
                    ->findOrFail($paymentData['payment_method_id']);

                // Codex review B4 (2026-04-30): defense-in-depth at the writer.
                // The HTTP request validator (StoreReceiptPaymentsRequest) rejects
                // this shape with 422, but programmatic callers (queue jobs,
                // internal flows, future controllers, tests) bypass FormRequest
                // validation. Without this guard, a v3 receipt could still be
                // sealed with `method_code = store_voucher` and
                // `instrument_serial = null` — exactly the fiscal-hash hole B4
                // closes "once and for all." The throw happens before any
                // Treasury / GL / ReceiptPayment write so the enclosing
                // DB::transaction() rolls back cleanly with no partial chain
                // state. Mapped to HTTP 422 by the generic DomainException
                // renderer in bootstrap/app.php.
                $methodCode = (string) $paymentMethod->code;
                if (PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)) {
                    $instrumentTypeInput = $paymentData['instrument_type'] ?? null;
                    $instrumentSerialInput = $paymentData['instrument_serial'] ?? null;
                    if (
                        ! is_string($instrumentTypeInput) || $instrumentTypeInput === ''
                        || ! is_string($instrumentSerialInput) || $instrumentSerialInput === ''
                    ) {
                        throw InstrumentRequiredException::forMethodCode($methodCode);
                    }
                }

                // Create Treasury Payment record
                $treasuryPayment = Payment::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $receipt->tenant_id,
                    'company_id' => $companyId,
                    'partner_id' => $scopedCustomerId,
                    'payment_method_id' => $paymentData['payment_method_id'],
                    'repository_id' => $paymentData['repository_id'],
                    'amount' => $paymentData['amount'],
                    'currency' => $receipt->currency,
                    'payment_date' => $receipt->posted_at,
                    'status' => PaymentStatus::Completed,
                    'payment_type' => PaymentType::POS,
                    'reference' => "POS Receipt {$receipt->receipt_number} - Payment ".($index + 1),
                    'notes' => 'POS payment ('.($index + 1).' of '.count($payments).')',
                ]);

                // Create GL entry for this payment
                $journalEntry = $this->generalLedgerService->createPOSPaymentEntry(
                    payment: $treasuryPayment,
                    receipt: $receipt,
                    repository: $repository
                );

                // Link journal entry to payment
                $treasuryPayment->update(['journal_entry_id' => $journalEntry->id]);

                // Post the journal entry immediately (no draft state for POS)
                $this->generalLedgerService->postEntry(
                    $journalEntry,
                    $receipt->cashier
                );

                // Create ReceiptPayment record linked to Treasury payment.
                // payment_method_code is an immutable snapshot of payment_methods.code
                // bound into the v3 canonical fiscal hash (Codex review B2, 2026-04-30).
                //
                // Codex review B3 (2026-04-30): coerce instrument_type to the
                // PaymentInstrumentKind enum so the cast on the model accepts it.
                // Fail loudly on an unknown string — the request validator's `in:`
                // rule should make this unreachable, but we don't trust string
                // inputs to enums.
                $instrumentTypeValue = $paymentData['instrument_type'] ?? null;
                $instrumentType = $instrumentTypeValue !== null
                    ? PaymentInstrumentKind::from($instrumentTypeValue)
                    : null;
                $receiptPayment = ReceiptPayment::create([
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                    'payment_method_id' => $paymentData['payment_method_id'],
                    'payment_type' => $paymentMethod->name,
                    'payment_method_code' => $paymentMethod->code,
                    'amount' => $paymentData['amount'],
                    'card_last_four' => $paymentData['card_last_four'] ?? null,
                    'transaction_reference' => $paymentData['transaction_reference'] ?? null,
                    'authorization_code' => $paymentData['authorization_code'] ?? null,
                    'instrument_type' => $instrumentType,
                    'instrument_serial' => $paymentData['instrument_serial'] ?? null,
                    'treasury_payment_id' => $treasuryPayment->id,
                ]);

                $receiptPayments[] = $receiptPayment;
                $treasuryPayments[] = $treasuryPayment;

                // Codex review B5 (2026-05-01): for store_voucher payments,
                // delegate the voucher-side accounting to the canonical
                // VoucherRedemptionService. It writes the voucher_ledger
                // Redeemed row, posts the Dr VoucherLiability / Cr
                // PosTenderClearing journal, decrements the voucher's
                // current_balance, and transitions its status. The receipt
                // payment row + treasury payment + repository GL entry above
                // remain — those represent the cashier's view of "this
                // tender came in via the voucher repository." The voucher
                // repository's GL account should be configured as
                // PosTenderClearing in deployment so the two journals net
                // out to Dr VoucherLiability / Cr Revenue (correct net
                // accounting: voucher liability extinguishes, revenue
                // recognized).
                //
                // The call inherits the wrapping DB::transaction(), so any
                // VoucherRedemptionException (insufficient balance, expired,
                // duplicate, terminal mismatch, currency mismatch, customer
                // mismatch) rolls back the receipt payment + treasury
                // payment + GL entry too — atomic, no partial chain state.
                if ($instrumentType === PaymentInstrumentKind::StoreVoucher) {
                    $instrumentSerial = $paymentData['instrument_serial'] ?? null;
                    if (! is_string($instrumentSerial) || $instrumentSerial === '') {
                        // Defense-in-depth: B4 guard above already enforces
                        // this, but assert again here so the redemption call
                        // never runs with an empty serial. The B4 throw site
                        // is the real fence; this is unreachable.
                        throw InstrumentRequiredException::forMethodCode($methodCode);
                    }

                    $this->voucherRedemptionService->redeem(new VoucherRedemptionRequest(
                        voucherCode: $instrumentSerial,
                        appliedAmount: $paymentData['amount'],
                        currency: (string) $receipt->currency,
                        receiptId: $receipt->id,
                        cashierId: (string) $receipt->cashier_id,
                        terminalId: (string) $receipt->terminal_id,
                        partnerId: $scopedCustomerId,
                        instrumentKind: PaymentInstrumentKind::StoreVoucher,
                    ));
                }
            }

            // A1 — Tolerance write-off: same-transaction GL post + shift increment.
            // tolerance_writeoff and change_due are set on the model without calling
            // save() here. finalize() below will persist them atomically together with
            // the fiscal_hash and the pending_seal → fiscalized status transition,
            // so a single UPDATE satisfies the immutability trigger.
            // PaymentRecorded event is intentionally untouched (Rule #8). Per the v1.1
            // contract, pos_receipt_payments.amount continues to store the tendered amount.
            if (bccomp($toleranceAmount, '0', self::SCALE) > 0) {
                $toleranceEntry = $this->generalLedgerService->createPOSPaymentToleranceEntry(
                    companyId: $companyId,
                    receiptId: $receipt->id,
                    amount: $toleranceAmount,
                    date: $receipt->posted_at,
                );
                $this->generalLedgerService->postEntry($toleranceEntry, $receipt->cashier);

                // Stage fields on the model — finalize() will persist them.
                $receipt->tolerance_writeoff = $toleranceAmount;
                $receipt->change_due = $changeDue;

                try {
                    /** @var Shift $shift */
                    $shift = Shift::query()
                        ->where('terminal_id', $receipt->terminal_id)
                        ->where('status', ShiftStatus::Open)
                        ->lockForUpdate()
                        ->firstOrFail();
                } catch (ModelNotFoundException) {
                    // Operational gap (prior shift closed, new one not yet opened)
                    // surfaces here as a generic Eloquent miss. Re-wrap so the cashier
                    // sees a domain-level error and not "Model [Shift] not found".
                    throw ShiftNotOpenException::noOpenShift($receipt->terminal_id);
                }
                $shift->applyToleranceWriteoff($toleranceAmount);
            } else {
                // Stage change_due on the model — finalize() will persist it.
                $receipt->change_due = $changeDue;
            }

            // Finalize the receipt: compute fiscal_hash, advance terminal chain, and
            // persist all staged fields (change_due, tolerance_writeoff, fiscal_hash,
            // chain_sequence, fiscal_status) in a single pending_seal → fiscalized UPDATE.
            // This call is inside the wrapping DB::transaction(), so a finalization
            // failure (e.g., unsupported schema version) rolls back the payment writes too.
            $receipt = $this->finalizationService->finalize($receipt);

            /** @var Receipt $freshReceipt */
            $freshReceipt = $receipt->fresh(['lines', 'vatDetails', 'payments']);

            // Dispatch event for cross-module listeners (loyalty, analytics).
            // The scoped customer id is what landed on treasury_payments and
            // (where applicable) the voucher redemption — emit the same value
            // here so downstream listeners see a consistent view.
            DB::afterCommit(function () use ($receipt, $companyId, $scopedCustomerId): void {
                event(new ReceiptCompleted(
                    receiptId: $receipt->id,
                    tenantId: $receipt->tenant_id,
                    companyId: $companyId,
                    customerId: $scopedCustomerId,
                    totalAmount: $receipt->total,
                    currency: $receipt->currency,
                ));
            });

            return [
                'receipt' => $freshReceipt,
                'receipt_payments' => $receiptPayments,
                'treasury_payments' => $treasuryPayments,
                'change_due' => $changeDue,
                'tolerance_writeoff' => $toleranceAmount,
            ];
        });
    }

    /**
     * Validate payment amounts match receipt total (allowing overpayment).
     *
     * @param  array<int, array{amount: numeric-string}>  $payments
     * @param  numeric-string  $receiptTotal
     */
    public function validatePaymentAmounts(array $payments, string $receiptTotal): bool
    {
        $totalPaid = '0.000';

        foreach ($payments as $payment) {
            if (bccomp($payment['amount'], '0', self::SCALE) <= 0) {
                return false;
            }

            $totalPaid = bcadd($totalPaid, $payment['amount'], self::SCALE);
        }

        // Must be at least equal to receipt total
        return bccomp($totalPaid, $receiptTotal, self::SCALE) >= 0;
    }

    /**
     * Calculate change due for overpayment.
     *
     * @param  numeric-string  $totalPaid
     * @param  numeric-string  $receiptTotal
     * @return numeric-string
     */
    public function calculateChange(string $totalPaid, string $receiptTotal): string
    {
        $change = bcsub($totalPaid, $receiptTotal, self::SCALE);

        return bccomp($change, '0', self::SCALE) > 0 ? $change : '0.000';
    }
}
