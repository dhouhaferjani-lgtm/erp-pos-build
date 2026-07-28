<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $methods = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $methods->map(fn (PaymentMethod $method) => $this->formatMethod($method)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped (api.treasury.073).
        $method = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatMethod($method),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('payment_methods', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_physical' => ['nullable', 'boolean'],
            'is_cash_tender' => ['nullable', 'boolean'],
            'has_maturity' => ['nullable', 'boolean'],
            'instrument_kind' => [
                Rule::requiredIf($request->boolean('has_maturity')),
                'nullable',
                Rule::enum(InstrumentKind::class),
            ],
            'requires_third_party' => ['nullable', 'boolean'],
            'is_push' => ['nullable', 'boolean'],
            'has_deducted_fees' => ['nullable', 'boolean'],
            'is_restricted' => ['nullable', 'boolean'],
            'fee_type' => ['nullable', 'string', Rule::in(['none', 'fixed', 'percentage', 'mixed'])],
            'fee_fixed' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'restriction_type' => ['nullable', 'string', 'max:50'],
            // default_journal_id: legacy storage-only field — no `journals` table exists
            // in the current schema (no migration, no model, no read path). The bare
            // `exists:journals,id` validator was broken (any value triggers a 500
            // SQL error: relation "journals" does not exist). Removed pending a
            // proper Accounting-module journals table + scoped validation.
            'default_journal_id' => ['nullable', 'uuid'],
            'default_account_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId),
            ],
            'fee_account_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId),
            ],
            'default_repository_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'position' => ['nullable', 'integer', 'min:0'],
        ], [
            'fee_fixed.regex' => 'Fixed fee must have at most 3 decimal places.',
            'fee_percent.regex' => 'Fee percent must have at most 2 decimal places.',
        ]);

        $instrumentKind = isset($validated['instrument_kind'])
            ? InstrumentKind::from((string) $validated['instrument_kind'])
            : null;
        $this->assertValidInstrumentConfiguration(
            (bool) ($validated['has_maturity'] ?? false),
            (string) $validated['code'],
            $instrumentKind,
        );

        $code = strtoupper(trim((string) $validated['code']));
        $isCashTender = (bool) ($validated['is_cash_tender'] ?? false);
        $this->assertCashTenderInvariant($code, $isCashTender);

        $method = PaymentMethod::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => $code,
            'name' => $validated['name'],
            'is_physical' => $validated['is_physical'] ?? false,
            'is_cash_tender' => $isCashTender,
            'has_maturity' => $validated['has_maturity'] ?? false,
            'instrument_kind' => $instrumentKind,
            'requires_third_party' => $validated['requires_third_party'] ?? false,
            'is_push' => $validated['is_push'] ?? true,
            'has_deducted_fees' => $validated['has_deducted_fees'] ?? false,
            'is_restricted' => $validated['is_restricted'] ?? false,
            'fee_type' => $validated['fee_type'] ?? null,
            'fee_fixed' => $validated['fee_fixed'] ?? '0.00',
            'fee_percent' => $validated['fee_percent'] ?? '0.00',
            'restriction_type' => $validated['restriction_type'] ?? null,
            'default_journal_id' => $validated['default_journal_id'] ?? null,
            'default_account_id' => $validated['default_account_id'] ?? null,
            'fee_account_id' => $validated['fee_account_id'] ?? null,
            'default_repository_id' => $validated['default_repository_id'] ?? null,
            'is_active' => true,
            'position' => $validated['position'] ?? 0,
        ]);

        return response()->json([
            'data' => $this->formatMethod($method),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped (api.treasury.074).
        $method = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:30',
                Rule::unique('payment_methods', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($method->id),
            ],
            'name' => ['sometimes', 'string', 'max:100'],
            'is_physical' => ['sometimes', 'boolean'],
            'is_cash_tender' => ['sometimes', 'boolean'],
            'has_maturity' => ['sometimes', 'boolean'],
            'instrument_kind' => ['nullable', Rule::enum(InstrumentKind::class)],
            'requires_third_party' => ['sometimes', 'boolean'],
            'is_push' => ['sometimes', 'boolean'],
            'has_deducted_fees' => ['sometimes', 'boolean'],
            'is_restricted' => ['sometimes', 'boolean'],
            'fee_type' => ['nullable', 'string', Rule::in(['none', 'fixed', 'percentage', 'mixed'])],
            'fee_fixed' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'restriction_type' => ['nullable', 'string', 'max:50'],
            // default_journal_id: legacy storage-only field — no `journals` table exists
            // in the current schema (no migration, no model, no read path). The bare
            // `exists:journals,id` validator was broken (any value triggers a 500
            // SQL error: relation "journals" does not exist). Removed pending a
            // proper Accounting-module journals table + scoped validation.
            'default_journal_id' => ['nullable', 'uuid'],
            'default_account_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId),
            ],
            'fee_account_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId),
            ],
            'default_repository_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ], [
            'fee_fixed.regex' => 'Fixed fee must have at most 3 decimal places.',
            'fee_percent.regex' => 'Fee percent must have at most 2 decimal places.',
        ]);

        $finalHasMaturity = (bool) ($validated['has_maturity'] ?? $method->has_maturity);
        $finalCode = (string) ($validated['code'] ?? $method->code);
        $finalInstrumentKind = array_key_exists('instrument_kind', $validated)
            ? ($validated['instrument_kind'] === null
                ? null
                : InstrumentKind::from((string) $validated['instrument_kind']))
            : $method->instrument_kind;
        $this->assertValidInstrumentConfiguration($finalHasMaturity, $finalCode, $finalInstrumentKind);

        if (array_key_exists('instrument_kind', $validated)) {
            $validated['instrument_kind'] = $finalInstrumentKind;
        }

        $finalCodeUpper = strtoupper(trim((string) ($validated['code'] ?? $method->code)));
        $finalIsCashTender = array_key_exists('is_cash_tender', $validated)
            ? (bool) $validated['is_cash_tender']
            : $method->is_cash_tender;
        $this->assertCashTenderInvariant($finalCodeUpper, $finalIsCashTender);

        if (array_key_exists('code', $validated)) {
            $validated['code'] = $finalCodeUpper;
        }

        $method->update($validated);

        /** @var PaymentMethod $freshMethod */
        $freshMethod = $method->fresh();

        return response()->json([
            'data' => $this->formatMethod($freshMethod),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMethod(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'is_physical' => $method->is_physical,
            'is_cash_tender' => $method->is_cash_tender,
            'has_maturity' => $method->has_maturity,
            'instrument_kind' => $method->instrument_kind?->value,
            'requires_third_party' => $method->requires_third_party,
            'is_push' => $method->is_push,
            'has_deducted_fees' => $method->has_deducted_fees,
            'is_restricted' => $method->is_restricted,
            'fee_type' => $method->fee_type?->value,
            'fee_fixed' => $method->fee_fixed,
            'fee_percent' => $method->fee_percent,
            'restriction_type' => $method->restriction_type,
            'default_repository_id' => $method->default_repository_id,
            'is_active' => $method->is_active,
            'position' => $method->position,
        ];
    }

    private function assertValidInstrumentConfiguration(
        bool $hasMaturity,
        string $code,
        ?InstrumentKind $instrumentKind,
    ): void {
        if (! $hasMaturity) {
            return;
        }

        if ($instrumentKind === null) {
            throw ValidationException::withMessages([
                'instrument_kind' => ['The instrument kind field is required when the method has maturity.'],
            ]);
        }

        if (PaymentInstrumentKind::requiresInstrumentForMethodCode($code)) {
            throw ValidationException::withMessages([
                'code' => ['A voucher instrument method cannot also be configured as a maturity method.'],
            ]);
        }
    }

    /**
     * Spec §4.1 invariant: `is_cash_tender = true` implies `code = 'CASH'`
     * EXACT (case-sensitive). The device Z aggregation matches
     * `method_code === 'CASH'` case-sensitively while the server matches
     * `UPPER(code)`; only an exact-code invariant makes all three predicates
     * provably coincide. Custom cash methods are a separate ticket.
     */
    private function assertCashTenderInvariant(string $upperCode, bool $isCashTender): void
    {
        if ($isCashTender && $upperCode !== 'CASH') {
            throw ValidationException::withMessages([
                'is_cash_tender' => 'Only the payment method with code CASH may be flagged as a cash tender.',
            ]);
        }
    }
}
