<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReverseInventoryMovementEntriesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
        ]);
        Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
        ]);
    }

    public function test_it_refuses_without_an_explicit_from_and_confirmation(): void
    {
        $this->artisan('accounting:reverse-inventory-movement-entries', ['--confirm' => true])
            ->assertExitCode(2);
        $this->artisan('accounting:reverse-inventory-movement-entries', ['--from' => now()->subHour()->toIso8601String()])
            ->assertExitCode(2);
    }

    public function test_it_posts_exact_compensating_entries_nets_the_trial_balance_and_is_idempotent(): void
    {
        $exit = $this->original('inventory_exit', MovementReason::Delivery, '5.000', false);
        $entry = $this->original('inventory_entry', MovementReason::CustomerReturn, '7.000', true);
        $from = now()->subMinute()->toIso8601String();

        $this->artisan('accounting:reverse-inventory-movement-entries', [
            '--from' => $from,
            '--confirm' => true,
        ])->assertExitCode(0);

        $reversals = JournalEntry::query()
            ->where('source_type', 'inventory_movement_reversal')
            ->with('lines')
            ->orderBy('source_id')
            ->get();
        $this->assertCount(2, $reversals);
        $this->assertEqualsCanonicalizing([$exit->id, $entry->id], $reversals->pluck('source_id')->all());
        foreach ([$exit, $entry] as $original) {
            $reversal = $reversals->firstWhere('source_id', $original->id);
            $this->assertNotNull($reversal);
            $this->assertSame(JournalEntryStatus::Posted, $reversal->status);
            foreach ($original->lines as $line) {
                $mirror = $reversal->lines->firstWhere('account_id', $line->account_id);
                $this->assertNotNull($mirror);
                $this->assertSame((string) $line->credit, (string) $mirror->debit);
                $this->assertSame((string) $line->debit, (string) $mirror->credit);
            }
        }

        $netByAccount = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.id', [$exit->id, $entry->id, ...$reversals->pluck('id')->all()])
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit - journal_lines.credit) AS net')
            ->pluck('net', 'account_id');
        foreach ($netByAccount as $net) {
            $this->assertSame(0, bccomp('0', (string) $net, 3));
        }

        $this->artisan('accounting:reverse-inventory-movement-entries', [
            '--from' => $from,
            '--confirm' => true,
        ])->assertExitCode(0);
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'inventory_movement_reversal')->count());
    }

    public function test_it_leaves_entries_before_the_from_timestamp_untouched(): void
    {
        $original = $this->original('inventory_exit', MovementReason::Delivery, '5.000', false);
        DB::table('journal_entries')->where('id', $original->id)->update(['created_at' => now()->subDay()]);

        $this->artisan('accounting:reverse-inventory-movement-entries', [
            '--from' => now()->subHour()->toIso8601String(),
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'inventory_movement_reversal',
            'source_id' => $original->id,
        ]);
    }

    private function original(
        string $sourceType,
        MovementReason $reason,
        string $amount,
        bool $debitInventory,
    ): JournalEntry {
        $entry = DB::transaction(fn (): ?JournalEntry => app(GeneralLedgerService::class)->createInventoryMovementEntry(
            companyId: $this->company->id,
            movementId: (string) Str::uuid(),
            sourceType: $sourceType,
            amount: $amount,
            reason: $reason,
            counterPurpose: SystemAccountPurpose::CostOfGoodsSold,
            debitInventory: $debitInventory,
            entryDate: now(),
            description: 'M3 reversal fixture',
            currencyCode: 'TND',
            postSynchronously: true,
        ));

        return $entry?->load('lines') ?? throw new \RuntimeException('Expected original inventory entry.');
    }
}
