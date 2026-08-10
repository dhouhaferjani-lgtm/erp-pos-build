<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Controllers;

use App\Modules\Company\Application\Services\CompanyFiscalIdentityService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\DTOs\CompanySettingsData;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Tenant\Presentation\Requests\UpdateCompanySettingsRequest;
use App\Modules\Tenant\Presentation\Requests\UploadLogoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * api.module-gating cluster — audit-attribution invariant.
 *
 * The actual settings update operates on Tenant::find($user->tenant_id)
 * (auth-user-anchored, already self-scoping). The earlier
 * $request->header('X-Company-Id') reads flowed only into
 * logAuditEvent's companyId parameter. Trusting the raw header for
 * audit attribution risks evidence-tampering: a user could perform
 * legitimate self-tenant settings changes but stamp the AuditEvent
 * with a foreign companyId, distorting forensic reconstruction.
 *
 * For NF525 + AdminAuditLog + two-tier hash chain compliance,
 * audit-trail integrity is independently load-bearing. This pin
 * derives audit companyId from the validated CompanyContext source.
 * The legacy `if ($companyId === null) return;` guard inside
 * logAuditEvent remains as defense-in-depth.
 */
class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CompanyFiscalIdentityService $companyFiscalIdentityService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Get company settings for the current tenant.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('settings.view')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view settings.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $company = Company::query()->find($this->companyContext->requireCompanyId());

        if ($company === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Company not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => CompanySettingsData::fromCompany($company),
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Update company settings for the current tenant.
     */
    public function update(UpdateCompanySettingsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $company = Company::query()->find($this->companyContext->requireCompanyId());

        if ($company === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Company not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validated();
        $changes = [];

        $fiscalIdentityAttributes = [];
        foreach ([
            'legal_name' => 'legal_name',
            'tax_id' => 'tax_id',
            'registration_number' => 'registration_number',
        ] as $requestKey => $attribute) {
            if (array_key_exists($requestKey, $validated)) {
                $value = $validated[$requestKey];
                $fiscalIdentityAttributes[$attribute] = is_string($value) ? $value : null;
            }
        }

        $fiscalIdentityChanges = $this->companyFiscalIdentityService->changedFields(
            $company,
            $fiscalIdentityAttributes,
        );
        $submittedIdentityChanges = $fiscalIdentityChanges;

        if (array_key_exists('country_code', $validated)) {
            $submittedIdentityChanges = array_replace(
                $submittedIdentityChanges,
                $this->companyFiscalIdentityService->changedFields(
                    $company,
                    ['country_code' => $validated['country_code']],
                ),
            );
        }

        if (array_key_exists('currency_code', $validated)) {
            $submittedIdentityChanges = array_replace(
                $submittedIdentityChanges,
                $this->companyFiscalIdentityService->changedFields(
                    $company,
                    ['currency' => $validated['currency_code']],
                ),
            );
        }

        $submittedAddress = $validated['address'] ?? null;
        if (is_array($submittedAddress) && array_key_exists('country', $submittedAddress)) {
            $submittedIdentityChanges = array_replace(
                $submittedIdentityChanges,
                $this->companyFiscalIdentityService->changedFields(
                    $company,
                    ['country_code' => $submittedAddress['country']],
                ),
            );
        }

        if ($submittedIdentityChanges !== [] && ! $user->can('settings.fiscal.update')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => __('company.identity.fiscal_permission_required'),
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        if (array_key_exists('country_code', $validated)) {
            $this->companyFiscalIdentityService->assertImmutableFieldsUnchanged(
                $company,
                ['country_code' => $validated['country_code']],
            );
        }

        if (array_key_exists('currency_code', $validated)) {
            $this->companyFiscalIdentityService->assertImmutableFieldsUnchanged(
                $company,
                ['currency' => $validated['currency_code']],
                ['currency' => 'currency_code'],
            );
        }

        if (is_array($submittedAddress) && array_key_exists('country', $submittedAddress)) {
            $this->companyFiscalIdentityService->assertImmutableFieldsUnchanged(
                $company,
                ['country_code' => $submittedAddress['country']],
                ['country_code' => 'address.country'],
            );
        }

        // Track changes for audit log
        $fieldsToUpdate = [
            'name' => 'name',
            'legal_name' => 'legal_name',
            'tax_id' => 'tax_id',
            'registration_number' => 'registration_number',
            'phone' => 'phone',
            'email' => 'email',
            'website' => 'website',
            'primary_color' => 'primary_color',
            'timezone' => 'timezone',
            'date_format' => 'date_format',
            'locale' => 'locale',
            'line_designation_override_enabled' => 'line_designation_override_enabled',
        ];

        $attributes = [];

        foreach ($fieldsToUpdate as $requestKey => $column) {
            if (array_key_exists($requestKey, $validated)) {
                $changes[$requestKey] = [
                    'old' => $company->{$column},
                    'new' => $validated[$requestKey],
                ];
                $attributes[$column] = $validated[$requestKey];
            }
        }

        // Handle address separately (nested array)
        if (array_key_exists('address', $validated)) {
            $address = $validated['address'] ?? [];
            $changes['address'] = [
                'old' => [
                    'street' => $company->address_street,
                    'city' => $company->address_city,
                    'postal_code' => $company->address_postal_code,
                    'country' => $company->country_code,
                ],
                'new' => $validated['address'],
            ];
            $attributes['address_street'] = $address['street'] ?? null;
            $attributes['address_city'] = $address['city'] ?? null;
            $attributes['address_postal_code'] = $address['postal_code'] ?? null;

        }

        DB::transaction(function () use ($attributes, $changes, $company, $fiscalIdentityChanges, $user): void {
            $company->update($attributes);

            if ($fiscalIdentityChanges !== []) {
                $this->auditService->record(
                    companyId: $company->id,
                    userId: $user->id,
                    eventType: 'company.fiscal_identity_updated',
                    aggregateType: 'company',
                    aggregateId: $company->id,
                    payload: ['changes' => $fiscalIdentityChanges],
                );
            }

            $this->logAuditEvent(
                eventType: 'tenant.settings_updated',
                aggregateId: $company->tenant_id,
                userId: $user->id,
                companyId: $this->companyContext->requireCompanyId(),
                payload: ['changes' => $changes]
            );
        });

        return response()->json([
            'data' => CompanySettingsData::fromCompany($company->refresh()),
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Upload a company logo.
     */
    public function uploadLogo(UploadLogoRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tenant = Tenant::find($user->tenant_id);

        if ($tenant === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Tenant not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        // Delete old logo if it exists
        if ($tenant->logo_path !== null) {
            Storage::disk('public')->delete($tenant->logo_path);
        }

        // Store the new logo
        $file = $request->file('logo');
        $path = $file->store('logos/'.$tenant->id, 'public');

        // Update tenant
        $tenant->update(['logo_path' => $path]);

        // Log audit event
        $this->logAuditEvent(
            eventType: 'tenant.logo_updated',
            aggregateId: $tenant->id,
            userId: $user->id,
            companyId: $this->companyContext->requireCompanyId(),
            payload: ['logo_path' => $path]
        );

        return response()->json([
            'data' => [
                'logo_url' => asset('storage/'.$path),
            ],
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Delete the company logo.
     */
    public function deleteLogo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('settings.update')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to update settings.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $tenant = Tenant::find($user->tenant_id);

        if ($tenant === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Tenant not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        // Check if logo exists
        if ($tenant->logo_path === null) {
            return response()->json([
                'data' => ['message' => 'No logo to delete'],
                'meta' => $this->getMeta($request),
            ]);
        }

        // Delete the logo file
        Storage::disk('public')->delete($tenant->logo_path);

        // Update tenant
        $tenant->update(['logo_path' => null]);

        // Log audit event
        $this->logAuditEvent(
            eventType: 'tenant.logo_deleted',
            aggregateId: $tenant->id,
            userId: $user->id,
            companyId: $this->companyContext->requireCompanyId(),
            payload: []
        );

        return response()->json([
            'data' => ['message' => 'Logo deleted successfully'],
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Get response metadata.
     *
     * @return array<string, mixed>
     */
    private function getMeta(Request $request): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
        ];
    }

    /**
     * Log an audit event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function logAuditEvent(
        string $eventType,
        string $aggregateId,
        string $userId,
        ?string $companyId,
        array $payload = []
    ): void {
        // Skip audit logging when no company context is available
        if ($companyId === null) {
            return;
        }

        $event = new AuditEvent(
            companyId: $companyId,
            userId: $userId,
            eventType: $eventType,
            aggregateType: 'tenant',
            aggregateId: $aggregateId,
            payload: $payload
        );
        $event->save();
    }
}
