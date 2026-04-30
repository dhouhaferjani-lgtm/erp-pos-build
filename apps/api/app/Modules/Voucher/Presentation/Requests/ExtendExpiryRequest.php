<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtendExpiryRequest extends FormRequest
{
    /**
     * Authorization handled by route middleware (can:pos.extend_voucher_expiry).
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
            'new_expires_at' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
