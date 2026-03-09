<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ClaimTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'terminal_id' => ['required', 'uuid', 'exists:pos_terminals,id'],
            'hardware_identifier' => ['required', 'string', 'max:255'],
        ];
    }
}
