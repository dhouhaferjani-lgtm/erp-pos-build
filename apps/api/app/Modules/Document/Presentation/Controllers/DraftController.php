<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Services\DraftPersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Controller for draft document auto-save operations.
 *
 * This controller provides endpoints for incremental saving of draft documents
 * without validation. The goal is to capture all user actions for fraud detection.
 *
 * Key differences from DocumentController:
 * - No validation (accepts partial/incomplete data)
 * - Fires granular events (line added/modified/removed)
 * - Optimized for frequent auto-save calls
 * - No business logic enforcement
 */
class DraftController extends Controller
{
    public function __construct(
        private readonly DraftPersistenceService $draftService,
        private readonly CompanyContext $companyContext,
    ) {}

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
    public function autoSave(Request $request): JsonResponse
    {
        /** @var \App\Modules\Identity\Domain\User|null $user */
        $user = $request->user();
        $tenantId = $user->tenant_id ?? '';
        $companyId = $this->companyContext->requireCompanyId();
        $userId = (string) Auth::id();

        $draftId = $request->input('draft_id');
        $data = $request->all();

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
        } catch (\Throwable $e) {
            // Log error but return success to avoid interrupting user flow
            // Auto-save failures should be silent from user perspective
            \Log::error('Draft auto-save failed', [
                'draft_id' => $draftId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            // Return existing draft_id or generate new one
            return response()->json([
                'draft_id' => $draftId ?? \Str::uuid()->toString(),
                'saved_at' => now()->toIso8601String(),
                'error' => 'silent_failure',
            ], 200); // Still 200 to avoid frontend errors
        }
    }
}
