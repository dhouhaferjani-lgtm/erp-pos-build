<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReceiptSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'receipt_header' => ['nullable', 'string', 'max:500'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'receipt_thank_you' => ['nullable', 'string', 'max:200'],
            'receipt_show_vat_breakdown' => ['sometimes', 'boolean'],
            'receipt_show_fiscal_info' => ['sometimes', 'boolean'],
            'receipt_show_payment_details' => ['sometimes', 'boolean'],
            'receipt_show_customer' => ['sometimes', 'boolean'],
            'auto_print_receipts' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipt_header.max' => 'Receipt header must not exceed 500 characters.',
            'receipt_footer.max' => 'Receipt footer must not exceed 500 characters.',
            'receipt_thank_you.max' => 'Thank-you message must not exceed 200 characters.',
        ];
    }
}
