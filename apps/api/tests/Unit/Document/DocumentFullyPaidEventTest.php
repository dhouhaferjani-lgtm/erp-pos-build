<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use PHPUnit\Framework\TestCase;

class DocumentFullyPaidEventTest extends TestCase
{
    public function test_event_name_is_document_fully_paid(): void
    {
        $event = new DocumentFullyPaid(
            documentId: 'doc-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            documentNumber: 'INV-2026-0001',
            documentType: 'invoice',
            partnerId: 'partner-uuid',
            totalPaid: '1000.00',
            paidAt: '2026-03-02T12:00:00+00:00',
        );

        $this->assertEquals('document.fully_paid', $event->getEventName());
    }

    public function test_event_contains_all_properties(): void
    {
        $event = new DocumentFullyPaid(
            documentId: 'doc-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            documentNumber: 'CN-2026-0001',
            documentType: 'credit_note',
            partnerId: 'partner-uuid',
            totalPaid: '250.00',
            paidAt: '2026-03-02T15:30:00+00:00',
        );

        $this->assertEquals('doc-uuid', $event->documentId);
        $this->assertEquals('tenant-uuid', $event->tenantId);
        $this->assertEquals('company-uuid', $event->companyId);
        $this->assertEquals('CN-2026-0001', $event->documentNumber);
        $this->assertEquals('credit_note', $event->documentType);
        $this->assertEquals('partner-uuid', $event->partnerId);
        $this->assertEquals('250.00', $event->totalPaid);
        $this->assertEquals('2026-03-02T15:30:00+00:00', $event->paidAt);
    }

    public function test_aggregate_root_uuid_is_document_id(): void
    {
        $event = new DocumentFullyPaid(
            documentId: 'doc-123',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            documentNumber: 'INV-001',
            documentType: 'invoice',
            partnerId: 'partner-uuid',
            totalPaid: '500.00',
            paidAt: '2026-03-02T10:00:00+00:00',
        );

        $this->assertEquals('doc-123', $event->aggregateRootUuid());
    }

    public function test_occurred_at_is_set(): void
    {
        $event = new DocumentFullyPaid(
            documentId: 'doc-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            documentNumber: 'INV-001',
            documentType: 'invoice',
            partnerId: 'partner-uuid',
            totalPaid: '100.00',
            paidAt: '2026-03-02T10:00:00+00:00',
        );

        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt());
    }
}
