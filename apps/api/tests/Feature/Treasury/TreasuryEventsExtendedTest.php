<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Events\ReconciliationCompleted;
use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for extended Treasury domain events.
 *
 * Verifies that all 8 new treasury events have correct structure,
 * event names, and audit payloads.
 */
final class TreasuryEventsExtendedTest extends TestCase
{
    // ── PaymentRefunded ─────────────────────────────────────────────

    public function test_payment_refunded_event_has_correct_properties(): void
    {
        $event = new PaymentRefunded(
            paymentId: 'pay-refund-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            originalPaymentId: 'pay-original-001',
            amount: '150.00',
            currency: 'EUR',
            reason: 'Customer request',
            refundedAt: '2026-03-24T10:00:00+00:00',
        );

        $this->assertSame('pay-refund-001', $event->paymentId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('pay-original-001', $event->originalPaymentId);
        $this->assertSame('150.00', $event->amount);
        $this->assertSame('EUR', $event->currency);
        $this->assertSame('Customer request', $event->reason);
        $this->assertSame('2026-03-24T10:00:00+00:00', $event->refundedAt);
    }

    public function test_payment_refunded_event_name(): void
    {
        $event = new PaymentRefunded(
            paymentId: 'pay-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            originalPaymentId: 'pay-original-001',
            amount: '100.00',
            currency: 'EUR',
            reason: 'Defective product',
            refundedAt: '2026-03-24T10:00:00+00:00',
        );

        $this->assertSame('treasury.payment.refunded', $event->getEventName());
    }

    public function test_payment_refunded_event_audit_payload(): void
    {
        $event = new PaymentRefunded(
            paymentId: 'pay-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            originalPaymentId: 'pay-original-001',
            amount: '100.00',
            currency: 'EUR',
            reason: 'Defective product',
            refundedAt: '2026-03-24T10:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('pay-001', $payload['payment_id']);
        $this->assertSame('pay-original-001', $payload['original_payment_id']);
        $this->assertSame('100.00', $payload['amount']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame('Defective product', $payload['reason']);
        $this->assertSame('2026-03-24T10:00:00+00:00', $payload['refunded_at']);
    }

    public function test_payment_refunded_event_dispatches(): void
    {
        Event::fake([PaymentRefunded::class]);

        event(new PaymentRefunded(
            paymentId: 'pay-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            originalPaymentId: 'pay-original-001',
            amount: '100.00',
            currency: 'EUR',
            reason: 'Test',
            refundedAt: '2026-03-24T10:00:00+00:00',
        ));

        Event::assertDispatched(PaymentRefunded::class);
    }

    public function test_payment_refunded_aggregate_uuid(): void
    {
        $event = new PaymentRefunded(
            paymentId: 'pay-refund-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            originalPaymentId: 'pay-original-001',
            amount: '100.00',
            currency: 'EUR',
            reason: 'Test',
            refundedAt: '2026-03-24T10:00:00+00:00',
        );

        $this->assertSame('pay-refund-001', $event->aggregateRootUuid());
    }

    // ── PaymentReversed ─────────────────────────────────────────────

    public function test_payment_reversed_event_has_correct_properties(): void
    {
        $event = new PaymentReversed(
            paymentId: 'pay-rev-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '200.00',
            currency: 'EUR',
            reversedAt: '2026-03-24T11:00:00+00:00',
        );

        $this->assertSame('pay-rev-001', $event->paymentId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('200.00', $event->amount);
        $this->assertSame('EUR', $event->currency);
        $this->assertSame('2026-03-24T11:00:00+00:00', $event->reversedAt);
    }

    public function test_payment_reversed_event_name(): void
    {
        $event = new PaymentReversed(
            paymentId: 'pay-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '200.00',
            currency: 'EUR',
            reversedAt: '2026-03-24T11:00:00+00:00',
        );

        $this->assertSame('treasury.payment.reversed', $event->getEventName());
    }

    public function test_payment_reversed_event_audit_payload(): void
    {
        $event = new PaymentReversed(
            paymentId: 'pay-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '200.00',
            currency: 'EUR',
            reversedAt: '2026-03-24T11:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('pay-001', $payload['payment_id']);
        $this->assertSame('200.00', $payload['amount']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame('2026-03-24T11:00:00+00:00', $payload['reversed_at']);
    }

    // ── InstrumentDeposited ─────────────────────────────────────────

    public function test_instrument_deposited_event_has_correct_properties(): void
    {
        $event = new InstrumentDeposited(
            instrumentId: 'inst-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            repositoryId: 'repo-001',
            amount: '500.00',
            depositedAt: '2026-03-24T12:00:00+00:00',
        );

        $this->assertSame('inst-001', $event->instrumentId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('repo-001', $event->repositoryId);
        $this->assertSame('500.00', $event->amount);
        $this->assertSame('2026-03-24T12:00:00+00:00', $event->depositedAt);
    }

    public function test_instrument_deposited_event_name(): void
    {
        $event = new InstrumentDeposited(
            instrumentId: 'inst-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            repositoryId: 'repo-001',
            amount: '500.00',
            depositedAt: '2026-03-24T12:00:00+00:00',
        );

        $this->assertSame('treasury.instrument.deposited', $event->getEventName());
    }

    public function test_instrument_deposited_event_audit_payload(): void
    {
        $event = new InstrumentDeposited(
            instrumentId: 'inst-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            repositoryId: 'repo-001',
            amount: '500.00',
            depositedAt: '2026-03-24T12:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('inst-001', $payload['instrument_id']);
        $this->assertSame('repo-001', $payload['repository_id']);
        $this->assertSame('500.00', $payload['amount']);
        $this->assertSame('2026-03-24T12:00:00+00:00', $payload['deposited_at']);
    }

    // ── InstrumentCleared ───────────────────────────────────────────

    public function test_instrument_cleared_event_has_correct_properties(): void
    {
        $event = new InstrumentCleared(
            instrumentId: 'inst-002',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '750.00',
            clearedAt: '2026-03-24T13:00:00+00:00',
        );

        $this->assertSame('inst-002', $event->instrumentId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('750.00', $event->amount);
        $this->assertSame('2026-03-24T13:00:00+00:00', $event->clearedAt);
    }

    public function test_instrument_cleared_event_name(): void
    {
        $event = new InstrumentCleared(
            instrumentId: 'inst-002',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '750.00',
            clearedAt: '2026-03-24T13:00:00+00:00',
        );

        $this->assertSame('treasury.instrument.cleared', $event->getEventName());
    }

    public function test_instrument_cleared_event_audit_payload(): void
    {
        $event = new InstrumentCleared(
            instrumentId: 'inst-002',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '750.00',
            clearedAt: '2026-03-24T13:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('inst-002', $payload['instrument_id']);
        $this->assertSame('750.00', $payload['amount']);
        $this->assertSame('2026-03-24T13:00:00+00:00', $payload['cleared_at']);
    }

    // ── InstrumentBounced ───────────────────────────────────────────

    public function test_instrument_bounced_event_has_correct_properties(): void
    {
        $event = new InstrumentBounced(
            instrumentId: 'inst-003',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '300.00',
            reason: 'Insufficient funds',
            bouncedAt: '2026-03-24T14:00:00+00:00',
        );

        $this->assertSame('inst-003', $event->instrumentId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('300.00', $event->amount);
        $this->assertSame('Insufficient funds', $event->reason);
        $this->assertSame('2026-03-24T14:00:00+00:00', $event->bouncedAt);
    }

    public function test_instrument_bounced_event_name(): void
    {
        $event = new InstrumentBounced(
            instrumentId: 'inst-003',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '300.00',
            reason: 'Insufficient funds',
            bouncedAt: '2026-03-24T14:00:00+00:00',
        );

        $this->assertSame('treasury.instrument.bounced', $event->getEventName());
    }

    public function test_instrument_bounced_event_audit_payload(): void
    {
        $event = new InstrumentBounced(
            instrumentId: 'inst-003',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '300.00',
            reason: 'Insufficient funds',
            bouncedAt: '2026-03-24T14:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('inst-003', $payload['instrument_id']);
        $this->assertSame('300.00', $payload['amount']);
        $this->assertSame('Insufficient funds', $payload['reason']);
        $this->assertSame('2026-03-24T14:00:00+00:00', $payload['bounced_at']);
    }

    // ── InstrumentTransferred ───────────────────────────────────────

    public function test_instrument_transferred_event_has_correct_properties(): void
    {
        $event = new InstrumentTransferred(
            instrumentId: 'inst-004',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            fromRepositoryId: 'repo-001',
            toRepositoryId: 'repo-002',
            amount: '1000.00',
            transferredAt: '2026-03-24T15:00:00+00:00',
        );

        $this->assertSame('inst-004', $event->instrumentId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('repo-001', $event->fromRepositoryId);
        $this->assertSame('repo-002', $event->toRepositoryId);
        $this->assertSame('1000.00', $event->amount);
        $this->assertSame('2026-03-24T15:00:00+00:00', $event->transferredAt);
    }

    public function test_instrument_transferred_event_name(): void
    {
        $event = new InstrumentTransferred(
            instrumentId: 'inst-004',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            fromRepositoryId: 'repo-001',
            toRepositoryId: 'repo-002',
            amount: '1000.00',
            transferredAt: '2026-03-24T15:00:00+00:00',
        );

        $this->assertSame('treasury.instrument.transferred', $event->getEventName());
    }

    public function test_instrument_transferred_event_audit_payload(): void
    {
        $event = new InstrumentTransferred(
            instrumentId: 'inst-004',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            fromRepositoryId: 'repo-001',
            toRepositoryId: 'repo-002',
            amount: '1000.00',
            transferredAt: '2026-03-24T15:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('inst-004', $payload['instrument_id']);
        $this->assertSame('repo-001', $payload['from_repository_id']);
        $this->assertSame('repo-002', $payload['to_repository_id']);
        $this->assertSame('1000.00', $payload['amount']);
        $this->assertSame('2026-03-24T15:00:00+00:00', $payload['transferred_at']);
    }

    // ── ReconciliationCompleted ─────────────────────────────────────

    public function test_reconciliation_completed_event_has_correct_properties(): void
    {
        $event = new ReconciliationCompleted(
            reconciliationId: 'recon-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            repositoryId: 'repo-001',
            matchedCount: 15,
            matchedTotal: '5000.00',
            completedAt: '2026-03-24T16:00:00+00:00',
        );

        $this->assertSame('recon-001', $event->reconciliationId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('repo-001', $event->repositoryId);
        $this->assertSame(15, $event->matchedCount);
        $this->assertSame('5000.00', $event->matchedTotal);
        $this->assertSame('2026-03-24T16:00:00+00:00', $event->completedAt);
    }

    public function test_reconciliation_completed_event_name(): void
    {
        $event = new ReconciliationCompleted(
            reconciliationId: 'recon-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            repositoryId: 'repo-001',
            matchedCount: 15,
            matchedTotal: '5000.00',
            completedAt: '2026-03-24T16:00:00+00:00',
        );

        $this->assertSame('treasury.reconciliation.completed', $event->getEventName());
    }

    public function test_reconciliation_completed_event_audit_payload(): void
    {
        $event = new ReconciliationCompleted(
            reconciliationId: 'recon-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            repositoryId: 'repo-001',
            matchedCount: 15,
            matchedTotal: '5000.00',
            completedAt: '2026-03-24T16:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('recon-001', $payload['reconciliation_id']);
        $this->assertSame('repo-001', $payload['repository_id']);
        $this->assertSame(15, $payload['matched_count']);
        $this->assertSame('5000.00', $payload['matched_total']);
        $this->assertSame('2026-03-24T16:00:00+00:00', $payload['completed_at']);
    }

    // ── RepositoryBalanceChanged ────────────────────────────────────

    public function test_repository_balance_changed_event_has_correct_properties(): void
    {
        $event = new RepositoryBalanceChanged(
            repositoryId: 'repo-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            previousBalance: '1000.00',
            newBalance: '1500.00',
            changeAmount: '500.00',
            currency: 'EUR',
            changedAt: '2026-03-24T17:00:00+00:00',
        );

        $this->assertSame('repo-001', $event->repositoryId);
        $this->assertSame('tenant-001', $event->tenantId);
        $this->assertSame('company-001', $event->companyId);
        $this->assertSame('1000.00', $event->previousBalance);
        $this->assertSame('1500.00', $event->newBalance);
        $this->assertSame('500.00', $event->changeAmount);
        $this->assertSame('EUR', $event->currency);
        $this->assertSame('2026-03-24T17:00:00+00:00', $event->changedAt);
    }

    public function test_repository_balance_changed_event_name(): void
    {
        $event = new RepositoryBalanceChanged(
            repositoryId: 'repo-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            previousBalance: '1000.00',
            newBalance: '1500.00',
            changeAmount: '500.00',
            currency: 'EUR',
            changedAt: '2026-03-24T17:00:00+00:00',
        );

        $this->assertSame('treasury.repository.balance_changed', $event->getEventName());
    }

    public function test_repository_balance_changed_event_audit_payload(): void
    {
        $event = new RepositoryBalanceChanged(
            repositoryId: 'repo-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            previousBalance: '1000.00',
            newBalance: '1500.00',
            changeAmount: '500.00',
            currency: 'EUR',
            changedAt: '2026-03-24T17:00:00+00:00',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('repo-001', $payload['repository_id']);
        $this->assertSame('1000.00', $payload['previous_balance']);
        $this->assertSame('1500.00', $payload['new_balance']);
        $this->assertSame('500.00', $payload['change_amount']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame('2026-03-24T17:00:00+00:00', $payload['changed_at']);
    }
}
