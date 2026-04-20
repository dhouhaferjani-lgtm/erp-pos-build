<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician;

use App\Modules\Workshop\Technician\Application\Contracts\TechnicianAvailabilityServiceInterface;
use App\Modules\Workshop\Technician\Application\Services\TechnicianAvailabilityService;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianCertificationRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianProfileRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianTimeEntryRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianTimeOffRepositoryInterface;
use App\Modules\Workshop\Technician\Infrastructure\Persistence\EloquentTechnicianCertificationRepository;
use App\Modules\Workshop\Technician\Infrastructure\Persistence\EloquentTechnicianProfileRepository;
use App\Modules\Workshop\Technician\Infrastructure\Persistence\EloquentTechnicianTimeEntryRepository;
use App\Modules\Workshop\Technician\Infrastructure\Persistence\EloquentTechnicianTimeOffRepository;
use Tests\TestCase;

/**
 * Sanity-checks every contract defined by the Technician submodule resolves through
 * the service provider to its intended implementation. Catches missing bindings
 * before they surface as 500s in production.
 */
final class RepositoryBindingsTest extends TestCase
{
    public function test_availability_service_interface_resolves(): void
    {
        $this->assertInstanceOf(
            TechnicianAvailabilityService::class,
            $this->app->make(TechnicianAvailabilityServiceInterface::class),
        );
    }

    public function test_profile_repo_interface_resolves(): void
    {
        $this->assertInstanceOf(
            EloquentTechnicianProfileRepository::class,
            $this->app->make(TechnicianProfileRepositoryInterface::class),
        );
    }

    public function test_certification_repo_interface_resolves(): void
    {
        $this->assertInstanceOf(
            EloquentTechnicianCertificationRepository::class,
            $this->app->make(TechnicianCertificationRepositoryInterface::class),
        );
    }

    public function test_time_off_repo_interface_resolves(): void
    {
        $this->assertInstanceOf(
            EloquentTechnicianTimeOffRepository::class,
            $this->app->make(TechnicianTimeOffRepositoryInterface::class),
        );
    }

    public function test_time_entry_repo_interface_resolves(): void
    {
        $this->assertInstanceOf(
            EloquentTechnicianTimeEntryRepository::class,
            $this->app->make(TechnicianTimeEntryRepositoryInterface::class),
        );
    }
}
