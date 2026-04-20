<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransferVehicleOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicles.manage_ownership') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $tenantId = $user->tenant_id;

        return [
            'new_owner_partner_id' => [
                'required',
                'uuid',
                Rule::exists('partners', 'id')->where('tenant_id', $tenantId),
            ],
            'occurred_at' => ['required', 'date'],
            'reason_code' => ['required', Rule::enum(OwnershipReason::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
