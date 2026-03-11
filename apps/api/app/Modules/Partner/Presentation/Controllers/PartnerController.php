<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Application\DTOs\PartnerData;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Partner\Domain\Services\TaxIdValidationService;
use App\Modules\Partner\Presentation\Requests\CreatePartnerRequest;
use App\Modules\Partner\Presentation\Requests\UpdatePartnerRequest;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PartnerController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxIdValidationService $taxIdValidationService,
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
            ->selectRaw('*, (receivable_balance - credit_balance) AS net_balance');

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

        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, PartnerData::class, $aggregates)
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
            'data' => PartnerData::fromModel($partnerModel),
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

        $partner = Partner::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            ...$validated,
        ]);

        return response()->json([
            'data' => PartnerData::fromModel($partner),
            'meta' => [
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
        $partnerModel->update($validated);

        /** @var Partner $freshPartner */
        $freshPartner = $partnerModel->fresh();

        return response()->json([
            'data' => PartnerData::fromModel($freshPartner),
            'meta' => [
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

        $partnerModel->delete();

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
