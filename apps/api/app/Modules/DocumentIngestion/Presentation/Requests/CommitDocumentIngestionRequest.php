<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Presentation\Requests;

use App\Modules\DocumentIngestion\Application\DTO\ReviewedPayloadData;
use Illuminate\Foundation\Http\FormRequest;

final class CommitDocumentIngestionRequest extends FormRequest
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
            'supplierId' => ['required', 'uuid'],
            'locationId' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:255'],
            'documentDate' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'pendingReceipt' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.productId' => ['required', 'uuid'],
            'lines.*.variantId' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.freeQuantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.unitPrice' => ['nullable', 'numeric', 'min:0', 'regex:/^-?\d+(\.\d{1,3})?$/'],
            'lines.*.vatRate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'lines.*.sourceLineId' => ['nullable', 'uuid'],
            'lines.*.batch' => ['nullable', 'array'],
            'lines.*.batch.batchNumber' => ['required_with:lines.*.batch', 'string', 'max:100'],
            'lines.*.batch.expiryDate' => ['required_with:lines.*.batch', 'date_format:Y-m-d'],
            'lines.*.batch.manufacturingDate' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function payload(): ReviewedPayloadData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return ReviewedPayloadData::fromArray($validated);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.quantity.regex' => 'Quantity may have at most 4 decimal places.',
            'lines.*.freeQuantity.regex' => 'Free quantity may have at most 4 decimal places.',
            'lines.*.unitPrice.regex' => 'Unit price may have at most 3 decimal places.',
            'lines.*.vatRate.regex' => 'VAT rate may have at most 2 decimal places.',
        ];
    }
}
