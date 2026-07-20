<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
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
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        if (! Str::isUuid($id)) {
            abort(404);
        }

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
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = RepositoryMovement::query()
            ->where('payment_repository_id', $repository->id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $company->id)
            ->withSum('statementAllocations as allocated_amount', 'matched_amount');

        if (array_key_exists('search', $validated)) {
            $search = $validated['search'];
            $query->where(function ($candidate) use ($search): void {
                $candidate
                    ->whereRaw('CAST(id AS TEXT) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('CAST(source_id AS TEXT) LIKE ?', ["%{$search}%"])
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('date_from', $validated)) {
            $query->where('occurred_at', '>=', $validated['date_from']);
        }

        if (array_key_exists('date_to', $validated)) {
            // `occurred_at` is a full UTC timestamp, not a date column — a bare
            // '<=' comparison against 'YYYY-MM-DD' parses to that date's
            // midnight and silently excludes every same-day row (audit M1).
            // Mirror ReportsController::resolveDateRangeForProfitLoss's
            // end-of-day convention for timestamp columns so date_to is
            // inclusive of the entire end date.
            $query->where('occurred_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
        }

        if (array_key_exists('source_type', $validated)) {
            $query->where('source_type', $validated['source_type']);
        }

        if (array_key_exists('direction', $validated)) {
            $query->where('direction', $validated['direction']);
        }

        $movements = $query->orderByDesc('ordinal')->paginate(20);

        $data = $movements->getCollection()->map(function (RepositoryMovement $movement): array {
            $rawAllocatedAmount = $movement->getAttribute('allocated_amount') ?? '0';
            if (! is_string($rawAllocatedAmount) || ! is_numeric($rawAllocatedAmount)) {
                throw new \LogicException('Repository movement allocation aggregate must be a decimal string.');
            }
            $scale = $this->scaleResolver->getScale($movement->currency);
            $allocatedAmount = bcadd($rawAllocatedAmount, '0', $scale);

            return [
                'id' => $movement->id,
                'direction' => $movement->direction->value,
                'amount' => $movement->amount,
                'allocated_amount' => $allocatedAmount,
                'remaining_allocatable_amount' => bcsub($movement->amount, $allocatedAmount, $scale),
                'currency' => $movement->currency,
                'balance_after' => $movement->balance_after,
                'ordinal' => $movement->ordinal,
                'source_type' => $movement->source_type->value,
                'source_id' => $movement->source_id,
                'journal_entry_id' => $movement->journal_entry_id,
                'reason_code' => $movement->reason_code?->value,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
                'recorded_while_frozen' => $movement->recorded_while_frozen,
                'recorded_behind_checkpoint' => $movement->recorded_behind_checkpoint,
            ];
        })->values();

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
