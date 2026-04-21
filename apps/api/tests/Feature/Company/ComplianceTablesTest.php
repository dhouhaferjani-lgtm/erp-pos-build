<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\CompanyHashChain;
use App\Modules\Company\Domain\CompanySequence;
use App\Modules\Company\Domain\Enums\HashChainType;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\Enums\SequenceType;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 0.1.8: Tests for compliance tables.
 *
 * Compliance tables enable:
 * - Hash chains for fiscal documents (NF525, e-invoicing)
 * - Document sequences with guaranteed gaps
 * - Fiscal years and periods for accounting
 */
class ComplianceTablesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Account',
            'slug' => 'test-account',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'ACME Garage',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    // ========================================
    // Company Hash Chains Tests
    // ========================================

    public function test_company_hash_chains_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('company_hash_chains'));
    }

    public function test_hash_chain_can_be_created(): void
    {
        $chain = CompanyHashChain::create([
            'company_id' => $this->company->id,
            'chain_type' => HashChainType::Invoice,
            'sequence_number' => 1,
            'hash' => hash('sha256', 'test data'),
            'previous_hash' => null,
            'document_id' => null,
            'document_type' => 'invoice',
            'payload_hash' => hash('sha256', 'payload'),
        ]);

        $this->assertDatabaseHas('company_hash_chains', [
            'id' => $chain->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_hash_chain_belongs_to_company(): void
    {
        $chain = CompanyHashChain::create([
            'company_id' => $this->company->id,
            'chain_type' => HashChainType::Invoice,
            'sequence_number' => 1,
            'hash' => hash('sha256', 'test'),
        ]);

        $this->assertEquals($this->company->id, $chain->company->id);
    }

    public function test_company_can_have_multiple_hash_chains(): void
    {
        CompanyHashChain::create([
            'company_id' => $this->company->id,
            'chain_type' => HashChainType::Invoice,
            'sequence_number' => 1,
            'hash' => hash('sha256', 'invoice1'),
        ]);

        CompanyHashChain::create([
            'company_id' => $this->company->id,
            'chain_type' => HashChainType::Invoice,
            'sequence_number' => 2,
            'hash' => hash('sha256', 'invoice2'),
            'previous_hash' => hash('sha256', 'invoice1'),
        ]);

        CompanyHashChain::create([
            'company_id' => $this->company->id,
            'chain_type' => HashChainType::CreditNote,
            'sequence_number' => 1,
            'hash' => hash('sha256', 'credit1'),
        ]);

        $this->assertCount(3, $this->company->fresh()->hashChains);
    }

    // ========================================
    // Company Sequences Tests
    // ========================================

    public function test_company_sequences_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('company_sequences'));
    }

    public function test_sequence_can_be_created(): void
    {
        $sequence = CompanySequence::create([
            'company_id' => $this->company->id,
            'sequence_type' => SequenceType::Invoice,
            'prefix' => 'INV',
            'current_number' => 0,
            'format' => '{prefix}-{year}-{number:05d}',
        ]);

        $this->assertDatabaseHas('company_sequences', [
            'id' => $sequence->id,
            'prefix' => 'INV',
        ]);
    }

    public function test_sequence_belongs_to_company(): void
    {
        $sequence = CompanySequence::create([
            'company_id' => $this->company->id,
            'sequence_type' => SequenceType::Invoice,
            'prefix' => 'INV',
            'current_number' => 0,
        ]);

        $this->assertEquals($this->company->id, $sequence->company->id);
    }

    public function test_sequence_unique_per_company_and_type(): void
    {
        CompanySequence::create([
            'company_id' => $this->company->id,
            'sequence_type' => SequenceType::Invoice,
            'prefix' => 'INV',
            'current_number' => 0,
        ]);

        $this->expectException(QueryException::class);

        CompanySequence::create([
            'company_id' => $this->company->id,
            'sequence_type' => SequenceType::Invoice,
            'prefix' => 'FAC',
            'current_number' => 0,
        ]);
    }

    // ========================================
    // Fiscal Years Tests
    // ========================================

    public function test_fiscal_years_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_years'));
    }

    public function test_fiscal_year_can_be_created(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_closed' => false,
        ]);

        $this->assertDatabaseHas('fiscal_years', [
            'id' => $year->id,
            'name' => '2025',
        ]);
    }

    public function test_fiscal_year_belongs_to_company(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->assertEquals($this->company->id, $year->company->id);
    }

    public function test_fiscal_year_can_have_periods(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'January 2025',
            'period_number' => 1,
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-31',
            'status' => PeriodStatus::Open,
        ]);

        $this->assertCount(1, $year->fresh()->periods);
    }

    // ========================================
    // Fiscal Periods Tests
    // ========================================

    public function test_fiscal_periods_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_periods'));
    }

    public function test_fiscal_period_can_be_created(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'January 2025',
            'period_number' => 1,
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-31',
            'status' => PeriodStatus::Open,
        ]);

        $this->assertDatabaseHas('fiscal_periods', [
            'id' => $period->id,
            'name' => 'January 2025',
        ]);
    }

    public function test_fiscal_period_belongs_to_year(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'January 2025',
            'period_number' => 1,
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-31',
            'status' => PeriodStatus::Open,
        ]);

        $this->assertEquals($year->id, $period->fiscalYear->id);
    }

    public function test_fiscal_period_status_enum(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $open = FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'January 2025',
            'period_number' => 1,
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-31',
            'status' => PeriodStatus::Open,
        ]);

        $closed = FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'February 2025',
            'period_number' => 2,
            'start_date' => '2025-02-01',
            'end_date' => '2025-02-28',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ]);

        $this->assertEquals(PeriodStatus::Open, $open->status);
        $this->assertEquals(PeriodStatus::Closed, $closed->status);
        $this->assertTrue($closed->isClosed());
        $this->assertFalse($open->isClosed());
    }

    public function test_fiscal_period_closing_tracking(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'January 2025',
            'period_number' => 1,
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-31',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ]);

        $this->assertNotNull($period->closed_at);
        $this->assertEquals($this->user->id, $period->closedBy->id);
    }
}
