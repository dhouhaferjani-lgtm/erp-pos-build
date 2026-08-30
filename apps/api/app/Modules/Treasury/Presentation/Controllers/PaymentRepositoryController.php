<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Enums\RepositoryWriteRefusal;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Banking\Contracts\BankAccountValidatorInterface;
use App\Shared\Banking\Domain\ValueObjects\IbanValidationResult;
use App\Shared\Banking\Domain\ValueObjects\RibValidationResult;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentRepositoryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BankAccountValidatorInterface $bankAccountValidator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $repositories = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['glAccount:id,code,name', 'location:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $repositories->map(fn (PaymentRepository $repo) => $this->formatRepository($repo, $company->country_code)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped (api.treasury.067).
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['glAccount:id,code,name', 'location:id,name'])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatRepository($repository, $company->country_code),
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
                Rule::unique('payment_repositories', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(['cash_register', 'safe', 'bank_account', 'virtual'])],
            // Owner ruling (W-5b Option B backend write path): an explicit
            // value always wins over the type-derived default; omit the key
            // entirely to let PaymentRepository::booted()'s creating hook
            // derive it from `type`.
            'allow_negative' => ['sometimes', 'boolean'],
            'bank_id' => ['nullable', 'uuid', ScopedExists::tenant('banks', $tenantId)],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:50'],
            'bic' => ['nullable', 'string', 'max:20'],
            // locations are company-owned (the table intentionally has no tenant_id).
            'location_id' => ['nullable', 'uuid', ScopedExists::company('locations', $companyId)],
            'responsible_user_id' => ['nullable', 'uuid', ScopedExists::tenant('users', $tenantId)],
            'account_id' => ['nullable', 'uuid'],
            'gl_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
        ]);
        $validated = $this->defaultAccountIdToGlAccountId($validated);

        // N-12 gate r2 (treasury R2-3) — the partial unique index added by
        // 2026_08_26_100000_backfill_payment_repository_location_n12 owns the
        // "one active GL-linked drawer per location per type" invariant, and a
        // second till for a location that already has one is an ordinary,
        // reachable operator action. Unhandled it surfaced as a raw 500 with a
        // PostgreSQL constraint string.
        //
        // The INSERT runs inside its own transaction so the violation rolls back
        // to a SAVEPOINT: on PostgreSQL an unhandled 23505 aborts whatever
        // transaction is open ("commands ignored until end of transaction
        // block"), which poisons every later statement — including the ones this
        // catch block and the caller still need. Same lesson as
        // `LocationCashRegisterProvisioner::provision()`.
        try {
            $repository = DB::transaction(fn (): PaymentRepository => PaymentRepository::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'code' => $validated['code'],
                'name' => $validated['name'],
                'type' => $validated['type'],
                // Omit the key entirely when absent from the payload (rather than
                // passing an explicit null) — the model's creating hook only
                // derives from `type` when the attribute is genuinely unset.
                ...(array_key_exists('allow_negative', $validated) ? ['allow_negative' => $validated['allow_negative']] : []),
                'bank_id' => $validated['bank_id'] ?? null,
                'bank_name' => $validated['bank_name'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'iban' => $validated['iban'] ?? null,
                'bic' => $validated['bic'] ?? null,
                'balance' => '0.00',
                'location_id' => $validated['location_id'] ?? null,
                'responsible_user_id' => $validated['responsible_user_id'] ?? null,
                'account_id' => $validated['account_id'] ?? null,
                'gl_account_id' => $validated['gl_account_id'] ?? null,
                'is_active' => true,
            ]));
        } catch (QueryException $exception) {
            if ($refusal = $this->duplicateDrawerRefusal($exception)) {
                return $this->refuse($refusal);
            }

            throw $exception;
        }

        $repository->load(['glAccount:id,code,name', 'location:id,name']);

        return response()->json([
            'data' => $this->formatRepository($repository, $company->country_code),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped (api.treasury.068).
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:30',
                Rule::unique('payment_repositories', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($repository->id),
            ],
            'name' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in(['cash_register', 'safe', 'bank_account', 'virtual'])],
            // Owner ruling (W-5b Option B backend write path): an explicit
            // value always wins over the type-derived default.
            'allow_negative' => ['sometimes', 'boolean'],
            'bank_id' => ['nullable', 'uuid', ScopedExists::tenant('banks', $tenantId)],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:50'],
            'bic' => ['nullable', 'string', 'max:20'],
            'location_id' => ['nullable', 'uuid', ScopedExists::company('locations', $companyId)],
            'responsible_user_id' => ['nullable', 'uuid', ScopedExists::tenant('users', $tenantId)],
            'account_id' => ['nullable', 'uuid'],
            'gl_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Gate fix (2026-08-07, IMPORTANT #1 — type-change drift): re-derive
        // allow_negative from the NEW type ONLY when this same payload didn't
        // also send an explicit allow_negative. This is the authoritative,
        // and only, place this re-derivation happens — see
        // PaymentRepository::booted()'s docblock for why a model-level
        // `updating` hook cannot do this correctly (Eloquent dirty-checking
        // cannot distinguish "explicitly re-sent the same value" from "never
        // sent"; only the caller holding the real request payload can).
        // Guard on the type actually CHANGING, not merely being present: a
        // whole-form PATCH that re-sends the unchanged type must not clobber
        // an explicit allow_negative set earlier (gate round-2 minor).
        if (array_key_exists('type', $validated)
            && $validated['type'] !== $repository->type->value
            && ! array_key_exists('allow_negative', $validated)) {
            $validated['allow_negative'] = PaymentRepository::defaultAllowNegativeForType(
                RepositoryType::from((string) $validated['type']),
            );
        }

        // Same invariant on the way in from the other side (R2-3): GL-linking a
        // previously unlinked drawer moves it INTO the index's predicate beside
        // an already-linked sibling, and reactivating one does the same. The
        // repositories screen issues exactly that PATCH today
        // (`RepositoryDetailPage`: `apiPatch({ gl_account_id })`), so this is the
        // likelier of the two paths to be hit in practice.
        try {
            if (array_key_exists('gl_account_id', $validated)) {
                $freshRepository = DB::transaction(function () use (
                    $companyId,
                    $id,
                    $tenantId,
                    $validated,
                ): PaymentRepository {
                    $lockedRepository = PaymentRepository::query()
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->lockForUpdate()
                        ->findOrFail($id);
                    $lockedAttributes = $this->defaultAccountIdToGlAccountId(
                        $validated,
                        $lockedRepository->gl_account_id,
                        $lockedRepository->account_id,
                    );

                    if ($lockedAttributes['gl_account_id'] !== $lockedRepository->gl_account_id) {
                        $affectedLegCount = RepositoryMovement::query()
                            ->where('tenant_id', $tenantId)
                            ->where('company_id', $companyId)
                            ->where('payment_repository_id', $lockedRepository->id)
                            ->where('source_type', MovementSourceType::Transfer->value)
                            ->whereNull('journal_entry_id')
                            ->count();

                        if ($affectedLegCount > 0) {
                            $legClause = $affectedLegCount === 1
                                ? 'leg has'
                                : 'legs have';

                            throw new \DomainException(sprintf(
                                'Cannot reassign the repository GL account while %d transfer movement %s no journal entry.',
                                $affectedLegCount,
                                $legClause,
                            ));
                        }
                    }

                    $lockedRepository->update($lockedAttributes);

                    /** @var PaymentRepository $freshRepository */
                    $freshRepository = $lockedRepository->fresh(['glAccount:id,code,name', 'location:id,name']);

                    return $freshRepository;
                });
            } else {
                $validated = $this->defaultAccountIdToGlAccountId(
                    $validated,
                    $repository->gl_account_id,
                    $repository->account_id,
                );
                $repository->update($validated);

                /** @var PaymentRepository $freshRepository */
                $freshRepository = $repository->fresh(['glAccount:id,code,name', 'location:id,name']);
            }
        } catch (QueryException $exception) {
            if ($refusal = $this->duplicateDrawerRefusal($exception)) {
                return $this->refuse($refusal);
            }

            throw $exception;
        }

        return response()->json([
            'data' => $this->formatRepository($freshRepository, $company->country_code),
        ]);
    }

    /**
     * The refusal a `23505` maps to, or null when it is some other unique
     * violation this controller has no better answer for than a 500.
     */
    private function duplicateDrawerRefusal(QueryException $exception): ?RepositoryWriteRefusal
    {
        if (($exception->errorInfo[0] ?? null) !== '23505') {
            return null;
        }

        foreach (RepositoryWriteRefusal::cases() as $refusal) {
            if (str_contains($exception->getMessage(), $refusal->constraintName())) {
                return $refusal;
            }
        }

        return null;
    }

    private function refuse(RepositoryWriteRefusal $refusal): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $refusal->value,
                'message' => $refusal->message(),
            ],
        ], 422);
    }

    public function balance(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped (api.treasury.069).
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $repository->id,
                'code' => $repository->code,
                'name' => $repository->name,
                'balance' => $repository->balance,
                'last_reconciled_at' => $repository->last_reconciled_at?->toIso8601String(),
                'last_reconciled_balance' => $repository->last_reconciled_balance,
            ],
        ]);
    }

    /**
     * Get all transactions (payments) for a repository
     */
    public function transactions(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped (api.treasury.070).
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $company->id)
            ->findOrFail($id);

        // Get all payments that went to this repository
        $payments = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('repository_id', $id)
            ->with(['partner', 'paymentMethod', 'allocations.document'])
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'payment_number' => $payment->reference ?? 'PMT-'.substr($payment->id, 0, 8),
                'partner_id' => $payment->partner_id,
                'partner_name' => $payment->partner?->name,
                'payment_method_name' => $payment->paymentMethod?->name,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'payment_date' => $payment->payment_date->toDateString(),
                'status' => $payment->status->value,
                'payment_type' => $payment->payment_type->value,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'allocations' => $payment->allocations->map(fn (PaymentAllocation $allocation) => [
                    'document_id' => $allocation->document_id,
                    'document_number' => $allocation->document->document_number,
                    'amount' => $allocation->amount,
                ])->toArray(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ])->toArray(),
            'meta' => [
                'total' => $payments->count(),
                'repository_id' => $repository->id,
                'repository_name' => $repository->name,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function defaultAccountIdToGlAccountId(
        array $attributes,
        ?string $existingGlAccountId = null,
        ?string $existingAccountId = null,
    ): array {
        if (array_key_exists('account_id', $attributes) || $existingAccountId !== null) {
            return $attributes;
        }

        $glAccountId = array_key_exists('gl_account_id', $attributes)
            ? $attributes['gl_account_id']
            : $existingGlAccountId;

        if (is_string($glAccountId) && $glAccountId !== '') {
            $attributes['account_id'] = $glAccountId;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRepository(PaymentRepository $repository, string $countryCode): array
    {
        return [
            'id' => $repository->id,
            'code' => $repository->code,
            'name' => $repository->name,
            'type' => $repository->type->value,
            'allow_negative' => $repository->allow_negative,
            'bank_id' => $repository->bank_id,
            'bank_name' => $repository->bank_name,
            'account_number' => $repository->account_number,
            'iban' => $repository->iban,
            'bic' => $repository->bic,
            'balance' => $repository->balance,
            'currency' => $repository->currency,
            'is_active' => $repository->is_active,
            'gl_account_id' => $repository->gl_account_id,
            'gl_account' => $repository->glAccount?->only(['id', 'code', 'name']),
            'location_id' => $repository->location_id,
            'location_name' => $repository->location?->name,
            'bank_account_validation' => $this->bankAccountValidation($repository, $countryCode),
        ];
    }

    /**
     * @return array{rib: RibValidationResult|null, iban: IbanValidationResult|null, bic_valid: bool|null}
     */
    private function bankAccountValidation(PaymentRepository $repository, string $countryCode): array
    {
        return [
            'rib' => $repository->account_number !== null && $repository->account_number !== ''
                ? $this->bankAccountValidator->validateRib($repository->account_number, $countryCode)
                : null,
            'iban' => $repository->iban !== null && $repository->iban !== ''
                ? $this->bankAccountValidator->validateIban($repository->iban)
                : null,
            'bic_valid' => $repository->bic !== null && $repository->bic !== ''
                ? $this->bankAccountValidator->validateBic($repository->bic)
                : null,
        ];
    }
}
