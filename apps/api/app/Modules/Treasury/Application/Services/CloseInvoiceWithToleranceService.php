<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Treasury\Application\Results\CloseInvoiceWithToleranceResult;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Events\InvoiceClosedWithTolerance;
use App\Modules\Treasury\Domain\Exceptions\InvoiceAlreadyPaidException;
use App\Modules\Treasury\Domain\Exceptions\ToleranceExceededException;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\Services\DocumentAllocationClassifier;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use DateTimeImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Closes a B2B invoice whose remaining balance is within the configured
 * payment-tolerance threshold by:
 *   1. Booking the residual to GL 658 (PaymentToleranceExpense / Cr AR) via
 *      the existing GeneralLedgerService::createPaymentToleranceJournalEntry.
 *   2. Persisting a tolerance-only PaymentAllocation row (payment_id = NULL)
 *      so the balance_due cache trigger stays consistent against any future
 *      credit-note allocation that fires the trigger.
 *   3. Marking the invoice as Paid + balance_due = 0 explicitly. The explicit
 *      set is defensive: under PostgreSQL the trigger now also computes 0
 *      (total - allocation.amount), and under SQLite (test driver) there is
 *      no trigger, so the explicit assignment is the source of truth in tests.
 *   4. Dispatching the immutable InvoiceClosedWithTolerance event for audit.
 *
 * Boundary semantics: strict inequality. A balance equal to the configured
 * max_amount or percentage threshold rejects — closes a sub-tolerance abuse
 * vector (per spec §15).
 */
final class CloseInvoiceWithToleranceService
{
    private const SCALE = 4;

    public function __construct(
        private readonly PaymentToleranceCheckerContract $toleranceChecker,
        private readonly PaymentToleranceService $toleranceService,
        private readonly GeneralLedgerService $glService,
        private readonly DocumentAllocationStateGuard $allocationStateGuard,
        private readonly DocumentAllocationClassifier $allocationClassifier,
        private readonly DocumentStatusService $documentStatus,
    ) {}

    public function close(string $invoiceId, string $closedBy): CloseInvoiceWithToleranceResult
    {
        return DB::transaction(function () use ($invoiceId, $closedBy): CloseInvoiceWithToleranceResult {
            /** @var Document $invoice */
            $invoice = Document::query()
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            // Treasury gate IMPORTANT — the only status check here used to be
            // `status === Paid`. A CANCELLED invoice carrying a partial
            // allocation has `balance_due > 0`, so it sailed straight through to
            // a GL write-off and `DocumentStatus::Paid` — a withdrawn document
            // reappearing as collected revenue.
            $this->allocationStateGuard->assertAllocatable($invoice);

            // N-6 — closing with tolerance writes off a RECEIVABLE residual, so
            // it presupposes a posted receivable. On a confirmed (unposted)
            // invoice there is nothing in 411 to write off and nothing to
            // settle: the money sits in 419 and the invoice still owes its
            // posting. Refuse before the write-off JE is created rather than
            // letting `markPaid()` roll the whole transaction back at the end.
            if ($this->allocationClassifier->classify($invoice) !== AllocationTreatment::ReceivableClearing) {
                throw new HttpResponseException(response()->json([
                    'error' => [
                        'code' => 'INVOICE_NOT_POSTED',
                        'message' => 'Only a posted invoice can be closed with a tolerance write-off.',
                        'details' => [
                            'document_id' => $invoiceId,
                            'document_number' => $invoice->document_number,
                            'status' => $invoice->status->value,
                        ],
                    ],
                ], 422));
            }

            // Treasury gate IMPORTANT (W-6 D2 consumer sweep): this used to be
            // `$invoice->balance_due ?? '0'`. `outstandingBalance()` treats a
            // NON-NULL cache as authoritative (unchanged for this trigger- or
            // opening-balance-maintained value) and only falls back to the
            // allocation-derived computation when the cache is genuinely NULL.
            // Scale 3 (not `self::SCALE`) to match `balance_due`'s own
            // `decimal:3` cast — this value flows into the tolerance checker,
            // the GL write-off amount and the API response, all of which
            // expect the money scale, not the wider internal comparison scale.
            $balance = $invoice->outstandingBalance(3);
            if ($invoice->status === DocumentStatus::Paid || bccomp($balance, '0', self::SCALE) <= 0) {
                throw new InvoiceAlreadyPaidException($invoiceId);
            }

            $companyId = (string) $invoice->company_id;
            $total = (string) ($invoice->total ?? '0');

            // Single source of truth for tolerance qualification (PaymentToleranceCheckerContract),
            // strict mode for A2 per spec §15. Country/currency keyed: resolve those from the
            // invoice's company before delegating.
            /** @var Company $company */
            $company = Company::query()->findOrFail($companyId);

            $check = $this->toleranceChecker->check(
                shortfall: $balance,
                invoiceTotal: $total,
                currencyCode: (string) $invoice->currency,
                countryCode: (string) $company->country_code,
                strict: true,
            );

            if (! $check->qualifies) {
                // Settings are still pulled from PaymentToleranceService directly so the
                // exception message reports the company-effective thresholds (which may
                // include a per-company override the contract surface intentionally
                // ignores). The qualifier itself runs against country defaults.
                $settings = $this->toleranceService->getToleranceSettings($companyId);

                throw new ToleranceExceededException(
                    remainingBalance: $balance,
                    maxAmount: (string) $settings['max_amount'],
                    percentage: (string) $settings['percentage'],
                );
            }

            $partnerId = (string) $invoice->partner_id;
            $entry = $this->glService->createPaymentToleranceJournalEntry(
                companyId: $companyId,
                partnerId: $partnerId,
                documentId: $invoiceId,
                amount: $balance,
                type: 'underpayment',
                date: new DateTimeImmutable('now'),
                description: 'Close with write-off (tolerance)',
                postedByUserId: $closedBy,
                currencyCode: (string) $invoice->currency,
            );

            // Tolerance-only allocation row (payment_id = NULL).
            // amount = the residual being cleared, tolerance_writeoff = same value.
            // This makes the balance_due cache trigger return total - amount = 0
            // and stay correct under any subsequent credit-note allocation.
            PaymentAllocation::create([
                'payment_id' => null,
                'document_id' => $invoiceId,
                'amount' => $balance,
                'tolerance_writeoff' => $balance,
            ]);

            // N-6 — the single write path. `markPaid()` re-checks the edge on
            // the LOCKED row and writes `balance_due` in the SAME statement.
            $this->documentStatus->markPaid($invoice, ['balance_due' => '0.000']);

            event(new InvoiceClosedWithTolerance(
                invoiceId: $invoiceId,
                companyId: $companyId,
                partnerId: $partnerId,
                amountWrittenOff: $balance,
                currency: (string) $invoice->currency,
                glEntryId: (string) $entry->id,
                closedBy: $closedBy,
                occurredAtTimestamp: new DateTimeImmutable('now'),
            ));

            return new CloseInvoiceWithToleranceResult(
                invoiceId: $invoiceId,
                amountWrittenOff: $balance,
                glEntryId: (string) $entry->id,
            );
        });
    }
}
