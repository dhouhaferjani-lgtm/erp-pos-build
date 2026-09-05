<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AddAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.attributes.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Route param is {attributeId}. The DB enforces unique(attribute_id, code)
        // (per-tenant DB, so tenant scope is implicit at the connection level).
        // Validating here turns a duplicate into a readable 422 instead of a DB
        // unique-violation 500 (DEV-QA-045).
        //
        // A FormRequest runs BEFORE the controller body, so this rule executes
        // ahead of the `Str::isUuid($attributeId)` guard in
        // AttributeController::storeValue() (AttributeController.php:68-70).
        // `attribute_id` is a `uuid` column (migration
        // 2026_06_02_100002_create_product_attribute_values_table.php:17) and
        // PostgreSQL rejects a non-UUID comparand outright (SQLSTATE 22P02), so
        // binding a malformed route param here would turn the controller's
        // documented 400 into a 500 — the exact failure class this rule exists
        // to remove. Scoping to `null` for a malformed id makes the unique rule
        // a harmless no-match and hands the case back to the controller's 400.
        $attributeId = $this->route('attributeId');
        $scopedAttributeId = is_string($attributeId) && Str::isUuid($attributeId)
            ? $attributeId
            : null;

        // Ceilings mirror the column widths (migration lines 18-19:
        // code varchar(64), label varchar(128)) so an over-long value is a 422,
        // not a `value too long for type character varying` 500.
        return [
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('product_attribute_values', 'code')
                    ->where('attribute_id', $scopedAttributeId),
            ],
            'label' => ['required', 'string', 'max:128'],
            'hex_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hex_color.regex' => 'Hex color must be a 6-digit hex value (e.g. #1A2B3C).',
            'code.unique' => 'This value code already exists for this attribute.',
        ];
    }
}
