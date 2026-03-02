<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddMenuCategoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('menus.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'composite_item_id' => ['required', 'uuid', 'exists:composite_items,id'],
            'override_price' => ['nullable', 'numeric', 'min:0'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }
}
