<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\User;
use App\Modules\Vehicle\Application\Commands\TransferVehicleOwnershipCommand;
use App\Modules\Vehicle\Application\DTOs\VehicleOwnershipData;
use App\Modules\Vehicle\Application\Services\VehicleOwnershipService;
use App\Modules\Vehicle\Domain\Contracts\VehicleOwnershipRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Presentation\Requests\TransferVehicleOwnershipRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VehicleOwnershipController extends Controller
{
    public function __construct(
        private readonly VehicleOwnershipService $service,
        private readonly VehicleOwnershipRepositoryInterface $ownerships,
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

        $history = $this->ownerships->findHistoryForVehicle($vehicleModel->id);

        return response()->json([
            'data' => $history->map(fn ($row): VehicleOwnershipData => VehicleOwnershipData::fromModel($row))->values(),
        ]);
    }

    public function store(TransferVehicleOwnershipRequest $request, string $vehicle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $vehicleModel = $this->vehicles->findByIdForTenant($vehicle, $user->tenant_id);
        if ($vehicleModel === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Vehicle not found']], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $reasonValue = $validated['reason_code'];
        $reason = $reasonValue instanceof OwnershipReason
            ? $reasonValue
            : OwnershipReason::from((string) $reasonValue);

        $occurredAtRaw = (string) $validated['occurred_at'];
        $occurredAt = new \DateTimeImmutable($occurredAtRaw);

        $notes = isset($validated['notes']) ? (string) $validated['notes'] : null;

        $newRow = $this->service->transfer(new TransferVehicleOwnershipCommand(
            vehicle_id: $vehicleModel->id,
            new_owner_partner_id: (string) $validated['new_owner_partner_id'],
            occurred_at: $occurredAt,
            reason: $reason,
            notes: $notes,
            actor_user_id: $user->id,
        ));

        return response()->json([
            'data' => VehicleOwnershipData::fromModel($newRow),
        ], 201);
    }
}
