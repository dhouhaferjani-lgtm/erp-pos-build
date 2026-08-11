<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Exceptions\UnsupportedValuationModeException;
use App\Modules\Inventory\Domain\InventoryGlSourceTypes;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InventoryGlPostingSeamTest extends TestCase
{
    use RefreshDatabase;

    private const MOVEMENT_ID = '11111111-1111-4111-8111-111111111111';

    /**
     * Let afterCommit and root-depth assertions observe production transaction
     * levels; RefreshDatabase otherwise holds the test at level one forever.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_exit_rounds_once_posts_balanced_and_replays_idempotently(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        $cogs = $this->account($tenant, $company, SystemAccountPurpose::CostOfGoodsSold, AccountType::Expense);
        $inventory = $this->account($tenant, $company, SystemAccountPurpose::Inventory, AccountType::Asset);
        $context = $this->context($company);
        $buffer = app(InventoryGlPostingBuffer::class);

        DB::transaction(function () use ($buffer, $context): void {
            $buffer->enqueue($context);
            $posted = $buffer->flushIfOutermost();
            $this->assertCount(1, $posted);
        });

        $entry = JournalEntry::query()->where('source_id', self::MOVEMENT_ID)->with('lines')->sole();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame('inventory_exit', $entry->source_type);
        $this->assertSame('5.000', (string) $entry->lines[0]->debit);
        $this->assertSame('5.000', (string) $entry->lines[1]->credit);
        $this->assertSame($cogs->id, $entry->lines[0]->account_id);
        $this->assertSame($inventory->id, $entry->lines[1]->account_id);

        DB::transaction(function () use ($buffer, $context): void {
            $buffer->enqueue($context);
            $buffer->flushIfOutermost();
        });

        $this->assertSame(1, JournalEntry::query()->where('source_id', self::MOVEMENT_ID)->count());

        $indexDefinition = DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar(
                "SELECT pg_get_indexdef(indexrelid) FROM pg_index WHERE indexrelid = 'uniq_je_source_inventory_movement'::regclass"
            )
            : (string) DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', 'uniq_je_source_inventory_movement')
                ->value('sql');
        foreach (InventoryGlSourceTypes::ALL as $sourceType) {
            $this->assertStringContainsString($sourceType, $indexDefinition);
        }

        $duplicate = $entry->getAttributes();
        $duplicate['id'] = (string) Str::uuid();
        $duplicate['entry_number'] = 'JE-DUPLICATE-PROBE';
        unset($duplicate['created_at'], $duplicate['updated_at']);
        try {
            DB::table('journal_entries')->insert($duplicate);
            $this->fail('The movement-keyed partial index accepted a duplicate source pair.');
        } catch (QueryException $e) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $this->assertSame('23505', $e->errorInfo[0] ?? null);
            }
        }
    }

    public function test_nested_flush_only_alarms_after_root_commit_and_never_posts(): void
    {
        Log::spy();
        $buffer = app(InventoryGlPostingBuffer::class);

        DB::transaction(function () use ($buffer): void {
            $buffer->enqueue($this->context('33333333-3333-4333-8333-333333333333'));
            DB::transaction(function () use ($buffer): void {
                $this->assertSame([], $buffer->flushIfOutermost());
                $this->assertFalse($buffer->isEmpty());
            });

        });

        Log::shouldHaveReceived('critical')->once();
        $this->assertTrue($buffer->isEmpty());
    }

    public function test_root_rollback_discards_contexts_before_the_next_writer(): void
    {
        $buffer = app(InventoryGlPostingBuffer::class);

        try {
            DB::transaction(function () use ($buffer): void {
                $buffer->enqueue($this->context('44444444-4444-4444-8444-444444444444'));
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertTrue($buffer->isEmpty());
    }

    public function test_historical_movement_stops_before_logging_or_account_lookup(): void
    {
        Log::spy();

        $posted = $this->flush($this->context(
            'company-without-a-row',
            movementId: '55555555-5555-4555-8555-555555555555',
            isHistorical: true,
        ));

        $this->assertSame([null], $posted);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_non_cogs_reason_stops_before_logging_or_account_lookup(): void
    {
        Log::spy();

        $posted = $this->flush($this->context(
            'company-without-a-row',
            movementId: '66666666-6666-4666-8666-666666666666',
            reason: MovementReason::GoodsReceipt,
        ));

        $this->assertSame([null], $posted);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_flat_row_stops_before_logging_or_account_lookup(): void
    {
        Log::spy();

        $posted = $this->flush($this->context(
            'company-without-a-row',
            movementId: '77777777-7777-4777-8777-777777777777',
            quantityBefore: '2.0000',
            quantityAfter: '2.0000',
        ));

        $this->assertSame([null], $posted);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_periodic_company_refuses_document_exit_but_skips_pos_without_throwing(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'periodic',
        ]);

        try {
            $this->flush($this->context(
                $company,
                movementId: '88888888-8888-4888-8888-888888888888',
            ));
            $this->fail('A periodic company posted a document-family inventory exit.');
        } catch (UnsupportedValuationModeException $e) {
            $this->assertSame($company->id, $e->companyId);
        }

        Log::spy();
        $posted = $this->flush($this->context(
            $company,
            movementId: '99999999-9999-4999-8999-999999999999',
            reason: MovementReason::POSSale,
        ));

        $this->assertSame([null], $posted);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'periodic POS company'),
        );
    }

    public function test_unmapped_chart_leaves_the_movement_unposted_and_warns(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        Log::spy();

        $posted = $this->flush($this->context(
            $company,
            movementId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ));

        $this->assertSame([null], $posted);
        $this->assertSame(0, JournalEntry::query()->where('source_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'accounts are not mapped'),
        );
    }

    public function test_non_positive_non_flat_value_warns_with_the_missing_relief_consequence(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        $this->account($tenant, $company, SystemAccountPurpose::CostOfGoodsSold, AccountType::Expense);
        $this->account($tenant, $company, SystemAccountPurpose::Inventory, AccountType::Asset);
        Log::spy();

        $posted = $this->flush($this->context(
            $company,
            movementId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            unitCost: '0.000000',
        ));

        $this->assertSame([null], $posted);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'no GL relief was recorded'),
        );
    }

    public function test_direction_contradicting_the_reason_refuses_before_posting(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        $this->account($tenant, $company, SystemAccountPurpose::CostOfGoodsSold, AccountType::Expense);
        $this->account($tenant, $company, SystemAccountPurpose::Inventory, AccountType::Asset);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('direction contradicts delivery');

        $this->flush($this->context(
            $company,
            movementId: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            quantityBefore: '1.0000',
            quantityAfter: '2.0000',
        ));
    }

    public function test_closed_fiscal_period_exception_reaches_the_root_caller_unchanged(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        $this->account($tenant, $company, SystemAccountPurpose::CostOfGoodsSold, AccountType::Expense);
        $this->account($tenant, $company, SystemAccountPurpose::Inventory, AccountType::Asset);
        FiscalPeriod::query()->where('company_id', $company->id)->delete();
        FiscalYear::query()->where('company_id', $company->id)->delete();
        $year = FiscalYear::query()->create([
            'company_id' => $company->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'company_id' => $company->id,
            'name' => 'August 2026',
            'period_number' => 8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
        ]);

        $this->expectException(ClosedFiscalPeriodException::class);

        $this->flush($this->context(
            $company,
            movementId: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        ));
    }

    public function test_unresolvable_device_actor_posts_as_system_and_logs_error(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        $this->account($tenant, $company, SystemAccountPurpose::CostOfGoodsSold, AccountType::Expense);
        $this->account($tenant, $company, SystemAccountPurpose::Inventory, AccountType::Asset);
        Log::spy();

        $this->flush($this->context(
            $company,
            movementId: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            postedByUserId: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        ));

        $entry = JournalEntry::query()->where('source_id', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee')->sole();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertNull($entry->posted_by);
        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'actor does not resolve'),
        );
    }

    public function test_migration_refuses_a_preexisting_duplicate_and_names_its_pair(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'T13 duplicate pre-check requires PostgreSQL.');
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
        $this->account($tenant, $company, SystemAccountPurpose::CostOfGoodsSold, AccountType::Expense);
        $this->account($tenant, $company, SystemAccountPurpose::Inventory, AccountType::Asset);
        $movementId = '12121212-1212-4212-8212-121212121212';
        $this->flush($this->context($company, movementId: $movementId));
        $entry = JournalEntry::query()->where('source_id', $movementId)->sole();
        $duplicateId = (string) Str::uuid();

        DB::statement('DROP INDEX IF EXISTS uniq_je_source_inventory_movement');
        $duplicate = $entry->getAttributes();
        $duplicate['id'] = $duplicateId;
        $duplicate['entry_number'] = 'JE-M1-DUPLICATE-PRECHECK';
        $duplicate['status'] = JournalEntryStatus::Draft->value;
        $duplicate['fiscal_hash'] = null;
        $duplicate['previous_hash'] = null;
        $duplicate['chain_sequence'] = null;
        $duplicate['posted_at'] = null;
        $duplicate['posted_by'] = null;
        unset($duplicate['created_at'], $duplicate['updated_at']);
        DB::table('journal_entries')->insert($duplicate);

        /** @var Migration $migration */
        $migration = require database_path('migrations/tenant/2026_08_11_000100_unique_journal_entries_source_inventory_movement.php');

        try {
            $migration->up();
            $this->fail('The migration accepted a duplicate inventory movement source pair.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('duplicate inventory GL pair', $e->getMessage());
            $this->assertStringContainsString("(inventory_exit, {$movementId})", $e->getMessage());
        } finally {
            DB::table('journal_entries')->where('id', $duplicateId)->delete();
            $migration->up();
        }
    }

    private function account(
        Tenant $tenant,
        Company $company,
        SystemAccountPurpose $purpose,
        AccountType $type,
    ): Account {
        return Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => $type,
            'system_purpose' => $purpose,
        ]);
    }

    /** @return list<JournalEntry|null> */
    private function flush(MovementGlContext $context): array
    {
        $buffer = app(InventoryGlPostingBuffer::class);

        return DB::transaction(function () use ($buffer, $context): array {
            $buffer->enqueue($context);

            return $buffer->flushIfOutermost();
        });
    }

    private function context(
        Company|string $company,
        string $movementId = self::MOVEMENT_ID,
        MovementReason $reason = MovementReason::Delivery,
        string $quantityBefore = '3.0000',
        string $quantityAfter = '0.0000',
        string $unitCost = '1.6666666',
        bool $isHistorical = false,
        ?string $postedByUserId = null,
    ): MovementGlContext {
        return new MovementGlContext(
            kind: MovementGlKind::Exit,
            movementId: $movementId,
            companyId: is_string($company) ? $company : $company->id,
            currencyCode: 'TND',
            reason: $reason,
            quantityBefore: $quantityBefore,
            quantityAfter: $quantityAfter,
            unitCost: $unitCost,
            sourceType: 'Document',
            sourceId: '22222222-2222-4222-8222-222222222222',
            occurredAt: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            entryDate: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            postedByUserId: $postedByUserId,
            isHistorical: $isHistorical,
        );
    }
}
