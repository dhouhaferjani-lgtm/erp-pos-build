<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GetLedgerRequest
 *
 * Form request validator for General Ledger queries.
 *
 * Validates query parameters for retrieving a general ledger report:
 * - account_id: Optional account UUID to filter transactions
 * - date_from: Optional start date for filtering transactions
 * - date_to: Optional end date for filtering transactions
 * - partner_id: Optional partner UUID for subledger filtering
 * - page: Optional page number for pagination (default: 1)
 * - per_page: Optional items per page (default: 50, max: 200)
 *
 * Validation Rules:
 * - account_id: Must be a valid UUID and exist in accounts table
 * - date_from: Must be a valid date, cannot be in the future
 * - date_to: Must be a valid date, cannot be before date_from
 * - partner_id: Must be a valid UUID and exist in partners table
 * - page: Must be an integer >= 1
 * - per_page: Must be an integer between 1 and 200
 *
 * Authorization:
 * - Handled by middleware/policies, not in this request
 * - authorize() returns true (permission check happens at route level)
 *
 * Example Usage:
 * ```php
 * // Controller method
 * public function index(GetLedgerRequest $request) {
 *     $accountId = $request->input('account_id');
 *     $dateFrom = $request->input('date_from');
 *     $page = $request->input('page', 1);
 *     // ...
 * }
 * ```
 *
 * Example API Calls:
 * ```
 * GET /api/v1/ledger
 * GET /api/v1/ledger?account_id={uuid}
 * GET /api/v1/ledger?date_from=2025-01-01&date_to=2025-12-19
 * GET /api/v1/ledger?partner_id={uuid}
 * GET /api/v1/ledger?page=2&per_page=100
 * ```
 *
 * @package App\Modules\Accounting\Presentation\Requests
 */
class GetLedgerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled via middleware (auth:sanctum) and
     * route-level permission checks (can:ledger.view), so this
     * always returns true.
     *
     * @return bool
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
            // Optional account filter
            // If provided, must be a valid UUID that exists in the accounts table
            'account_id' => [
                'nullable',
                'uuid',
                'exists:accounts,id',
            ],

            // Optional start date for the ledger report
            // If not provided, ledger will show all history
            'date_from' => [
                'nullable',
                'date',
                'before_or_equal:today', // Prevent future dates
            ],

            // Optional end date for the ledger report
            // If not provided, defaults to today in the controller
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from', // Ensure date_to is not before date_from
            ],

            // Optional partner filter for subledger queries
            // If provided, must be a valid UUID that exists in the partners table
            'partner_id' => [
                'nullable',
                'uuid',
                'exists:partners,id',
            ],

            // Page number for pagination (default: 1)
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            // Number of items per page (default: 50, max: 200)
            // The controller enforces the max limit as well
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:200',
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
            'account_id.uuid' => 'The account ID must be a valid UUID.',
            'account_id.exists' => 'The specified account does not exist.',
            'date_from.date' => 'The start date must be a valid date in format YYYY-MM-DD.',
            'date_from.before_or_equal' => 'The start date cannot be in the future.',
            'date_to.date' => 'The end date must be a valid date in format YYYY-MM-DD.',
            'date_to.after_or_equal' => 'The end date must be on or after the start date.',
            'partner_id.uuid' => 'The partner ID must be a valid UUID.',
            'partner_id.exists' => 'The specified partner does not exist.',
            'page.integer' => 'The page number must be an integer.',
            'page.min' => 'The page number must be at least 1.',
            'per_page.integer' => 'The per_page value must be an integer.',
            'per_page.min' => 'The per_page value must be at least 1.',
            'per_page.max' => 'The per_page value cannot exceed 200.',
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
            'account_id' => 'account',
            'date_from' => 'start date',
            'date_to' => 'end date',
            'partner_id' => 'partner',
            'page' => 'page number',
            'per_page' => 'items per page',
        ];
    }
}
