<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaymentInstrumentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $query = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->with(['paymentMethod', 'partner', 'repository']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by partner
        if ($request->has('partner_id')) {
            $query->where('partner_id', $request->input('partner_id'));
        }

        // Filter by repository
        if ($request->has('repository_id')) {
            $query->where('repository_id', $request->input('repository_id'));
        }

        $instruments = $query->orderByDesc('received_date')->get();

        return response()->json([
            'data' => $instruments->map(fn (PaymentInstrument $instrument) => $this->formatInstrument($instrument)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->with(['paymentMethod', 'partner', 'repository', 'depositedTo'])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatInstrument($instrument),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $validated = $request->validate([
            'payment_method_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'reference' => ['required', 'string', 'max:100'],
            'partner_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'drawer_name' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'received_date' => ['required', 'date'],
            'maturity_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'repository_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_branch' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:50'],
        ]);

        $instrument = PaymentInstrument::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'payment_method_id' => $validated['payment_method_id'],
            'reference' => $validated['reference'],
            'partner_id' => $validated['partner_id'] ?? null,
            'drawer_name' => $validated['drawer_name'] ?? null,
            'amount' => $validated['amount'],
            'currency' => $validated['currency'] ?? 'TND',
            'received_date' => $validated['received_date'],
            'maturity_date' => $validated['maturity_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'status' => InstrumentStatus::Received,
            'repository_id' => $validated['repository_id'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_branch' => $validated['bank_branch'] ?? null,
            'bank_account' => $validated['bank_account'] ?? null,
            'created_by' => $user->id,
        ]);

        $instrument->load(['paymentMethod', 'partner', 'repository']);

        return response()->json([
            'data' => $this->formatInstrument($instrument),
        ], 201);
    }

    public function deposit(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var PaymentInstrument $instrument */
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if (! $instrument->status->canDeposit()) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Instrument cannot be deposited in its current status',
                ],
            ], 422);
        }

        $validated = $request->validate([
            'repository_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
        ]);

        // Verify the repository is a bank account
        /** @var PaymentRepository $repository */
        $repository = PaymentRepository::findOrFail($validated['repository_id']);
        if ($repository->type->value !== 'bank_account') {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_REPOSITORY',
                    'message' => 'Instruments can only be deposited to bank accounts',
                ],
            ], 422);
        }

        $instrument->update([
            'status' => InstrumentStatus::Deposited,
            'deposited_at' => now(),
            'deposited_to_id' => $validated['repository_id'],
        ]);

        event(new InstrumentDeposited(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            repositoryId: $validated['repository_id'],
            amount: $instrument->amount,
            depositedAt: now()->toIso8601String(),
        ));

        /** @var PaymentInstrument $freshInstrument */
        $freshInstrument = $instrument->fresh(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json([
            'data' => $this->formatInstrument($freshInstrument),
        ]);
    }

    public function clear(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var PaymentInstrument $instrument */
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if (! $instrument->status->canClear()) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Instrument cannot be cleared in its current status',
                ],
            ], 422);
        }

        $instrument->update([
            'status' => InstrumentStatus::Cleared,
            'cleared_at' => now(),
        ]);

        event(new InstrumentCleared(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            amount: $instrument->amount,
            clearedAt: now()->toIso8601String(),
        ));

        /** @var PaymentInstrument $freshInstrument */
        $freshInstrument = $instrument->fresh(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json([
            'data' => $this->formatInstrument($freshInstrument),
        ]);
    }

    public function bounce(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var PaymentInstrument $instrument */
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if (! $instrument->status->canBounce()) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Instrument cannot be bounced in its current status',
                ],
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $instrument->update([
            'status' => InstrumentStatus::Bounced,
            'bounced_at' => now(),
            'bounce_reason' => $validated['reason'] ?? null,
        ]);

        event(new InstrumentBounced(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            amount: $instrument->amount,
            reason: $validated['reason'] ?? '',
            bouncedAt: now()->toIso8601String(),
        ));

        /** @var PaymentInstrument $freshInstrument */
        $freshInstrument = $instrument->fresh(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json([
            'data' => $this->formatInstrument($freshInstrument),
        ]);
    }

    public function transfer(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var PaymentInstrument $instrument */
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if (! $instrument->status->canTransfer()) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Instrument cannot be transferred in its current status',
                ],
            ], 422);
        }

        $validated = $request->validate([
            'to_repository_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $fromRepositoryId = $instrument->repository_id ?? '';

        $instrument->update([
            'repository_id' => $validated['to_repository_id'],
        ]);

        event(new InstrumentTransferred(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            fromRepositoryId: $fromRepositoryId,
            toRepositoryId: $validated['to_repository_id'],
            amount: $instrument->amount,
            transferredAt: now()->toIso8601String(),
        ));

        /** @var PaymentInstrument $freshInstrument */
        $freshInstrument = $instrument->fresh(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json([
            'data' => $this->formatInstrument($freshInstrument),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInstrument(PaymentInstrument $instrument): array
    {
        return [
            'id' => $instrument->id,
            'payment_method_id' => $instrument->payment_method_id,
            'payment_method' => $instrument->paymentMethod ? [
                'id' => $instrument->paymentMethod->id,
                'code' => $instrument->paymentMethod->code,
                'name' => $instrument->paymentMethod->name,
            ] : null,
            'reference' => $instrument->reference,
            'partner_id' => $instrument->partner_id,
            'partner' => $instrument->partner ? [
                'id' => $instrument->partner->id,
                'name' => $instrument->partner->name,
            ] : null,
            'drawer_name' => $instrument->drawer_name,
            'amount' => $instrument->amount,
            'currency' => $instrument->currency,
            'received_date' => $instrument->received_date->toDateString(),
            'maturity_date' => $instrument->maturity_date?->toDateString(),
            'expiry_date' => $instrument->expiry_date?->toDateString(),
            'status' => $instrument->status->value,
            'repository_id' => $instrument->repository_id,
            'repository' => $instrument->repository ? [
                'id' => $instrument->repository->id,
                'code' => $instrument->repository->code,
                'name' => $instrument->repository->name,
            ] : null,
            'bank_name' => $instrument->bank_name,
            'bank_branch' => $instrument->bank_branch,
            'bank_account' => $instrument->bank_account,
            'deposited_at' => $instrument->deposited_at?->toIso8601String(),
            'deposited_to_id' => $instrument->deposited_to_id,
            'deposited_to' => $instrument->depositedTo ? [
                'id' => $instrument->depositedTo->id,
                'code' => $instrument->depositedTo->code,
                'name' => $instrument->depositedTo->name,
            ] : null,
            'cleared_at' => $instrument->cleared_at?->toIso8601String(),
            'bounced_at' => $instrument->bounced_at?->toIso8601String(),
            'bounce_reason' => $instrument->bounce_reason,
            'created_at' => $instrument->created_at?->toIso8601String(),
        ];
    }
}
