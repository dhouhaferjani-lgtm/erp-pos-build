<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for processing receipt payments.
 *
 * Supports split payments across multiple payment methods.
 */
final class StoreReceiptPaymentsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'payments.*.repository_id' => ['required', 'uuid', 'exists:payment_repositories,id'],
            'payments.*.card_last_four' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'payments.*.transaction_reference' => ['nullable', 'string', 'max:100'],
            'payments.*.authorization_code' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'uuid', 'exists:partners,id'],
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
            'payments.required' => 'At least one payment method is required',
            'payments.array' => 'Payments must be an array',
            'payments.min' => 'At least one payment method is required',
            'payments.*.payment_method_id.required' => 'Payment method ID is required',
            'payments.*.payment_method_id.exists' => 'Payment method does not exist',
            'payments.*.amount.required' => 'Payment amount is required',
            'payments.*.amount.numeric' => 'Payment amount must be a valid number',
            'payments.*.amount.min' => 'Payment amount must be greater than zero',
            'payments.*.amount.regex' => 'Payment amount must have at most 3 decimal places',
            'payments.*.repository_id.required' => 'Payment repository ID is required',
            'payments.*.repository_id.exists' => 'Payment repository does not exist',
            'payments.*.card_last_four.size' => 'Card last four digits must be exactly 4 digits',
            'payments.*.card_last_four.regex' => 'Card last four digits must contain only numbers',
            'payments.*.transaction_reference.max' => 'Transaction reference cannot exceed 100 characters',
            'payments.*.authorization_code.max' => 'Authorization code cannot exceed 50 characters',
            'customer_id.exists' => 'Customer does not exist',
        ];
    }
}
