<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesMarginBand
{
    protected function marginBandInverts(?string $min, ?string $max): bool
    {
        if ($min === null || $max === null || $min === '' || $max === '') {
            return false;
        }
        if (! is_numeric($min) || ! is_numeric($max)) {
            return false;
        }

        return bccomp($min, $max, 2) > 0;
    }

    /** Call from a FormRequest withValidator() to attach the cross-field rule. */
    protected function attachMarginBandRule(Validator $validator, string $minField, string $maxField): void
    {
        $validator->after(function ($v) use ($minField, $maxField) {
            $data = $v->getData();
            if ($this->marginBandInverts($data[$minField] ?? null, $data[$maxField] ?? null)) {
                $v->errors()->add($minField, "The {$minField} must be less than or equal to {$maxField}.");
            }
        });
    }
}
