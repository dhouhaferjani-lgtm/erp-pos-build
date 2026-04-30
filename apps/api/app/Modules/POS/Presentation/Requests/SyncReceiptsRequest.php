<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for batch receipt sync.
 *
 * Accepts either a single receipt object or an array of receipts
 * under the `receipts` key. The frontend currently sends one at a time
 * but the endpoint supports batching.
 */
final class SyncReceiptsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Prepare the data for validation.
     *
     * Normalizes single-receipt payloads into the batch format.
     */
    protected function prepareForValidation(): void
    {
        // If the payload has 'idempotency_key' at root, it's a single receipt — wrap it
        if ($this->has('idempotency_key') && ! $this->has('receipts')) {
            $singleReceipt = $this->all();
            $this->replace([
                'receipts' => [$singleReceipt],
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'receipts' => ['required', 'array', 'min:1', 'max:100'],
            'receipts.*.idempotency_key' => ['required', 'string', 'max:255'],
            'receipts.*.receipt_number' => ['required', 'string', 'max:100'],
            'receipts.*.terminal_id' => ['required', 'uuid'],
            'receipts.*.operator_id' => ['required', 'uuid'],
            'receipts.*.lines' => ['required', 'array', 'min:1'],
            'receipts.*.lines.*.product_id' => ['nullable', 'uuid'],
            'receipts.*.lines.*.composite_item_id' => ['nullable', 'uuid'],
            'receipts.*.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'receipts.*.lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'receipts.*.lines.*.modifiers' => ['nullable', 'array'],
            'receipts.*.lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'receipts.*.lines.*.discount_type' => ['nullable', 'string', 'in:percentage,fixed'],
            'receipts.*.lines.*.discount_percent' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'receipts.*.lines.*.discount_reason' => ['nullable', 'string', 'max:255'],
            'receipts.*.subtotal' => ['required', 'numeric'],
            'receipts.*.tax_amount' => ['required', 'numeric'],
            'receipts.*.discount_amount' => ['required', 'numeric'],
            'receipts.*.total' => ['required', 'numeric'],
            'receipts.*.currency' => ['required', 'string', 'size:3'],
            'receipts.*.offline_fiscal_hash' => ['required', 'string', 'size:64'],
            'receipts.*.previous_hash' => ['present', 'nullable', 'string'],
            'receipts.*.hash_sequence' => ['required', 'integer', 'min:0'],
            'receipts.*.transaction_discount_amount' => ['nullable', 'numeric'],
            'receipts.*.transaction_discount_reason' => ['nullable', 'string', 'max:255'],
            'receipts.*.tendered_amount' => ['nullable', 'numeric'],
            'receipts.*.change_due' => ['nullable', 'numeric'],
            'receipts.*.payment_method_id' => ['required', 'uuid'],
            'receipts.*.payment_repository_id' => ['required', 'uuid'],
            'receipts.*.created_at' => ['required', 'date'],
            'receipts.*.payments' => ['required', 'array', 'min:1', 'max:5'],
            'receipts.*.payments.*.payment_method_id' => ['required', 'uuid'],
            'receipts.*.payments.*.repository_id' => ['required', 'uuid'],
            'receipts.*.payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'receipts.*.payments.*.card_last_four' => ['nullable', 'string', 'size:4'],
            'receipts.*.payments.*.transaction_reference' => ['nullable', 'string', 'max:100'],
            'receipts.*.consumption_mode' => ['nullable', 'string', 'in:SUR_PLACE,A_EMPORTER'],
            'receipts.*.table_id' => ['nullable', 'uuid'],
            // Codex review B1 (2026-04-30): clients MUST declare the version every
            // payload was sealed under. The default-to-2 fallback was removed so a
            // missing field surfaces as 422 here rather than silently downgrading
            // a v3 payload (which the server would later reject as a chain break).
            'receipts.*.fiscal_schema_version' => ['required', 'integer', 'in:2,3'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipts.required' => 'At least one receipt is required',
            'receipts.max' => 'Maximum 100 receipts per batch',
            'receipts.*.idempotency_key.required' => 'Each receipt must have an idempotency key',
            'receipts.*.terminal_id.required' => 'Terminal ID is required for each receipt',
            'receipts.*.lines.required' => 'Each receipt must have at least one line item',
            'receipts.*.offline_fiscal_hash.size' => 'Offline fiscal hash must be a 64-character SHA-256 hex string',
            'receipts.*.payments.required' => 'At least one payment entry is required per receipt',
            'receipts.*.fiscal_schema_version.required' => 'fiscal_schema_version is required (declare 2 or 3)',
            'receipts.*.fiscal_schema_version.in' => 'fiscal_schema_version must be 2 or 3',
        ];
    }
}
