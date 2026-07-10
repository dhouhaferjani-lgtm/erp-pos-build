<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Application\Services\ReplenishmentQueryService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Exceptions\CrossCompanyReplayException;
use App\Modules\Replenishment\Presentation\Resources\ReplenishmentRequestResource;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PosReplenishmentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReplenishmentCaptureService $captureService,
        private readonly ReplenishmentQueryService $queryService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();
        $validated = $request->validate([
            'client_request_uuid' => ['required', 'uuid'],
            'terminal_id' => ['required', 'uuid'],
            'product_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
            ],
            'variant_id' => ['nullable', 'uuid'],
            'requested_qty' => ['nullable', 'string', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $terminal = $this->terminal($companyId, $validated['terminal_id']);
        if ($terminal === null) {
            return $this->terminalNotFound();
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $row = $this->captureService->capture(new CaptureRequestData(
                tenantId: $tenantId,
                companyId: $companyId,
                locationId: $terminal->location_id,
                productId: $validated['product_id'],
                variantId: $validated['variant_id'] ?? null,
                requestedQty: $validated['requested_qty'] ?? null,
                note: $validated['note'] ?? null,
                requestedByUserId: $user->id,
                channel: ReplenishmentChannel::Pos,
                clientRequestUuid: $validated['client_request_uuid'],
            ));
        } catch (CrossCompanyReplayException) {
            return response()->json([
                'error' => [
                    'code' => 'REPLENISHMENT_UUID_COMPANY_CONFLICT',
                    'message' => 'The replenishment request UUID belongs to another company.',
                ],
            ], 409);
        }

        $feed = $this->queryService->feedForLocation(
            $tenantId,
            $companyId,
            $terminal->location_id,
        );
        $responseRow = $feed['rows']->firstWhere('id', $row->id);
        abort_if($responseRow === null, 500, 'Captured replenishment row was not readable.');

        return (new ReplenishmentRequestResource($responseRow))
            ->response()
            ->setStatusCode($row->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validate(['terminal_id' => ['required', 'uuid']]);
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();
        $terminal = $this->terminal($companyId, $validated['terminal_id']);
        if ($terminal === null) {
            return $this->terminalNotFound();
        }

        $feed = $this->queryService->feedForLocation(
            $tenantId,
            $companyId,
            $terminal->location_id,
        );

        return response()->json([
            'data' => ReplenishmentRequestResource::collection($feed['rows'])->resolve($request),
            'as_of' => now()->toIso8601String(),
            'truncated' => $feed['truncated'],
        ]);
    }

    private function terminal(string $companyId, string $terminalId): ?Terminal
    {
        return Terminal::query()
            ->where('company_id', $companyId)
            ->where('id', $terminalId)
            ->first();
    }

    private function terminalNotFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'TERMINAL_NOT_FOUND',
                'message' => 'Terminal not found for this company',
            ],
        ], 404);
    }
}
