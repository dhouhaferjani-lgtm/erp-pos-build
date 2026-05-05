<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateTerminalRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            // api.pos-stabilization.002 — scope locations by company (no tenant_id col).
            'location_id' => [
                'sometimes',
                'required',
                'uuid',
                ScopedExists::company('locations', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'pos_software_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'max_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'allow_line_discounts' => ['sometimes', 'boolean'],
            'allow_transaction_discounts' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.exists' => 'The selected location does not exist.',
        ];
    }
}
