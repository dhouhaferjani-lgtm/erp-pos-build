<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Controllers;

use App\Enums\Vertical;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Application\DTOs\PartnerData;
use App\Modules\Partner\Application\Services\PartnerBankAccountService;
use App\Modules\Partner\Application\Services\PartnerReferenceCounter;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Events\PartnerCreated;
use App\Modules\Partner\Domain\Events\PartnerDeleted;
use App\Modules\Partner\Domain\Events\PartnerUpdated;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Partner\Domain\Services\TaxIdValidationService;
use App\Modules\Partner\Presentation\Requests\CreatePartnerRequest;
use App\Modules\Partner\Presentation\Requests\UpdatePartnerRequest;
use App\Shared\Banking\Contracts\BankAccountValidatorInterface;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PartnerController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    /**
     * `$db` is `DatabaseManager`, not `ConnectionInterface` — see the
     * 2026-08-06 live-verification fix on
     * `PartnerReferenceCounter` for the full root-cause writeup. In short:
     * this controller is `make()`'d by Laravel during
     * `Route::gatherMiddleware()`, BEFORE `ResolveTenancy` swaps
     * `database.default` from `central` to the tenant DB. A
     * constructor-captured `ConnectionInterface` is therefore permanently
     * pinned to `central` for the lifetime of the request. `DatabaseManager`
     * defers connection resolution to call time (`->connection()` re-reads
     * `database.default` on every call), so `store()`/`update()`'s
     * transactions correctly cover the tenant DB where `Partner::create()`
     * / `->update()` actually write.
     */
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxIdValidationService $taxIdValidationService,
        private readonly PartnerBankAccountService $partnerBankAccounts,
        private readonly BankAccountValidatorInterface $bankAccountValidator,
        private readonly DatabaseManager $db,
        private readonly PartnerReferenceCounter $partnerReferenceCounter,
    ) {}

    /**
     * List all partners for the current company with sorting, filtering, and aggregates.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $allowedSortColumns = ['name', 'created_at', 'receivable_balance', 'payable_balance', 'net_balance'];
        $sortParams = $this->getSortParams($request, $allowedSortColumns, 'name', 'asc');

        /** @var array<string, array{type: string, column?: string, columns?: array<string>, operator?: string, callback?: callable}> $filterConfig */
        $filterConfig = [
            'type' => [
                'type' => 'computed',
                'callback' => static function (Builder $query, string $value): void {
                    if ($value === 'customer') {
                        $query->whereIn('type', [PartnerType::Customer, PartnerType::Both]);
                    } elseif ($value === 'supplier') {
                        $query->whereIn('type', [PartnerType::Supplier, PartnerType::Both]);
                    } else {
                        $query->whereRaw('type = ?', [$value]);
                    }
                },
            ],
            'is_active' => [
                'type' => 'boolean',
                'column' => 'is_active',
            ],
            'search' => [
                'type' => 'text',
                'columns' => ['name', 'email', 'vat_number'],
            ],
            'has_balance' => [
                'type' => 'computed',
                'callback' => static function (Builder $query, string $value): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $query->where(static function (Builder $q): void {
                            $q->whereRaw('(receivable_balance - credit_balance) != 0')
                                ->orWhereRaw('payable_balance != 0');
                        });
                    }
                },
            ],
            'balance_min' => [
                'type' => 'range',
                'column' => 'receivable_balance',
                'operator' => '>=',
            ],
            'balance_max' => [
                'type' => 'range',
                'column' => 'receivable_balance',
                'operator' => '<=',
            ],
        ];

        $filters = $this->getFilterParams($request, $filterConfig);

        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = Partner::query()
            ->where('company_id', $companyId)
            ->selectRaw('*, '.Partner::netBalanceSqlExpression().' AS net_balance');

        $this->applyFilters($query, $filters, $filterConfig);
        $this->applySorting($query, $sortParams);

        /** @var array<string, array{type: string, column?: string, expression?: string, filter?: array<string, mixed>}> $aggregateConfig */
        $aggregateConfig = [
            'total_partners' => ['type' => 'count'],
            'total_active' => [
                'type' => 'count',
                'filter' => ['is_active' => true],
            ],
            'total_receivable' => [
                'type' => 'sum',
                'column' => 'receivable_balance',
            ],
            'total_payable' => [
                'type' => 'sum',
                'column' => 'payable_balance',
            ],
        ];

        $aggregates = $this->calculateAggregates($query, $aggregateConfig);

        $paginator = $query->paginate($perPage);

        // PartnerData::fromModel requires the bank-account validator, so the
        // rows are mapped here instead of via the trait's 1-arg fromModel path.
        $bankCountry = $this->companyContext->requireCompany()->country_code;
        $paginator->through(fn (Partner $p): PartnerData => PartnerData::fromModel(
            $p,
            $this->bankAccountValidator,
            $bankCountry,
        ));

        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, null, $aggregates)
        );
    }

    /**
     * Get a single partner.
     */
    public function show(Request $request, string $partner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->with('bankAccounts')
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => PartnerData::fromModel(
                $partnerModel,
                $this->bankAccountValidator,
                $this->companyContext->requireCompany()->country_code,
            ),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new partner.
     */
    public function store(CreatePartnerRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        unset($validated['bank_accounts']);

        /** @var User $actor */
        $actor = $request->user();
        $partner = $this->db->connection()->transaction(function () use ($validated, $tenantId, $companyId, $request, $actor, $company): Partner {
            $partner = Partner::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                ...$validated,
            ]);
            $this->partnerBankAccounts->sync(
                $partner,
                $request->bankAccounts(),
                $actor->id,
                $company->country_code,
            );

            return $partner;
        });

        event(new PartnerCreated(
            partnerId: $partner->id,
            tenantId: $tenantId,
            companyId: $companyId,
            name: $partner->name,
            type: $partner->type->value,
            email: $partner->email,
            phone: $partner->phone,
            createdAt: $partner->created_at->toIso8601String(),
        ));

        return response()->json([
            'data' => PartnerData::fromModel(
                $partner->load('bankAccounts'),
                $this->bankAccountValidator,
                $company->country_code,
            ),
            'meta' => [
                'bank_account_validation' => $request->bankAccountValidity(),
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing partner.
     */
    public function update(UpdatePartnerRequest $request, string $partner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $hasBankAccounts = array_key_exists('bank_accounts', $validated);
        unset($validated['bank_accounts']);

        // Skin fields are parapharmacy-only merchandising data. Mirror the
        // read-path vertical gate (PosCustomerMirrorResource) on the write path:
        // silently drop them for other verticals so a non-parapharmacy tenant
        // cannot persist them.
        if ($this->companyContext->requireCompany()->tenant->vertical !== Vertical::Parapharmacy) {
            unset($validated['skin_type'], $validated['skin_advice_note']);
        }

        $company = $this->companyContext->requireCompany();
        $this->db->connection()->transaction(function () use ($partnerModel, $validated, $hasBankAccounts, $request, $user, $company): void {
            $partnerModel->update($validated);
            if ($hasBankAccounts) {
                $this->partnerBankAccounts->sync(
                    $partnerModel,
                    $request->bankAccounts(),
                    $user->id,
                    $company->country_code,
                );
            }
        });

        $changes = $partnerModel->getChanges();
        unset($changes['updated_at']);

        if ($changes !== []) {
            event(new PartnerUpdated(
                partnerId: $partnerModel->id,
                tenantId: $partnerModel->tenant_id,
                companyId: $partnerModel->company_id,
                changes: $changes,
                updatedAt: $partnerModel->updated_at->toIso8601String(),
            ));
        }

        /** @var Partner $freshPartner */
        $freshPartner = $partnerModel->fresh(['bankAccounts']);

        return response()->json([
            'data' => PartnerData::fromModel(
                $freshPartner,
                $this->bankAccountValidator,
                $company->country_code,
            ),
            'meta' => [
                'bank_account_validation' => $request->bankAccountValidity(),
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a partner (soft delete).
     */
    public function destroy(Request $request, string $partner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // BUG-007: block the delete while the partner still carries financial
        // history. The partner is soft-deleted, so the DB FKs never fire and
        // the referencing rows would silently point at an invisible partner.
        $references = $this->partnerReferenceCounter->countFor($partnerModel->id);

        if ($references !== []) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_HAS_DOCUMENTS',
                    'message' => 'Cannot delete this partner: it is still referenced by financial records.',
                    'details' => $references,
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 409);
        }

        $partnerModel->delete();

        event(new PartnerDeleted(
            partnerId: $partnerModel->id,
            tenantId: $partnerModel->tenant_id,
            companyId: $partnerModel->company_id,
            deletedAt: $partnerModel->deleted_at->toIso8601String(),
        ));

        return response()->json(null, 204);
    }

    /**
     * Get contacts linked to a partner.
     */
    public function contacts(Request $request, string $partner): JsonResponse
    {
        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $contacts = $partnerModel->contacts()->get()->map(fn ($contact) => [
            'id' => $contact->id,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => $contact->full_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'job_title' => $contact->getAttribute('pivot')?->getAttribute('job_title'),
            'department' => $contact->getAttribute('pivot')?->getAttribute('department'),
            'is_primary' => (bool) ($contact->getAttribute('pivot')?->getAttribute('is_primary') ?? false),
            'is_invoice_contact' => (bool) ($contact->getAttribute('pivot')?->getAttribute('is_invoice_contact') ?? false),
            'is_delivery_contact' => (bool) ($contact->getAttribute('pivot')?->getAttribute('is_delivery_contact') ?? false),
        ]);

        return response()->json([
            'data' => $contacts,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Validate a partner's business registration / tax ID number.
     */
    public function validateTaxId(Request $request, string $partner): JsonResponse
    {
        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $countryCode = $partnerModel->country_code;
        $registrationNumber = $partnerModel->business_registration_number;

        if ($countryCode === null || $registrationNumber === null) {
            return response()->json([
                'error' => [
                    'code' => 'MISSING_DATA',
                    'message' => 'Partner must have both country_code and business_registration_number set.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        $result = $this->taxIdValidationService->validate($countryCode, $registrationNumber);

        return response()->json([
            'data' => $result->toArray(),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get tax status and exemption information for a partner
     */
    public function taxStatus(Request $request, string $partner): JsonResponse
    {
        $partnerModel = Partner::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $partner)
            ->first();

        if (! $partnerModel) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'tax_status' => $partnerModel->tax_status->value,
                'tax_status_label' => $partnerModel->tax_status->label(),
                'has_valid_exemption' => $partnerModel->hasValidTaxExemption(),
                'warnings' => $partnerModel->getTaxExemptionWarnings(),
                'exemption_reason' => $partnerModel->tax_exemption_reason,
                'exemption_valid_until' => $partnerModel->tax_exemption_valid_until?->format('Y-m-d'),
            ],
        ]);
    }
}
