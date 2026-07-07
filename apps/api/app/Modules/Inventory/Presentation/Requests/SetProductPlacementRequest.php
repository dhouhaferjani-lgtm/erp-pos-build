<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Set/clear a product's placement at a location: `node_id` null clears
 * (tombstones) the live placement; a uuid moves/creates it. The node must be
 * a LIVE node; the same-location guard is enforced in
 * LocationNodeService::assignProduct inside the transaction.
 */
class SetProductPlacementRequest extends FormRequest
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
            'location_id' => ['required', 'bail', 'uuid', ScopedExists::company('locations', $company->id)],
            'node_id' => [
                'present',
                'nullable',
                'bail',
                'uuid',
                Rule::exists('location_nodes', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $company->tenant_id)->whereNull('deleted_at')),
            ],
        ];
    }
}
