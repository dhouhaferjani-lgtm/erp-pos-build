<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMenuCategoryRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:100'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
