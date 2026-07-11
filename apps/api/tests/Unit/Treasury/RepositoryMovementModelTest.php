<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RepositoryMovementModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_movement_direction_enum_has_required_cases(): void
    {
        $this->assertSame('in', MovementDirection::In->value);
        $this->assertSame('out', MovementDirection::Out->value);
    }

    public function test_movement_source_type_enum_has_required_cases(): void
    {
        $expected = [
            'Payment' => 'payment',
            'Expense' => 'expense',
            'Income' => 'income',
            'Refund' => 'refund',
            'FiscalEvent' => 'fiscal_event',
            'Transfer' => 'transfer',
            'Adjustment' => 'adjustment',
            'OpeningBalance' => 'opening_balance',
            'Instrument' => 'instrument',
        ];

        foreach ($expected as $case => $value) {
            $this->assertSame($value, constant(MovementSourceType::class.'::'.$case)->value);
        }
    }

    public function test_movement_reason_code_enum_has_required_cases(): void
    {
        $expected = [
            'CountVariance' => 'count_variance',
            'Correction' => 'correction',
            'TheftLoss' => 'theft_loss',
            'Other' => 'other',
        ];

        foreach ($expected as $case => $value) {
            $this->assertSame($value, constant(MovementReasonCode::class.'::'.$case)->value);
        }
    }

    public function test_model_casts_direction_source_type_and_amounts_from_a_hydrated_row(): void
    {
        $movement = RepositoryMovement::find($this->seedMovementRow());

        $this->assertInstanceOf(RepositoryMovement::class, $movement);
        $this->assertInstanceOf(MovementDirection::class, $movement->direction);
        $this->assertSame(MovementDirection::In, $movement->direction);
        $this->assertInstanceOf(MovementSourceType::class, $movement->source_type);
        $this->assertSame(MovementSourceType::OpeningBalance, $movement->source_type);
        $this->assertSame('100.000', $movement->amount);
        $this->assertSame('100.000', $movement->balance_after);
        $this->assertSame(1, $movement->ordinal);
        $this->assertFalse($movement->recorded_while_frozen);
    }

    public function test_repository_and_journal_entry_relations_are_belongs_to_with_expected_foreign_keys(): void
    {
        $movement = new RepositoryMovement;

        $repository = $movement->repository();
        $this->assertInstanceOf(BelongsTo::class, $repository);
        $this->assertSame('payment_repository_id', $repository->getForeignKeyName());

        $journalEntry = $movement->journalEntry();
        $this->assertInstanceOf(BelongsTo::class, $journalEntry);
        $this->assertSame('journal_entry_id', $journalEntry->getForeignKeyName());
    }

    public function test_model_has_no_timestamps_and_is_unguarded_for_inserts(): void
    {
        // Inserts go through the port (insert()), not Eloquent save(); the
        // model stays fully unguarded and timestamp-free for that path.
        $movement = new RepositoryMovement;

        $this->assertFalse($movement->usesTimestamps());
        $this->assertSame([], $movement->getGuarded());
    }

    public function test_model_throws_on_update(): void
    {
        $movement = RepositoryMovement::find($this->seedMovementRow());
        $this->assertInstanceOf(RepositoryMovement::class, $movement);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('repository_movements are append-only; corrections are compensating movements');

        $movement->update(['notes' => 'tampered']);
    }

    public function test_model_throws_on_delete(): void
    {
        $movement = RepositoryMovement::find($this->seedMovementRow());
        $this->assertInstanceOf(RepositoryMovement::class, $movement);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('repository_movements are append-only; corrections are compensating movements');

        $movement->delete();
    }

    /**
     * @return string the inserted movement's id
     */
    private function seedMovementRow(): string
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
        ]);

        $id = (string) Str::uuid();

        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'direction' => 'in',
            'amount' => '100.000',
            'currency' => $repository->currency,
            'balance_after' => '100.000',
            'ordinal' => 1,
            'source_type' => 'opening_balance',
            'source_id' => (string) Str::uuid(),
            'journal_entry_id' => null,
            'idempotency_key' => 'test:'.Str::uuid(),
            'transfer_group_id' => null,
            'reverses_movement_id' => null,
            'reason_code' => null,
            'occurred_at' => now(),
            'created_at' => now(),
            'created_by' => null,
            'recorded_while_frozen' => false,
            'notes' => null,
        ]);

        return $id;
    }
}
