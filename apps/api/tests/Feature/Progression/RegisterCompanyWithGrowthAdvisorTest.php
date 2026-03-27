<?php

declare(strict_types=1);

namespace Tests\Feature\Progression;

use App\Modules\Company\Domain\Events\CompanyCreated;
use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Progression\Infrastructure\Listeners\RegisterCompanyWithGrowthAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class RegisterCompanyWithGrowthAdvisorTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_company_with_growth_advisor(): void
    {
        $mockClient = $this->createMock(GrowthAdvisorClientInterface::class);
        $mockClient->expects($this->once())
            ->method('registerCompany')
            ->with($this->callback(function (array $data): bool {
                return $data['company_id'] === 'comp-uuid'
                    && $data['tenant_id'] === 'tenant-uuid'
                    && $data['country'] === 'TN';
            }))
            ->willReturn(['id' => 'comp-uuid', 'current_stage' => 'launch']);

        $listener = new RegisterCompanyWithGrowthAdvisor($mockClient);

        $event = new CompanyCreated(
            companyId: 'comp-uuid',
            tenantId: 'tenant-uuid',
            name: 'Test Coffee Shop',
            countryCode: 'TN',
            currency: 'TND',
            fiscalYearStartMonth: 1,
            createdBy: 'user-uuid',
            createdAt: now()->toIso8601String(),
        );

        $listener->handle($event);
    }

    public function test_fails_silently_when_service_unavailable(): void
    {
        $mockClient = $this->createMock(GrowthAdvisorClientInterface::class);
        $mockClient->method('registerCompany')->willReturn(null);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'Growth Advisor'));

        $listener = new RegisterCompanyWithGrowthAdvisor($mockClient);

        $event = new CompanyCreated(
            companyId: 'comp-uuid',
            tenantId: 'tenant-uuid',
            name: 'Test Shop',
            countryCode: 'TN',
            currency: 'TND',
            fiscalYearStartMonth: 1,
            createdBy: 'user-uuid',
            createdAt: now()->toIso8601String(),
        );

        // Should not throw
        $listener->handle($event);
    }
}
