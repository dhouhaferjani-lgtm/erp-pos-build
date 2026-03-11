<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'sellable_type' => ['required', 'string', Rule::in(['product', 'composite_item'])],
            'sellable_id' => ['required', 'uuid'],
            'override_price' => ['nullable', 'numeric', 'min:0'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var string|null $type */
            $type = $this->input('sellable_type');
            /** @var string|null $id */
            $id = $this->input('sellable_id');

            if ($type === null || $id === null) {
                return;
            }

            $table = $type === 'product' ? 'products' : 'composite_items';

            if (! \Illuminate\Support\Facades\DB::table($table)->where('id', $id)->exists()) {
                $validator->errors()->add('sellable_id', "The selected {$type} does not exist.");
            }
        });
    }
}
