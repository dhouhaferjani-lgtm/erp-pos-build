<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\POS\Application\DTOs\ReceiptFilterCashierData;
use App\Modules\POS\Application\DTOs\ReceiptFilterTerminalData;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Requests\ReceiptFilterOptionsRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReceiptFilterOptionsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {}

    public function __invoke(ReceiptFilterOptionsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_receipts');

        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();
        $requestedLocationIds = isset($validated['location_ids']) && is_array($validated['location_ids'])
            ? array_values(array_map('strval', $validated['location_ids']))
            : null;
        $allowedLocationIds = $this->locationContext->getAllowedLocationIds($company->id);
        $effectiveLocationIds = $this->effectiveLocationIds(
            $allowedLocationIds === null ? null : array_values($allowedLocationIds),
            $requestedLocationIds,
        );

        $terminalQuery = Terminal::withTrashed()
            ->where('company_id', $company->id);
        $receiptQuery = Receipt::query()
            ->where('company_id', $company->id);

        if ($effectiveLocationIds !== null) {
            $terminalQuery->whereIn('location_id', $effectiveLocationIds);
            $receiptQuery->whereIn('location_id', $effectiveLocationIds);
        }

        if (isset($validated['from_date']) && is_string($validated['from_date'])) {
            $from = $this->dateBoundary($validated['from_date'], $company->timezone)->utc();
            $receiptQuery->where('posted_at', '>=', $from);
        }

        if (isset($validated['to_date']) && is_string($validated['to_date'])) {
            $to = $this->dateBoundary($validated['to_date'], $company->timezone)
                ->addDay()
                ->utc();
            $receiptQuery->where('posted_at', '<', $to);
        }

        $terminals = $terminalQuery
            ->orderBy('code')
            ->get()
            ->map(static fn (Terminal $terminal): array => (new ReceiptFilterTerminalData(
                id: $terminal->id,
                code: $terminal->code,
                name: $terminal->name,
                is_active: $terminal->is_active,
                v4_refund_authoring_enabled: $terminal->v4_refund_authoring_enabled,
                v4_refund_authoring_acknowledged_at: $terminal->v4_refund_authoring_acknowledged_at?->toISOString(),
            ))->toArray())
            ->values();

        $cashierSnapshots = (clone $receiptQuery)
            ->select(['cashier_id', 'cashier_name'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY cashier_id ORDER BY posted_at DESC, id DESC) AS snapshot_rank');
        $cashiers = DB::query()
            ->fromSub($cashierSnapshots, 'cashier_snapshots')
            ->where('snapshot_rank', 1)
            ->orderBy('cashier_name')
            ->orderBy('cashier_id')
            ->get()
            ->map(static fn (object $cashier): array => (new ReceiptFilterCashierData(
                id: (string) $cashier->cashier_id,
                name: (string) $cashier->cashier_name,
            ))->toArray())
            ->values();

        return response()->json([
            'data' => [
                'terminals' => $terminals,
                'cashiers' => $cashiers,
            ],
        ]);
    }

    /**
     * @param  list<string>|null  $allowedLocationIds
     * @param  list<string>|null  $requestedLocationIds
     * @return list<string>|null
     */
    private function effectiveLocationIds(?array $allowedLocationIds, ?array $requestedLocationIds): ?array
    {
        if ($allowedLocationIds === null) {
            return $requestedLocationIds;
        }

        if ($requestedLocationIds === null) {
            return $allowedLocationIds;
        }

        return array_values(array_intersect($allowedLocationIds, $requestedLocationIds));
    }

    private function dateBoundary(string $date, string $timezone): CarbonImmutable
    {
        $boundary = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if (! $boundary instanceof CarbonImmutable) {
            throw new \LogicException('Validated receipt date could not be parsed.');
        }

        return $boundary;
    }
}
