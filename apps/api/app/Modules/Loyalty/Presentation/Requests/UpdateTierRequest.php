<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Loyalty\Domain\Enums\QualificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTierRequest extends FormRequest
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
            'level' => ['sometimes', 'integer', 'min:1'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'qualification_type' => ['sometimes', Rule::enum(QualificationType::class)],
            'qualification_threshold' => ['sometimes', 'numeric', 'min:0'],
            'qualification_period_months' => ['nullable', 'integer', 'min:1'],
            'earning_multiplier' => ['sometimes', 'numeric', 'min:1'],
            'benefits' => ['nullable', 'array'],
            'benefits.exclusive_rewards' => ['sometimes', 'array'],
            'benefits.exclusive_rewards.*' => ['uuid'],
            'benefits.free_shipping' => ['sometimes', 'boolean'],
            'benefits.priority_support' => ['sometimes', 'boolean'],
            'benefits.birthday_bonus_multiplier' => ['sometimes', 'numeric', 'min:1'],
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
            'level.min' => 'Tier level must be at least 1',
            'earning_multiplier.min' => 'Earning multiplier must be at least 1',
        ];
    }
}
