<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Technician\Application\DTOs\TechnicianCertificationData;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianProfileRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use App\Modules\Workshop\Technician\Presentation\Requests\StoreCertificationRequest;
use App\Modules\Workshop\Technician\Presentation\Requests\UpdateCertificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * CRUD controller for a technician's professional certifications.
 *
 * Authorization: reads require `workshop.technicians.view`; writes require
 * `workshop.technicians.manage_certifications`. Both are enforced at the
 * route level (`can:` middleware) and at the FormRequest level (defense in
 * depth for programmatic dispatch).
 */
final class TechnicianCertificationController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TechnicianProfileRepositoryInterface $profiles,
    ) {}

    public function index(string $technicianId): JsonResponse
    {
        if (! Str::isUuid($technicianId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        $certifications = TechnicianCertification::query()
            ->where('technician_profile_id', $technicianId)
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->get();

        /** @var list<array<string, mixed>> $data */
        $data = $certifications
            ->map(static fn (TechnicianCertification $c): array => TechnicianCertificationData::fromModel($c)->toArray())
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreCertificationRequest $request, string $technicianId): JsonResponse
    {
        if (! Str::isUuid($technicianId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        /** @var array{certification_name: string, issuing_body?: string|null, certificate_number?: string|null, issued_at?: string|null, expires_at?: string|null, notes?: string|null} $validated */
        $validated = $request->validated();

        $cert = TechnicianCertification::query()->create([
            'tenant_id' => $profile->tenant_id,
            'technician_profile_id' => $profile->id,
            'certification_name' => $validated['certification_name'],
            'issuing_body' => $validated['issuing_body'] ?? null,
            'certificate_number' => $validated['certificate_number'] ?? null,
            'issued_at' => $validated['issued_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(
            ['data' => TechnicianCertificationData::fromModel($cert)->toArray()],
            201,
        );
    }

    public function update(
        UpdateCertificationRequest $request,
        string $technicianId,
        string $certificationId,
    ): JsonResponse {
        if (! Str::isUuid($technicianId) || ! Str::isUuid($certificationId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        /** @var TechnicianCertification|null $cert */
        $cert = TechnicianCertification::query()
            ->where('technician_profile_id', $technicianId)
            ->where('id', $certificationId)
            ->first();
        if ($cert === null) {
            abort(404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $cert->fill($validated);
        $cert->save();

        return response()->json([
            'data' => TechnicianCertificationData::fromModel($cert->refresh())->toArray(),
        ]);
    }

    public function destroy(string $technicianId, string $certificationId): JsonResponse
    {
        if (! Str::isUuid($technicianId) || ! Str::isUuid($certificationId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        $deleted = TechnicianCertification::query()
            ->where('technician_profile_id', $technicianId)
            ->where('id', $certificationId)
            ->delete();

        if ($deleted === 0) {
            abort(404);
        }

        return response()->json(null, 204);
    }
}
