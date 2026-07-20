<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Treasury\Domain\Enums\StatementLineIgnoreReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IgnoreStatementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(StatementLineIgnoreReason::class)],
            'text' => ['required', 'string', 'max:1000'],
        ];
    }
}
