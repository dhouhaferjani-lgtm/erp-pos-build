<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use App\Modules\POS\Application\Services\V4RefundAuthoringAcknowledgementService;
use App\Modules\POS\Domain\Exceptions\V4RefundAuthoringNotOfferedException;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * v3-refund-chain-integration spec §9.3 Phase 2 — the DEVICE's explicit
 * acknowledgement that it received and locally wrote the server's Phase-1
 * `v4_refund_authoring_enabled = true` offer.
 *
 * Route: POST /api/v1/pos/terminals/{id}/acknowledge-v4-refund-authoring
 *
 * **Wave-2 fix-wave finding 9 (fiscal C-6) — this endpoint did not exist.**
 * The device had been posting to it since wave 2 and swallowing the 404 to
 * a `console.warn`, so `pos_terminals.v4_refund_authoring_acknowledged_at`
 * stayed NULL forever and {@see LegacyCorrectionGuard}
 * — which conditions on exactly that column, deliberately NOT on raw
 * `fiscal_schema_version` — could never fire. A v4-routed terminal kept the
 * legacy `/return` path open PERMANENTLY: the exact endpoint whose
 * chain-corruption failure mode (§1) this lane exists to lock out.
 *
 * Deliberately a distinct, explicit signal rather than something inferred
 * from "the pull succeeded" (§9.3's contract): a pull can succeed while the
 * flag lands via the device's `FiscalRegressionError` fallback branch.
 *
 * Authorization mirrors the sibling device-facing terminal reads
 * (`TerminalController::zChainState`): a POS operator on the terminal's own
 * company. Company scoping is enforced by the query, not by extra
 * middleware — a foreign terminal is a 404, never a leak.
 */
final class V4RefundAuthoringAcknowledgementController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly V4RefundAuthoringAcknowledgementService $acknowledgementService,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        if (! Gate::any(['pos.manage_terminals', 'pos.operate_terminal'])) {
            abort(403);
        }

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->findOrFail($id);

        try {
            $this->acknowledgementService->acknowledge($terminal);
        } catch (V4RefundAuthoringNotOfferedException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->code(),
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'data' => TerminalResource::make($terminal->fresh()?->load(['location', 'company'])),
        ]);
    }
}
