<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Loyalty\Domain\Enums\RewardType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRewardRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'reward_type' => ['required', Rule::enum(RewardType::class)],
            'points_cost' => ['required', 'numeric', 'min:0'],
            'reward_value' => ['nullable', 'numeric', 'min:0'],
            'qualifying_items' => ['nullable', 'array'],
            'qualifying_items.item_types' => ['sometimes', 'array'],
            'qualifying_items.item_types.*' => ['string'],
            'qualifying_items.item_ids' => ['sometimes', 'array'],
            'qualifying_items.item_ids.*' => ['uuid'],
            'qualifying_items.category_ids' => ['sometimes', 'array'],
            'qualifying_items.category_ids.*' => ['uuid'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'tier_ids' => ['nullable', 'array'],
            'tier_ids.*' => ['uuid'],
            'is_active' => ['sometimes', 'boolean'],
            'quantity_available' => ['nullable', 'integer', 'min:0'],
            'quantity_per_member' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
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
            'name.required' => 'Reward name is required',
            'reward_type.required' => 'Reward type is required',
            'points_cost.required' => 'Points cost is required',
            'points_cost.min' => 'Points cost must be positive',
            'end_date.after' => 'End date must be after start date',
        ];
    }
}
