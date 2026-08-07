<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestElevationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
