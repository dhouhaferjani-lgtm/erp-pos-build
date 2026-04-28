<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Technician\Application\Contracts\TechnicianAvailabilityServiceInterface;
use App\Modules\Workshop\Technician\Application\DTOs\AvailabilityResultData;
use App\Modules\Workshop\Technician\Application\DTOs\TechnicianProfileData;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianProfileRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal read-side controller for the Technician submodule.
 *
 * Plan C scope: list + show + availability. Full authoring CRUD (create, update,
 * time-off approval, time-entry adjustment) is deferred to a follow-up patch —
 * the current scope unblocks Spec B (assignment) and Spec D (scheduler) by
 * providing the availability query and the list/show endpoints those UIs need.
 *
 * All endpoints are `auth:sanctum` + `SetPermissionsTeam` gated (see routes.php).
 * DTO-level PII/pay masking (see TechnicianProfileData::toArray) applies to each
 * response payload automatically — no controller-level filtering needed.
 */
final class TechnicianProfileController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TechnicianProfileRepositoryInterface $profiles,
        private readonly TechnicianAvailabilityServiceInterface $availability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var array{active_only?: bool, specialty?: SpecialtyCode} $filters */
        $filters = [];
        $activeOnly = $request->query('active_only');
        if ($activeOnly === '1' || $activeOnly === 'true') {
            $filters['active_only'] = true;
        }
        $specialtyRaw = $request->query('specialty');
        if (is_string($specialtyRaw) && $specialtyRaw !== '') {
            $specialty = SpecialtyCode::tryFrom($specialtyRaw);
            if ($specialty !== null) {
                $filters['specialty'] = $specialty;
            }
        }

        $profiles = $this->profiles->listForCompany($companyId, $filters);

        /** @var list<array<string, mixed>> $data */
        $data = $profiles
            ->map(static fn ($p): array => TechnicianProfileData::fromModel($p)->toArray())
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    public function show(string $id): JsonResponse
    {
        $profile = $this->profiles->findById($id);
        if ($profile === null) {
            return response()->json(['message' => 'Technician profile not found.'], 404);
        }

        return response()->json([
            'data' => TechnicianProfileData::fromModel($profile)->toArray(),
        ]);
    }

    public function available(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'technician_profile_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'required_specialty' => ['nullable', 'string'],
        ]);

        /** @var string $profileId */
        $profileId = $validated['technician_profile_id'];
        /** @var string $startsAtRaw */
        $startsAtRaw = $validated['starts_at'];
        /** @var int $durationMinutes */
        $durationMinutes = (int) $validated['duration_minutes'];
        /** @var string|null $specialtyRaw */
        $specialtyRaw = $validated['required_specialty'] ?? null;
        $specialty = $specialtyRaw !== null ? SpecialtyCode::tryFrom($specialtyRaw) : null;

        $startsAt = new \DateTimeImmutable($startsAtRaw);

        $result = $this->availability->isAvailable(
            profileId: $profileId,
            startsAt: $startsAt,
            durationMinutes: $durationMinutes,
            requiredSpecialty: $specialty,
        );

        return response()->json([
            'data' => AvailabilityResultData::fromResult($result)->toArray(),
        ]);
    }
}
