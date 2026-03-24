<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\DTOs\AccountData;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Events\AccountCreated;
use App\Modules\Accounting\Domain\Events\AccountUpdated;
use App\Modules\Accounting\Presentation\Requests\CreateAccountRequest;
use App\Modules\Accounting\Presentation\Requests\UpdateAccountRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List accounts
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $query = Account::forTenant($tenantId);

        // Filter by type
        $type = $request->query('type');
        if (is_string($type) && $type !== '') {
            $typeEnum = AccountType::tryFrom($type);
            if ($typeEnum !== null) {
                $query->ofType($typeEnum);
            }
        }

        // Filter by active status
        $active = $request->query('active');
        if ($active === '1' || $active === 'true') {
            $query->active();
        }

        // Search by name or code
        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $accounts = $query->orderBy('code')->get();

        return response()->json([
            'data' => $accounts->map(fn (Account $account): AccountData => AccountData::fromModel($account)),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get a single account
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $account = Account::forTenant($tenantId)->find($id);

        if ($account === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Account not found',
                ],
            ], 404);
        }

        return response()->json([
            'data' => AccountData::fromModel($account),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create a new account
     */
    public function store(CreateAccountRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $accountType = AccountType::from((string) $validated['type']);

        $account = Account::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'type' => $accountType,
            'description' => $validated['description'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'is_system' => false,
        ]);

        event(new AccountCreated(
            accountId: $account->id,
            tenantId: $tenantId,
            companyId: $companyId,
            code: $validated['code'],
            name: $validated['name'],
            type: $accountType->value,
            createdAt: $account->created_at->toIso8601String(),
        ));

        return response()->json([
            'data' => AccountData::fromModel($account),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update an account
     */
    public function update(UpdateAccountRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $account = Account::forTenant($tenantId)->find($id);

        if ($account === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Account not found',
                ],
            ], 404);
        }

        // System accounts cannot be modified
        if ($account->isSystemAccount()) {
            return response()->json([
                'error' => [
                    'code' => 'SYSTEM_ACCOUNT_IMMUTABLE',
                    'message' => 'System accounts cannot be modified',
                ],
            ], 422);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // Remove type from updates (type cannot be changed)
        unset($validated['type']);

        $account->update($validated);

        /** @var Account $freshAccount */
        $freshAccount = $account->fresh();

        event(new AccountUpdated(
            accountId: $freshAccount->id,
            tenantId: $tenantId,
            companyId: $companyId,
            changes: $validated,
            updatedAt: $freshAccount->updated_at->toIso8601String(),
        ));

        return response()->json([
            'data' => AccountData::fromModel($freshAccount),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
