<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMemberRequest extends FormRequest
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
        /** @var \App\Modules\Identity\Domain\User|null $user */
        $user = $this->user();
        $tenantId = $user?->tenant_id;

        return [
            'phone' => [
                'required',
                'string',
                'max:20',
                Rule::unique('loyalty_members', 'phone')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'customer_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'loyaltyable_type' => ['nullable', 'string', 'in:contact,partner'],
            'loyaltyable_id' => ['nullable', 'uuid'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
