<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class CreateTerminalRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            // api.pos-stabilization.007 — scope locations by company.
            'location_id' => [
                'required',
                'uuid',
                ScopedExists::company('locations', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Terminal code must contain only letters, numbers, and hyphens.',
            'location_id.exists' => 'The selected location does not exist.',
        ];
    }
}
