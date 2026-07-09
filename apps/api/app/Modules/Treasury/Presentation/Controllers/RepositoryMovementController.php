<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Paginated drill-down over one repository's append-only `repository_movements`
 * ledger (Treasury spine Task 26 — the read side of the Task 11 write port).
 *
 * Ordered newest-first by `ordinal` (not `occurred_at`): ordinal is the
 * gapless, monotonic, per-repository sequence the port itself assigns
 * (Task 11/22), so it is a stable total order even when two movements share an
 * `occurred_at` timestamp — `occurred_at` alone is not guaranteed unique.
 */
final class RepositoryMovementController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped, mirroring
        // PaymentRepositoryController::show/balance/transactions. A repository
        // id belonging to another company/tenant 404s via findOrFail.
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $company->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'source_type' => ['sometimes', Rule::enum(MovementSourceType::class)],
            'direction' => ['sometimes', Rule::enum(MovementDirection::class)],
        ]);

        $query = RepositoryMovement::query()
            ->where('payment_repository_id', $repository->id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $company->id);

        if (array_key_exists('date_from', $validated)) {
            $query->where('occurred_at', '>=', $validated['date_from']);
        }

        if (array_key_exists('date_to', $validated)) {
            $query->where('occurred_at', '<=', $validated['date_to']);
        }

        if (array_key_exists('source_type', $validated)) {
            $query->where('source_type', $validated['source_type']);
        }

        if (array_key_exists('direction', $validated)) {
            $query->where('direction', $validated['direction']);
        }

        $movements = $query->orderByDesc('ordinal')->paginate(20);

        $data = $movements->getCollection()->map(fn (RepositoryMovement $movement): array => [
            'id' => $movement->id,
            'direction' => $movement->direction->value,
            'amount' => $movement->amount,
            'currency' => $movement->currency,
            'balance_after' => $movement->balance_after,
            'ordinal' => $movement->ordinal,
            'source_type' => $movement->source_type->value,
            'source_id' => $movement->source_id,
            'journal_entry_id' => $movement->journal_entry_id,
            'reason_code' => $movement->reason_code?->value,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
            'recorded_while_frozen' => $movement->recorded_while_frozen,
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
        ]);
    }
}
