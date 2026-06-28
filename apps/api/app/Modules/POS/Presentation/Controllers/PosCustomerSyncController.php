<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Enums\Vertical;
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
use Illuminate\Support\Facades\Validator;

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

        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;
        $isParapharmacy = $company->tenant->vertical === Vertical::Parapharmacy;
        $updatedSince = $this->parseUpdatedSince($request);
        $updatedSinceId = $this->parseUpdatedSinceId($request, $updatedSince);
        $limit = $this->parseLimit($request);

        $query = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both]);

        $customers = $this->applyUpdatedSinceCursor($query, $updatedSince, $updatedSinceId)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $customers->count() > $limit;
        $page = $customers->take($limit)->values();
        $lastCustomer = $page->last();

        return response()->json([
            'data' => [
                'customers' => $page
                    ->map(fn (Partner $customer): array => (new PosCustomerMirrorResource($customer, $isParapharmacy))->toArray($request))
                    ->values()
                    ->all(),
                'has_more' => $hasMore,
                'next_updated_since' => $hasMore && $lastCustomer instanceof Partner
                    ? $lastCustomer->updated_at?->toISOString()
                    : null,
                'next_updated_since_id' => $hasMore && $lastCustomer instanceof Partner
                    ? $lastCustomer->id
                    : null,
                'synced_at' => Carbon::now()->toISOString(),
            ],
        ]);
    }

    private function parseUpdatedSince(Request $request): ?Carbon
    {
        if (! $request->query->has('updated_since')) {
            return null;
        }

        $value = $request->query('updated_since');

        if (! is_string($value) || $value === '') {
            abort(422, 'updated_since must be a non-empty ISO-8601 timestamp');
        }

        $validator = Validator::make(
            ['updated_since' => $value],
            ['updated_since' => ['date']],
        );

        if ($validator->fails()) {
            abort(422, 'updated_since must be a valid ISO-8601 timestamp');
        }

        return Carbon::parse($value);
    }

    private function parseUpdatedSinceId(Request $request, ?Carbon $updatedSince): ?string
    {
        if (! $request->query->has('updated_since_id')) {
            return null;
        }

        if ($updatedSince === null) {
            abort(422, 'updated_since_id requires updated_since');
        }

        $value = $request->query('updated_since_id');

        if (! is_string($value) || $value === '') {
            abort(422, 'updated_since_id must be a non-empty UUID');
        }

        $validator = Validator::make(
            ['updated_since_id' => $value],
            ['updated_since_id' => ['uuid']],
        );

        if ($validator->fails()) {
            abort(422, 'updated_since_id must be a valid UUID');
        }

        return $value;
    }

    private function parseLimit(Request $request): int
    {
        $value = $request->query('limit');

        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            abort(422, 'limit must be a positive integer');
        }

        $limit = (int) $value;
        if ($limit < 1) {
            abort(422, 'limit must be positive');
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @param  Builder<Partner>  $query
     * @return Builder<Partner>
     */
    private function applyUpdatedSinceCursor(Builder $query, ?Carbon $updatedSince, ?string $updatedSinceId): Builder
    {
        if ($updatedSince === null) {
            return $query;
        }

        if ($updatedSinceId === null) {
            return $query->where('updated_at', '>', $updatedSince);
        }

        return $query->where(static function (Builder $cursorQuery) use ($updatedSince, $updatedSinceId): void {
            $cursorQuery
                ->where('updated_at', '>', $updatedSince)
                ->orWhere(static function (Builder $tieQuery) use ($updatedSince, $updatedSinceId): void {
                    $tieQuery
                        ->where('updated_at', '=', $updatedSince)
                        ->where('id', '>', $updatedSinceId);
                });
        });
    }
}
