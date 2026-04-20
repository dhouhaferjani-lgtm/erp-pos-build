<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\User;
use App\Modules\Vehicle\Application\DTOs\VehicleData;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PartnerVehiclesController extends Controller
{
    public function __construct(
        private readonly VehicleRepositoryInterface $vehicles,
    ) {}

    public function index(Request $request, string $partner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $perPageRaw = $request->query('per_page', '15');
        $perPage = is_numeric($perPageRaw) ? max(1, min(100, (int) $perPageRaw)) : 15;

        $paginator = $this->vehicles->paginateForOwner($user->tenant_id, $partner, $perPage);

        /** @var Collection<int, Vehicle> $items */
        $items = collect($paginator->items());

        return response()->json([
            'data' => $items->map(fn (Vehicle $v): VehicleData => VehicleData::fromModel($v))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
