<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test the PostgreSQL trigger that automatically updates balance_due cache.
 */
class BalanceDueCacheTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip if not using PostgreSQL (tests use SQLite in-memory)
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Trigger tests only run on PostgreSQL. SQLite does not support PostgreSQL triggers.');
        }

        // Ensure trigger is installed (migration should have run)
        $triggerExists = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.triggers
                WHERE trigger_name = 'payment_allocation_balance_update'
                AND event_object_table = 'payment_allocations'
            ) as exists
        ");

        if (! $triggerExists->exists) {
            $this->markTestSkipped('PostgreSQL trigger not installed. Run migrations first.');
        }
    }

    public function test_trigger_updates_cache_on_allocation_insert(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        // Create payment allocation
        $payment = Payment::factory()->create();

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '60.00',
        ]);

        // Trigger should have updated balance_due automatically
        $invoice = $invoice->fresh();
        $this->assertEquals('40.00', $invoice->balance_due);
    }

    public function test_trigger_updates_cache_on_allocation_delete(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create();

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '60.00',
        ]);

        // Verify cache updated
        $invoice = $invoice->fresh();
        $this->assertEquals('40.00', $invoice->balance_due);

        // Delete allocation
        $allocation->delete();

        // Trigger should restore balance_due to full amount
        $invoice = $invoice->fresh();
        $this->assertEquals('100.00', $invoice->balance_due);
    }

    public function test_trigger_updates_cache_on_allocation_update(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create();

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '60.00',
        ]);

        $invoice = $invoice->fresh();
        $this->assertEquals('40.00', $invoice->balance_due);

        // Update allocation amount
        $allocation->update(['amount' => '80.00']);

        // Trigger should adjust balance_due
        $invoice = $invoice->fresh();
        $this->assertEquals('20.00', $invoice->balance_due);
    }

    public function test_trigger_handles_multiple_rapid_allocations(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '1000.00',
            'balance_due' => '1000.00',
        ]);

        $payment1 = Payment::factory()->create();
        $payment2 = Payment::factory()->create();
        $payment3 = Payment::factory()->create();

        // Create multiple allocations rapidly
        PaymentAllocation::create([
            'payment_id' => $payment1->id,
            'document_id' => $invoice->id,
            'amount' => '300.00',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment2->id,
            'document_id' => $invoice->id,
            'amount' => '250.00',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment3->id,
            'document_id' => $invoice->id,
            'amount' => '150.00',
        ]);

        // Trigger should have updated cache correctly
        $invoice = $invoice->fresh();
        $this->assertEquals('300.00', $invoice->balance_due);
    }

    public function test_computed_method_matches_cached_value(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);

        $payment1 = Payment::factory()->create();
        $payment2 = Payment::factory()->create();

        PaymentAllocation::create([
            'payment_id' => $payment1->id,
            'document_id' => $invoice->id,
            'amount' => '200.00',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment2->id,
            'document_id' => $invoice->id,
            'amount' => '150.00',
        ]);

        $invoice = $invoice->fresh();

        // Computed method (source of truth) should match cached value (maintained by trigger)
        $this->assertEquals($invoice->balance_due, $invoice->getOutstandingAmount());
    }

    public function test_trigger_handles_transaction_rollback(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create();

        try {
            DB::transaction(function () use ($invoice, $payment) {
                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'document_id' => $invoice->id,
                    'amount' => '60.00',
                ]);

                // Force rollback
                throw new \Exception('Test rollback');
            });
        } catch (\Exception $e) {
            // Expected
        }

        // balance_due should NOT have changed (trigger respects transaction)
        $invoice = $invoice->fresh();
        $this->assertEquals('100.00', $invoice->balance_due);
    }

    public function test_trigger_with_null_total(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => null,
            'balance_due' => '0.00',
        ]);

        $payment = Payment::factory()->create();

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '50.00',
        ]);

        $invoice = $invoice->fresh();

        // Trigger should handle COALESCE for null total
        $this->assertEquals('-50.00', $invoice->balance_due); // Overpayment on null invoice
    }
}
