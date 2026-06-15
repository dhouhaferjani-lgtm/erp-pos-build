<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Support\VariantMatrixLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.variants.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // New axes shape (preferred).
            'axes' => ['nullable', 'array', 'min:1'],
            'axes.*.attribute_id' => ['required_with:axes', 'uuid'],
            'axes.*.value_ids' => ['required_with:axes', 'array', 'min:1'],
            'axes.*.value_ids.*' => ['uuid'],

            // Legacy shape — still accepted.
            'attribute_ids' => ['nullable', 'array', 'min:1'],
            'attribute_ids.*' => ['uuid'],
        ];
    }

    /**
     * Add post-rule cross-field validation:
     *   (a) exactly one of axes / attribute_ids must be present.
     *   (b) every value_id in an axis must belong to that axis's attribute.
     *   (c) the gross Cartesian product must not exceed the cap.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Skip further validation if structural rules already failed.
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $hasAxes = $this->has('axes') && is_array($this->input('axes'));
            $hasLegacy = $this->has('attribute_ids') && is_array($this->input('attribute_ids'));

            if (! $hasAxes && ! $hasLegacy) {
                $v->errors()->add('axes', 'Either axes or attribute_ids is required.');

                return;
            }

            // Only the new axes shape requires deeper validation.
            if (! $hasAxes) {
                return;
            }

            /** @var array<int, mixed> $axes */
            $axes = $this->input('axes', []);

            $grossCounts = [];

            foreach ($axes as $i => $axis) {
                if (! is_array($axis)) {
                    continue;
                }

                $attributeId = is_string($axis['attribute_id'] ?? null) ? $axis['attribute_id'] : null;
                $rawValueIds = $axis['value_ids'] ?? null;
                $valueIds = is_array($rawValueIds)
                    ? array_values(array_unique(array_filter($rawValueIds, 'is_string')))
                    : [];

                if ($attributeId === null || $valueIds === []) {
                    continue;
                }

                // (b) Ownership check: all value_ids must belong to the attribute.
                $found = ProductAttributeValue::query()
                    ->where('attribute_id', $attributeId)
                    ->whereIn('id', $valueIds)
                    ->count();

                if ($found !== count($valueIds)) {
                    $v->errors()->add(
                        "axes.{$i}.value_ids",
                        'One or more value IDs do not belong to the specified attribute.',
                    );
                }

                $grossCounts[] = count($valueIds);
            }

            // (c) Gross cap check.
            if ($grossCounts !== []) {
                $gross = (int) array_product($grossCounts);
                if ($gross > VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE) {
                    $v->errors()->add(
                        'combinations',
                        sprintf(
                            'The requested matrix (%d combinations) exceeds the limit of %d.',
                            $gross,
                            VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE,
                        ),
                    );
                }
            }
        });
    }
}
