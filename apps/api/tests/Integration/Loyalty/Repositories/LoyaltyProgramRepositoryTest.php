<?php

declare(strict_types=1);

namespace Tests\Integration\Loyalty\Repositories;

use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentLoyaltyProgramRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyProgramRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentLoyaltyProgramRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentLoyaltyProgramRepository;
    }

    public function test_find_by_id_returns_program(): void
    {
        $program = LoyaltyProgram::factory()->create();

        $found = $this->repository->findById($program->id);

        $this->assertNotNull($found);
        $this->assertEquals($program->id, $found->id);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $found = $this->repository->findById('non-existent-id');

        $this->assertNull($found);
    }

    public function test_find_by_tenant_returns_all_tenant_programs(): void
    {
        $tenantId = 'tenant-1';
        $otherTenantId = 'tenant-2';

        LoyaltyProgram::factory()->count(3)->create(['tenant_id' => $tenantId]);
        LoyaltyProgram::factory()->count(2)->create(['tenant_id' => $otherTenantId]);

        $programs = $this->repository->findByTenant($tenantId);

        $this->assertCount(3, $programs);
        $this->assertTrue($programs->every(fn ($p) => $p->tenant_id === $tenantId));
    }

    public function test_find_by_tenant_and_status_filters_correctly(): void
    {
        $tenantId = 'tenant-1';

        LoyaltyProgram::factory()->count(2)->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
        ]);

        LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Draft,
        ]);

        LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Paused,
        ]);

        $activePrograms = $this->repository->findByTenantAndStatus($tenantId, ProgramStatus::Active);

        $this->assertCount(2, $activePrograms);
        $this->assertTrue($activePrograms->every(fn ($p) => $p->status === ProgramStatus::Active));
    }

    public function test_find_active_for_company_returns_programs_for_all_companies(): void
    {
        $tenantId = 'tenant-1';
        $companyId = 'company-1';

        // Program with company_ids = null (applies to all)
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
            'company_ids' => null,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $programs = $this->repository->findActiveForCompany($tenantId, $companyId);

        $this->assertCount(1, $programs);
        $this->assertEquals($program->id, $programs->first()->id);
    }

    public function test_find_active_for_company_returns_programs_for_specific_company(): void
    {
        $tenantId = 'tenant-1';
        $companyId = 'company-1';

        // Program specifically for company-1
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
            'company_ids' => ['company-1', 'company-2'],
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        // Program for different company
        LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
            'company_ids' => ['company-3'],
        ]);

        $programs = $this->repository->findActiveForCompany($tenantId, $companyId);

        $this->assertCount(1, $programs);
        $this->assertEquals($program->id, $programs->first()->id);
    }

    public function test_find_active_for_company_excludes_inactive_programs(): void
    {
        $tenantId = 'tenant-1';
        $companyId = 'company-1';

        LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Draft,
            'company_ids' => null,
        ]);

        LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Paused,
            'company_ids' => null,
        ]);

        $programs = $this->repository->findActiveForCompany($tenantId, $companyId);

        $this->assertCount(0, $programs);
    }

    public function test_find_active_for_company_respects_start_date(): void
    {
        $tenantId = 'tenant-1';
        $companyId = 'company-1';

        // Program not yet started
        LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
            'company_ids' => null,
            'start_date' => now()->addDay(),
        ]);

        $programs = $this->repository->findActiveForCompany($tenantId, $companyId);

        $this->assertCount(0, $programs);
    }

    public function test_find_active_for_company_respects_end_date(): void
    {
        $tenantId = 'tenant-1';
        $companyId = 'company-1';

        // Program already ended
        LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
            'company_ids' => null,
            'end_date' => now()->subDay(),
        ]);

        $programs = $this->repository->findActiveForCompany($tenantId, $companyId);

        $this->assertCount(0, $programs);
    }

    public function test_save_creates_new_program(): void
    {
        $program = new LoyaltyProgram([
            'tenant_id' => 'tenant-1',
            'name' => 'Test Program',
            'program_type' => ProgramType::Points,
            'status' => ProgramStatus::Draft,
            'currency' => 'Points',
        ]);

        $saved = $this->repository->save($program);

        $this->assertNotNull($saved->id);
        $this->assertEquals('Test Program', $saved->name);
        $this->assertDatabaseHas('loyalty_programs', [
            'name' => 'Test Program',
            'tenant_id' => 'tenant-1',
        ]);
    }

    public function test_save_updates_existing_program(): void
    {
        $program = LoyaltyProgram::factory()->create(['name' => 'Original Name']);

        $program->name = 'Updated Name';
        $updated = $this->repository->save($program);

        $this->assertEquals('Updated Name', $updated->name);
        $this->assertDatabaseHas('loyalty_programs', [
            'id' => $program->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_delete_removes_program(): void
    {
        $program = LoyaltyProgram::factory()->create();

        $result = $this->repository->delete($program->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('loyalty_programs', ['id' => $program->id]);
    }

    public function test_delete_returns_false_when_program_not_found(): void
    {
        $result = $this->repository->delete('non-existent-id');

        $this->assertFalse($result);
    }
}
