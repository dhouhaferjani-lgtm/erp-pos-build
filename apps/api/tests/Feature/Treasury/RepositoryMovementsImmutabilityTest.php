<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 3 — `repository_movements` append-only immutability trigger.
 *
 * Unlike `fiscal_events`, there is no allowed-mutation whitelist: every
 * UPDATE and DELETE is rejected outright (spec §4). This behavior is
 * enforced entirely by a PostgreSQL trigger, so it does not exist on the
 * sqlite fast loop — both tests below skip there.
 *
 * Split into two methods (one UPDATE, one DELETE) rather than one combined
 * test: under RefreshDatabase on pgsql, a single test method runs inside one
 * transaction. Once a trigger RAISEs, that transaction enters the aborted
 * state and any further statement fails with a *different* QueryException
 * ("current transaction is aborted"), which would make a combined
 * update-then-delete test pass for the wrong reason.
 */
final class RepositoryMovementsImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_movement_row_cannot_be_updated(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('append-only enforced by pgsql trigger');
        }

        $id = $this->insertValidMovement();

        try {
            DB::table('repository_movements')->where('id', $id)->update(['notes' => 'tampered']);
            $this->fail('UPDATE should have been rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_movement_row_cannot_be_deleted(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('append-only enforced by pgsql trigger');
        }

        $id = $this->insertValidMovement();

        try {
            DB::table('repository_movements')->where('id', $id)->delete();
            $this->fail('DELETE should have been rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    private function insertValidMovement(): string
    {
        $row = $this->baseMovementRow();
        DB::table('repository_movements')->insert($row);

        return $row['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMovementRow(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
        ]);

        return [
            'id' => (string) Str::uuid(),
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
        ];
    }
}
