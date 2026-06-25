<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Document\Domain\Document;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PaymentAllocationPrecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_allocation_amount_cast_preserves_currency_scale_3(): void
    {
        $payment = Payment::factory()->create(['amount' => '12.345', 'currency' => 'TND']);
        $document = Document::factory()->create([
            'tenant_id' => $payment->tenant_id,
            'company_id' => $payment->company_id,
            'partner_id' => $payment->partner_id,
            'currency' => 'TND',
            'total' => '12.345',
            'balance_due' => '12.345',
        ]);

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $document->id,
            'amount' => '12.345',
        ]);

        $this->assertSame('12.345', $allocation->refresh()->amount);
    }

    public function test_payment_allocations_amount_column_is_decimal_15_3_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $columns = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name = 'payment_allocations'
              AND column_name = 'amount'
        ");

        $this->assertCount(1, $columns, 'payment_allocations.amount column not found');
        $this->assertSame(15, (int) $columns[0]->numeric_precision, 'payment_allocations.amount precision should be 15');
        $this->assertSame(3, (int) $columns[0]->numeric_scale, 'payment_allocations.amount scale should be 3');
    }
}
