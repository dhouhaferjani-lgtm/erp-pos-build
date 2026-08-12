<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use App\Models\Enums\SuperAdminRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDefaultsEditorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\Rule|\Illuminate\Contracts\Validation\ValidationRule>> */
    public function rules(): array
    {
        return [
            'editor_id' => ['required', 'uuid'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in([
                SuperAdminRole::DefaultsEditor->value,
                SuperAdminRole::SupportApprover->value,
            ])],
        ];
    }
}
