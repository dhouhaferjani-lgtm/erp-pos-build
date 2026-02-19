<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Application;

use App\Modules\Loyalty\Application\DTOs\EnrollmentData;
use App\Modules\Loyalty\Application\Services\MemberEnrollmentService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Events\MemberEnrolledV2;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyMemberRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for MemberEnrollmentService
 *
 * @group loyalty
 * @group unit
 */
final class MemberEnrollmentServiceTest extends TestCase
{
    private MemberEnrollmentService $service;

    private LoyaltyMemberRepositoryInterface $memberRepository;

    private LoyaltyProgramRepositoryInterface $programRepository;

    private EnrollmentRepositoryInterface $enrollmentRepository;

    private TransactionRepositoryInterface $transactionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock repositories
        $this->memberRepository = $this->createMock(LoyaltyMemberRepositoryInterface::class);
        $this->programRepository = $this->createMock(LoyaltyProgramRepositoryInterface::class);
        $this->enrollmentRepository = $this->createMock(EnrollmentRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);

        // Create service with mocked dependencies
        $this->service = new MemberEnrollmentService(
            $this->enrollmentRepository,
            $this->memberRepository,
            $this->programRepository,
            $this->transactionRepository,
        );

        // Mock database transactions
        DB::shouldReceive('transaction')
            ->andReturnUsing(function ($callback) {
                return $callback();
            });
    }

    /** @test */
    public function it_enrolls_member_successfully_without_welcome_bonus(): void
    {
        // Arrange
        $memberId = 'member-123';
        $programId = 'program-456';

        $member = $this->createMockMember($memberId);
        $program = $this->createMockProgram($programId, ProgramStatus::Active);
        $enrollment = $this->createMockEnrollment($programId, $memberId);

        Event::fake([MemberEnrolledV2::class]);

        // Expectations
        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn($member);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByMemberAndProgram')
            ->with($memberId, $programId)
            ->willReturn(null); // No existing enrollment

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollment->id)
            ->willReturn($enrollment);

        // Act
        $result = $this->service->enroll($memberId, $programId);

        // Assert
        $this->assertInstanceOf(EnrollmentData::class, $result);
        Event::assertDispatched(MemberEnrolledV2::class);
    }

    /** @test */
    public function it_enrolls_member_with_welcome_bonus(): void
    {
        // Arrange
        $memberId = 'member-123';
        $programId = 'program-456';
        $welcomeBonus = 100.0;

        $member = $this->createMockMember($memberId);
        $program = $this->createMockProgram($programId, ProgramStatus::Active);
        $enrollment = $this->createMockEnrollment($programId, $memberId);

        Event::fake([MemberEnrolledV2::class]);

        // Expectations
        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn($member);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByMemberAndProgram')
            ->with($memberId, $programId)
            ->willReturn(null);

        // Expect save to be called 2 times: initial enrollment, after bonus
        $this->enrollmentRepository->expects($this->exactly(2))
            ->method('save')
            ->willReturn($enrollment);

        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollment->id)
            ->willReturn($enrollment);

        // Act
        $result = $this->service->enroll($memberId, $programId, $welcomeBonus);

        // Assert
        $this->assertInstanceOf(EnrollmentData::class, $result);
        Event::assertDispatched(MemberEnrolledV2::class, function ($event) use ($welcomeBonus) {
            return $event->welcomeBonus === $welcomeBonus;
        });
    }

    /** @test */
    public function it_throws_exception_when_member_not_found(): void
    {
        // Arrange
        $memberId = 'nonexistent-member';
        $programId = 'program-456';

        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Member with ID {$memberId} not found");

        // Act
        $this->service->enroll($memberId, $programId);
    }

    /** @test */
    public function it_throws_exception_when_program_not_found(): void
    {
        // Arrange
        $memberId = 'member-123';
        $programId = 'nonexistent-program';

        $member = $this->createMockMember($memberId);

        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn($member);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Program with ID {$programId} not found");

        // Act
        $this->service->enroll($memberId, $programId);
    }

    /** @test */
    public function it_throws_exception_when_program_not_active(): void
    {
        // Arrange
        $memberId = 'member-123';
        $programId = 'program-456';

        $member = $this->createMockMember($memberId);
        $program = $this->createMockProgram($programId, ProgramStatus::Paused);

        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn($member);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not active');

        // Act
        $this->service->enroll($memberId, $programId);
    }

    /** @test */
    public function it_throws_exception_when_member_already_enrolled(): void
    {
        // Arrange
        $memberId = 'member-123';
        $programId = 'program-456';

        $member = $this->createMockMember($memberId);
        $program = $this->createMockProgram($programId, ProgramStatus::Active);
        $existingEnrollment = $this->createMockEnrollment($programId, $memberId);

        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn($member);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByMemberAndProgram')
            ->with($memberId, $programId)
            ->willReturn($existingEnrollment);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Member is already enrolled in this program');

        // Act
        $this->service->enroll($memberId, $programId);
    }

    /** @test */
    public function it_opts_out_member_successfully(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $enrollment = $this->createMockEnrollment('program-1', 'member-1');
        $enrollment->id = $enrollmentId;

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->with($enrollment)
            ->willReturn($enrollment);

        // Act
        $this->service->optOut($enrollmentId);

        // Assert
        $this->assertEquals('OPTED_OUT', $enrollment->status);
    }

    /** @test */
    public function it_throws_exception_when_opting_out_nonexistent_enrollment(): void
    {
        // Arrange
        $enrollmentId = 'nonexistent-enrollment';

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Enrollment with ID {$enrollmentId} not found");

        // Act
        $this->service->optOut($enrollmentId);
    }

    /** @test */
    public function it_reactivates_enrollment_successfully(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $enrollment = $this->createMockEnrollment('program-1', 'member-1');
        $enrollment->id = $enrollmentId;
        $enrollment->status = 'OPTED_OUT';

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->with($enrollment)
            ->willReturn($enrollment);

        // Act
        $this->service->reactivate($enrollmentId);

        // Assert
        $this->assertEquals('ACTIVE', $enrollment->status);
    }

    /** @test */
    public function it_throws_exception_when_reactivating_nonexistent_enrollment(): void
    {
        // Arrange
        $enrollmentId = 'nonexistent-enrollment';

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Enrollment with ID {$enrollmentId} not found");

        // Act
        $this->service->reactivate($enrollmentId);
    }

    /** @test */
    public function it_ignores_zero_welcome_bonus(): void
    {
        // Arrange
        $memberId = 'member-123';
        $programId = 'program-456';
        $welcomeBonus = 0.0;

        $member = $this->createMockMember($memberId);
        $program = $this->createMockProgram($programId, ProgramStatus::Active);
        $enrollment = $this->createMockEnrollment($programId, $memberId);

        Event::fake([MemberEnrolledV2::class]);

        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->willReturn($member);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn($program);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByMemberAndProgram')
            ->willReturn(null);

        // Should not create transaction for zero bonus
        $this->transactionRepository->expects($this->never())
            ->method('save');

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        // Act
        $result = $this->service->enroll($memberId, $programId, $welcomeBonus);

        // Assert
        $this->assertInstanceOf(EnrollmentData::class, $result);
    }

    /** @test */
    public function it_ignores_negative_welcome_bonus(): void
    {
        // Arrange
        $memberId = 'member-123';
        $programId = 'program-456';
        $welcomeBonus = -50.0;

        $member = $this->createMockMember($memberId);
        $program = $this->createMockProgram($programId, ProgramStatus::Active);
        $enrollment = $this->createMockEnrollment($programId, $memberId);

        Event::fake([MemberEnrolledV2::class]);

        $this->memberRepository->expects($this->once())
            ->method('findById')
            ->willReturn($member);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn($program);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByMemberAndProgram')
            ->willReturn(null);

        // Should not create transaction for negative bonus
        $this->transactionRepository->expects($this->never())
            ->method('save');

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        // Act
        $result = $this->service->enroll($memberId, $programId, $welcomeBonus);

        // Assert
        $this->assertInstanceOf(EnrollmentData::class, $result);
    }

    /**
     * Helper method to create a mock member
     */
    private function createMockMember(string $id): LoyaltyMember
    {
        $member = new LoyaltyMember([
            'tenant_id' => 'tenant-1',
            'phone' => '+1234567890',
        ]);

        // Explicitly set ID (not in fillable, set directly)
        $member->id = $id;

        return $member;
    }

    /**
     * Helper method to create a mock program
     */
    private function createMockProgram(string $id, ProgramStatus $status): LoyaltyProgram
    {
        $program = new LoyaltyProgram([
            'tenant_id' => 'tenant-1',
            'name' => 'Test Program',
            'status' => $status,
        ]);

        // Explicitly set ID (not in fillable, set directly)
        $program->id = $id;

        return $program;
    }

    /**
     * Helper method to create a mock enrollment
     */
    private function createMockEnrollment(string $programId, string $memberId): Enrollment
    {
        $enrollment = new Enrollment([
            'program_id' => $programId,
            'member_id' => $memberId,
            'current_balance' => 0,
            'lifetime_earned' => 0,
            'lifetime_redeemed' => 0,
            'status' => 'ACTIVE',
            'enrolled_at' => now(),
        ]);

        // Explicitly set ID (not in fillable, set directly)
        $enrollment->id = 'enrollment-123';

        return $enrollment;
    }
}
