<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for recording a back-office customer-account deposit.
 *
 * Authorization is handled by the `can:payments.create` route middleware. The
 * amount is a plain non-negative decimal string (no float, no scientific
 * notation); the authoring service rejects any precision beyond the currency
 * scale. `payment_method_code` matches the canonical payload's `method_code`
 * (the Treasury bridge resolves the tenant-scoped PaymentMethod by code).
 */
final class RecordDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^(0|[1-9]\d*)(\.\d+)?$/'],
            'payment_method_code' => ['required', 'string', 'max:64'],
            'repository_id' => ['required', 'uuid'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
