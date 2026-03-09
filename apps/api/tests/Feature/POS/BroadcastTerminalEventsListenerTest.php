<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Events\TerminalActivated;
use App\Modules\POS\Infrastructure\Broadcasting\TerminalActivatedBroadcast;
use App\Modules\POS\Infrastructure\Listeners\BroadcastPosEventsListener;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class BroadcastTerminalEventsListenerTest extends TestCase
{
    public function test_terminal_activated_event_triggers_broadcast(): void
    {
        Event::fake([TerminalActivatedBroadcast::class]);

        $domainEvent = new TerminalActivated(
            terminalId: 'terminal-001',
            terminalCode: 'POS01',
            terminalName: 'Front Counter',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
        );

        $listener = new BroadcastPosEventsListener;
        $listener->handleTerminalActivated($domainEvent);

        Event::assertDispatched(TerminalActivatedBroadcast::class);
    }

    public function test_broadcast_event_has_correct_channel(): void
    {
        $domainEvent = new TerminalActivated(
            terminalId: 'terminal-002',
            terminalCode: 'POS02',
            terminalName: 'Back Office',
            tenantId: 'tenant-123',
            companyId: 'company-456',
        );

        $broadcast = new TerminalActivatedBroadcast($domainEvent);
        $channels = $broadcast->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame(
            'private-tenant.tenant-123.company.company-456.pos.terminal.terminal-002',
            $channels[0]->name,
        );
    }

    public function test_broadcast_event_has_correct_payload(): void
    {
        $domainEvent = new TerminalActivated(
            terminalId: 'terminal-003',
            terminalCode: 'POS03',
            terminalName: 'Drive Through',
            tenantId: 'tenant-def',
            companyId: 'company-ghi',
        );

        $broadcast = new TerminalActivatedBroadcast($domainEvent);
        $payload = $broadcast->broadcastWith();

        $this->assertSame('terminal-003', $payload['terminalId']);
        $this->assertSame('POS03', $payload['terminalCode']);
        $this->assertSame('Drive Through', $payload['terminalName']);
        $this->assertArrayHasKey('timestamp', $payload);
    }

    public function test_broadcast_event_name_is_terminal_activated(): void
    {
        $domainEvent = new TerminalActivated(
            terminalId: 'terminal-004',
            terminalCode: 'POS04',
            terminalName: 'Kiosk',
            tenantId: 'tenant-jkl',
            companyId: 'company-mno',
        );

        $broadcast = new TerminalActivatedBroadcast($domainEvent);

        $this->assertSame('terminal.activated', $broadcast->broadcastAs());
    }
}
