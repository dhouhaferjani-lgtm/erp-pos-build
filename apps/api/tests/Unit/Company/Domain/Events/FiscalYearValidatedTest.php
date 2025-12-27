<?php

declare(strict_types=1);

namespace Tests\Unit\Company\Domain\Events;

use App\Modules\Company\Domain\Events\FiscalYearValidated;
use Tests\TestCase;

class FiscalYearValidatedTest extends TestCase
{
    public function test_it_can_be_instantiated_with_required_fields(): void
    {
        $event = new FiscalYearValidated(
            companyId: 'company-uuid',
            tenantId: 'tenant-uuid',
            fiscalYearStartMonth: 1,
            validatedBy: 'user-uuid',
            validatedAt: '2025-12-23T10:00:00Z'
        );

        $this->assertInstanceOf(FiscalYearValidated::class, $event);
        $this->assertEquals('company-uuid', $event->companyId);
        $this->assertEquals('tenant-uuid', $event->tenantId);
        $this->assertEquals(1, $event->fiscalYearStartMonth);
        $this->assertEquals('user-uuid', $event->validatedBy);
        $this->assertEquals('2025-12-23T10:00:00Z', $event->validatedAt);
    }

    public function test_it_has_correct_event_name(): void
    {
        $event = new FiscalYearValidated(
            companyId: 'company-uuid',
            tenantId: 'tenant-uuid',
            fiscalYearStartMonth: 1,
            validatedBy: 'user-uuid',
            validatedAt: '2025-12-23T10:00:00Z'
        );

        $this->assertEquals('company.fiscal_year.validated', $event->getEventName());
    }

    public function test_all_properties_are_readonly(): void
    {
        $event = new FiscalYearValidated(
            companyId: 'company-uuid',
            tenantId: 'tenant-uuid',
            fiscalYearStartMonth: 1,
            validatedBy: 'user-uuid',
            validatedAt: '2025-12-23T10:00:00Z'
        );

        // This test verifies that properties are readonly by checking reflection
        $reflection = new \ReflectionClass($event);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            if ($property->getName() !== 'aggregateId') {
                $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
            }
        }
    }
}
