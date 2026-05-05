<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Request validation for creating a POS receipt.
 *
 * Validates terminal, line items, and optional customer.
 */
final class StoreReceiptRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

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
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;

        return [
            // api.pos-stabilization.010 — pos_terminals (T+C).
            'terminal_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('pos_terminals', $tenantId, $companyId),
            ],
            'lines' => ['required', 'array', 'min:1'],
            // api.pos-stabilization.011 — products (T+C).
            'lines.*.product_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
                'required_without:lines.*.composite_item_id',
            ],
            // Round-2 Opus Finding 2 — composite_items HAS both tenant_id
            // and company_id (per migration 2026_02_19_100001). Same scope
            // as the sibling product_id field above.
            'lines.*.composite_item_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('composite_items', $tenantId, $companyId),
                'required_without:lines.*.product_id',
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.modifiers' => ['nullable', 'array'],
            // api.pos-stabilization.012 — modifiers has no tenant_id/company_id of
            // its own; scope by parent modifier_groups via subquery callback.
            'lines.*.modifiers.*.modifier_id' => [
                'required', 'uuid',
                Rule::exists('modifiers', 'id')->where(
                    function ($query) use ($tenantId, $companyId) {
                        $query->whereIn(
                            'modifier_group_id',
                            DB::table('modifier_groups')
                                ->select('id')
                                ->where('tenant_id', $tenantId)
                                ->where('company_id', $companyId)
                        );
                    }
                ),
            ],
            // api.pos-stabilization.013 — modifier_groups (T+C).
            'lines.*.modifiers.*.modifier_group_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('modifier_groups', $tenantId, $companyId),
            ],
            'lines.*.modifiers.*.price_adjustment' => ['required', 'numeric'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.discount_type' => ['nullable', 'string', 'in:percentage,fixed'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'lines.*.discount_reason' => ['nullable', 'string', 'max:255'],
            // api.pos-stabilization.014 — partners (T+C).
            'customer_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            // api.pos-stabilization.015 — contacts (T+C).
            'contact_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('contacts', $tenantId, $companyId),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'transaction_discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'transaction_discount_reason' => ['nullable', 'string', 'max:255'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'loyalty_discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'loyalty_reward_id' => ['nullable', 'uuid'],
            'consumption_mode' => ['nullable', 'string', Rule::enum(ConsumptionMode::class)],
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
            'terminal_id.required' => 'Terminal ID is required',
            'terminal_id.exists' => 'Terminal does not exist',
            'lines.required' => 'At least one line item is required',
            'lines.min' => 'At least one line item is required',
            'lines.*.product_id.required_without' => 'Either product_id or composite_item_id is required for each line',
            'lines.*.product_id.exists' => 'Product does not exist',
            'lines.*.composite_item_id.required_without' => 'Either product_id or composite_item_id is required for each line',
            'lines.*.composite_item_id.exists' => 'Composite item does not exist',
            'lines.*.quantity.required' => 'Quantity is required for each line',
            'lines.*.quantity.gt' => 'Quantity must be greater than zero',
            'lines.*.unit_price.required' => 'Unit price is required for each line',
            'lines.*.unit_price.gte' => 'Unit price must be zero or greater',
            'lines.*.discount_type.in' => 'Discount type must be "percentage" or "fixed"',
            'lines.*.discount_percent.lte' => 'Discount percentage cannot exceed 100%',
            'customer_id.exists' => 'Customer does not exist',
        ];
    }
}
