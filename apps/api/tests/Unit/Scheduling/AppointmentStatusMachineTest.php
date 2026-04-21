<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Scheduling\Domain\Services\AppointmentStatusMachine;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Appointment lifecycle adjacency matrix per Spec D §5.3, and
 * enforces the public-transition guardrail: the three system-mirrored
 * states (InProgress / Completed / Closed) are only reachable via the 4
 * MirrorAppointmentOn* listeners — never via the public transition API.
 */
final class AppointmentStatusMachineTest extends TestCase
{
    public function test_scheduled_can_transition_to_confirmed_or_checked_in_or_cancelled_or_no_show(): void
    {
        $machine = new AppointmentStatusMachine;
        $this->assertTrue($machine->isAllowed(AppointmentStatus::Scheduled, AppointmentStatus::Confirmed));
        $this->assertTrue($machine->isAllowed(AppointmentStatus::Scheduled, AppointmentStatus::CheckedIn));
        $this->assertTrue($machine->isAllowed(AppointmentStatus::Scheduled, AppointmentStatus::Cancelled));
        $this->assertTrue($machine->isAllowed(AppointmentStatus::Scheduled, AppointmentStatus::NoShow));
    }

    public function test_confirmed_can_transition_to_checked_in_or_cancelled_or_no_show(): void
    {
        $machine = new AppointmentStatusMachine;
        $this->assertTrue($machine->isAllowed(AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn));
        $this->assertTrue($machine->isAllowed(AppointmentStatus::Confirmed, AppointmentStatus::Cancelled));
        $this->assertTrue($machine->isAllowed(AppointmentStatus::Confirmed, AppointmentStatus::NoShow));
    }

    public function test_checked_in_can_only_transition_to_cancelled_via_public_api(): void
    {
        // After CheckedIn a WO exists. Cancellation from CheckedIn is the only
        // operator-reachable public edge. InProgress / Completed / Closed are
        // exclusively system-mirrored via MirrorAppointmentOn* listeners and
        // enforced at the public controller boundary.
        $machine = new AppointmentStatusMachine;
        $this->assertTrue($machine->isAllowed(AppointmentStatus::CheckedIn, AppointmentStatus::Cancelled));
    }

    public function test_system_mirrored_states_reject_public_transitions(): void
    {
        // Public transitions may NEVER target these three states — they come
        // only from the Workshop/WorkOrder lifecycle listeners.
        $machine = new AppointmentStatusMachine;
        $this->assertFalse($machine->isAllowedForPublicTransition(AppointmentStatus::Scheduled, AppointmentStatus::InProgress));
        $this->assertFalse($machine->isAllowedForPublicTransition(AppointmentStatus::Confirmed, AppointmentStatus::Completed));
        $this->assertFalse($machine->isAllowedForPublicTransition(AppointmentStatus::CheckedIn, AppointmentStatus::Closed));
    }

    public function test_system_mirror_path_allows_forbidden_transitions(): void
    {
        // The Mirror* listeners use isAllowedForSystemMirror which permits the
        // CheckedIn → InProgress / InProgress → Completed / Completed → Closed
        // edges that are forbidden on the public transition endpoint.
        $machine = new AppointmentStatusMachine;
        $this->assertTrue($machine->isAllowedForSystemMirror(AppointmentStatus::CheckedIn, AppointmentStatus::InProgress));
        $this->assertTrue($machine->isAllowedForSystemMirror(AppointmentStatus::InProgress, AppointmentStatus::Completed));
        $this->assertTrue($machine->isAllowedForSystemMirror(AppointmentStatus::Completed, AppointmentStatus::Closed));
    }

    public function test_terminal_states_have_no_outgoing_edges(): void
    {
        $machine = new AppointmentStatusMachine;
        foreach (AppointmentStatus::cases() as $target) {
            $this->assertFalse($machine->isAllowed(AppointmentStatus::Closed, $target));
            $this->assertFalse($machine->isAllowed(AppointmentStatus::Cancelled, $target));
            $this->assertFalse($machine->isAllowed(AppointmentStatus::NoShow, $target));
        }
    }

    public function test_self_loops_are_forbidden(): void
    {
        $machine = new AppointmentStatusMachine;
        foreach (AppointmentStatus::cases() as $status) {
            $this->assertFalse($machine->isAllowed($status, $status));
        }
    }

    public function test_assert_allowed_throws_invalid_transition_exception_on_illegal_edge(): void
    {
        $machine = new AppointmentStatusMachine;
        $this->expectException(InvalidAppointmentTransitionException::class);
        $machine->assertAllowed(AppointmentStatus::Scheduled, AppointmentStatus::Completed);
    }

    public function test_assert_allowed_is_silent_on_legal_edge(): void
    {
        $machine = new AppointmentStatusMachine;
        $this->expectNotToPerformAssertions();
        $machine->assertAllowed(AppointmentStatus::Scheduled, AppointmentStatus::Confirmed);
    }
}
