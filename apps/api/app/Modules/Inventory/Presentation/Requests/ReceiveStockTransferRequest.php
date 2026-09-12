<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Shared\Domain\QuantityScale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * @phpstan-import-type ReceivePayload from \App\Modules\Inventory\Application\Services\ReceiptPayloadCanonicalizer
 */
final class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $quantity = ['required', 'numeric', 'regex:/^\d+(\.\d{1,4})?$/'];

        return [
            'idempotency_key' => ['required', 'string', 'max:128', 'not_regex:/^sys:/i'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.transfer_line_id' => ['required', 'string', 'uuid', 'distinct'],
            'lines.*.quantity_received' => $quantity,
            'lines.*.quantity_damaged' => $quantity,
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:32'],
            'lines.*.discrepancy_note' => ['nullable', 'string', 'max:1000'],
            'lines.*.lots' => ['nullable', 'array'],
            'lines.*.lots.*.batch_id' => ['required', 'integer', 'min:1'],
            'lines.*.lots.*.quantity_received' => $quantity,
            'lines.*.lots.*.quantity_damaged' => $quantity,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['idempotency_key.not_regex' => 'The idempotency key uses a reserved namespace.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('lines', []) as $index => $line) {
                if (bccomp(bcadd($this->quantity((string) $line['quantity_received']), $this->quantity((string) $line['quantity_damaged']), QuantityScale::SCALE), '0', QuantityScale::SCALE) <= 0) {
                    $validator->errors()->add('lines.'.$index.'.quantity_received', 'A receipt line must contain a positive quantity.');
                }
                $seen = [];
                foreach ($line['lots'] ?? [] as $lotIndex => $lot) {
                    if (in_array((int) $lot['batch_id'], $seen, true)) {
                        $validator->errors()->add('lines.'.$index.'.lots.'.$lotIndex.'.batch_id', 'A batch may occur only once on a line.');
                    }
                    $seen[] = (int) $lot['batch_id'];
                    if (bccomp(bcadd($this->quantity((string) $lot['quantity_received']), $this->quantity((string) $lot['quantity_damaged']), QuantityScale::SCALE), '0', QuantityScale::SCALE) <= 0) {
                        $validator->errors()->add('lines.'.$index.'.lots.'.$lotIndex.'.quantity_received', 'A lot row must contain a positive quantity.');
                    }
                }
            }
        });
    }

    /** @return ReceivePayload */
    public function toPayload(): array
    {
        $lines = [];
        foreach ($this->input('lines', []) as $line) {
            $lots = [];
            foreach ($line['lots'] ?? [] as $lot) {
                $lots[] = ['batch_id' => (int) $lot['batch_id'], 'quantity_received' => $this->quantity((string) $lot['quantity_received']), 'quantity_damaged' => $this->quantity((string) $lot['quantity_damaged'])];
            }
            $lines[] = ['transfer_line_id' => (string) $line['transfer_line_id'], 'quantity_received' => $this->quantity((string) $line['quantity_received']), 'quantity_damaged' => $this->quantity((string) $line['quantity_damaged']),
                'discrepancy_reason' => isset($line['discrepancy_reason']) ? (string) $line['discrepancy_reason'] : null,
                'discrepancy_note' => isset($line['discrepancy_note']) ? (string) $line['discrepancy_note'] : null, 'lots' => $lots];
        }

        return ['idempotency_key' => (string) $this->input('idempotency_key'), 'notes' => $this->input('notes') === null ? null : (string) $this->input('notes'), 'lines' => $lines];
    }

    /** @return numeric-string */
    private function quantity(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('A validated quantity must be numeric.');
        }

        return $value;
    }
}
