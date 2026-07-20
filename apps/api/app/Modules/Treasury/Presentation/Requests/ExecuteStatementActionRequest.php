<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Treasury\Domain\Enums\MatchActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExecuteStatementActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(MatchActionType::class)],
            'params' => ['sometimes', 'array'],
        ];
    }
}
