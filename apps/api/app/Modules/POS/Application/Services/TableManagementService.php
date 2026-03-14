<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\TableStatus;
use App\Modules\POS\Domain\Floor;
use App\Modules\POS\Domain\Table;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing POS floors and tables.
 *
 * Handles CRUD operations for floors and tables, as well as
 * table assignment/release for the order lifecycle.
 */
final class TableManagementService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    private function resolveTenantId(string $companyId): string
    {
        /** @var Company $company */
        $company = Company::findOrFail($companyId);

        return $company->tenant_id;
    }

    public function createFloor(string $name, int $position = 0): Floor
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->resolveTenantId($companyId);

        /** @var Floor $floor */
        $floor = Floor::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => $name,
            'position' => $position,
            'is_active' => true,
        ]);

        return $floor;
    }

    public function updateFloor(string $floorId, ?string $name, ?int $position, ?bool $isActive): Floor
    {
        /** @var Floor $floor */
        $floor = Floor::where('company_id', $this->companyContext->requireCompanyId())
            ->findOrFail($floorId);

        $data = [];
        if ($name !== null) {
            $data['name'] = $name;
        }
        if ($position !== null) {
            $data['position'] = $position;
        }
        if ($isActive !== null) {
            $data['is_active'] = $isActive;
        }

        $floor->update($data);

        return $floor->fresh() ?? $floor;
    }

    public function deleteFloor(string $floorId): void
    {
        /** @var Floor $floor */
        $floor = Floor::where('company_id', $this->companyContext->requireCompanyId())
            ->findOrFail($floorId);

        if ($floor->tables()->exists()) {
            throw new \RuntimeException('Cannot delete floor that has tables. Remove or reassign tables first.');
        }

        $floor->delete();
    }

    public function createTable(
        ?string $floorId,
        string $tableNumber,
        ?string $label,
        int $seats = 4,
        ?string $shape = null,
    ): Table {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->resolveTenantId($companyId);

        if ($floorId !== null) {
            Floor::where('company_id', $companyId)->findOrFail($floorId);
        }

        /** @var Table $table */
        $table = Table::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'floor_id' => $floorId,
            'table_number' => $tableNumber,
            'label' => $label,
            'seats' => $seats,
            'status' => TableStatus::Available,
            'shape' => $shape,
        ]);

        return $table->load('floor');
    }

    public function updateTable(
        string $tableId,
        ?string $floorId,
        ?string $tableNumber,
        ?string $label,
        ?int $seats,
        ?string $shape,
    ): Table {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var Table $table */
        $table = Table::where('company_id', $companyId)->findOrFail($tableId);

        $data = [];
        if ($floorId !== null) {
            Floor::where('company_id', $companyId)->findOrFail($floorId);
            $data['floor_id'] = $floorId;
        }
        if ($tableNumber !== null) {
            $data['table_number'] = $tableNumber;
        }
        if ($label !== null) {
            $data['label'] = $label;
        }
        if ($seats !== null) {
            $data['seats'] = $seats;
        }
        if ($shape !== null) {
            $data['shape'] = $shape;
        }

        $table->update($data);

        return $table->fresh(['floor']) ?? $table;
    }

    public function deleteTable(string $tableId): void
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var Table $table */
        $table = Table::where('company_id', $companyId)->findOrFail($tableId);

        if ($table->isOccupied()) {
            throw new \RuntimeException('Cannot delete an occupied table.');
        }

        $table->delete();
    }

    public function assignOrderToTable(string $tableId, string $orderId): Table
    {
        return DB::transaction(function () use ($tableId, $orderId): Table {
            /** @var Table $table */
            $table = Table::lockForUpdate()->findOrFail($tableId);

            if (! $table->isAvailable()) {
                throw new \RuntimeException('Table is not available for assignment.');
            }

            $table->update([
                'status' => TableStatus::Occupied,
                'current_order_id' => $orderId,
            ]);

            return $table->fresh() ?? $table;
        });
    }

    public function releaseTable(string $tableId): Table
    {
        return DB::transaction(function () use ($tableId): Table {
            /** @var Table $table */
            $table = Table::lockForUpdate()->findOrFail($tableId);

            $table->update([
                'status' => TableStatus::Available,
                'current_order_id' => null,
            ]);

            return $table->fresh() ?? $table;
        });
    }

    public function setTableStatus(string $tableId, TableStatus $status): Table
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var Table $table */
        $table = Table::where('company_id', $companyId)->findOrFail($tableId);

        $table->update([
            'status' => $status,
        ]);

        return $table->fresh() ?? $table;
    }
}
