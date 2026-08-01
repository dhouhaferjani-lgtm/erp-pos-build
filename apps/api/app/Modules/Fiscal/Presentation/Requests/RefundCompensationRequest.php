<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /fiscal/refund-compensations` — v3-refund-chain-integration spec
 * §5.2. `operator_attestation` is a REQUIRED field on every request (not an
 * inference from any device state) — the explicit server-observable
 * evidence the ⚖️ orchestrator ruling requires.
 */
final class RefundCompensationRequest extends FormRequest
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
            'fiscal_event_id' => ['required', 'uuid'],
            'compensation_class' => ['required', 'string', 'in:invalid_refund,valid_unbooked'],
            'operator_attestation' => ['required', 'string', 'min:10'],
        ];
    }
}
