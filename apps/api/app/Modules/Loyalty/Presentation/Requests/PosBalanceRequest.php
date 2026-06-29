<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PosBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (auth:sanctum + module:Loyalty + can:pos.operate_terminal) gates access
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'partner_id' => ['nullable', 'uuid', 'required_without:contact_id'],
            'contact_id' => ['nullable', 'uuid', 'required_without:partner_id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
