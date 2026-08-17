<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\POS\Domain\Enums\FiscalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class IndexReceiptsRequest extends FormRequest
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
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
            'terminal_id' => ['sometimes', 'nullable', 'uuid'],
            'cashier_id' => ['sometimes', 'nullable', 'uuid'],
            'invoice_type_codes' => ['sometimes', 'array', 'min:1'],
            'invoice_type_codes.*' => ['string', Rule::in(['SALE', 'TRAINING', 'REFUND', 'VOID'])],
            'fiscal_status' => ['sometimes', Rule::enum(FiscalStatus::class)],
            'include_training' => ['sometimes', Rule::in([true, false, 1, 0, 'true', 'false', '1', '0'])],
            'receipt_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'receipt_type' => ['sometimes', 'nullable', Rule::in(['sale', 'return'])],
            'customer_id' => ['sometimes', 'nullable', 'uuid'],
            'contact_id' => ['sometimes', 'nullable', 'uuid'],
            'is_voided' => ['sometimes', 'boolean'],
            'from_date' => ['sometimes', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $codes = $this->input('invoice_type_codes', []);
            if (! is_array($codes) || ! in_array('TRAINING', $codes, true)) {
                return;
            }

            if (! $this->boolean('include_training')) {
                $validator->errors()->add(
                    'invoice_type_codes',
                    '`invoice_type_codes` may include TRAINING only when `include_training=true`',
                );
            }
        });
    }
}
