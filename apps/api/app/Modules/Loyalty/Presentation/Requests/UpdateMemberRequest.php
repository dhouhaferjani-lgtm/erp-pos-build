<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
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
        $user = $this->user();
        $tenantId = $user?->tenant_id;
        $memberId = $this->route('id');

        return [
            'phone' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('loyalty_members', 'phone')
                    ->where('tenant_id', $tenantId)
                    ->ignore($memberId)
                    ->whereNull('deleted_at'),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'customer_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
