<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DeliveryNoteConfirmed;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePaid;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use App\Modules\Treasury\Domain\Payment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for the DomainEventSubscriber that captures domain events
 * and persists them to the audit log for compliance and fraud detection.
 */
class DomainEventSubscriberTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user-' . Str::random(8) . '@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => 'customer',
            'is_active' => true,
        ]);

        // Authenticate user so Auth::id() works in subscriber
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_invoice_posted_event_creates_audit_entry(): void
    {
        $invoiceId = Str::uuid()->toString();
        $documentNumber = 'INV-2025-0001';

        event(new InvoicePosted(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: $documentNumber,
            documentType: 'invoice',
            partnerId: $this->partner->id,
            total: '1000.00',
            currency: 'TND',
            fiscalHash: 'abc123hash',
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $invoiceId)
            ->where('event_type', 'invoice.posted')
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertEquals($this->company->id, $auditEvent->company_id);
        $this->assertEquals('Document', $auditEvent->aggregate_type);
        $this->assertEquals($invoiceId, $auditEvent->aggregate_id);
        $this->assertEquals('invoice.posted', $auditEvent->event_type);
        $this->assertArrayHasKey('document_number', $auditEvent->payload);
        $this->assertEquals($documentNumber, $auditEvent->payload['document_number']);
        $this->assertArrayHasKey('fiscal_hash', $auditEvent->payload);
        $this->assertEquals('abc123hash', $auditEvent->payload['fiscal_hash']);
    }

    public function test_invoice_cancelled_event_creates_audit_entry(): void
    {
        $invoiceId = Str::uuid()->toString();
        $documentNumber = 'INV-2025-0002';

        event(new InvoiceCancelled(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: $documentNumber,
            documentType: 'invoice',
            originalFiscalHash: 'original-hash-123',
            cancelledAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $invoiceId)
            ->where('event_type', 'invoice.cancelled')
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertEquals($this->company->id, $auditEvent->company_id);
        $this->assertEquals('Document', $auditEvent->aggregate_type);
        $this->assertEquals('invoice.cancelled', $auditEvent->event_type);
        $this->assertArrayHasKey('original_fiscal_hash', $auditEvent->payload);
        $this->assertEquals('original-hash-123', $auditEvent->payload['original_fiscal_hash']);
    }

    public function test_invoice_paid_event_creates_audit_entry(): void
    {
        $invoiceId = Str::uuid()->toString();
        $documentNumber = 'INV-2025-0003';

        event(new InvoicePaid(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: $documentNumber,
            partnerId: $this->partner->id,
            totalPaid: '1500.00',
            paidAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $invoiceId)
            ->where('event_type', 'invoice.paid')
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertEquals($this->company->id, $auditEvent->company_id);
        $this->assertEquals('Document', $auditEvent->aggregate_type);
        $this->assertEquals('invoice.paid', $auditEvent->event_type);
        $this->assertArrayHasKey('total_paid', $auditEvent->payload);
        $this->assertEquals('1500.00', $auditEvent->payload['total_paid']);
        $this->assertArrayHasKey('partner_id', $auditEvent->payload);
    }

    public function test_delivery_note_confirmed_event_creates_audit_entry(): void
    {
        $deliveryNoteId = Str::uuid()->toString();
        $documentNumber = 'DN-2025-0001';

        event(new DeliveryNoteConfirmed(
            deliveryNoteId: $deliveryNoteId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: $documentNumber,
            partnerId: $this->partner->id,
            total: '2500.00',
            currency: 'TND',
            fiscalHash: 'dn-hash-456',
            chainSequence: 5,
            confirmedAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $deliveryNoteId)
            ->where('event_type', 'delivery_note.confirmed')
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertEquals($this->company->id, $auditEvent->company_id);
        $this->assertEquals('Document', $auditEvent->aggregate_type);
        $this->assertEquals('delivery_note.confirmed', $auditEvent->event_type);
        $this->assertArrayHasKey('fiscal_hash', $auditEvent->payload);
        $this->assertEquals('dn-hash-456', $auditEvent->payload['fiscal_hash']);
        $this->assertArrayHasKey('chain_sequence', $auditEvent->payload);
        $this->assertEquals(5, $auditEvent->payload['chain_sequence']);
    }

    public function test_payment_recorded_event_creates_audit_entry(): void
    {
        $paymentId = Str::uuid()->toString();

        event(new PaymentRecorded(
            paymentId: $paymentId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            amount: '750.00',
            currency: 'TND',
            paymentMethodId: null,
            recordedAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $paymentId)
            ->where('event_type', 'payment.recorded')
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertEquals($this->company->id, $auditEvent->company_id);
        $this->assertEquals('Payment', $auditEvent->aggregate_type);
        $this->assertEquals('payment.recorded', $auditEvent->event_type);
        $this->assertArrayHasKey('amount', $auditEvent->payload);
        $this->assertEquals('750.00', $auditEvent->payload['amount']);
        $this->assertArrayHasKey('currency', $auditEvent->payload);
        $this->assertEquals('TND', $auditEvent->payload['currency']);
    }

    public function test_audit_events_include_user_id_when_authenticated(): void
    {
        $invoiceId = Str::uuid()->toString();

        event(new InvoicePosted(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: 'INV-2025-0004',
            documentType: 'invoice',
            partnerId: $this->partner->id,
            total: '100.00',
            currency: 'TND',
            fiscalHash: 'test-hash',
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $invoiceId)->first();

        $this->assertNotNull($auditEvent);
        $this->assertEquals($this->user->id, $auditEvent->user_id);
    }

    public function test_audit_events_have_tamper_proof_hash(): void
    {
        $invoiceId = Str::uuid()->toString();

        event(new InvoicePosted(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: 'INV-2025-0005',
            documentType: 'invoice',
            partnerId: $this->partner->id,
            total: '500.00',
            currency: 'TND',
            fiscalHash: 'test-hash-2',
            chainSequence: 2,
            postedAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $invoiceId)->first();

        $this->assertNotNull($auditEvent);
        $this->assertNotNull($auditEvent->event_hash);
        $this->assertEquals(64, strlen($auditEvent->event_hash)); // SHA-256 = 64 hex chars
    }

    public function test_audit_events_include_metadata(): void
    {
        $invoiceId = Str::uuid()->toString();

        event(new InvoicePosted(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: 'INV-2025-0006',
            documentType: 'invoice',
            partnerId: $this->partner->id,
            total: '800.00',
            currency: 'TND',
            fiscalHash: 'test-hash-3',
            chainSequence: 3,
            postedAt: now()->toIso8601String(),
        ));

        $auditEvent = AuditEvent::where('aggregate_id', $invoiceId)->first();

        $this->assertNotNull($auditEvent);
        $this->assertIsArray($auditEvent->metadata);
        $this->assertArrayHasKey('event_class', $auditEvent->metadata);
        $this->assertEquals(InvoicePosted::class, $auditEvent->metadata['event_class']);
        $this->assertArrayHasKey('occurred_at', $auditEvent->metadata);
    }

    public function test_multiple_events_for_same_document_create_separate_audit_entries(): void
    {
        $invoiceId = Str::uuid()->toString();
        $documentNumber = 'INV-2025-0007';

        // Post invoice
        event(new InvoicePosted(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: $documentNumber,
            documentType: 'invoice',
            partnerId: $this->partner->id,
            total: '1000.00',
            currency: 'TND',
            fiscalHash: 'hash-1',
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        ));

        // Pay invoice
        event(new InvoicePaid(
            invoiceId: $invoiceId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: $documentNumber,
            partnerId: $this->partner->id,
            totalPaid: '1000.00',
            paidAt: now()->toIso8601String(),
        ));

        $auditEvents = AuditEvent::where('aggregate_id', $invoiceId)->get();

        $this->assertCount(2, $auditEvents);
        $eventTypes = $auditEvents->pluck('event_type')->toArray();
        $this->assertContains('invoice.posted', $eventTypes);
        $this->assertContains('invoice.paid', $eventTypes);
    }

    public function test_audit_events_are_tenant_isolated(): void
    {
        // Create a second tenant and company
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant-' . Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $invoice1Id = Str::uuid()->toString();
        $invoice2Id = Str::uuid()->toString();

        // Event for original company
        event(new InvoicePosted(
            invoiceId: $invoice1Id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: 'INV-C1-001',
            documentType: 'invoice',
            partnerId: $this->partner->id,
            total: '100.00',
            currency: 'TND',
            fiscalHash: 'hash-c1',
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        ));

        // Event for other company
        event(new InvoicePosted(
            invoiceId: $invoice2Id,
            tenantId: $otherTenant->id,
            companyId: $otherCompany->id,
            documentNumber: 'INV-C2-001',
            documentType: 'invoice',
            partnerId: $this->partner->id,
            total: '200.00',
            currency: 'EUR',
            fiscalHash: 'hash-c2',
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        ));

        // Query events by company
        $company1Events = AuditEvent::where('company_id', $this->company->id)->get();
        $company2Events = AuditEvent::where('company_id', $otherCompany->id)->get();

        $this->assertCount(1, $company1Events);
        $this->assertCount(1, $company2Events);
        $this->assertEquals($invoice1Id, $company1Events->first()->aggregate_id);
        $this->assertEquals($invoice2Id, $company2Events->first()->aggregate_id);
    }
}
