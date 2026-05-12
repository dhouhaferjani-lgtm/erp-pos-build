<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\Validator;
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
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

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
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;

        return [
            'receipts' => ['required', 'array', 'min:1', 'max:100'],
            'receipts.*.idempotency_key' => ['required', 'string', 'max:255'],
            'receipts.*.receipt_number' => ['required', 'string', 'max:100'],
            'receipts.*.terminal_id' => ['required', 'uuid'],
            'receipts.*.operator_id' => ['required', 'uuid'],
            'receipts.*.lines' => ['required', 'array', 'min:1'],
            // Round-3 Codex Finding 1 — without scoped exists, a tenant-A
            // sync receipt could persist tenant-B sellable FKs into
            // pos_receipt_lines. Reject at the validator + null-fallback
            // service-tier persist (defense-in-depth).
            'receipts.*.lines.*.product_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
            ],
            'receipts.*.lines.*.composite_item_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('composite_items', $tenantId, $companyId),
            ],
            // C2 Day 3 — Menu-tenant category context. Optional UUID; non-
            // Menu tenants and pre-C2 historical sync payloads omit it.
            'receipts.*.lines.*.menu_category_id' => ['nullable', 'uuid'],
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
            // Codex review B3 (2026-04-30): the offline POS sealed the v3 fiscal
            // hash with these fields populated. They MUST round-trip through sync
            // or the server-recomputed hash will not match the offline hash.
            // method_code is the snapshot the client hashed against — preferred
            // over a live PaymentMethod join. Both-or-neither for the instrument
            // pair is enforced in withValidator() (Laravel's required_with does
            // not bind to the same wildcard index).
            //
            // B3-followup audit (Finding 2, 2026-05-01): promoted from `nullable`
            // to `required`. The audit flagged that a stale pre-B3 client could
            // in theory queue a voucher-bearing receipt with no method_code and
            // hit the writer's fallback to a live PaymentMethod::code lookup.
            // The fallback is the wrong contract: method_code is the hash-input
            // snapshot, not a live join. Required-on-the-wire makes the contract
            // explicit and lets us delete the fallback in ReceiptSyncService.
            // No pre-B3 voucher path existed (voucher tender wiring landed with
            // B3), so no production client is sending a missing method_code.
            'receipts.*.payments.*.method_code' => ['required', 'string', 'max:64'],
            'receipts.*.payments.*.instrument_type' => [
                'nullable',
                'string',
                'in:store_voucher,restaurant_voucher,gift_card',
            ],
            'receipts.*.payments.*.instrument_serial' => ['nullable', 'string', 'max:255'],
            'receipts.*.consumption_mode' => ['nullable', 'string', 'in:SUR_PLACE,A_EMPORTER'],
            'receipts.*.table_id' => ['nullable', 'uuid'],
            // Codex review B1 (2026-04-30): clients MUST declare the version every
            // payload was sealed under. The default-to-2 fallback was removed so a
            // missing field surfaces as 422 here rather than silently downgrading
            // a v3 payload (which the server would later reject as a chain break).
            'receipts.*.fiscal_schema_version' => ['required', 'integer', 'in:2,3'],
            // T2.7 — training-mode flag. Optional + defaults false on the wire so
            // pre-T2.7 clients (which don't send the field) continue to behave as
            // production. When true, the server skips hash chain validation, the
            // finalize call, the offline-fiscal-hash mismatch check, and voucher
            // redemption — matching the online `ReceiptCreationService`'s training
            // path. The terminal's runtime `is_training_mode` flag is informational
            // here; receipt-time mode is the source of truth (the cashier may have
            // toggled the terminal before sync).
            'receipts.*.is_training' => ['nullable', 'boolean'],
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
            'receipts.*.payments.*.instrument_type.in' => 'instrument_type must be one of: store_voucher, restaurant_voucher, gift_card',
        ];
    }

    /**
     * Cross-field guard: per-payment instrument_type and instrument_serial must
     * be both present or both absent. Mirrors the rule on the online payments
     * request so the offline sync path cannot relax the contract.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $receipts = $this->input('receipts');
            if (! is_array($receipts)) {
                return;
            }
            foreach ($receipts as $rIndex => $receipt) {
                if (! is_array($receipt) || ! isset($receipt['payments']) || ! is_array($receipt['payments'])) {
                    continue;
                }
                foreach ($receipt['payments'] as $pIndex => $payment) {
                    if (! is_array($payment)) {
                        continue;
                    }
                    $type = $payment['instrument_type'] ?? null;
                    $serial = $payment['instrument_serial'] ?? null;
                    $hasType = $type !== null && $type !== '';
                    $hasSerial = $serial !== null && $serial !== '';
                    if ($hasType && ! $hasSerial) {
                        $validator->errors()->add(
                            "receipts.{$rIndex}.payments.{$pIndex}.instrument_serial",
                            'instrument_serial is required when instrument_type is provided',
                        );

                        continue;
                    }
                    if ($hasSerial && ! $hasType) {
                        $validator->errors()->add(
                            "receipts.{$rIndex}.payments.{$pIndex}.instrument_type",
                            'instrument_type is required when instrument_serial is provided',
                        );

                        continue;
                    }

                    // Codex review B4 (2026-04-30): value-conditional rule.
                    // On the sync wire, `method_code` is the client-supplied
                    // snapshot the offline POS sealed against — there is no
                    // FK lookup. So the rule inspects the snapshot string
                    // directly: if (lowercased) it matches an instrument-bearing
                    // case of PaymentInstrumentKind, both fields are REQUIRED.
                    //
                    // Without this guard, a stale offline client could ship
                    // `method_code: store_voucher` with both instrument fields
                    // null and the v3 hash recomputation would faithfully bind
                    // a null voucher serial — same fiscal-integrity hole as
                    // B2/B3, just shifted from "field dropped by code" to
                    // "field optional under the voucher method".
                    $methodCode = $payment['method_code'] ?? null;
                    if (! is_string($methodCode) || $methodCode === '') {
                        // The base `required` rule on method_code will surface
                        // a separate error; skip B4 to avoid double-reporting.
                        continue;
                    }

                    if (PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)) {
                        if (! $hasType) {
                            $validator->errors()->add(
                                "receipts.{$rIndex}.payments.{$pIndex}.instrument_type",
                                "instrument_type is required when method_code is {$methodCode}",
                            );
                        }
                        if (! $hasSerial) {
                            $validator->errors()->add(
                                "receipts.{$rIndex}.payments.{$pIndex}.instrument_serial",
                                "instrument_serial is required when method_code is {$methodCode}",
                            );
                        }
                    }
                }
            }
        });
    }
}
