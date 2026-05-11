<?php

declare(strict_types=1);

namespace App\Modules\Contact\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Contact\Application\DTOs\ContactData;
use App\Modules\Contact\Application\Services\ContactService;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Contact\Presentation\Requests\CreateContactRequest;
use App\Modules\Contact\Presentation\Requests\UpdateContactRequest;
use App\Shared\Presentation\Validation\ScopedExists;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContactController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ContactService $contactService,
    ) {}

    /**
     * List all contacts for the current company with sorting, filtering, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $allowedSortColumns = ['first_name', 'last_name', 'email', 'created_at'];
        $sortParams = $this->getSortParams($request, $allowedSortColumns, 'first_name', 'asc');

        /** @var array<string, array{type: string, column?: string, columns?: array<string>, operator?: string, callback?: callable}> $filterConfig */
        $filterConfig = [
            'search' => [
                'type' => 'text',
                'columns' => ['first_name', 'last_name', 'phone', 'email'],
            ],
            'is_active' => [
                'type' => 'boolean',
                'column' => 'is_active',
            ],
            'party_id' => [
                'type' => 'computed',
                'callback' => static function (Builder $query, string $value): void {
                    $query->whereHas('parties', function (Builder $q) use ($value): void {
                        $q->where('partners.id', $value);
                    });
                },
            ],
        ];

        $filters = $this->getFilterParams($request, $filterConfig);

        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = Contact::query()
            ->where('company_id', $companyId)
            ->with('parties');

        $this->applyFilters($query, $filters, $filterConfig);
        $this->applySorting($query, $sortParams);

        $paginator = $query->paginate($perPage);

        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, ContactData::class)
        );
    }

    /**
     * Create a new contact.
     */
    public function store(CreateContactRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $partyId = $validated['party_id'] ?? null;
        $jobTitle = $validated['job_title'] ?? null;
        $isPrimary = (bool) ($validated['is_primary'] ?? false);

        unset($validated['party_id'], $validated['job_title'], $validated['is_primary']);

        $contact = $this->contactService->create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            ...$validated,
        ]);

        if ($partyId !== null) {
            $this->contactService->linkToParty(
                contact: $contact,
                partyId: $partyId,
                jobTitle: $jobTitle,
                department: null,
                isPrimary: $isPrimary,
            );
        }

        $contact->load('parties');

        return response()->json([
            'data' => ContactData::fromModel($contact),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Get a single contact with party associations.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $contact = Contact::where('tenant_id', $this->companyContext->requireCompany()->tenant_id)
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $id)
            ->with('parties')
            ->first();

        if (! $contact) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_NOT_FOUND',
                    'message' => 'Contact not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => ContactData::fromModel($contact),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Update an existing contact.
     */
    public function update(UpdateContactRequest $request, string $id): JsonResponse
    {
        $contact = Contact::where('tenant_id', $this->companyContext->requireCompany()->tenant_id)
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $id)
            ->first();

        if (! $contact) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_NOT_FOUND',
                    'message' => 'Contact not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $contact = $this->contactService->update($contact, $validated);
        $contact->load('parties');

        return response()->json([
            'data' => ContactData::fromModel($contact),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a contact (soft delete).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $contact = Contact::where('tenant_id', $this->companyContext->requireCompany()->tenant_id)
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $id)
            ->first();

        if (! $contact) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_NOT_FOUND',
                    'message' => 'Contact not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $contact->delete();

        return response()->json(null, 204);
    }

    /**
     * Link a contact to a party (partner).
     */
    public function linkParty(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $contact = Contact::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (! $contact) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_NOT_FOUND',
                    'message' => 'Contact not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'party_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'job_title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $partyContact = $this->contactService->linkToParty(
            contact: $contact,
            partyId: $validated['party_id'],
            jobTitle: $validated['job_title'] ?? null,
            department: $validated['department'] ?? null,
            isPrimary: (bool) ($validated['is_primary'] ?? false),
        );

        $contact->load('parties');

        return response()->json([
            'data' => ContactData::fromModel($contact),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Unlink a contact from a party (partner).
     */
    public function unlinkParty(Request $request, string $id, string $partyId): JsonResponse
    {
        $contact = Contact::where('tenant_id', $this->companyContext->requireCompany()->tenant_id)
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $id)
            ->first();

        if (! $contact) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_NOT_FOUND',
                    'message' => 'Contact not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $this->contactService->unlinkFromParty($contact, $partyId);

        return response()->json(null, 204);
    }
}
