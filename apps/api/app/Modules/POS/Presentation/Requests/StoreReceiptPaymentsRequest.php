<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for processing receipt payments.
 *
 * Supports split payments across multiple payment methods.
 *
 * Authorization:
 *  - The route is gated for `pos.operate_terminal` by the controller.
 *  - When the request would result in a short-pay (totalPaid < receipt.total),
 *    additionally requires `pos.tolerance.apply`. Exact-tender and overpay
 *    flows are unchanged.
 */
final class StoreReceiptPaymentsRequest extends FormRequest
{
    /**
     * Cached receipt resolved from the route id, used for tenant-scoping
     * `exists` rules and the withValidator() PaymentMethod lookup.
     */
    private ?Receipt $resolvedReceipt = null;

    private bool $receiptResolved = false;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Note: detection of "would-be short-pay" is intentional here rather
     * than in the service. We want a 403 (permission) for short-pay attempts
     * by an unprivileged cashier, not a 4xx after the request reaches the
     * service. Outside-tolerance short-pay still rejects later in the
     * service with 422 / domain exception (Task 9).
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if (! $user->can('pos.operate_terminal')) {
            return false;
        }

        $receiptId = $this->route('id');
        if (! is_string($receiptId)) {
            return true;
        }

        $payments = $this->input('payments');
        if (! is_array($payments) || $payments === []) {
            return true;
        }

        $receipt = $this->resolveReceipt();
        if ($receipt === null) {
            // Let the controller resolve to a 404; not an authorization concern.
            return true;
        }

