<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for adding a product to a draft counting operation (barcode scan).
 *
 * Used when manager scans a barcode to incrementally build the product list.
 */
class AddProductToCountingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization checked in controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'barcode' => ['required_without:product_id', 'string', 'max:255'],
            'product_id' => ['required_without:barcode', 'string', 'exists:products,id'],
            'location_id' => ['nullable', 'string', 'exists:locations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'barcode.required_without' => 'Either barcode or product_id is required.',
            'product_id.required_without' => 'Either barcode or product_id is required.',
            'product_id.exists' => 'The specified product does not exist.',
            'location_id.exists' => 'The specified location does not exist.',
        ];
    }
}
