<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

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

        $method = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
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
            'has_maturity' => ['nullable', 'boolean'],
            'requires_third_party' => ['nullable', 'boolean'],
            'is_push' => ['nullable', 'boolean'],
            'has_deducted_fees' => ['nullable', 'boolean'],
            'is_restricted' => ['nullable', 'boolean'],
            'fee_type' => ['nullable', 'string', Rule::in(['none', 'fixed', 'percentage', 'mixed'])],
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'restriction_type' => ['nullable', 'string', 'max:50'],
            'default_journal_id' => ['nullable', 'uuid', 'exists:journals,id'],
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
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $method = PaymentMethod::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'is_physical' => $validated['is_physical'] ?? false,
            'has_maturity' => $validated['has_maturity'] ?? false,
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

        $method = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
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
            'has_maturity' => ['sometimes', 'boolean'],
            'requires_third_party' => ['sometimes', 'boolean'],
            'is_push' => ['sometimes', 'boolean'],
            'has_deducted_fees' => ['sometimes', 'boolean'],
            'is_restricted' => ['sometimes', 'boolean'],
            'fee_type' => ['nullable', 'string', Rule::in(['none', 'fixed', 'percentage', 'mixed'])],
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'restriction_type' => ['nullable', 'string', 'max:50'],
            'default_journal_id' => ['nullable', 'uuid', 'exists:journals,id'],
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
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

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
            'has_maturity' => $method->has_maturity,
            'requires_third_party' => $method->requires_third_party,
            'is_push' => $method->is_push,
            'has_deducted_fees' => $method->has_deducted_fees,
            'is_restricted' => $method->is_restricted,
            'fee_type' => $method->fee_type?->value,
            'fee_fixed' => $method->fee_fixed,
            'fee_percent' => $method->fee_percent,
            'restriction_type' => $method->restriction_type,
            'is_active' => $method->is_active,
            'position' => $method->position,
        ];
    }
}
