<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Application;

use App\Modules\Loyalty\Application\DTOs\LoyaltyProgramData;
use App\Modules\Loyalty\Application\Services\ProgramManagementService;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Loyalty\Domain\Events\ProgramActivated;
use App\Modules\Loyalty\Domain\Events\ProgramDeactivated;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for ProgramManagementService
 *
 * @group loyalty
 * @group unit
 */
final class ProgramManagementServiceTest extends TestCase
{
    private ProgramManagementService $service;

    private LoyaltyProgramRepositoryInterface $programRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock repository
        $this->programRepository = $this->createMock(LoyaltyProgramRepositoryInterface::class);

        // Create service
        $this->service = new ProgramManagementService($this->programRepository);

        // Mock database transactions
        DB::shouldReceive('transaction')
            ->andReturnUsing(function ($callback) {
                return $callback();
            });

        DB::shouldReceive('afterCommit')
            ->andReturnUsing(function (callable $callback) {
                $callback();
            });
    }

    /** @test */
    public function it_creates_program_successfully(): void
    {
        // Arrange
        $data = [
            'tenant_id' => 'tenant-123',
            'name' => 'VIP Rewards',
            'description' => 'Premium rewards program',
            'program_type' => ProgramType::Points,
            'currency' => 'TND',
            'points_expiry_months' => 12,
            'is_active' => false,
            'welcome_bonus_points' => 100,
        ];

        $program = $this->createMockProgram('program-123', $data);

        $this->programRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(LoyaltyProgram::class))
            ->willReturn($program);

        // Act
        $result = $this->service->createProgram($data);

        // Assert
        $this->assertInstanceOf(LoyaltyProgramData::class, $result);
        $this->assertEquals('VIP Rewards', $result->name);
    }

    /** @test */
    public function it_throws_exception_when_creating_without_name(): void
    {
        // Arrange
        $data = [
            'program_type' => ProgramType::Points,
        ];

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Program name is required');

        // Act
        $this->service->createProgram($data);
    }

    /** @test */
    public function it_throws_exception_when_creating_without_type(): void
    {
        // Arrange
        $data = [
            'name' => 'VIP Rewards',
        ];

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Program type is required');

        // Act
        $this->service->createProgram($data);
    }

    /** @test */
    public function it_updates_program_successfully(): void
    {
        // Arrange
        $programId = 'program-123';
        $updateData = [
            'name' => 'Updated VIP Rewards',
            'description' => 'Updated description',
            'points_expiry_months' => 24,
        ];

        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
        ]);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        $this->programRepository->expects($this->once())
            ->method('save')
            ->with($program)
            ->willReturn($program);

        // Act
        $result = $this->service->updateProgram($programId, $updateData);

        // Assert
        $this->assertInstanceOf(LoyaltyProgramData::class, $result);
    }

    /** @test */
    public function it_throws_exception_when_updating_nonexistent_program(): void
    {
        // Arrange
        $programId = 'nonexistent';
        $updateData = ['name' => 'Updated'];

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Program with ID {$programId} not found");

        // Act
        $this->service->updateProgram($programId, $updateData);
    }

    /** @test */
    public function it_gets_program_by_id(): void
    {
        // Arrange
        $programId = 'program-123';
        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
        ]);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        // Act
        $result = $this->service->getProgram($programId);

        // Assert
        $this->assertInstanceOf(LoyaltyProgramData::class, $result);
        $this->assertEquals('VIP Rewards', $result->name);
    }

    /** @test */
    public function it_throws_exception_when_getting_nonexistent_program(): void
    {
        // Arrange
        $programId = 'nonexistent';

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Program with ID {$programId} not found");

        // Act
        $this->service->getProgram($programId);
    }

    /** @test */
    public function it_gets_programs_by_tenant(): void
    {
        // Arrange
        $tenantId = 'tenant-123';
        $program1 = $this->createMockProgram('program-1', ['name' => 'Program 1', 'program_type' => ProgramType::Points]);
        $program2 = $this->createMockProgram('program-2', ['name' => 'Program 2', 'program_type' => ProgramType::Stamps]);
        $programs = new Collection([$program1, $program2]);

        $this->programRepository->expects($this->once())
            ->method('findByTenant')
            ->with($tenantId)
            ->willReturn($programs);

        // Act
        $result = $this->service->getProgramsByTenant($tenantId);

        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(LoyaltyProgramData::class, $result->first());
    }

    /** @test */
    public function it_gets_active_programs(): void
    {
        // Arrange
        $tenantId = 'tenant-123';
        $program1 = $this->createMockProgram('program-1', [
            'name' => 'Active Program 1',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Active,
        ]);
        $programs = new Collection([$program1]);

        $this->programRepository->expects($this->once())
            ->method('findByTenantAndStatus')
            ->with($tenantId, ProgramStatus::Active)
            ->willReturn($programs);

        // Act
        $result = $this->service->getActivePrograms($tenantId);

        // Assert
        $this->assertCount(1, $result);
        $this->assertInstanceOf(LoyaltyProgramData::class, $result->first());
    }

    /** @test */
    public function it_activates_program_successfully(): void
    {
        // Arrange
        $programId = 'program-123';
        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Draft,
        ]);

        Event::fake([ProgramActivated::class]);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        $this->programRepository->expects($this->once())
            ->method('save')
            ->with($program)
            ->willReturn($program);

        // Act
        $result = $this->service->activateProgram($programId);

        // Assert
        $this->assertInstanceOf(LoyaltyProgramData::class, $result);
        Event::assertDispatched(ProgramActivated::class);
    }

    /** @test */
    public function it_returns_program_when_already_active(): void
    {
        // Arrange
        $programId = 'program-123';
        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Active,
        ]);

        Event::fake();

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn($program);

        // Should not save when already active
        $this->programRepository->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->activateProgram($programId);

        // Assert
        $this->assertInstanceOf(LoyaltyProgramData::class, $result);
        Event::assertNotDispatched(ProgramActivated::class);
    }

    /** @test */
    public function it_deactivates_program_successfully(): void
    {
        // Arrange
        $programId = 'program-123';
        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Active,
        ]);

        Event::fake([ProgramDeactivated::class]);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        $this->programRepository->expects($this->once())
            ->method('save')
            ->with($program)
            ->willReturn($program);

        // Act
        $result = $this->service->deactivateProgram($programId);

        // Assert
        $this->assertInstanceOf(LoyaltyProgramData::class, $result);
        Event::assertDispatched(ProgramDeactivated::class);
    }

    /** @test */
    public function it_returns_program_when_already_inactive(): void
    {
        // Arrange
        $programId = 'program-123';
        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Paused,
        ]);

        Event::fake();

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn($program);

        // Should not save when already inactive
        $this->programRepository->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->deactivateProgram($programId);

        // Assert
        $this->assertInstanceOf(LoyaltyProgramData::class, $result);
        Event::assertNotDispatched(ProgramDeactivated::class);
    }

    /** @test */
    public function it_deletes_inactive_program(): void
    {
        // Arrange
        $programId = 'program-123';
        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Paused,
        ]);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->with($programId)
            ->willReturn($program);

        $this->programRepository->expects($this->once())
            ->method('delete')
            ->with($programId)
            ->willReturn(true);

        // Act
        $result = $this->service->deleteProgram($programId);

        // Assert
        $this->assertTrue($result);
    }

    /** @test */
    public function it_throws_exception_when_deleting_active_program(): void
    {
        // Arrange
        $programId = 'program-123';
        $program = $this->createMockProgram($programId, [
            'name' => 'VIP Rewards',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Active,
        ]);

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn($program);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete an active program');

        // Act
        $this->service->deleteProgram($programId);
    }

    /** @test */
    public function it_throws_exception_when_deleting_nonexistent_program(): void
    {
        // Arrange
        $programId = 'nonexistent';

        $this->programRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Program with ID {$programId} not found");

        // Act
        $this->service->deleteProgram($programId);
    }

    /**
     * Helper method to create a mock loyalty program
     *
     * @param  array<string, mixed>  $data
     */
    private function createMockProgram(string $id, array $data): LoyaltyProgram
    {
        $program = new LoyaltyProgram([
            'tenant_id' => $data['tenant_id'] ?? 'tenant-123',
            'company_ids' => $data['company_ids'] ?? null,
            'name' => $data['name'] ?? 'Test Program',
            'description' => $data['description'] ?? 'Test Description',
            'program_type' => $data['program_type'] ?? ProgramType::Points,
            'status' => $data['status'] ?? ProgramStatus::Draft,
            'currency' => $data['currency'] ?? 'TND',
            'points_expiry_months' => $data['points_expiry_months'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'terms_and_conditions' => $data['terms_and_conditions'] ?? null,
            'welcome_bonus_points' => $data['welcome_bonus_points'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $program->id = $id;

        return $program;
    }
}
