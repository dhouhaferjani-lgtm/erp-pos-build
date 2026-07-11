<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\BounceInstrumentData;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Presentation\Validation\ScopedExists;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class PaymentInstrumentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly InstrumentLifecycleService $lifecycle,
        private readonly InstrumentAccountResolver $accountResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'kind' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'partner_id' => ['nullable', 'uuid'],
            'repository_id' => ['nullable', 'uuid'],
            'needs_details' => ['nullable', 'in:true,false,1,0'],
            'maturity_from' => ['nullable', 'date'],
            'maturity_to' => ['nullable', 'date', 'after_or_equal:maturity_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PaymentInstrument::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['paymentMethod', 'partner', 'repository', 'depositedTo']);
        foreach (['status', 'kind', 'direction', 'partner_id', 'repository_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (array_key_exists('needs_details', $validated)) {
            $query->where(
                'needs_details',
                filter_var($validated['needs_details'], FILTER_VALIDATE_BOOLEAN),
            );
        }
        if (isset($validated['maturity_from'])) {
            $query->whereDate('maturity_date', '>=', $validated['maturity_from']);
        }
        if (isset($validated['maturity_to'])) {
            $query->whereDate('maturity_date', '<=', $validated['maturity_to']);
        }

        /** @var LengthAwarePaginator<int, PaymentInstrument> $instruments */
        $instruments = $query->orderByDesc('received_date')->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => collect($instruments->items())->map(fn (PaymentInstrument $instrument): array => $this->formatInstrument($instrument)),
            'meta' => [
                'current_page' => $instruments->currentPage(),
                'last_page' => $instruments->lastPage(),
                'per_page' => $instruments->perPage(),
                'total' => $instruments->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $instrument = $this->findInstrument($id);
        $instrument->load(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json(['data' => $this->formatInstrument($instrument)]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'payment_method_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $company->tenant_id, $company->id),
            ],
            'reference' => ['required', 'string', 'max:100'],
            'partner_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
            'drawer_name' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'received_date' => ['required', 'date'],
            'maturity_date' => ['nullable', 'date'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'repository_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $company->tenant_id, $company->id),
            ],
            'bank_id' => ['nullable', 'uuid'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_branch' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:50'],
            'needs_details' => ['nullable', 'boolean'],
        ], ['amount.regex' => 'Amount must have at most 3 decimal places.']);
        $paymentMethodId = $validated['payment_method_id'];
        if (! is_string($paymentMethodId)) {
            abort(422);
        }
        $method = PaymentMethod::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereKey($paymentMethodId)
            ->firstOrFail();
        $kind = $method->instrument_kind;
        if (! $method->has_maturity || $kind === null || $kind === InstrumentKind::Other) {
            return $this->domainError(new DomainException('Payment method is not a supported paper instrument method.'));
        }

        try {
            $this->accountResolver->resolveOrFail(
                $kind === InstrumentKind::Cheque
                    ? InstrumentAccountPurpose::ChecksToCollect
                    : InstrumentAccountPurpose::EffectsReceivable,
                $company->id,
            );
            $instrument = $this->lifecycle->receive(new ReceiveInstrumentData(
                tenantId: $company->tenant_id,
                companyId: $company->id,
                paymentMethodId: $method->id,
                kind: $kind,
                direction: InstrumentDirection::from($validated['direction'] ?? InstrumentDirection::Inbound->value),
                origin: InstrumentOrigin::Web,
                reference: $validated['reference'],
                amount: $validated['amount'],
                currency: strtoupper($validated['currency'] ?? $company->currency),
                repositoryId: $validated['repository_id'],
                partnerId: $validated['partner_id'] ?? null,
                drawerName: $validated['drawer_name'] ?? null,
                maturityDate: $validated['maturity_date'] ?? null,
                receivedDate: $validated['received_date'],
                bankId: $validated['bank_id'] ?? null,
                bankName: $validated['bank_name'] ?? null,
                bankBranch: $validated['bank_branch'] ?? null,
                bankAccount: $validated['bank_account'] ?? null,
                needsDetails: (bool) ($validated['needs_details'] ?? false),
                createdBy: $user->id,
            ));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $instrument->load(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json(['data' => $this->formatInstrument($instrument)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $instrument = $this->findInstrument($id);
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'reference' => ['sometimes', 'string', 'max:100'],
            'maturity_date' => ['sometimes', 'nullable', 'date'],
            'drawer_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'bank_id' => ['sometimes', 'nullable', 'uuid'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_branch' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_account' => ['sometimes', 'nullable', 'string', 'max:50'],
            'partner_id' => [
                'sometimes', 'nullable', 'uuid',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
        ]);

        try {
            $instrument = $this->lifecycle->updateDetails($instrument->id, $validated, $request->user()?->id);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $instrument->load(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json(['data' => $this->formatInstrument($instrument)]);
    }

    public function deposit(Request $request, string $id): JsonResponse
    {
        $instrument = $this->findInstrument($id);
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'repository_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $company->tenant_id, $company->id),
            ],
        ]);
        try {
            $this->lifecycle->deposit($instrument->id, $validated['repository_id'], $request->user()?->id);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return $this->show($instrument->id);
    }

    public function clear(Request $request, string $id): JsonResponse
    {
        $instrument = $this->findInstrument($id);
        $validated = $request->validate([
            'fee_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'fee_vat_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'value_date' => ['nullable', 'date'],
        ]);
        try {
            $instrument = $this->lifecycle->clear(new ClearInstrumentData(
                instrumentId: $instrument->id,
                currency: $instrument->currency,
                feeAmount: $validated['fee_amount'] ?? '0.000',
                feeVatAmount: $validated['fee_vat_amount'] ?? '0.000',
                valueDate: $validated['value_date'] ?? null,
                userId: $request->user()?->id,
            ));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $instrument->load(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json(['data' => $this->formatInstrument($instrument)]);
    }

    public function bounce(Request $request, string $id): JsonResponse
    {
        $instrument = $this->findInstrument($id);
        $validated = $request->validate([
            'routing' => ['required', 'in:re_present,receivable,doubtful'],
            'fee_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'fee_vat_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $instrument = $this->lifecycle->bounce(new BounceInstrumentData(
                instrumentId: $instrument->id,
                routing: DishonorRouting::from($validated['routing']),
                currency: $instrument->currency,
                feeAmount: $validated['fee_amount'] ?? '0.000',
                feeVatAmount: $validated['fee_vat_amount'] ?? '0.000',
                reason: $validated['reason'] ?? null,
                userId: $request->user()?->id,
            ));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $instrument->load(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        return response()->json(['data' => $this->formatInstrument($instrument)]);
    }

    public function transfer(Request $request, string $id): JsonResponse
    {
        $instrument = $this->findInstrument($id);
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'to_repository_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $company->tenant_id, $company->id),
            ],
        ]);
        try {
            $this->lifecycle->custodyTransfer($instrument->id, $validated['to_repository_id'], $request->user()?->id);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return $this->show($instrument->id);
    }

    private function findInstrument(string $id): PaymentInstrument
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $company = $this->companyContext->requireCompany();

        return PaymentInstrument::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'BUSINESS_ERROR',
                'message' => $exception->getMessage(),
            ],
        ], 422);
    }

    /** @return array<string, mixed> */
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
            'partner' => $instrument->partner ? ['id' => $instrument->partner->id, 'name' => $instrument->partner->name] : null,
            'drawer_name' => $instrument->drawer_name,
            'amount' => $instrument->amount,
            'currency' => $instrument->currency,
            'received_date' => $instrument->received_date->toDateString(),
            'maturity_date' => $instrument->maturity_date?->toDateString(),
            'expiry_date' => $instrument->expiry_date?->toDateString(),
            'status' => $instrument->status->value,
            'kind' => $instrument->kind?->value,
            'direction' => $instrument->direction->value,
            'origin' => $instrument->origin->value,
            'needs_details' => $instrument->needs_details,
            'dishonor_routing' => $instrument->dishonor_routing?->value,
            'remittance_id' => $instrument->remittance_id,
            'repository_id' => $instrument->repository_id,
            'repository' => $instrument->repository ? [
                'id' => $instrument->repository->id,
                'code' => $instrument->repository->code,
                'name' => $instrument->repository->name,
            ] : null,
            'bank_id' => $instrument->bank_id,
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
