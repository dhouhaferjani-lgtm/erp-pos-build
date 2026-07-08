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

final class RepositoryMovementsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_key_is_unique_and_amount_must_be_positive(): void
    {
        $row = $this->baseMovementRow();
        DB::table('repository_movements')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('repository_movements')->insert(array_merge($row, ['id' => (string) Str::uuid()]));
    }

    public function test_amount_check_rejects_zero(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint is pgsql-only DDL');
        }

        $this->expectException(QueryException::class);
        DB::table('repository_movements')->insert(
            array_merge($this->baseMovementRow(), ['id' => (string) Str::uuid(), 'idempotency_key' => 'x:'.Str::uuid(), 'amount' => '0.000'])
        );
    }

    public function test_direction_check_rejects_invalid_value(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint is pgsql-only DDL');
        }

        $this->expectException(QueryException::class);
        DB::table('repository_movements')->insert(
            array_merge($this->baseMovementRow(), ['id' => (string) Str::uuid(), 'idempotency_key' => 'y:'.Str::uuid(), 'direction' => 'bad'])
        );
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
