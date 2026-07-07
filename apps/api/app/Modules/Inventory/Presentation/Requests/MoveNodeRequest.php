<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reparent payload: `parent_id` must be present (null = move to root) and,
 * when set, a LIVE node in the current tenant (tenant scoping for parity
 * with BulkMovePlacementsRequest/SetProductPlacementRequest). Same-location
 * + cycle guards live in LocationNodeService::moveNode inside the
 * transaction — the request shape-checks and tenant-scopes.
 */
class MoveNodeRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'parent_id' => [
                'present',
                'nullable',
                'uuid',
                Rule::exists('location_nodes', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $company->tenant_id)->whereNull('deleted_at')),
            ],
        ];
    }
}
