<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Support\LabelLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PrepareLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.labels.print') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.LabelLimits::MAX_LABEL_ITEMS],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Enforce the aggregate label cap: Σ(quantity) must not exceed
     * MAX_LABELS_PER_REQUEST. Skipped when structural rules already failed
     * (so we never sum malformed input).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            /** @var array<int, mixed> $items */
            $items = $this->input('items', []);

            $total = 0;
            foreach ($items as $item) {
                if (is_array($item) && is_numeric($item['quantity'] ?? null)) {
                    $total += (int) $item['quantity'];
                }
            }

            if ($total > LabelLimits::MAX_LABELS_PER_REQUEST) {
                $v->errors()->add(
                    'quantity',
                    sprintf(
                        'The total number of labels (%d) exceeds the limit of %d.',
                        $total,
                        LabelLimits::MAX_LABELS_PER_REQUEST,
                    ),
                );
            }
        });
    }
}
