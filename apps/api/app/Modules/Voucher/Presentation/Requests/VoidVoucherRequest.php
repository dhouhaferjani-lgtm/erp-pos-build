<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidVoucherRequest extends FormRequest
{
    /**
     * Authorization handled by route middleware (can:pos.void_voucher).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
