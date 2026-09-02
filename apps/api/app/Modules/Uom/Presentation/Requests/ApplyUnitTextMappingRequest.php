<?php

declare(strict_types=1);

namespace App\Modules\Uom\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyUnitTextMappingRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'source_text' => ['present', 'nullable', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'target_unit_id' => ['required', 'uuid', 'exists:units,id'],
        ];
    }
}
