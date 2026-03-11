<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SyncMenuCategoryItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.sellable_type' => ['required', 'string', Rule::in(['product', 'composite_item'])],
            'items.*.sellable_id' => ['required', 'uuid'],
            'items.*.override_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.display_order' => ['sometimes', 'integer', 'min:0'],
            'items.*.is_available' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{sellable_type?: string, sellable_id?: string}>|null $items */
            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                $type = $item['sellable_type'] ?? null;
                $id = $item['sellable_id'] ?? null;

                if ($type === null || $id === null) {
                    continue;
                }

                $table = $type === 'product' ? 'products' : 'composite_items';

                if (! \Illuminate\Support\Facades\DB::table($table)->where('id', $id)->exists()) {
                    $validator->errors()->add("items.{$index}.sellable_id", "The selected {$type} does not exist.");
                }
            }
        });
    }
}