        $totalPaid = '0.000';
        foreach ($payments as $payment) {
            if (! is_array($payment) || ! isset($payment['amount'])) {
                continue;
            }
            $amount = $payment['amount'];
            if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
                continue;
            }
            if (! is_numeric($amount)) {
                continue;
            }
            $totalPaid = bcadd($totalPaid, (string) $amount, 3);
        }

        if (bccomp($totalPaid, (string) $receipt->total, 3) < 0) {
            return $user->can('pos.tolerance.apply');
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Tenant-scoping context: derived from the receipt being paid so that
        // every `exists:` lookup rejects cross-tenant or cross-company UUIDs
        // even if the attacker is authenticated to the API. If the receipt is
        // missing, we still emit the scoped rules — the eq-against-null filters
        // match zero rows (tenant_id / company_id are NOT NULL on these tables),
        // so the validator fails closed rather than falling back to unscoped.
        $receipt = $this->resolveReceipt();
        $tenantId = $receipt?->tenant_id;
        $companyId = $receipt?->company_id;

        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => [
                'required',
                'uuid',
                Rule::exists('payment_methods', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId),
            ],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'payments.*.repository_id' => [
                'required',
                'uuid',
                Rule::exists('payment_repositories', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId),
            ],
            'payments.*.card_last_four' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'payments.*.transaction_reference' => ['nullable', 'string', 'max:100'],
            'payments.*.authorization_code' => ['nullable', 'string', 'max:50'],
            // Codex review B3 (2026-04-30): instrument fields are bound into the v3
            // canonical fiscal hash by V3ReceiptHashComputer. The request validator
            // must accept them so a voucher-bearing tender writes the actual serial
            // into pos_receipt_payments — without this the v3 chain cannot reconstruct
            // which voucher paid which receipt. The both-or-neither cross-field
            // constraint is enforced in withValidator() because Laravel's
            // `required_with:payments.*.x` does not bind to the same array index.
            'payments.*.instrument_type' => [
                'nullable',
                'string',
                'in:store_voucher,restaurant_voucher,gift_card',
            ],
            'payments.*.instrument_serial' => [
                'nullable',
                'string',
                'max:255',
            ],
            'customer_id' => [
                'nullable',
                'uuid',
                Rule::exists('partners', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId),
            ],
        ];
    }

    /**
     * Cross-field guard: `instrument_type` and `instrument_serial` must be either
     * both present or both absent on the same payment row.
     *
     * Laravel's `required_with:payments.*.instrument_serial` rule treats the
     * `*` wildcard as a flatten match, not a per-index pair, so it would accept
     * a row whose `instrument_type` was set as long as ANY row in the array had
     * an `instrument_serial`. That's not the contract we want — each payment
     * row carries its own instrument identity, and a half-configured row would
     * silently bind null into the v3 fiscal hash. This callback replicates
     * `required_with` per row.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payments = $this->input('payments');
            if (! is_array($payments)) {
                return;
            }

            // Codex review B4 (2026-04-30): cache PaymentMethod lookups across
            // the per-row loop so a multi-line tender doesn't issue N queries.
            // Tenant-isolation sweep (2026-05-01): the lookup is explicitly
            // scoped to the receipt's tenant_id and company_id below — there
            // is no global tenant scope on PaymentMethod (the model only
            // exposes manual scopeForTenant/scopeForCompany), so the bare
            // `query()->find()` previously here would have accepted a
            // cross-tenant ID. The `exists:` rule above also rejects that
            // shape; this scoping is defense in depth for programmatic flows
            // that bypass FormRequest validation.
            $receiptForScope = $this->resolveReceipt();
            /** @var array<string, ?PaymentMethod> $methodCache */
            $methodCache = [];

            foreach ($payments as $index => $payment) {
                if (! is_array($payment)) {
                    continue;
                }
                $type = $payment['instrument_type'] ?? null;
                $serial = $payment['instrument_serial'] ?? null;
                $hasType = $type !== null && $type !== '';
                $hasSerial = $serial !== null && $serial !== '';
                if ($hasType && ! $hasSerial) {
                    $validator->errors()->add(
                        "payments.{$index}.instrument_serial",
                        'instrument_serial is required when instrument_type is provided',
                    );

                    continue;
                }
                if ($hasSerial && ! $hasType) {
                    $validator->errors()->add(
                        "payments.{$index}.instrument_type",
                        'instrument_type is required when instrument_serial is provided',
                    );

                    continue;
                }

                // Codex review B4 (2026-04-30): value-conditional rule.
                // Both-or-neither is necessary but not sufficient — `both null`
                // still passes that check. For instrument-bearing payment
                // method codes (store_voucher / restaurant_voucher / gift_card,
                // per the PaymentInstrumentKind enum), BOTH fields MUST be
                // present and non-empty. Without this, a stale client could
                // submit `payment_method_id` = a store_voucher method with no
                // serial, the v3 hash would faithfully bind
                // `method_code = store_voucher, instrument_serial = null`,
                // and the legally meaningful event ("voucher SV-XXXX paid")
                // would never reach the chain.
                $paymentMethodId = $payment['payment_method_id'] ?? null;
                if (! is_string($paymentMethodId) || $paymentMethodId === '') {
                    // The base rule (`required`, `uuid`, `exists`) will surface
                    // a separate error for this row. Skip the B4 rule so we
                    // don't double-report.
                    continue;
                }

                if (! array_key_exists($paymentMethodId, $methodCache)) {
                    $methodQuery = PaymentMethod::query();
                    if ($receiptForScope !== null) {
                        $methodQuery
                            ->where('tenant_id', $receiptForScope->tenant_id)
                            ->where('company_id', $receiptForScope->company_id);
                    }
                    /** @var ?PaymentMethod $found */
                    $found = $methodQuery->find($paymentMethodId);
                    $methodCache[$paymentMethodId] = $found;
                }
                $resolvedMethod = $methodCache[$paymentMethodId];

                if ($resolvedMethod === null) {
                    // The `exists` rule will fail this row; nothing to add.
                    continue;
                }

                $methodCode = (string) $resolvedMethod->code;
                if (PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)) {
                    if (! $hasType) {
                        $validator->errors()->add(
                            "payments.{$index}.instrument_type",
                            "instrument_type is required when payment method code is {$methodCode}",
                        );
                    }
                    if (! $hasSerial) {
                        $validator->errors()->add(
                            "payments.{$index}.instrument_serial",
                            "instrument_serial is required when payment method code is {$methodCode}",
                        );
                    }
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payments.required' => 'At least one payment method is required',
            'payments.array' => 'Payments must be an array',
            'payments.min' => 'At least one payment method is required',
            'payments.*.payment_method_id.required' => 'Payment method ID is required',
            'payments.*.payment_method_id.exists' => 'Payment method does not exist',
            'payments.*.amount.required' => 'Payment amount is required',
            'payments.*.amount.numeric' => 'Payment amount must be a valid number',
            'payments.*.amount.min' => 'Payment amount must be greater than zero',
            'payments.*.amount.regex' => 'Payment amount must have at most 3 decimal places',
            'payments.*.repository_id.required' => 'Payment repository ID is required',
            'payments.*.repository_id.exists' => 'Payment repository does not exist',
            'payments.*.card_last_four.size' => 'Card last four digits must be exactly 4 digits',
            'payments.*.card_last_four.regex' => 'Card last four digits must contain only numbers',
            'payments.*.transaction_reference.max' => 'Transaction reference cannot exceed 100 characters',
            'payments.*.authorization_code.max' => 'Authorization code cannot exceed 50 characters',
            'payments.*.instrument_type.in' => 'instrument_type must be one of: store_voucher, restaurant_voucher, gift_card',
            'payments.*.instrument_type.required_with' => 'instrument_type is required when instrument_serial is provided',
            'payments.*.instrument_serial.required_with' => 'instrument_serial is required when instrument_type is provided',
            'payments.*.instrument_serial.max' => 'instrument_serial cannot exceed 255 characters',
            'customer_id.exists' => 'Customer does not exist',
        ];
    }

    /**
     * Resolve and cache the receipt referenced by the route parameter.
     *
     * Used by both authorize() (short-pay tolerance check) and rules()
     * (tenant-scoping context for `Rule::exists` lookups). Cached so we
     * don't re-query the receipt twice per request.
     */
    private function resolveReceipt(): ?Receipt
    {
        if ($this->receiptResolved) {
            return $this->resolvedReceipt;
        }

        $this->receiptResolved = true;

        $receiptId = $this->route('id');
        if (! is_string($receiptId)) {
            return $this->resolvedReceipt = null;
        }

        return $this->resolvedReceipt = Receipt::query()->find($receiptId);
    }
}
