<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\User;
use App\Modules\Vehicle\Application\Commands\LogVehicleMileageCommand;
use App\Modules\Vehicle\Application\DTOs\VehicleMileageReadingData;
use App\Modules\Vehicle\Application\Services\VehicleMileageService;
use App\Modules\Vehicle\Domain\Contracts\VehicleMileageRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Presentation\Requests\LogVehicleMileageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VehicleMileageController extends Controller
{
    public function __construct(
        private readonly VehicleMileageService $service,
        private readonly VehicleMileageRepositoryInterface $mileage,
        private readonly VehicleRepositoryInterface $vehicles,
    ) {}

    public function index(Request $request, string $vehicle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $vehicleModel = $this->vehicles->findByIdForTenant($vehicle, $user->tenant_id);
        if ($vehicleModel === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Vehicle not found']], 404);
        }

        $limitRaw = $request->query('limit', '50');
        $limit = is_numeric($limitRaw) ? max(1, min(200, (int) $limitRaw)) : 50;
        $readings = $this->mileage->recentForVehicle($vehicleModel->id, $limit);

        return response()->json([
            'data' => $readings->map(fn ($r): VehicleMileageReadingData => VehicleMileageReadingData::fromModel($r))->values(),
        ]);
    }

    public function store(LogVehicleMileageRequest $request, string $vehicle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $vehicleModel = $this->vehicles->findByIdForTenant($vehicle, $user->tenant_id);
        if ($vehicleModel === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Vehicle not found']], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $sourceValue = $validated['source'];
        $source = $sourceValue instanceof MileageSource
            ? $sourceValue
            : MileageSource::from((string) $sourceValue);

        $recordedAt = new \DateTimeImmutable((string) $validated['recorded_at']);
        $notes = isset($validated['notes']) ? (string) $validated['notes'] : null;
        $contextDocumentId = isset($validated['context_document_id']) ? (string) $validated['context_document_id'] : null;
        $contextWorkOrderId = isset($validated['context_work_order_id']) ? (string) $validated['context_work_order_id'] : null;

        $reading = $this->service->log(new LogVehicleMileageCommand(
            vehicle_id: $vehicleModel->id,
            mileage: (int) $validated['mileage'],
            recorded_at: $recordedAt,
            source: $source,
            context_document_id: $contextDocumentId,
            context_work_order_id: $contextWorkOrderId,
            recorded_by_user_id: $user->id,
            notes: $notes,
        ));

        return response()->json([
            'data' => VehicleMileageReadingData::fromModel($reading),
        ], 201);
    }
}
