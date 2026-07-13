<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Bank;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentInstrumentBankForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_nulls_orphaned_bank_references_logs_the_count_and_is_re_runnable(): void
    {
        $migration = $this->migration();
        $migration->down();
        $instrument = $this->instrument();
        $orphanId = Str::uuid()->toString();
        DB::table('payment_instruments')->where('id', $instrument->id)->update(['bank_id' => $orphanId]);
        Log::spy();

        $migration->up();
        $migration->up();

        $this->assertNull($instrument->fresh()?->bank_id);
        Log::shouldHaveReceived('warning')->once()->with(
            'Migration: nulled orphaned payment instrument bank references.',
            ['orphan_count' => 1],
        );
    }

    public function test_migration_preserves_a_valid_bank_reference_and_nulls_it_when_bank_is_deleted(): void
    {
        $migration = $this->migration();
        $migration->up();
        $instrument = $this->instrument();
        $bank = Bank::query()->create([
            'tenant_id' => $instrument->tenant_id,
            'country_code' => 'TN',
            'name' => 'Amen Bank',
        ]);
        $instrument->update(['bank_id' => $bank->id]);

        $this->assertSame($bank->id, $instrument->fresh()?->bank_id);

        $bank->delete();

        $this->assertNull($instrument->fresh()?->bank_id);
    }

    public function test_foreign_key_rejects_a_nonexistent_bank_reference(): void
    {
        $migration = $this->migration();
        $migration->up();
        $instrument = $this->instrument();

        $this->expectException(QueryException::class);
        DB::table('payment_instruments')->where('id', $instrument->id)->update([
            'bank_id' => Str::uuid()->toString(),
        ]);
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/tenant/2026_07_13_090000_add_bank_foreign_key_to_payment_instruments.php'
        );
    }

    private function instrument(): PaymentInstrument
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        return PaymentInstrument::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'CHK-FK-'.Str::random(8),
            'amount' => '100.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => 'received',
        ]);
    }
}
