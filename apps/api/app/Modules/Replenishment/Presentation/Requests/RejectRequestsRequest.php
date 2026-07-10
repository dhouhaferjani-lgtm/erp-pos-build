<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectRequestsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
