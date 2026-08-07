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
 * the customer's deposit history.
 *
 * These rules are the CHEAP SUBSET of the bridge's preconditions — tenant,
 * company and `is_active`, expressible as a single `exists` query and reported
 * against the offending field. They are NOT full parity (gate finding I-4): the
 * bridge additionally requires a resolvable, active cash GL account and an actor
 * holding an ACTIVE company membership, plus one of two mutually exclusive tails
 * decided by the maturity predicate. For a NON-maturity tender: a matching
 * REPOSITORY currency, an UNFROZEN repository, and a repository not already
 * reconciled through the deposit date. For a cheque/effet: a resolvable
 * instrument portfolio account, and a tender denominated in the COMPANY currency
 * (a different operand from the repository-currency check, not a synonym for it).
 * Every one of those needs a loaded row or a second table, and both tails need
 * the maturity predicate first. They are enforced by
 * `DepositReferenceResolutionService::refusalFor()` inside
 * `RecordCustomerDepositService::record()` — still before the seal — and surface
 * as a 422 `BUSINESS_ERROR`.
 *
 * The freeze, checkpoint and membership predicates are deliberately NOT mirrored
 * here as field rules (R2-K-prev): they are time-of-check/time-of-use state, not
 * input shape, so a validation-time answer would be no more authoritative than
 * the service's and would report a stale `frozen_at` against a field the caller
 * did not get wrong.
 *
 * `'bail'` leads each reference rule so validation stops at the format failure.
 * Laravel already skips `Exists` for an attribute that carries a message
 * (`Validator::hasNotFailedPreviousRuleIfPresenceRule()`, "to avoid possible
 * database type comparison errors"), so this is an explicit, order-independent
 * belt rather than a fix: it keeps a malformed uuid away from the native
 * PostgreSQL `uuid` column (SQLSTATE 22P02) even if the rule order changes.
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
                'bail',
                'required',
                'string',
                'max:64',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId, 'code')
                    ->where('is_active', true),
            ],
            'repository_id' => [
                'bail',
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
