<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Application;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Application\Services\PointAdjustmentService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for PointAdjustmentService validation logic.
 *
 * Full integration tests are in Feature\Loyalty\PointAdjustmentTest.
 *
 * @group loyalty
 * @group unit
 */
final class PointAdjustmentServiceTest extends TestCase
{
    private PointAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PointAdjustmentService(
            app(CompanyContext::class),
        );
    }

    /** @test */
    public function it_rejects_non_active_enrollment(): void
    {
        $enrollment = $this->createTestEnrollment(EnrollmentStatus::OptedOut);
        $user = $this->createTestUser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enrollment must be active to adjust points');

        $this->service->adjust($enrollment, '10', 'test', $user);
    }

    /** @test */
    public function it_rejects_suspended_enrollment(): void
    {
        $enrollment = $this->createTestEnrollment(EnrollmentStatus::Suspended);
        $user = $this->createTestUser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enrollment must be active to adjust points');

        $this->service->adjust($enrollment, '10', 'test', $user);
    }

    /** @test */
    public function it_rejects_debit_exceeding_balance(): void
    {
        $enrollment = $this->createTestEnrollment(EnrollmentStatus::Active, '50.000');
        $user = $this->createTestUser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit amount exceeds current balance');

        $this->service->adjust($enrollment, '-100', 'test', $user);
    }

    /** @test */
    public function it_allows_debit_equal_to_balance(): void
    {
        // This should NOT throw InvalidArgumentException for exceeding balance.
        // It will fail at DB transaction level (no company context), but the validation passes.
        $enrollment = $this->createTestEnrollment(EnrollmentStatus::Active, '50.000');
        $user = $this->createTestUser();

        try {
            $this->service->adjust($enrollment, '-50', 'Exact debit', $user);
        } catch (InvalidArgumentException $e) {
            $this->fail('Should not throw InvalidArgumentException for debit equal to balance: '.$e->getMessage());
        } catch (\RuntimeException) {
            // Expected - no company context set in unit test
            $this->assertTrue(true);
        }
    }

    private function createTestEnrollment(EnrollmentStatus $status, string $balance = '100.000'): Enrollment
    {
        $program = new LoyaltyProgram;
        $program->id = 'program-123';
        $program->currency = 'EUR';

        $enrollment = new Enrollment([
            'program_id' => 'program-123',
            'member_id' => 'member-123',
            'current_balance' => $balance,
            'lifetime_earned' => '200.000',
            'lifetime_redeemed' => '100.000',
            'status' => $status,
            'enrolled_at' => now(),
        ]);
        $enrollment->id = 'enrollment-123';
        $enrollment->setRelation('program', $program);

        return $enrollment;
    }

    private function createTestUser(): User
    {
        $user = new User;
        $user->id = 'user-123';
        $user->tenant_id = 'tenant-123';

        return $user;
    }
}
