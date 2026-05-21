<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Presentation\Resources\PosCustomerMirrorResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class PosCustomerSyncController extends Controller
{
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();
        $updatedSince = $this->parseUpdatedSince($request);
        $limit = $this->parseLimit($request);

        $customers = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
            ->when(
                $updatedSince !== null,
                static fn (Builder $q): Builder => $q->where('updated_at', '>', $updatedSince),
            )
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => [
                'customers' => $customers
                    ->map(static fn (Partner $customer): array => (new PosCustomerMirrorResource($customer))->toArray($request))
                    ->values()
                    ->all(),
                'synced_at' => Carbon::now()->toISOString(),
            ],
        ]);
    }

    private function parseUpdatedSince(Request $request): ?Carbon
    {
        $value = $request->query('updated_since');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function parseLimit(Request $request): int
    {
        $value = $request->query('limit');

        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        if (! is_numeric($value)) {
            abort(422, 'limit must be numeric');
        }

        $limit = (int) $value;
        if ($limit < 1) {
            abort(422, 'limit must be positive');
        }

        return min($limit, self::MAX_LIMIT);
    }
}
