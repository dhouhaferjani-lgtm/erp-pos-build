<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Events\AccountCreated;
use App\Modules\Accounting\Domain\Events\AccountUpdated;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\Events\OpeningBalancePosted;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for the 4 new Accounting domain events.
 *
 * These tests verify event construction, event names, and audit payloads
 * without requiring database interaction (unit-style).
 */
final class AccountingEventsExtendedTest extends TestCase
{
    // ─── JournalEntryPosted ───────────────────────────────────────────

    public function test_journal_entry_posted_event_has_correct_event_name(): void
    {
        $event = new JournalEntryPosted(
            entryId: 'entry-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryNumber: 'JE-2026-000001',
            totalDebit: '1000.00',
            totalCredit: '1000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('accounting.journal_entry.posted', $event->getEventName());
    }

    public function test_journal_entry_posted_event_has_correct_aggregate_id(): void
    {
        $event = new JournalEntryPosted(
            entryId: 'entry-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryNumber: 'JE-2026-000001',
            totalDebit: '1000.00',
            totalCredit: '1000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('entry-uuid', $event->aggregateRootUuid());
    }

    public function test_journal_entry_posted_event_has_correct_audit_payload(): void
    {
        $event = new JournalEntryPosted(
            entryId: 'entry-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryNumber: 'JE-2026-000001',
            totalDebit: '1000.00',
            totalCredit: '1000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('entry-uuid', $payload['entry_id']);
        $this->assertSame('JE-2026-000001', $payload['entry_number']);
        $this->assertSame('1000.00', $payload['total_debit']);
        $this->assertSame('1000.00', $payload['total_credit']);
        $this->assertSame('2026-03-24T12:00:00Z', $payload['posted_at']);
    }

    public function test_journal_entry_posted_event_can_be_dispatched(): void
    {
        Event::fake([JournalEntryPosted::class]);

        $event = new JournalEntryPosted(
            entryId: 'entry-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryNumber: 'JE-2026-000001',
            totalDebit: '1000.00',
            totalCredit: '1000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        event($event);

        Event::assertDispatched(JournalEntryPosted::class, function (JournalEntryPosted $e): bool {
            return $e->entryId === 'entry-uuid'
                && $e->getEventName() === 'accounting.journal_entry.posted';
        });
    }

    // ─── OpeningBalancePosted ─────────────────────────────────────────

    public function test_opening_balance_posted_event_has_correct_event_name(): void
    {
        $event = new OpeningBalancePosted(
            batchId: 'batch-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryCount: 5,
            totalAmount: '50000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('accounting.opening_balance.posted', $event->getEventName());
    }

    public function test_opening_balance_posted_event_has_correct_aggregate_id(): void
    {
        $event = new OpeningBalancePosted(
            batchId: 'batch-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryCount: 5,
            totalAmount: '50000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('batch-uuid', $event->aggregateRootUuid());
    }

    public function test_opening_balance_posted_event_has_correct_audit_payload(): void
    {
        $event = new OpeningBalancePosted(
            batchId: 'batch-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryCount: 5,
            totalAmount: '50000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('batch-uuid', $payload['batch_id']);
        $this->assertSame(5, $payload['entry_count']);
        $this->assertSame('50000.00', $payload['total_amount']);
        $this->assertSame('2026-03-24T12:00:00Z', $payload['posted_at']);
    }

    public function test_opening_balance_posted_event_can_be_dispatched(): void
    {
        Event::fake([OpeningBalancePosted::class]);

        $event = new OpeningBalancePosted(
            batchId: 'batch-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            entryCount: 5,
            totalAmount: '50000.00',
            postedAt: '2026-03-24T12:00:00Z',
        );

        event($event);

        Event::assertDispatched(OpeningBalancePosted::class, function (OpeningBalancePosted $e): bool {
            return $e->batchId === 'batch-uuid'
                && $e->getEventName() === 'accounting.opening_balance.posted';
        });
    }

    // ─── AccountCreated ──────────────────────────────────────────────

    public function test_account_created_event_has_correct_event_name(): void
    {
        $event = new AccountCreated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            code: '411000',
            name: 'Accounts Receivable',
            type: 'asset',
            createdAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('accounting.account.created', $event->getEventName());
    }

    public function test_account_created_event_has_correct_aggregate_id(): void
    {
        $event = new AccountCreated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            code: '411000',
            name: 'Accounts Receivable',
            type: 'asset',
            createdAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('account-uuid', $event->aggregateRootUuid());
    }

    public function test_account_created_event_has_correct_audit_payload(): void
    {
        $event = new AccountCreated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            code: '411000',
            name: 'Accounts Receivable',
            type: 'asset',
            createdAt: '2026-03-24T12:00:00Z',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('account-uuid', $payload['account_id']);
        $this->assertSame('411000', $payload['code']);
        $this->assertSame('Accounts Receivable', $payload['name']);
        $this->assertSame('asset', $payload['type']);
        $this->assertSame('2026-03-24T12:00:00Z', $payload['created_at']);
    }

    public function test_account_created_event_can_be_dispatched(): void
    {
        Event::fake([AccountCreated::class]);

        $event = new AccountCreated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            code: '411000',
            name: 'Accounts Receivable',
            type: 'asset',
            createdAt: '2026-03-24T12:00:00Z',
        );

        event($event);

        Event::assertDispatched(AccountCreated::class, function (AccountCreated $e): bool {
            return $e->accountId === 'account-uuid'
                && $e->getEventName() === 'accounting.account.created';
        });
    }

    // ─── AccountUpdated ──────────────────────────────────────────────

    public function test_account_updated_event_has_correct_event_name(): void
    {
        $event = new AccountUpdated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            changes: ['name' => 'Updated Name', 'is_active' => false],
            updatedAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('accounting.account.updated', $event->getEventName());
    }

    public function test_account_updated_event_has_correct_aggregate_id(): void
    {
        $event = new AccountUpdated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            changes: ['name' => 'Updated Name'],
            updatedAt: '2026-03-24T12:00:00Z',
        );

        $this->assertSame('account-uuid', $event->aggregateRootUuid());
    }

    public function test_account_updated_event_has_correct_audit_payload(): void
    {
        $changes = ['name' => 'Updated Name', 'is_active' => false];

        $event = new AccountUpdated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            changes: $changes,
            updatedAt: '2026-03-24T12:00:00Z',
        );

        $payload = $event->getAuditPayload();

        $this->assertSame('account-uuid', $payload['account_id']);
        $this->assertSame($changes, $payload['changes']);
        $this->assertSame('2026-03-24T12:00:00Z', $payload['updated_at']);
    }

    public function test_account_updated_event_can_be_dispatched(): void
    {
        Event::fake([AccountUpdated::class]);

        $event = new AccountUpdated(
            accountId: 'account-uuid',
            tenantId: 'tenant-uuid',
            companyId: 'company-uuid',
            changes: ['name' => 'Updated Name'],
            updatedAt: '2026-03-24T12:00:00Z',
        );

        event($event);

        Event::assertDispatched(AccountUpdated::class, function (AccountUpdated $e): bool {
            return $e->accountId === 'account-uuid'
                && $e->getEventName() === 'accounting.account.updated';
        });
    }
}
