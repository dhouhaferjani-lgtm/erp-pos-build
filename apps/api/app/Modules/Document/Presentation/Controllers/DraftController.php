<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\DocumentLineTaxResolver;
use App\Modules\Document\Domain\Exceptions\DraftNotEditableException;
use App\Modules\Document\Domain\Services\DraftPersistenceService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\AutoSaveDraftRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Controller for draft document auto-save operations.
 *
 * This controller provides endpoints for incremental saving of draft documents.
 * The goal is to capture all user actions for fraud detection, so business rules
 * (a partner being chosen, a line being complete) are NOT enforced here.
 *
 * Key differences from DocumentController:
 * - Lenient validation (accepts partial/incomplete data, including zero lines)
 * - Fires granular events (line added/modified/removed)
 * - Optimized for frequent auto-save calls
 * - No business logic enforcement
 *
 * P1 hardening (ticket 2026-08-22 §1) added the three properties it was missing
 * and that no amount of leniency justifies: a permission gate on the route, a
 * draft-only status guard, and a payload contract (`AutoSaveDraftRequest`).
 */
class DraftController extends Controller
{
    use HandlesDocuments;

    public function __construct(
        private readonly DraftPersistenceService $draftService,
        protected readonly CompanyContext $companyContext,
        private readonly DocumentLineTaxResolver $lineTaxResolver,
    ) {}

    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
    }

    /**
     * Resolve every incoming line's `tax_rate` through the SAME resolver the
     * manual document-create path uses, BEFORE the payload reaches the
     * persistence service (campaign defect N-1; fiscal gate r1 finding 1,
     * relocated here by fiscal gate r2 finding 2).
     *
     * WHY IT IS NEEDED AT ALL. `DraftPersistenceService` writes
     * `$lineData['tax_rate'] ?? 0` and never consulted a tax configuration.
     * That was survivable only while the editor always sent a rate; the N-1
     * frontend fix made a line that knows its configuration send
     * `tax_configuration_id` and NO `tax_rate`, so the `?? 0` fallback started
     * persisting 0 % draft lines — and a draft is a real `documents` row whose
     * rate conversion copies verbatim into an invoice.
     *
     * WHY IT LIVES IN THE CONTROLLER AND NOT IN THE SERVICE. The first attempt
     * constructor-injected `DocumentLineTaxResolver` (Application tier) into
     * `DraftPersistenceService` (Domain tier) and turned
     * `tools/deptrac-ratchet.php` red — `ModuleDomain on ModuleApplication`
     * 54 → 55, which that tool classifies as a BLOCKER by name, not a ratchet.
     * This repo had already refused the identical injection once, in writing:
     * `Domain/Services/Conversion/Converters/PurchaseQuoteRequestToPurchaseOrderConverter.php`
     * resolves nothing itself and takes rates from its caller, with
     * `PurchaseQuoteRequestAwardService::resolveTaxRates()` (Application tier)
     * doing the work. This method is that same pattern: Presentation may depend
     * on Application, so the resolution happens here and the Domain service
     * keeps receiving a plain resolved payload.
     *
     * DEFENSIVE, BECAUSE AUTO-SAVE MUST NOT 500. No lines, or a company that
     * cannot be resolved, returns the payload untouched. The product lookup is
     * tenant+company scoped exactly like every lookup inside the persistence
     * service, so a foreign product id contributes no rate.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveLineTaxRates(array $data): array
    {
        $rawLines = $data['lines'] ?? null;

        if (! is_array($rawLines) || $rawLines === []) {
            return $data;
        }

        $company = $this->companyContext->getCompany();

        if ($company === null) {
            return $data;
        }

        $tenantId = (string) $company->tenant_id;
        $companyId = (string) $company->id;

        /** @var array<int, array{description: string, quantity: string, unit_price: string, product_id?: string, service_id?: string, tax_rate?: string|null, tax_configuration_id?: string|null, discount_percent?: string|null, discount_amount?: string|null, notes?: string|null}> $lines */
        $lines = array_values($rawLines);

        $productIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->toArray();

        /** @var Collection<array-key, Product> $products */
        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $data['lines'] = $this->lineTaxResolver->resolve($lines, $company, $products);

        return $data;
    }

    /**
     * Auto-save a draft document.
     *
     * This endpoint is called frequently by the frontend (every 3 seconds after changes).
     * It accepts partial data and does not validate business rules.
     *
     * Request body:
     * {
     *   "draft_id": "uuid|null",
     *   "type": "quote|sales_order|invoice|purchase_order",
     *   "partner_id": "uuid|null",
     *   "lines": [
     *     {
     *       "id": "uuid|null",        // null for new lines
     *       "product_id": "uuid",
     *       "quantity": 1,
     *       "unit_price": 100.00
     *     }
     *   ],
     *   "notes": "string|null"
     * }
     *
     * @group Documents
     *
     * @subgroup Drafts
     */
    public function autoSave(AutoSaveDraftRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $tenantId = $user->tenant_id ?? '';
        $companyId = $this->companyContext->requireCompanyId();
        $userId = (string) Auth::id();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $rawDraftId = $data['draft_id'] ?? null;
        $draftId = is_string($rawDraftId) ? $rawDraftId : null;

        try {
            // INSIDE the try, deliberately (r3 finding 6). This method issues
            // two queries plus one per line, on an endpoint the editor calls
            // every three seconds; a QueryException from any of them must reach
            // the silent-failure arm below, not a 500. Before the tier
            // relocation the resolution ran inside saveDraft(), i.e. inside
            // this same try — moving it up must not widen the 500 window.
            $data = $this->resolveLineTaxRates($data);

            $document = $this->draftService->saveDraft(
                tenantId: $tenantId,
                companyId: $companyId,
                userId: $userId,
                draftId: $draftId,
                data: $data
            );

            return response()->json([
                'draft_id' => $document->id,
                'saved_at' => now()->toIso8601String(),
                'line_count' => $document->lines->count(),
            ]);
        } catch (DraftNotEditableException $e) {
            // P1: this refusal must NOT reach the silent-failure arm below. The
            // caller aimed auto-save at a document that has left the draft
            // stage, and answering 200 would tell the editor its (discarded)
            // line set had been saved.
            return $this->validationErrorResponse($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            // Log error but return success to avoid interrupting user flow
            // Auto-save failures should be silent from user perspective
            Log::error('Draft auto-save failed', [
                'draft_id' => $draftId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            // Return existing draft_id or generate new one
            return response()->json([
                'draft_id' => $draftId ?? Str::uuid()->toString(),
                'saved_at' => now()->toIso8601String(),
                'error' => 'silent_failure',
            ], 200); // Still 200 to avoid frontend errors
        }
    }
}
