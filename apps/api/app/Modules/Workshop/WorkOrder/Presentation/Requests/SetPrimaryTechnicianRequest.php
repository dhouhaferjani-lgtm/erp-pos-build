<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetPrimaryTechnicianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work-orders.assign') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'technician_profile_id' => ['required', 'uuid'],
        ];
    }
}
