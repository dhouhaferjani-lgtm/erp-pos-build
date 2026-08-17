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
        $effectiveLocationIds = $this->effectiveLocationIds(
            $this->locationContext->getAllowedLocationIds($company->id),
            $validated['location_ids'] ?? null,
        );

        $terminalQuery = Terminal::withTrashed()
            ->where('company_id', $company->id);
        $receiptQuery = Receipt::query()
            ->where('company_id', $company->id);

        if ($effectiveLocationIds !== null) {
            $terminalQuery->whereIn('location_id', $effectiveLocationIds);
            $receiptQuery->whereIn('location_id', $effectiveLocationIds);
        }

        if (isset($validated['from_date'])) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['from_date'], $company->timezone)
                ->startOfDay()
                ->utc();
            $receiptQuery->where('posted_at', '>=', $from);
        }

        if (isset($validated['to_date'])) {
            $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['to_date'], $company->timezone)
                ->addDay()
                ->startOfDay()
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

        $cashiers = $receiptQuery
            ->select(['cashier_id', 'cashier_name', 'posted_at'])
            ->orderByDesc('posted_at')
            ->get()
            ->unique('cashier_id')
            ->map(static fn (Receipt $receipt): array => (new ReceiptFilterCashierData(
                id: $receipt->cashier_id,
                name: $receipt->cashier_name,
            ))->toArray())
            ->sort(static fn (array $left, array $right): int => [$left['name'], $left['id']] <=> [$right['name'], $right['id']])
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
}
