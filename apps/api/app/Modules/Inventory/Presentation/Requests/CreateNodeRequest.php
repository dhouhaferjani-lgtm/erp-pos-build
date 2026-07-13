<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\NodeCode;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateNodeRequest extends FormRequest
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
        $locationId = (string) $this->input('location_id');

        return [
            'location_id' => ['required', 'bail', 'uuid', ScopedExists::company('locations', $company->id)],
            'parent_id' => [
                'sometimes',
                'nullable',
                'bail',
                'uuid',
                // Parent must be a LIVE node at the same location.
                Rule::exists('location_nodes', 'id')
                    ->where(fn ($query) => $query->where('location_id', $locationId)->whereNull('deleted_at')),
            ],
            'node_type' => ['required', Rule::enum(LocationNodeType::class)],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:'.NodeCode::PATTERN,
                // Partial-unique mirror: only LIVE codes collide (tombstoned
                // codes are reusable — spec Important 1).
                Rule::unique('location_nodes', 'code')
                    ->where(fn ($query) => $query->where('location_id', $locationId)->whereNull('deleted_at')),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
