<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GetProfitLossRequest
 *
 * Form request validator for Profit & Loss (Income Statement) report queries.
 *
 * Validates query parameters for retrieving a profit & loss report:
 * - date_from: Required start date (unless fiscal_period_id provided)
 * - date_to: Required end date (unless fiscal_period_id provided)
 * - fiscal_period_id: Optional fiscal period ID (alternative to date range)
 * - include_zero_balances: Optional boolean to include zero-balance accounts
 * - include_hierarchy: Optional boolean to build hierarchical structure
 *
 * Validation Rules:
 * - date_from + date_to: Both required unless fiscal_period_id is provided
 * - date_to: Must be on or after date_from
 * - fiscal_period_id: Must be a valid UUID and exist in fiscal_periods table
 * - Booleans: Converted to true/false
 *
 * Authorization:
 * - Handled by middleware/policies, not in this request
 * - authorize() returns true (permission check happens at route level)
 *
 * Example Usage:
 * ```php
 * // Controller method
 * public function profitLoss(GetProfitLossRequest $request) {
 *     $dateFrom = $request->get('date_from');
 *     $dateTo = $request->get('date_to');
 *     $includeZero = $request->boolean('include_zero_balances');
 *     // ...
 * }
 * ```
 *
 * Example API Calls:
 * ```
 * GET /api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31
 * GET /api/v1/reports/profit-loss?fiscal_period_id={uuid}
 * GET /api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31&include_zero_balances=true
 * ```
 */
class GetProfitLossRequest extends FormRequest
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
            // Start date for the report period
            // Required unless fiscal_period_id is provided
            'date_from' => [
                'required_without:fiscal_period_id',
                'nullable',
                'date',
                'before_or_equal:today', // Prevent future dates
            ],

            // End date for the report period
            // Required unless fiscal_period_id is provided
            // Must be on or after date_from
            'date_to' => [
                'required_without:fiscal_period_id',
                'nullable',
                'date',
                'after_or_equal:date_from', // Ensure date_to is not before date_from
            ],

            // Optional fiscal period ID (alternative to date range)
            // If provided, the period's start_date and end_date will be used
            // Must belong to the current company
            'fiscal_period_id' => [
                'nullable',
                'uuid',
                'exists:fiscal_periods,id',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return;
                    }

                    // Validate that fiscal period belongs to current company
                    $companyContext = app(CompanyContext::class);
                    try {
                        $companyId = $companyContext->requireCompanyId();
                    } catch (\RuntimeException $e) {
                        $fail('Company context is required to validate fiscal period.');

                        return;
                    }

                    $period = \DB::table('fiscal_periods')
                        ->where('id', $value)
                        ->where('company_id', $companyId)
                        ->exists();

                    if (! $period) {
                        $fail('The selected fiscal period does not belong to your company.');
                    }
                },
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
            'date_from.required_without' => 'The start date is required when fiscal period is not provided.',
            'date_from.date' => 'The start date must be a valid date in format YYYY-MM-DD.',
            'date_from.before_or_equal' => 'The start date cannot be in the future.',
            'date_to.required_without' => 'The end date is required when fiscal period is not provided.',
            'date_to.date' => 'The end date must be a valid date in format YYYY-MM-DD.',
            'date_to.after_or_equal' => 'The end date must be on or after the start date.',
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
            'date_from' => 'start date',
            'date_to' => 'end date',
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
