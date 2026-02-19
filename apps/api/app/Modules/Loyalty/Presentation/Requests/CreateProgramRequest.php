<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'program_type' => ['required', new Enum(ProgramType::class)],
            'status' => ['nullable', new Enum(ProgramStatus::class)],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['required', 'uuid', 'exists:companies,id'],
            'currency' => ['nullable', 'string', 'size:3'], // ISO 4217 currency code
            'points_expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'terms_and_conditions' => ['nullable', 'string', 'max:10000'],
            'welcome_bonus_points' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
