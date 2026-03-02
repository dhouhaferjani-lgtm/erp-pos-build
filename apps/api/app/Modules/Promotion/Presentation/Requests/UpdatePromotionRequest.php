<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Presentation\Requests;

use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdatePromotionRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['sometimes', new Enum(PromotionType::class)],
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
            'conditions.trigger_qty' => ['sometimes', 'integer', 'min:1'],
            'conditions.min_qty' => ['sometimes', 'integer', 'min:1'],
            'conditions.min_amount' => ['sometimes', 'numeric', 'gte:0'],
            'discount_type' => ['sometimes', new Enum(DiscountType::class)],
            'discount_value' => ['sometimes', 'numeric', 'gt:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'applies_to' => ['sometimes', new Enum(DiscountAppliesTo::class)],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
