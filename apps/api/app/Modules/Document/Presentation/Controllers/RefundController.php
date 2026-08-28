<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DTOs\ReturnDecisionData;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use App\Modules\Document\Domain\Exceptions\DocumentHasPaymentsException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionConflictException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionForbiddenException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionMismatchesGoodsException;
use App\Modules\Document\Domain\Exceptions\ReturnLocationAmbiguousException;
use App\Modules\Document\Domain\Exceptions\ReturnLocationUnresolvedException;
use App\Modules\Document\Domain\Exceptions\ReturnNothingDeliveredException;
use App\Modules\Document\Domain\Exceptions\ReturnQuantityExceededException;
use App\Modules\Document\Domain\Services\RefundService;
use App\Modules\Taxation\Domain\Exceptions\DocumentPeriodLockedException;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Tenant+company scoped Document base query for route-anchored lookups.
     *
     * @return Builder<Document>
     */
    private function scopedQuery(): Builder
    {
        $company = $this->companyContext->requireCompany();

        return Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id);
    }

    /**
     * Cancel an invoice
     */
    public function cancelInvoice(Request $request, string $id): JsonResponse
    {
        $invoice = $this->scopedQuery()->findOrFail($id);

        $request->validate([
            'reason' => 'required|string|max:500',

            // Plan CF §2. OPTIONAL — an absent `return_decision` keeps today's
            // behaviour byte for byte, which is this lane's regression contract.
            'return_decision' => ['nullable', 'array'],
            'return_decision.mode' => ['required_with:return_decision', Rule::in(ReturnDecisionMode::values())],

            // `returned_on` is meaningful ONLY for already_returned, and `prohibited_unless`
            // rather than `nullable` on purpose: a date silently ignored on a
            // will_return decision would read to the user as "I recorded that date"
            // when nothing recorded it.
            'return_decision.returned_on' => [
                'required_if:return_decision.mode,'.ReturnDecisionMode::AlreadyReturned->value,
                'prohibited_unless:return_decision.mode,'.ReturnDecisionMode::AlreadyReturned->value,
                'date',
                'before_or_equal:today',
                'after_or_equal:'.$invoice->document_date->toDateString(),
            ],

            // CF-D7: no line selection in this lane. Rejected explicitly rather than
            // ignored, so a stale client that still sends it learns that its partial
            // return did NOT happen instead of silently getting a full one.
            'return_decision.lines' => ['prohibited'],
            'lines' => ['prohibited'],
        ]);

        try {
            $user = $request->user();
            $actorId = $user === null ? null : (string) $user->getAuthIdentifier();

            $cancelled = $this->refundService->cancelInvoice(
                $invoice,
                (string) $request->input('reason'),
                $actorId,
                $this->returnDecisionFrom($request, $actorId),
            );

            return response()->json([
                'data' => $cancelled->load(['lines', 'partner']),
                'return_decision' => $this->returnDecisionReadback($cancelled),
                'message' => 'Invoice cancelled successfully',
            ]);
        } catch (DocumentPeriodLockedException $e) {
            // R2-F1. The generic catch below flattens every failure into
            // `{error: <message>, code: <message>}` — `code` carries the message,
            // not a code — which would erase the typed refusal code and its
            // period metadata. Re-throwing hands the exception to the dedicated
            // renderer in bootstrap/app.php, which emits the standard
            // `{error: {code, message, …}}` envelope with a stable machine code.
            // The pre-existing envelope of the generic branch is left untouched
            // (out of this lane's scope; other consumers assert on it).
            throw $e;
        } catch (ReturnDecisionForbiddenException $e) {
            // CF-D8's typed 403. This arm is SEPARATE from the one below because the
            // exception extends `AuthorizationException`, not `\DomainException` — so
            // the generic `catch (\Exception)` at the bottom would swallow it BEFORE
            // Laravel could convert it to `AccessDeniedHttpException` and before the
            // dedicated `bootstrap/app.php` arm could ever be consulted. Without this
            // re-throw the whole CF-D8 leg was delivered as
            // `{error: "This action is unauthorized.", code: "This action is unauthorized."}`
            // — the exact untyped envelope frontend I-1 exists to eliminate, and the
            // `RETURN_DECISION_FORBIDDEN` copy T12 wrote could never render.
            throw $e;
        } catch (ReturnPeriodLockedException|ReturnQuantityExceededException|ReturnNothingDeliveredException|ReturnLocationUnresolvedException|ReturnLocationAmbiguousException|ReturnDecisionConflictException|ReturnDecisionMismatchesGoodsException $e) {
            // Plan CF T6. Every one of these extends \DomainException, so WITHOUT this
            // arm the generic catch below would flatten them into
            // `{error: <message>, code: <message>}` — a STRING in `error`, against
            // which the web app's `extractErrorCode` (which reads `error.code`) yields
            // undefined and `getErrorMessage` yields axios' bare "Request failed with
            // status code 422". The modal could then not tell "nothing was delivered"
            // from "the period is closed" from "someone already decided", which are
            // three different remedies rendered in three different places.
            //
            // `ReturnDecisionConflictException` in particular must pass through
            // UNTOUCHED: RefundService has already appended the rejected decision and
            // re-thrown it (CF-D5's commit-then-refuse), and swallowing or re-wrapping
            // it here would lose that ordering.
            throw $e;
        } catch (\DomainException $e) {
            // The refusal itself is not new — RefundService and DocumentPostingService
            // have thrown `\DomainException('DOCUMENT_HAS_PAYMENTS')` all along — but
            // it reached the client through the untyped envelope below. Re-throwing it
            // typed hands it to the dedicated renderer (frontend gate I-1). Matching on
            // the message is deliberate: it keeps both services untouched, and the
            // string is already the de-facto contract three tests assert on.
            if ($e->getMessage() === DocumentHasPaymentsException::CODE) {
                throw new DocumentHasPaymentsException($invoice->id, (string) $invoice->document_number);
            }

            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Build the goods decision from the validated request, or NULL when the client
     * sent none (today's behaviour).
     */
    private function returnDecisionFrom(Request $request, ?string $actorId): ?ReturnDecisionData
    {
        $raw = $request->input('return_decision');

        if (! is_array($raw) || ! isset($raw['mode'])) {
            return null;
        }

        $mode = ReturnDecisionMode::from((string) $raw['mode']);
        $returnedOn = isset($raw['returned_on']) ? Carbon::parse((string) $raw['returned_on']) : null;

        return new ReturnDecisionData($mode, $returnedOn, $actorId);
    }

    /**
     * The recorded decision and its return note, for the 200 body.
     *
     * CF-D5's readability half — the owner's "the choice is recorded" has to be
     * visible to the client, not only to the database. Read back from the payload
     * rather than returned from the service so the identical-replay path (which
     * creates nothing) reports the SAME shape as the first call.
     *
     * @return array<string, mixed>|null
     */
    private function returnDecisionReadback(Document $invoice): ?array
    {
        $recorded = $invoice->payload['return_decisions'] ?? null;

        if (! is_array($recorded)) {
            return null;
        }

        $accepted = null;
        foreach (array_reverse($recorded) as $entry) {
            if (is_array($entry) && ($entry['accepted'] ?? false) === true) {
                $accepted = $entry;
                break;
            }
        }

        if ($accepted === null) {
            return null;
        }

        $returnNote = null;
        $returnNoteId = $accepted['return_note_id'] ?? null;
        if (is_string($returnNoteId)) {
            $note = $this->scopedQuery()->find($returnNoteId);
            if ($note !== null) {
                $returnNote = [
                    'id' => $note->id,
                    'document_number' => $note->document_number,
                    'status' => $note->status->value,
                ];
            }
        }

        return [
            'mode' => $accepted['mode'] ?? null,
            'returned_on' => $accepted['returned_on'] ?? null,
            'return_note' => $returnNote,
        ];
    }

    /**
     * Cancel a credit note
     */
    public function cancelCreditNote(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $creditNote = $this->scopedQuery()->findOrFail($id);

        try {
            $cancelled = $this->refundService->cancelCreditNote(
                $creditNote,
                (string) $request->input('reason')
            );

            return response()->json([
                'data' => $cancelled->load(['lines', 'partner']),
                'message' => 'Credit note cancelled successfully',
            ]);
        } catch (DocumentPeriodLockedException $e) {
            // R2-F1 — see cancelInvoice() above.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create a full credit note from an invoice
     */
    public function createFullCreditNote(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $creditNote = $this->refundService->createFullCreditNote(
                $invoice,
                (string) $request->input('reason'),
            );

            return response()->json([
                'data' => $creditNote->load(['lines', 'partner']),
                'message' => 'Full credit note created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create a partial credit note from an invoice
     */
    public function createPartialCreditNote(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'reason' => 'required|string|max:500',
            'line_items' => 'required|array|min:1',
            // api.document.042: tenant+company-scoped product validator.
            'line_items.*.product_id' => ['nullable', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'required|numeric|min:0.01',
            'line_items.*.unit_price' => 'required|numeric|min:0',
            'line_items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'line_items.*.discount_amount' => 'nullable|numeric|min:0',
            'line_items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'line_items.*.tax_amount' => 'nullable|numeric|min:0',
            'line_items.*.subtotal' => 'required|numeric|min:0',
            'line_items.*.total' => 'required|numeric|min:0',
        ]);

        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $creditNote = $this->refundService->createPartialCreditNote(
                $invoice,
                $request->input('line_items'),
                (string) $request->input('reason'),
            );

            return response()->json([
                'data' => $creditNote->load(['lines', 'partner']),
                'message' => 'Partial credit note created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if invoice can be cancelled
     */
    public function checkCancellable(string $id): JsonResponse
    {
        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            // R2-F1 / GL gate I-3: `reason_code` is additive and carries the SAME
            // codes the cancel endpoint's 422 returns, so the UI can disable the
            // button and explain itself (DOCUMENT_PERIOD_FILED is a permanent dead
            // end — a filed declaration is never reopened) instead of letting the
            // user discover the refusal on submit.
            $reasonCode = $this->refundService->cancellationBlockReason($invoice);

            $invoice->loadMissing('lines');

            return response()->json([
                'data' => [
                    'can_cancel' => $reasonCode === null,
                    'reason_code' => $reasonCode,
                    'status' => $invoice->status->value,

                    // Plan CF T7. Additive — `can_cancel`, `reason_code` and `status`
                    // are unchanged. CF-D5 and CF-D6 make this endpoint load-bearing
                    // for the modal's CONTENT, not just for whether the button is
                    // live: which options render, which are disabled and why, whether
                    // a decision was already recorded, and how a multi-location
                    // delivery will be split.
                    'requires_return_decision' => $this->refundService->requiresReturnDecision($invoice),
                    'goods_issued' => $this->refundService->hasGoodsIssued($invoice),
                    'delivered_quantities' => $this->refundService->deliveredQuantities($invoice),
                    'return_decision' => $this->refundService->recordedReturnDecision($invoice),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if invoice can be credited
     */
    public function checkCreditable(string $id): JsonResponse
    {
        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $canCredit = $this->refundService->canCreditInvoice($invoice);

            return response()->json([
                'data' => [
                    'can_credit' => $canCredit,
                    'status' => $invoice->status->value,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get credit note summary for an invoice
     */
    public function getCreditNoteSummary(string $id): JsonResponse
    {
        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $summary = $this->refundService->getCreditNoteSummary($invoice);

            return response()->json([
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
