<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from_repository_id' => ['required', 'uuid'],
            'to_repository_id' => ['required', 'uuid', 'different:from_repository_id'],
            'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'transfer_group_id' => ['nullable', 'uuid'],
        ];
    }
}
