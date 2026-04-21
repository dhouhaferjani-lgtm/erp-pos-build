<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Services\StatusMachine;
use PHPUnit\Framework\TestCase;

/**
 * Validates the Spec §5.3 adjacency map of the WorkOrder status machine.
 */
final class StatusMachineTest extends TestCase
{
    public function test_terminal_states_have_no_outgoing_edges(): void
    {
        $machine = new StatusMachine;
        foreach (WorkOrderStatus::cases() as $target) {
            $this->assertFalse(
                $machine->isAllowed(WorkOrderStatus::Closed, $target),
                "Closed should not transition to {$target->value}"
            );
            $this->assertFalse(
                $machine->isAllowed(WorkOrderStatus::Cancelled, $target),
                "Cancelled should not transition to {$target->value}"
            );
        }
    }

    public function test_no_self_loops(): void
    {
        $machine = new StatusMachine;
        foreach (WorkOrderStatus::cases() as $s) {
            $this->assertFalse(
                $machine->isAllowed($s, $s),
                "Self-loop on {$s->value} must be disallowed"
            );
        }
    }

    /**
     * @return list<array{0: WorkOrderStatus, 1: WorkOrderStatus}>
     */
    public static function allowedEdges(): array
    {
        return [
            [WorkOrderStatus::Received, WorkOrderStatus::Diagnosed],
            [WorkOrderStatus::Received, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::Diagnosed, WorkOrderStatus::Quoted],
            [WorkOrderStatus::Diagnosed, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::Quoted, WorkOrderStatus::Approved],
            [WorkOrderStatus::Quoted, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::Approved, WorkOrderStatus::InProgress],
            [WorkOrderStatus::Approved, WorkOrderStatus::WaitingParts],
            [WorkOrderStatus::Approved, WorkOrderStatus::Quoted],   // re-quote (supersedes)
            [WorkOrderStatus::Approved, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::InProgress, WorkOrderStatus::Paused],
            [WorkOrderStatus::InProgress, WorkOrderStatus::WaitingParts],
            [WorkOrderStatus::InProgress, WorkOrderStatus::Completed],
            [WorkOrderStatus::Paused, WorkOrderStatus::InProgress],
            [WorkOrderStatus::Paused, WorkOrderStatus::WaitingParts],
            [WorkOrderStatus::Paused, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::WaitingParts, WorkOrderStatus::InProgress],
            [WorkOrderStatus::WaitingParts, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::Completed, WorkOrderStatus::Invoiced],
            [WorkOrderStatus::Invoiced, WorkOrderStatus::Closed],
        ];
    }

    /**
     * @dataProvider allowedEdges
     */
    public function test_allowed_edge(WorkOrderStatus $from, WorkOrderStatus $to): void
    {
        $this->assertTrue(
            (new StatusMachine)->isAllowed($from, $to),
            "Expected transition {$from->value} → {$to->value} to be allowed"
        );
    }

    /**
     * @return list<array{0: WorkOrderStatus, 1: WorkOrderStatus}>
     */
    public static function forbiddenEdges(): array
    {
        return [
            // Cancellation forbidden from InProgress/Completed/Invoiced (per spec).
            [WorkOrderStatus::InProgress, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::Completed, WorkOrderStatus::Cancelled],
            [WorkOrderStatus::Invoiced, WorkOrderStatus::Cancelled],
            // Cannot skip Quote.
            [WorkOrderStatus::Diagnosed, WorkOrderStatus::Approved],
            [WorkOrderStatus::Diagnosed, WorkOrderStatus::InProgress],
            // Cannot go back to Received after diagnosis.
            [WorkOrderStatus::Diagnosed, WorkOrderStatus::Received],
            // Cannot invoice straight from Diagnosed.
            [WorkOrderStatus::Diagnosed, WorkOrderStatus::Invoiced],
            // Cannot re-open Closed.
            [WorkOrderStatus::Closed, WorkOrderStatus::Invoiced],
            // Cannot go Completed → Closed (must invoice first).
            [WorkOrderStatus::Completed, WorkOrderStatus::Closed],
        ];
    }

    /**
     * @dataProvider forbiddenEdges
     */
    public function test_forbidden_edge(WorkOrderStatus $from, WorkOrderStatus $to): void
    {
        $this->assertFalse(
            (new StatusMachine)->isAllowed($from, $to),
            "Expected transition {$from->value} → {$to->value} to be forbidden"
        );
    }

    public function test_terminal_states_accept_zero_transitions(): void
    {
        $machine = new StatusMachine;
        $this->assertSame([], $machine->allowedTargetsOf(WorkOrderStatus::Closed));
        $this->assertSame([], $machine->allowedTargetsOf(WorkOrderStatus::Cancelled));
    }
}
