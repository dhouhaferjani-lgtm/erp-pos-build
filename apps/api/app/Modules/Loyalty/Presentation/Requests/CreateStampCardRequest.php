<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateStampCardRequest extends FormRequest
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
            'stamps_required' => ['required', 'integer', 'min:1', 'max:50'],
            'stamps_per_item' => ['required', 'integer', 'min:1'],
            'qualifying_items' => ['required', 'array'],
            'qualifying_items.item_types' => ['sometimes', 'array'],
            'qualifying_items.item_types.*' => ['string'],
            'qualifying_items.item_ids' => ['sometimes', 'array'],
            'qualifying_items.item_ids.*' => ['uuid'],
            'qualifying_items.category_ids' => ['sometimes', 'array'],
            'qualifying_items.category_ids.*' => ['uuid'],
            'reward_id' => ['required', 'uuid', 'exists:loyalty_rewards,id'],
            'max_active_cards' => ['nullable', 'integer', 'min:1'],
            'expiry_days' => ['nullable', 'integer', 'min:1'],
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
            'name.required' => 'Stamp card name is required',
            'stamps_required.required' => 'Number of stamps required is required',
            'stamps_required.min' => 'At least 1 stamp is required',
            'stamps_required.max' => 'Maximum 50 stamps allowed',
            'stamps_per_item.required' => 'Stamps per item is required',
            'qualifying_items.required' => 'Qualifying items are required',
            'reward_id.required' => 'Reward is required',
            'reward_id.exists' => 'Selected reward does not exist',
        ];
    }
}
