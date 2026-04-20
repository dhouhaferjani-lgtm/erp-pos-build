<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\TableManagementService;
use App\Modules\POS\Domain\Enums\TableStatus;
use App\Modules\POS\Domain\Floor;
use App\Modules\POS\Domain\Table;
use App\Modules\POS\Presentation\Requests\CreateFloorRequest;
use App\Modules\POS\Presentation\Requests\CreateTableRequest;
use App\Modules\POS\Presentation\Requests\SetTableStatusRequest;
use App\Modules\POS\Presentation\Requests\UpdateFloorRequest;
use App\Modules\POS\Presentation\Requests\UpdateTableRequest;
use App\Modules\POS\Presentation\Resources\FloorResource;
use App\Modules\POS\Presentation\Resources\TableResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class TableController
{
    public function __construct(
        private readonly TableManagementService $tableService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function indexFloors(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('pos.manage_tables');
        $companyId = $this->companyContext->requireCompanyId();

        $floors = Floor::where('company_id', $companyId)
            ->with('tables')
            ->orderBy('position')
            ->get();

        return FloorResource::collection($floors);
    }

    public function storeFloor(CreateFloorRequest $request): JsonResponse
    {
        Gate::authorize('pos.manage_tables');
        $floor = $this->tableService->createFloor(
            name: $request->validated('name'),
            position: (int) $request->validated('position', 0),
        );

        return (new FloorResource($floor))
            ->response()
            ->setStatusCode(201);
    }

    public function updateFloor(UpdateFloorRequest $request, string $id): FloorResource
    {
        Gate::authorize('pos.manage_tables');
        $floor = $this->tableService->updateFloor(
            floorId: $id,
            name: $request->validated('name'),
            position: $request->has('position') ? (int) $request->validated('position') : null,
            isActive: $request->has('is_active') ? (bool) $request->validated('is_active') : null,
        );

        return new FloorResource($floor);
    }

    public function destroyFloor(string $id): JsonResponse
    {
        Gate::authorize('pos.manage_tables');
        try {
            $this->tableService->deleteFloor($id);

            return response()->json(['message' => 'Floor deleted successfully.']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function indexTables(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('pos.operate_terminal');
        $companyId = $this->companyContext->requireCompanyId();

        $query = Table::where('company_id', $companyId)->with('floor');

        if ($request->has('floor_id')) {
            $query->where('floor_id', $request->input('floor_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $tables = $query->orderBy('table_number')->get();

        return TableResource::collection($tables);
    }

    public function storeTable(CreateTableRequest $request): JsonResponse
    {
        Gate::authorize('pos.manage_tables');
        $table = $this->tableService->createTable(
            floorId: $request->validated('floor_id'),
            tableNumber: $request->validated('table_number'),
            label: $request->validated('label'),
            seats: (int) $request->validated('seats', 4),
            shape: $request->validated('shape'),
        );

        return (new TableResource($table))
            ->response()
            ->setStatusCode(201);
    }

    public function updateTable(UpdateTableRequest $request, string $id): TableResource
    {
        Gate::authorize('pos.manage_tables');
        $table = $this->tableService->updateTable(
            tableId: $id,
            floorId: $request->validated('floor_id'),
            tableNumber: $request->validated('table_number'),
            label: $request->validated('label'),
            seats: $request->has('seats') ? (int) $request->validated('seats') : null,
            shape: $request->validated('shape'),
        );

        return new TableResource($table);
    }

    public function destroyTable(string $id): JsonResponse
    {
        Gate::authorize('pos.manage_tables');
        try {
            $this->tableService->deleteTable($id);

            return response()->json(['message' => 'Table deleted successfully.']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function releaseTable(string $id): TableResource
    {
        Gate::authorize('pos.operate_terminal');
        $table = $this->tableService->releaseTable($id);

        return new TableResource($table);
    }

    public function setStatus(SetTableStatusRequest $request, string $id): TableResource
    {
        Gate::authorize('pos.operate_terminal');
        $status = TableStatus::from($request->validated('status'));
        $table = $this->tableService->setTableStatus($id, $status);

        return new TableResource($table);
    }
}
