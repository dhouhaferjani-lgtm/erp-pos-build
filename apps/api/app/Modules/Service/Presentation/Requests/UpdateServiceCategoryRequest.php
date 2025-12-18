<?php

declare(strict_types=1);

namespace App\Modules\Service\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = $this->route('category');
        $companyId = app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('service_categories', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($categoryId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'uuid', 'exists:service_categories,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
