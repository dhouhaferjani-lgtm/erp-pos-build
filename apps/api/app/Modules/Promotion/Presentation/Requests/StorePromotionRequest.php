<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Presentation\Requests;

use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('promotions.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', new Enum(PromotionType::class)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_exclusive' => ['sometimes', 'boolean'],
            'stacking_group' => ['sometimes', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:1', 'max:7'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_until' => ['nullable', 'date_format:H:i'],
            'conditions' => ['sometimes', 'array'],
            'conditions.qualifying_product_ids' => ['sometimes', 'array'],
            'conditions.qualifying_product_ids.*' => ['string', 'uuid'],
            'conditions.category_ids' => ['sometimes', 'array'],
            'conditions.category_ids.*' => ['string', 'uuid'],
            'conditions.combo_product_ids' => ['sometimes', 'array'],
            'conditions.combo_product_ids.*' => ['string', 'uuid'],
            'conditions.reward_product_ids' => ['sometimes', 'array'],
            'conditions.reward_product_ids.*' => ['string', 'uuid'],
            'conditions.reward_qty' => ['sometimes', 'integer', 'min:1'],
            'conditions.trigger_qty' => ['sometimes', 'integer', 'min:1'],
            'conditions.min_qty' => ['sometimes', 'integer', 'min:1'],
            'conditions.min_amount' => ['sometimes', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'discount_type' => ['required', new Enum(DiscountType::class)],
            'discount_value' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'applies_to' => ['required', new Enum(DiscountAppliesTo::class)],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'conditions.min_amount.regex' => 'Min amount must not exceed 3 decimal places.',
            'discount_value.regex' => 'Discount value must not exceed 4 decimal places.',
            'max_discount_amount.regex' => 'Max discount amount must not exceed 3 decimal places.',
        ];
    }
}
