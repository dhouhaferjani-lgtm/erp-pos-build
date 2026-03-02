<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Coupon\Domain\Enums\CouponType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCouponRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('coupons.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();
        $couponId = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('coupons', 'code')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($couponId),
            ],
            'type' => ['sometimes', new Enum(CouponType::class)],
            'is_single_use' => ['sometimes', 'boolean'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'discount_type' => ['sometimes', 'in:percentage,fixed'],
            'discount_value' => ['sometimes', 'numeric', 'gt:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'minimum_order_amount' => ['nullable', 'numeric', 'gte:0'],
            'qualifying_product_ids' => ['nullable', 'array'],
            'qualifying_product_ids.*' => ['string', 'uuid'],
            'qualifying_category_ids' => ['nullable', 'array'],
            'qualifying_category_ids.*' => ['string', 'uuid'],
            'is_exclusive' => ['sometimes', 'boolean'],
            'stacking_group' => ['sometimes', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }
}
