<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schema + behaviour tests for Task 21 migration:
 * pos_receipt_payments.voucher_serial → instrument_serial rename
 * + instrument_type discriminator column + backfill.
 *
 * Spec §3.3.
 */
final class InstrumentSerialMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function skipUnlessPostgres(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Column introspection and CHECK constraints are Postgres-specific');
        }
    }

    // -------------------------------------------------------------------------
    // Column rename — schema introspection (Postgres only)
    // -------------------------------------------------------------------------

    public function test_instrument_serial_column_exists(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT column_name, is_nullable '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipt_payments' AND column_name = 'instrument_serial'"
        );

        $this->assertNotNull($row, 'instrument_serial column should exist on pos_receipt_payments');
        $this->assertSame('YES', $row->is_nullable, 'instrument_serial must be nullable');
    }

    public function test_voucher_serial_column_no_longer_exists(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT column_name '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipt_payments' AND column_name = 'voucher_serial'"
        );

        $this->assertNull($row, 'voucher_serial column should have been renamed to instrument_serial');
    }

    // -------------------------------------------------------------------------
    // instrument_type column — schema introspection (Postgres only)
    // -------------------------------------------------------------------------

    public function test_instrument_type_column_exists_and_is_nullable(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT is_nullable, character_maximum_length '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipt_payments' AND column_name = 'instrument_type'"
        );

        $this->assertNotNull($row, 'instrument_type column should exist on pos_receipt_payments');
        $this->assertSame('YES', $row->is_nullable, 'instrument_type must be nullable');
        $this->assertSame(32, (int) $row->character_maximum_length, 'instrument_type should be VARCHAR(32)');
    }

    public function test_instrument_type_check_constraint_exists(): void
    {
        $this->skipUnlessPostgres();

        $constraint = DB::selectOne(
            'SELECT constraint_name '.
            'FROM information_schema.table_constraints '.
            "WHERE table_name = 'pos_receipt_payments' ".
            "AND constraint_name = 'pos_receipt_payments_instrument_type_check'"
        );

        $this->assertNotNull(
            $constraint,
            'CHECK constraint pos_receipt_payments_instrument_type_check should exist'
        );
    }

    // -------------------------------------------------------------------------
    // Row insertion via model (both drivers)
    // The factory creates the parent Tenant/Company/etc via factories.
    // We use the model fillable rather than raw DB::table to respect FK constraints.
    // -------------------------------------------------------------------------

    /**
     * Build a minimal ReceiptPayment using factories, with proper FK chain.
     *
     * @return array{receipt: Receipt, paymentMethod: PaymentMethod}
     */
    private function scaffoldParents(): array
    {
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $location = Location::factory()->create([
            'company_id' => $company->id,
        ]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $cashier = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $receipt = Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
            'previous_hash' => null,
            'chain_sequence' => null,
        ]);

        $paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        return compact('receipt', 'paymentMethod');
    }

    public function test_new_row_with_store_voucher_instrument_type_succeeds(): void
    {
        ['receipt' => $receipt, 'paymentMethod' => $pm] = $this->scaffoldParents();

        $payment = ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'StoreVoucher',
            'amount' => '20.000',
            'instrument_serial' => 'POSC-1234-5678',
            'instrument_type' => PaymentInstrumentKind::StoreVoucher,
        ]);

        $payment->refresh();
        $this->assertSame('POSC-1234-5678', $payment->instrument_serial);
        $this->assertSame(PaymentInstrumentKind::StoreVoucher, $payment->instrument_type);
    }

    public function test_row_with_null_instrument_type_succeeds(): void
    {
        ['receipt' => $receipt, 'paymentMethod' => $pm] = $this->scaffoldParents();

        $payment = ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'Cash',
            'amount' => '50.000',
            'instrument_serial' => null,
            'instrument_type' => null,
        ]);

        $payment->refresh();
        $this->assertNull($payment->instrument_serial);
        $this->assertNull($payment->instrument_type);
    }

    public function test_restaurant_voucher_instrument_type_row_succeeds(): void
    {
        // Writing a restaurant_voucher row via the model (legacy path) must still succeed.
        // Only the service layer rejects this — the DB accepts it.
        ['receipt' => $receipt, 'paymentMethod' => $pm] = $this->scaffoldParents();

        $payment = ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'Voucher',
            'amount' => '8.500',
            'instrument_serial' => 'TICKET-REST-001',
            'instrument_type' => PaymentInstrumentKind::RestaurantVoucher,
        ]);

        $payment->refresh();
        $this->assertSame('TICKET-REST-001', $payment->instrument_serial);
        $this->assertSame(PaymentInstrumentKind::RestaurantVoucher, $payment->instrument_type);
    }

    // -------------------------------------------------------------------------
    // Model: instrument_type casts to PaymentInstrumentKind enum
    // -------------------------------------------------------------------------

    public function test_instrument_type_is_cast_to_enum(): void
    {
        ['receipt' => $receipt, 'paymentMethod' => $pm] = $this->scaffoldParents();

        $payment = ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'StoreVoucher',
            'amount' => '10.000',
            'instrument_serial' => 'POSC-0001',
            'instrument_type' => PaymentInstrumentKind::StoreVoucher,
        ]);

        $payment->refresh();
        $this->assertInstanceOf(PaymentInstrumentKind::class, $payment->instrument_type);
        $this->assertSame(PaymentInstrumentKind::StoreVoucher, $payment->instrument_type);
    }

    // -------------------------------------------------------------------------
    // CHECK constraint rejects invalid instrument_type (Postgres only)
    // -------------------------------------------------------------------------

    public function test_invalid_instrument_type_fails_check_constraint_on_postgres(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('CHECK constraint enforcement is Postgres-specific');
        }

        ['receipt' => $receipt, 'paymentMethod' => $pm] = $this->scaffoldParents();

        $this->expectException(QueryException::class);

        DB::table('pos_receipt_payments')->insert([
            'id' => (string) Str::uuid(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'Unknown',
            'amount' => '5.000',
            'instrument_serial' => 'BAD-001',
            'instrument_type' => 'banana', // invalid — CHECK should reject
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
