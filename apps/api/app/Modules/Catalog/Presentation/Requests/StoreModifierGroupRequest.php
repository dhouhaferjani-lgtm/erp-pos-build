<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\SelectionType;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('modifier-groups.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->getCompanyId();

        return [
            'code' => [
                'required', 'string', 'max:100',
                Rule::unique('modifier_groups', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'selection_type' => ['sometimes', new Enum(SelectionType::class)],
            'min_selections' => ['sometimes', 'integer', 'min:0'],
            'max_selections' => ['sometimes', 'integer', 'min:1'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
