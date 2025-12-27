<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
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
        /** @var User|null $authenticatedUser */
        $authenticatedUser = $this->user();

        /** @var User $user */
        $user = $authenticatedUser;
        $tenantId = $user->tenant_id;

        return [
            'partner_id' => [
                'sometimes',
                'uuid',
                Rule::exists('partners', 'id')->where('tenant_id', $tenantId),
            ],
            'vehicle_context' => ['nullable', 'array'],
            'vehicle_context.vehicle_id' => ['required_with:vehicle_context', 'uuid'],
            'vehicle_context.snapshot' => ['nullable', 'array'],
            'vehicle_context.snapshot.license_plate' => ['nullable', 'string', 'max:50'],
            'vehicle_context.snapshot.brand' => ['nullable', 'string', 'max:100'],
            'vehicle_context.snapshot.model' => ['nullable', 'string', 'max:100'],
            'vehicle_context.snapshot.year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'vehicle_context.mileage' => ['nullable', 'integer', 'min:0'],
            'vehicle_context.additional_data' => ['nullable', 'array'],
            'document_date' => ['sometimes', 'date'],
            'issue_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => [
                'nullable',
                'uuid',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.description' => ['required_with:lines', 'string', 'max:1000'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
