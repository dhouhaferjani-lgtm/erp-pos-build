<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\InventoryGlSourceTypes;
use App\Modules\Tenant\Domain\Tenant;
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

    private function context(Company|string $company): MovementGlContext
    {
        return new MovementGlContext(
            kind: MovementGlKind::Exit,
            movementId: self::MOVEMENT_ID,
            companyId: is_string($company) ? $company : $company->id,
            currencyCode: 'TND',
            reason: MovementReason::Delivery,
            quantityBefore: '3.0000',
            quantityAfter: '0.0000',
            unitCost: '1.6666666',
            sourceType: 'Document',
            sourceId: '22222222-2222-4222-8222-222222222222',
            occurredAt: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            entryDate: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            postedByUserId: null,
            isHistorical: false,
        );
    }
}
