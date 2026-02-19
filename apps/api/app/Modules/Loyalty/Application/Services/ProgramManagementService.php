<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\DTOs\LoyaltyProgramData;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Events\ProgramActivated;
use App\Modules\Loyalty\Domain\Events\ProgramDeactivated;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application service for managing loyalty programs
 *
 * Handles CRUD operations and program lifecycle management
 */
final readonly class ProgramManagementService
{
    public function __construct(
        private LoyaltyProgramRepositoryInterface $programRepository,
    ) {}

    /**
     * Create a new loyalty program
     *
     * @param  array<string, mixed>  $data  Program data
     */
    public function createProgram(array $data): LoyaltyProgramData
    {
        return DB::transaction(function () use ($data) {
            // Validate required fields
            if (empty($data['name'])) {
                throw new InvalidArgumentException('Program name is required');
            }

            if (empty($data['program_type'])) {
                throw new InvalidArgumentException('Program type is required');
            }

            // Create program
            $program = new LoyaltyProgram([
                'tenant_id' => $data['tenant_id'] ?? null,
                'company_ids' => $data['company_ids'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'program_type' => $data['program_type'],
                'status' => $data['status'] ?? ProgramStatus::Draft,
                'currency' => $data['currency'] ?? 'TND',
                'points_expiry_months' => $data['points_expiry_months'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'terms_and_conditions' => $data['terms_and_conditions'] ?? null,
                'welcome_bonus_points' => $data['welcome_bonus_points'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $program = $this->programRepository->save($program);

            return LoyaltyProgramData::fromModel($program);
        });
    }

    /**
     * Update an existing loyalty program
     *
     * @param  string  $programId  Program to update
     * @param  array<string, mixed>  $data  Update data
     */
    public function updateProgram(string $programId, array $data): LoyaltyProgramData
    {
        $program = $this->programRepository->findById($programId);
        if ($program === null) {
            throw new InvalidArgumentException("Program with ID {$programId} not found");
        }

        return DB::transaction(function () use ($program, $data) {
            // Update allowed fields
            if (isset($data['name'])) {
                $program->name = $data['name'];
            }

            if (isset($data['description'])) {
                $program->description = $data['description'];
            }

            if (isset($data['currency'])) {
                $program->currency = $data['currency'];
            }

            if (isset($data['points_expiry_months'])) {
                $program->points_expiry_months = $data['points_expiry_months'];
            }

            if (isset($data['start_date'])) {
                $program->start_date = $data['start_date'];
            }

            if (isset($data['end_date'])) {
                $program->end_date = $data['end_date'];
            }

            if (isset($data['terms_and_conditions'])) {
                $program->terms_and_conditions = $data['terms_and_conditions'];
            }

            if (isset($data['welcome_bonus_points'])) {
                $program->welcome_bonus_points = $data['welcome_bonus_points'];
            }

            $program = $this->programRepository->save($program);

            return LoyaltyProgramData::fromModel($program);
        });
    }

    /**
     * Get a loyalty program by ID
     *
     * @param  string  $programId  Program ID
     */
    public function getProgram(string $programId): LoyaltyProgramData
    {
        $program = $this->programRepository->findById($programId);
        if ($program === null) {
            throw new InvalidArgumentException("Program with ID {$programId} not found");
        }

        return LoyaltyProgramData::fromModel($program);
    }

    /**
     * Get all loyalty programs for a tenant
     *
     * @param  string  $tenantId  Tenant ID
     * @return Collection<int, LoyaltyProgramData>
     */
    public function getProgramsByTenant(string $tenantId): Collection
    {
        $programs = $this->programRepository->findByTenant($tenantId);

        return $programs->map(fn (LoyaltyProgram $program) => LoyaltyProgramData::fromModel($program));
    }

    /**
     * Get all active loyalty programs for a tenant
     *
     * @return Collection<int, LoyaltyProgramData>
     */
    public function getActivePrograms(string $tenantId): Collection
    {
        $programs = $this->programRepository->findByTenantAndStatus($tenantId, ProgramStatus::Active);

        return $programs->map(fn (LoyaltyProgram $program) => LoyaltyProgramData::fromModel($program));
    }

    /**
     * Activate a loyalty program
     *
     * @param  string  $programId  Program to activate
     */
    public function activateProgram(string $programId): LoyaltyProgramData
    {
        $program = $this->programRepository->findById($programId);
        if ($program === null) {
            throw new InvalidArgumentException("Program with ID {$programId} not found");
        }

        if ($program->status === ProgramStatus::Active) {
            // Already active, return as-is
            return LoyaltyProgramData::fromModel($program);
        }

        return DB::transaction(function () use ($program) {
            $program->status = ProgramStatus::Active;
            $program = $this->programRepository->save($program);

            // Dispatch event
            event(new ProgramActivated(
                programId: $program->id,
                tenantId: $program->tenant_id,
                programName: $program->name,
                activatedAt: now()->toIso8601String(),
            ));

            return LoyaltyProgramData::fromModel($program);
        });
    }

    /**
     * Deactivate a loyalty program
     *
     * @param  string  $programId  Program to deactivate
     */
    public function deactivateProgram(string $programId): LoyaltyProgramData
    {
        $program = $this->programRepository->findById($programId);
        if ($program === null) {
            throw new InvalidArgumentException("Program with ID {$programId} not found");
        }

        if ($program->status !== ProgramStatus::Active) {
            // Already inactive, return as-is
            return LoyaltyProgramData::fromModel($program);
        }

        return DB::transaction(function () use ($program) {
            $program->status = ProgramStatus::Paused;
            $program = $this->programRepository->save($program);

            // Dispatch event
            event(new ProgramDeactivated(
                programId: $program->id,
                tenantId: $program->tenant_id,
                programName: $program->name,
                deactivatedAt: now()->toIso8601String(),
            ));

            return LoyaltyProgramData::fromModel($program);
        });
    }

    /**
     * Delete a loyalty program
     *
     * @param  string  $programId  Program to delete
     */
    public function deleteProgram(string $programId): bool
    {
        $program = $this->programRepository->findById($programId);
        if ($program === null) {
            throw new InvalidArgumentException("Program with ID {$programId} not found");
        }

        // Prevent deletion of active programs
        if ($program->status === ProgramStatus::Active) {
            throw new InvalidArgumentException('Cannot delete an active program. Deactivate it first.');
        }

        return DB::transaction(function () use ($programId) {
            return $this->programRepository->delete($programId);
        });
    }
}
