<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Loyalty\Domain\Enums\QualificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTierRequest extends FormRequest
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
            'level' => ['required', 'integer', 'min:1'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'qualification_type' => ['required', Rule::enum(QualificationType::class)],
            'qualification_threshold' => ['required', 'numeric', 'min:0'],
            'qualification_period_months' => ['nullable', 'integer', 'min:1'],
            'earning_multiplier' => ['required', 'numeric', 'min:1'],
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
            'name.required' => 'Tier name is required',
            'level.required' => 'Tier level is required',
            'level.min' => 'Tier level must be at least 1',
            'qualification_type.required' => 'Qualification type is required',
            'qualification_threshold.required' => 'Qualification threshold is required',
            'earning_multiplier.required' => 'Earning multiplier is required',
            'earning_multiplier.min' => 'Earning multiplier must be at least 1',
        ];
    }
}
