<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class CaptureApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work-orders.approve') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approval_method' => ['required', new Enum(ApprovalMethod::class)],
            'approval_reference' => ['nullable', 'string'],
            'approval_captured_at' => ['nullable', 'date'],
            'expected_updated_at' => ['nullable', 'date'],
        ];
    }
}
