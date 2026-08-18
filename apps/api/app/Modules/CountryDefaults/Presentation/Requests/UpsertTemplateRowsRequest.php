<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertTemplateRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['id' => $this->route('id')]);
    }

    /** @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\Rule|ValidationRule>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'rows' => ['required', 'array', 'max:5000'],
            'rows.*.id' => ['sometimes', 'uuid', 'distinct'],
            'rows.*.code' => ['required', 'string', 'max:50', 'distinct'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.type' => ['required', Rule::enum(AccountType::class)],
            'rows.*.parent_code' => ['nullable', 'string', 'max:50'],
            'rows.*.system_purpose' => ['nullable', Rule::enum(SystemAccountPurpose::class), 'distinct'],
            'rows.*.is_system' => ['required', 'boolean'],
            'rows.*.sort_order' => ['required', 'integer', 'min:0', 'distinct'],
        ];
    }
}
