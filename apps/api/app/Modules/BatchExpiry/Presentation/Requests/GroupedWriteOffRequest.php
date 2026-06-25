<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the HTTP input for POST /api/v1/batches/write-off-grouped.
 *
 * Authorization is delegated to the route-level `can:batches.write-off`
 * middleware; authorize() mirrors the existing single-lot pattern as a
 * belt-and-suspenders guard.
 */
final class GroupedWriteOffRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('batches.write-off') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return [
            // Location must exist in the current company.
            // api.unmapped.007: locations is company-scoped (no tenant_id).
            'location_id' => ['required', 'uuid', ScopedExists::company('locations', $companyId)],

            // At least one lot must be supplied.
            'lines' => ['required', 'array', 'min:1'],

            // Each line: a company-scoped batch UUID + a positive quantity (scale 4).
            'lines.*.batch_id' => [
                'required',
                'uuid',
                Rule::exists('product_batches', 'uuid')->where('company_id', $companyId),
            ],
            'lines.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                // Positive, up to 4 decimal places — never more (precision contract).
                'regex:/^\d+(\.\d{1,4})?$/',
            ],

            // Write-off reason subset — maps to MovementReason in the controller.
            'reason' => ['required', 'in:expiry,damage,other'],

            // Client-supplied idempotency key: arbitrary string, reasonable max.
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'Location is required.',
            'location_id.uuid' => 'Location ID must be a valid UUID.',
            'location_id.exists' => 'Location not found for the current company.',
            'lines.required' => 'At least one lot line is required.',
            'lines.min' => 'At least one lot line is required.',
            'lines.*.batch_id.required' => 'Batch ID is required for each line.',
            'lines.*.batch_id.uuid' => 'Batch ID must be a valid UUID.',
            'lines.*.batch_id.exists' => 'Batch not found for the current company.',
            'lines.*.quantity.required' => 'Quantity is required for each line.',
            'lines.*.quantity.gt' => 'Quantity must be greater than zero.',
            'lines.*.quantity.regex' => 'Quantity may have at most 4 decimal places.',
            'reason.required' => 'Write-off reason is required.',
            'reason.in' => 'Reason must be one of: expiry, damage, other.',
            'idempotency_key.required' => 'Idempotency key is required.',
        ];
    }
}
