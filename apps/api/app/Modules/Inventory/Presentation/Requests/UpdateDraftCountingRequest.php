<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request for updating a draft counting operation.
 *
 * Allows updating title, instructions, counter assignments, and settings
 * while count is still in draft status.
 */
class UpdateDraftCountingRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true; // Authorization checked in controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'execution_mode' => ['sometimes', Rule::enum(CountingExecutionMode::class)],
            'requires_count_2' => ['sometimes', 'boolean'],
            'requires_count_3' => ['sometimes', 'boolean'],
            'allow_unexpected_items' => ['sometimes', 'boolean'],

            'count_1_user_id' => ['sometimes', 'nullable', 'string', ScopedExists::tenant('users', $company->tenant_id)],
            'count_2_user_id' => ['sometimes', 'nullable', 'string', ScopedExists::tenant('users', $company->tenant_id)],
            'count_3_user_id' => ['sometimes', 'nullable', 'string', ScopedExists::tenant('users', $company->tenant_id)],

            'scheduled_start' => ['sometimes', 'nullable', 'date', 'after_or_equal:now'],
            'scheduled_end' => ['sometimes', 'nullable', 'date', 'after:scheduled_start'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scheduled_end.after' => 'The end date must be after the start date.',
        ];
    }
}
