<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\NodeCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateNodeRequest extends FormRequest
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
        $nodeId = (string) $this->route('node');

        // The node's own location_id scopes the code-uniqueness check below.
        // A node that doesn't belong to the current company (or doesn't
        // exist) resolves to a null location_id here; the controller still
        // 404s on the actual lookup, so this is safe.
        //
        // A malformed (non-UUID) route id must never reach the `find($nodeId)`
        // query below: native Postgres `uuid` columns raise SQLSTATE 22P02 on
        // an invalid literal, which surfaces as an uncaught 500 instead of the
        // 404 the controller's own lookup would produce. Skip the query
        // entirely and treat it the same as "not found".
        $locationId = null;

        if (Str::isUuid($nodeId)) {
            $node = LocationNode::query()
                ->whereHas('location', function (Builder $query) use ($company): void {
                    /** @var Builder<Location> $query */
                    $query->where('company_id', $company->id);
                })
                ->find($nodeId);
            $locationId = $node?->location_id;
        }

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'node_type' => ['sometimes', Rule::enum(LocationNodeType::class)],
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:'.NodeCode::PATTERN,
                Rule::unique('location_nodes', 'code')
                    ->where(fn ($query) => $query->where('location_id', $locationId)->whereNull('deleted_at'))
                    ->ignore($nodeId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
