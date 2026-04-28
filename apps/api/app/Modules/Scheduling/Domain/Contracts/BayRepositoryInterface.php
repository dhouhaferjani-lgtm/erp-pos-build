<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Bay;

/**
 * Persistence contract for the Bay aggregate. Implemented by
 * `Infrastructure\Persistence\EloquentBayRepository`.
 *
 * findForUpdate is required by AppointmentAuthoringService so concurrent
 * bay-reassign operations serialize against a single row lock.
 */
interface BayRepositoryInterface
{
    public function findById(string $id): ?Bay;

    /**
     * Pessimistic lock (`SELECT ... FOR UPDATE`) — caller must be inside a
     * DB transaction. Returns null if the bay does not exist.
     */
    public function findForUpdate(string $id): ?Bay;

    public function save(Bay $bay): Bay;

    /**
     * List active bays for a location, ordered by display_order.
     *
     * @return list<Bay>
     */
    public function listActiveForLocation(string $tenantId, string $companyId, string $locationId): array;

    /**
     * List ALL bays for a company (active + inactive), ordered by display_order.
     *
     * @return list<Bay>
     */
    public function findByCompany(string $companyId): array;

    /**
     * List active bays for a company, ordered by display_order — used by
     * capacity / availability queries which iterate all currently-bookable
     * resources for a company (regardless of specific location).
     *
     * @return list<Bay>
     */
    public function listActiveForCompany(string $companyId): array;
}
