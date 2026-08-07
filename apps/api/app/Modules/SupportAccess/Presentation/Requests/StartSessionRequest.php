<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'grant_id' => ['required', 'uuid'],
            'subject_user_id' => ['required', 'uuid'],
        ];
    }
}
