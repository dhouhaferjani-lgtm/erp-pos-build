<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEarningRuleRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'rule_type' => ['sometimes', Rule::enum(EarningRuleType::class)],
            'priority' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'conditions' => ['sometimes', 'array'],
            'conditions.min_purchase_amount' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'conditions.max_purchase_amount' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'conditions.product_ids' => ['sometimes', 'array'],
            'conditions.product_ids.*' => ['uuid'],
            'conditions.category_ids' => ['sometimes', 'array'],
            'conditions.category_ids.*' => ['uuid'],
            'conditions.excluded_product_ids' => ['sometimes', 'array'],
            'conditions.excluded_product_ids.*' => ['uuid'],
            'conditions.time_start' => ['sometimes', 'date_format:H:i'],
            'conditions.time_end' => ['sometimes', 'date_format:H:i'],
            'conditions.day_of_week' => ['sometimes', 'array'],
            'conditions.day_of_week.*' => ['integer', 'min:1', 'max:7'],
            'conditions.min_quantity' => ['sometimes', 'integer', 'min:1'],
            'conditions.max_quantity' => ['sometimes', 'integer', 'min:1'],
            'conditions.tier_ids' => ['sometimes', 'array'],
            'conditions.tier_ids.*' => ['uuid'],
            'reward_value' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reward_type' => ['sometimes', 'string', 'in:fixed,multiplier,percentage'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'max_earn_per_transaction' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'max_earn_per_day' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
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
            'priority.min' => 'Priority must be at least 1',
            'reward_value.min' => 'Reward value must be positive',
            'reward_value.regex' => 'Reward value must not exceed 4 decimal places.',
            'end_date.after' => 'End date must be after start date',
            'conditions.min_purchase_amount.regex' => 'Min purchase amount must not exceed 3 decimal places.',
            'conditions.max_purchase_amount.regex' => 'Max purchase amount must not exceed 3 decimal places.',
            'max_earn_per_transaction.regex' => 'Max earn per transaction must not exceed 2 decimal places.',
            'max_earn_per_day.regex' => 'Max earn per day must not exceed 2 decimal places.',
        ];
    }
}
