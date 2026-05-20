<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Outer wire-shape validation for `POST /api/v1/pos/sync/fiscal-events`
 * (Task 20, spec v7 §7.1).
 *
 * Validates only the outer envelope-of-envelopes shape — `envelopes` is a
 * required list of objects each carrying the four outer keys + a `payload`
 * sub-object. Per-field regex validation on the inner `payload` runs
 * through `FiscalEventEnvelope::fromArray()` in the controller (round-2
 * T19-B4 standing pattern — regex-validate free-form fields at the typed
 * boundary). Duplicating the canonical regexes here would create a second
 * place for them to drift.
 *
 * Authorization gating happens at two layers:
 *   - The route's middleware tuple (`auth:sanctum` + `SetPermissionsTeam` +
 *     `EnforceTokenTenantClaim`) handles authentication and token-vs-user
 *     tenant claim verification.
 *   - The controller enforces the envelope-vs-user tenant boundary
 *     (`$envelope->tenantId === $user->tenant_id` per envelope).
 *
 * `authorize()` returns `true` because both layers operate outside the
 * FormRequest's `validated()` surface; the controller's tenant check needs
 * the parsed `FiscalEventEnvelope` DTO, not the raw wire array.
 */
final class IngestFiscalEventsRequest extends FormRequest
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
            // Batch cap of 100 preserves the retired receipt-sync ceiling
            // (`'receipts' => ['required', 'array', 'min:1', 'max:100']`).
            // Bounds the worst-case per-request OutboxIngestor cost + the
            // device-side memory footprint of a single sync POST.
            'envelopes' => ['required', 'array', 'min:1', 'max:100'],
            'envelopes.*' => ['required', 'array'],
            'envelopes.*.envelope_id' => ['required', 'string'],
            'envelopes.*.type' => ['required', 'string', 'in:FISCAL_EVENT'],
            'envelopes.*.payload_version' => ['required', 'integer', 'min:1'],
            'envelopes.*.idempotency_key' => ['required', 'string'],
            'envelopes.*.payload' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'envelopes.required' => 'At least one envelope is required.',
            'envelopes.max' => 'Maximum 100 envelopes per batch.',
            'envelopes.*.type.in' => 'Wire envelope `type` must equal "FISCAL_EVENT".',
        ];
    }
}
