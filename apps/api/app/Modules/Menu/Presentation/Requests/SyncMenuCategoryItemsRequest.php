<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncMenuCategoryItemsRequest extends FormRequest
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
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.composite_item_id' => ['required', 'uuid', 'exists:composite_items,id'],
            'items.*.override_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.display_order' => ['sometimes', 'integer', 'min:0'],
            'items.*.is_available' => ['sometimes', 'boolean'],
        ];
    }
}
