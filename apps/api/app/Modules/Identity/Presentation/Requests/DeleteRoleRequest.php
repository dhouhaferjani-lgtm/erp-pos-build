<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Deleting a role is a privileged, destructive mutation and must be
     * gated by `roles.manage` — the same gate as StoreRoleRequest /
     * UpdateRoleRequest (go-live audit Finding #1 / TD-015). Without this
     * gate any authenticated tenant member could delete a non-system,
     * user-less role.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
