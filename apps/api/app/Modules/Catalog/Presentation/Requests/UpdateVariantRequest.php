<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial update of a product variant. cost_override stays advisory
 * (spec §6.7) — accepting it here never feeds the inventory WAC pipeline.
 */
class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.variants.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variant_code' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'string', 'max:100'],
            'name_suffix' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_variants', 'barcode')->where(
                    fn ($q) => $q->whereNull('deleted_at')->where('tenant_id', $this->tenantId())
                )->ignore($this->route('id')),
            ],
            'price_override' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'cost_override' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * Tenant id of the authenticated catalog user. authorize() guarantees a
     * User with catalog.variants.update, so the cast is safe here.
     */
    private function tenantId(): string
    {
        /** @var User $user */
        $user = $this->user();

        return $user->tenant_id;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_override.regex' => 'Price override must have at most 4 decimal places.',
            'cost_override.regex' => 'Cost override must have at most 4 decimal places.',
        ];
    }
}
