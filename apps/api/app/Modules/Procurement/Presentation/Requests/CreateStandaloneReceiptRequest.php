<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateStandaloneReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'external_date' => ['nullable', 'date_format:Y-m-d'],
            'post_immediately' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.qty' => ['required', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'lines.*.free_qty' => ['nullable', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'lines.*.unit_price' => ['required', 'regex:/^-?\d+(\.\d{1,3})?$/'],
            'lines.*.batch' => ['nullable', 'array'],
            'lines.*.batch.batch_number' => ['required_with:lines.*.batch', 'string', 'max:100'],
            'lines.*.batch.expiry_date' => ['required_with:lines.*.batch', 'date_format:Y-m-d'],
            'lines.*.batch.manufacturing_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
