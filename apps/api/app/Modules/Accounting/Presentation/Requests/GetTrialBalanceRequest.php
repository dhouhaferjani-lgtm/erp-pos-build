<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GetTrialBalanceRequest
 *
 * Form request validator for Trial Balance report queries.
 *
 * Validates query parameters for retrieving a trial balance report:
 * - as_of_date: Optional point-in-time date (defaults to today)
 * - fiscal_period_id: Optional fiscal period ID (alternative to as_of_date)
 * - include_zero_balances: Optional boolean to include zero-balance accounts
 * - include_hierarchy: Optional boolean to build hierarchical structure
 *
 * Validation Rules:
 * - as_of_date: Must be a valid date, cannot be in the future
 * - fiscal_period_id: Must be a valid UUID if provided
 * - Booleans: Converted to true/false
 *
 * Authorization:
 * - Handled by middleware/policies, not in this request
 * - authorize() returns true (permission check happens at route level)
 *
 * Example Usage:
 * ```php
 * // Controller method
 * public function trialBalance(GetTrialBalanceRequest $request) {
 *     $asOfDate = $request->get('as_of_date');
 *     $includeZero = $request->boolean('include_zero_balances');
 *     // ...
 * }
 * ```
 */
class GetTrialBalanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled via middleware (auth:sanctum) and
     * route-level permission checks (can:reports.view), so this
     * always returns true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional point-in-time date for the report
            // If not provided, controller will default to today
            'as_of_date' => [
                'nullable',
                'date',
                'before_or_equal:today', // Prevent future dates
            ],

            // Optional fiscal period ID (alternative to as_of_date)
            // If provided, the period's end_date will be used as as_of_date
            'fiscal_period_id' => [
                'nullable',
                'uuid',
                'exists:fiscal_periods,id',
                // Note: We don't validate company_id here as it's handled by middleware
            ],

            // Whether to include accounts with zero balances (default: false)
            // Most users prefer to hide zero-balance accounts for cleaner reports
            'include_zero_balances' => [
                'nullable',
                'boolean',
            ],

            // Whether to build hierarchical account structure (default: true)
            // When false, returns flat list of accounts sorted by code
            'include_hierarchy' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'as_of_date.date' => 'The as_of_date must be a valid date in format YYYY-MM-DD.',
            'as_of_date.before_or_equal' => 'The as_of_date cannot be in the future.',
            'fiscal_period_id.uuid' => 'The fiscal_period_id must be a valid UUID.',
            'fiscal_period_id.exists' => 'The specified fiscal period does not exist.',
            'include_zero_balances.boolean' => 'The include_zero_balances must be true or false.',
            'include_hierarchy.boolean' => 'The include_hierarchy must be true or false.',
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * Makes error messages more user-friendly by using proper names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'as_of_date' => 'as of date',
            'fiscal_period_id' => 'fiscal period',
            'include_zero_balances' => 'include zero balances',
            'include_hierarchy' => 'include hierarchy',
        ];
    }

    /**
     * Prepare inputs for validation.
     *
     * Normalizes input data before validation:
     * - Converts string booleans ('true', 'false', '1', '0') to actual booleans
     * - Trims whitespace from strings
     */
    protected function prepareForValidation(): void
    {
        // Convert string booleans to actual booleans for proper validation
        if ($this->has('include_zero_balances')) {
            $this->merge([
                'include_zero_balances' => filter_var(
                    $this->input('include_zero_balances'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
            ]);
        }

        if ($this->has('include_hierarchy')) {
            $this->merge([
                'include_hierarchy' => filter_var(
                    $this->input('include_hierarchy'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
            ]);
        }
    }
}
