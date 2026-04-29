<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Requests;

use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueGoodwillRequest extends FormRequest
{
    /**
     * Authorization handled by route middleware (can:pos.issue_goodwill_voucher).
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
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d+)?$/'],
            'currency' => ['required', 'string', 'size:3'],
            'partner_id' => ['nullable', 'uuid'],
            'redemption_mode' => ['required', Rule::enum(RedemptionMode::class)],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'terminal_id' => ['nullable', 'uuid'],
            'second_admin_user_id' => ['nullable', 'uuid'],
        ];
    }
}
