<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Requests;

use App\Modules\Procurement\Domain\Enums\ProcurementPreset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProcurementPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preset' => ['sometimes', 'required', Rule::enum(ProcurementPreset::class)],
            'bill_control_mode' => ['required_without:preset', Rule::in(['received'])],
            'match_mode' => ['required_without:preset', Rule::in(['two_way', 'three_way'])],
            'match_enforcement' => ['required_without:preset', Rule::in(['warn', 'block'])],
            'variance_tolerance_percent' => [
                'required_without:preset',
                'numeric',
                'min:0',
                'max:999.99',
                'decimal:0,2',
            ],
            'variance_tolerance_max_amount' => [
                'required_without:preset',
                'numeric',
                'min:0',
                'max:999999999999.999',
                'decimal:0,3',
            ],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->all() === []) {
                    $validator->errors()->add('preset', 'A preset or raw policy fields are required.');
                }

                if ($this->has('preset')) {
                    foreach ([
                        'bill_control_mode',
                        'match_mode',
                        'match_enforcement',
                        'variance_tolerance_percent',
                        'variance_tolerance_max_amount',
                    ] as $rawField) {
                        if ($this->has($rawField)) {
                            $validator->errors()->add('preset', 'Preset updates cannot be mixed with raw policy fields.');

                            return;
                        }
                    }
                }
            },
        ];
    }
}
