<?php

declare(strict_types=1);

namespace Tests\Feature\EventSourcing;

use App\Modules\Document\Domain\Events\InvoicePaid;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class EventStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_posted_event_class_exists(): void
    {
        $this->assertTrue(class_exists(InvoicePosted::class));
    }

    public function test_invoice_paid_event_class_exists(): void
    {
        $this->assertTrue(class_exists(InvoicePaid::class));
    }

    public function test_payment_recorded_event_class_exists(): void
    {
        $this->assertTrue(class_exists(PaymentRecorded::class));
    }

    public function test_can_store_invoice_posted_event(): void
    {
        $event = new InvoicePosted(
            invoiceId: 'inv-123',
            tenantId: 'tenant-456',
            companyId: 'company-789',
            documentNumber: 'INV-2025-0001',
            documentType: 'invoice',
            partnerId: 'partner-789',
            total: '1500.00',
            currency: 'TND',
            fiscalHash: hash('sha256', 'test-hash-seed'),
            chainSequence: 1,
            postedAt: now()->toIso8601String()
        );

        event($event);

        $this->assertDatabaseHas('stored_events', [
            'event_class' => InvoicePosted::class,
        ]);
    }

    public function test_can_store_payment_recorded_event(): void
    {
        $event = new PaymentRecorded(
            paymentId: 'pmt-123',
            tenantId: 'tenant-456',
            companyId: 'company-789',
            partnerId: 'partner-789',
            amount: '500.00',
            currency: 'TND',
            paymentMethodId: 'method-001',
            recordedAt: now()->toIso8601String()
        );

        event($event);

        $this->assertDatabaseHas('stored_events', [
            'event_class' => PaymentRecorded::class,
        ]);
    }

    public function test_stored_events_are_ordered_by_creation(): void
    {
        $event1 = new InvoicePosted(
            invoiceId: 'inv-001',
            tenantId: 'tenant-456',
            companyId: 'company-789',
            documentNumber: 'INV-2025-0001',
            documentType: 'invoice',
            partnerId: 'partner-789',
            total: '1000.00',
            currency: 'TND',
            fiscalHash: hash('sha256', 'test-hash-seed-1'),
            chainSequence: 1,
            postedAt: now()->toIso8601String()
        );

        $event2 = new InvoicePosted(
            invoiceId: 'inv-002',
            tenantId: 'tenant-456',
            companyId: 'company-789',
            documentNumber: 'INV-2025-0002',
            documentType: 'invoice',
            partnerId: 'partner-789',
            total: '2000.00',
            currency: 'TND',
            fiscalHash: hash('sha256', 'test-hash-seed-2'),
            chainSequence: 2,
            postedAt: now()->toIso8601String()
        );

        event($event1);
        event($event2);

        $storedEvents = EloquentStoredEvent::all();
        $this->assertCount(2, $storedEvents);
        $this->assertLessThan($storedEvents[1]->id, $storedEvents[0]->id);
    }

    public function test_event_contains_metadata(): void
    {
        $event = new InvoicePosted(
            invoiceId: 'inv-123',
            tenantId: 'tenant-456',
            companyId: 'company-789',
            documentNumber: 'INV-2025-0001',
            documentType: 'invoice',
            partnerId: 'partner-789',
            total: '1500.00',
            currency: 'TND',
            fiscalHash: hash('sha256', 'test-hash-seed'),
            chainSequence: 1,
            postedAt: now()->toIso8601String()
        );

        $event->setMetaData(['user_id' => 'user-123']);

        event($event);

        $storedEvent = EloquentStoredEvent::first();
        $this->assertNotNull($storedEvent);
        $this->assertArrayHasKey('user_id', $storedEvent->meta_data);
    }
}
