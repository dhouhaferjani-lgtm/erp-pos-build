<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\POS\Domain\Receipt;
use Illuminate\Foundation\Http\FormRequest;

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

        $receipt = Receipt::query()->find($receiptId);
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
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'payments.*.repository_id' => ['required', 'uuid', 'exists:payment_repositories,id'],
            'payments.*.card_last_four' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'payments.*.transaction_reference' => ['nullable', 'string', 'max:100'],
            'payments.*.authorization_code' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'uuid', 'exists:partners,id'],
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
            'customer_id.exists' => 'Customer does not exist',
        ];
    }
}
