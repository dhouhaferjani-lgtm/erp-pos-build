<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferReceiptFailureReason;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * @phpstan-import-type ClosePayload from \App\Modules\Inventory\Application\Services\ReceiptPayloadCanonicalizer
 */
final class CloseStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Enum>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128', 'not_regex:/^sys:/i'],
            'disposition' => ['required', Rule::enum(TransferCloseDisposition::class)],
            'reason' => ['required', Rule::enum(TransferDiscrepancyReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['idempotency_key.not_regex' => 'The idempotency key uses a reserved namespace.'];
    }

    protected function failedValidation(Validator $validator): void
    {
        if (! $this->filled('disposition')) {
            $reason = TransferReceiptFailureReason::DispositionRequired;
            throw new HttpResponseException(response()->json(['error' => ['code' => $reason->value, 'message' => $reason->message(), 'details' => []]], 422));
        }
        parent::failedValidation($validator);
    }

    /** @return ClosePayload */
    public function toPayload(): array
    {
        return ['idempotency_key' => (string) $this->input('idempotency_key'), 'disposition' => (string) $this->input('disposition'), 'reason' => (string) $this->input('reason'), 'note' => $this->input('note') === null ? null : (string) $this->input('note')];
    }
}
