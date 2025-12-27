<?php

declare(strict_types=1);

namespace Tests\Unit\Company\Domain\Events;

use App\Modules\Company\Domain\Events\FirstTransactionPosted;
use Tests\TestCase;

class FirstTransactionPostedTest extends TestCase
{
    public function test_it_can_be_instantiated_with_required_fields(): void
    {
        $event = new FirstTransactionPosted(
            companyId: 'company-uuid',
            tenantId: 'tenant-uuid',
            documentId: 'document-uuid',
            documentNumber: 'INV-2025-001',
            documentType: 'invoice',
            postedAt: '2025-12-23T10:00:00Z'
        );

        $this->assertInstanceOf(FirstTransactionPosted::class, $event);
        $this->assertEquals('company-uuid', $event->companyId);
        $this->assertEquals('tenant-uuid', $event->tenantId);
        $this->assertEquals('document-uuid', $event->documentId);
        $this->assertEquals('INV-2025-001', $event->documentNumber);
        $this->assertEquals('invoice', $event->documentType);
        $this->assertEquals('2025-12-23T10:00:00Z', $event->postedAt);
    }

    public function test_it_has_correct_event_name(): void
    {
        $event = new FirstTransactionPosted(
            companyId: 'company-uuid',
            tenantId: 'tenant-uuid',
            documentId: 'document-uuid',
            documentNumber: 'INV-2025-001',
            documentType: 'invoice',
            postedAt: '2025-12-23T10:00:00Z'
        );

        $this->assertEquals('company.first_transaction.posted', $event->getEventName());
    }

    public function test_all_properties_are_readonly(): void
    {
        $event = new FirstTransactionPosted(
            companyId: 'company-uuid',
            tenantId: 'tenant-uuid',
            documentId: 'document-uuid',
            documentNumber: 'INV-2025-001',
            documentType: 'invoice',
            postedAt: '2025-12-23T10:00:00Z'
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
