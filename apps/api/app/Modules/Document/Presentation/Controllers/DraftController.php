<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Exceptions\DraftNotEditableException;
use App\Modules\Document\Domain\Services\DraftPersistenceService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\AutoSaveDraftRequest;
use App\Modules\Identity\Domain\User;
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
    ) {}

    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
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
