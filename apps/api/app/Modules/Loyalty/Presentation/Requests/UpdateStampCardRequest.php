<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStampCardRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Route is PATCH /loyalty/stamp-cards/{id} — no programId in URL.
        // The reward_id must belong to a program owned by the current tenant.
        // loyalty_rewards has no tenant_id column, so scope via the parent
        // FK: reward.program_id IN (tenant's program ids). Closure-based
        // exists rule because ScopedExists handles only direct column predicates.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'stamps_required' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'stamps_per_item' => ['sometimes', 'integer', 'min:1'],
            'qualifying_items' => ['sometimes', 'array'],
            'qualifying_items.item_types' => ['sometimes', 'array'],
            'qualifying_items.item_types.*' => ['string'],
            'qualifying_items.item_ids' => ['sometimes', 'array'],
            'qualifying_items.item_ids.*' => ['uuid'],
            'qualifying_items.category_ids' => ['sometimes', 'array'],
            'qualifying_items.category_ids.*' => ['uuid'],
            'reward_id' => [
                'sometimes',
                'uuid',
                Rule::exists('loyalty_rewards', 'id')->where(
                    fn (Builder $query) => $query->whereIn(
                        'program_id',
                        fn (Builder $sub) => $sub
                            ->select('id')
                            ->from('loyalty_programs')
                            ->where('tenant_id', $tenantId),
                    ),
                ),
            ],
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
            'stamps_required.min' => 'At least 1 stamp is required',
            'stamps_required.max' => 'Maximum 50 stamps allowed',
            'reward_id.exists' => 'Selected reward does not exist',
        ];
    }
}
