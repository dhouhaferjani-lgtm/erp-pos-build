<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateStampCardRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly RewardRepositoryInterface $rewardRepository,
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
            'reward_id' => [
                'required',
                'uuid',
            ],
            'max_active_cards' => ['nullable', 'integer', 'min:1'],
            'expiry_days' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rewardId = $this->input('reward_id');
            if (! is_string($rewardId) || $rewardId === '') {
                return;
            }

            $programId = $this->route('programId');
            if (! is_string($programId) || $programId === '') {
                $validator->errors()->add('reward_id', 'Selected reward does not exist');

                return;
            }

            $tenantId = $this->companyContext->requireCompany()->tenant_id;
            if (! $this->rewardRepository->existsForProgramInTenant($rewardId, $programId, $tenantId)) {
                $validator->errors()->add('reward_id', 'Selected reward does not exist');
            }
        });
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
