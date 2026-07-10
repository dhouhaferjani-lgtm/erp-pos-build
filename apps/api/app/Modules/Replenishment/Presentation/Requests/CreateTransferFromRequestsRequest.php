<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateTransferFromRequestsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'source_location_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.request_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }
}
