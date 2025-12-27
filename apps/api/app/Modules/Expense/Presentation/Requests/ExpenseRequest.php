<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for expense validation.
 */
class ExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'expense_category_id' => [
                'nullable',
                Rule::exists('expense_categories', 'id'),
            ],
            'payment_method_id' => [
                'nullable',
                Rule::exists('payment_methods', 'id'),
            ],
            'payment_repository_id' => [
                'nullable',
                Rule::exists('payment_repositories', 'id'),
            ],
            'payment_date' => ['nullable', 'date'],
            'receipt_number' => ['nullable', 'string', 'max:255'],
            'total' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'is_paid' => ['boolean'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'document_date' => ['nullable', 'date'],
        ];

        return $rules;
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vendor_name' => 'vendor name',
            'expense_category_id' => 'expense category',
            'payment_method_id' => 'payment method',
            'payment_repository_id' => 'payment repository',
            'payment_date' => 'payment date',
            'receipt_number' => 'receipt number',
            'total' => 'amount',
            'notes' => 'notes',
            'internal_notes' => 'internal notes',
            'is_paid' => 'paid status',
            'status' => 'status',
            'document_date' => 'document date',
        ];
    }
}
