<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Support\LabelLimits;
use App\Modules\Catalog\Domain\Support\LabelSheetFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateLabelPdfRequest extends FormRequest
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
            'format' => ['required', Rule::in(LabelSheetFormat::keys())],
            'items' => ['required', 'array', 'min:1', 'max:'.LabelLimits::MAX_LABEL_ITEMS],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'start_cell' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Aggregate guards (run only when structural rules already passed):
     *   - Σ(quantity) must not exceed MAX_LABELS_PER_REQUEST.
     *   - start_cell must fall inside the chosen sheet (< rows*cols).
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

            $format = LabelSheetFormat::find((string) $this->input('format'));
            if ($format !== null) {
                $startCell = (int) $this->input('start_cell', 0);
                $capacity = $format->rows * $format->cols;

                if ($startCell >= $capacity) {
                    $v->errors()->add(
                        'start_cell',
                        sprintf(
                            'The start cell (%d) must be less than the sheet capacity (%d).',
                            $startCell,
                            $capacity,
                        ),
                    );
                }
            }
        });
    }
}
