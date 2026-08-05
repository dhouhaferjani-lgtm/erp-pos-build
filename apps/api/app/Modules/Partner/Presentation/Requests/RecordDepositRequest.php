<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for recording a back-office customer-account deposit.
 *
 * Authorization is handled by the `can:payments.create` route middleware. The
 * amount is a plain non-negative decimal string (no float, no scientific
 * notation); the authoring service rejects any precision beyond the currency
 * scale. `payment_method_code` matches the canonical payload's `method_code`
 * (the Treasury bridge resolves the tenant-scoped PaymentMethod by code).
 *
 * **W-5c D1 — the existence checks are load-bearing, not cosmetic.** A
 * `DEPOSIT_RECEIPT` is authored and SEALED into the fiscal hash chain before the
 * Treasury bridge ever looks the references up, and there is no delete route by
 * design. Without a boundary check a dangling `payment_method_code` /
 * `repository_id` produced a 500 plus a permanent orphan receipt that over-stated
 * the customer's deposit history. The `is_active` predicates mirror
 * `TreasuryDepositBridge::resolvePaymentMethod()` / `resolveRepository()` exactly,
 * so deactivating a method or repository while this dialog is open is a 422 too.
 */
final class RecordDepositRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'amount' => ['required', 'string', 'regex:/^(0|[1-9]\d*)(\.\d+)?$/'],
            'payment_method_code' => [
                'required',
                'string',
                'max:64',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId, 'code')
                    ->where('is_active', true),
            ],
            'repository_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId)
                    ->where('is_active', true),
            ],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method_code.exists' => 'The selected payment method does not exist or is no longer active.',
            'repository_id.exists' => 'The selected payment repository does not exist or is no longer active.',
        ];
    }
}
