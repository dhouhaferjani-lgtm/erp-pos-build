<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\BuildsPosSaleReceiptEvents;
use Tests\Traits\BuildsWave3ExitFixtures;

/**
 * Wave-3 COGS cutover characterization on real PostgreSQL root transactions.
 * Pins the retired invoice trigger, movement-keyed DN/RN/POS entries, original
 * return costs, terminal advisory order, rollback, and the cutover watermark.
 */
final class CogsRelocationCharacterisationTest extends TestCase
{
    use BuildsPosSaleReceiptEvents;
    use BuildsWave3ExitFixtures;
    use RefreshDatabase;

    /**
     * Inventory GL flushes are defined against the real application root
     * transaction. Laravel's test-only wrapper would make every writer appear
     * nested and intentionally defer forever, so this cutover suite observes
     * real root depths and commits.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWave3ExitFixtures();
    }

    // =================================================================
    // ACCEPTANCE CRITERION — run this first: the suite must be able to
    // SEE the listener. A green characterisation that survives the
    // listener's removal is proving nothing.
    // =================================================================

    public function test_invoice_posted_has_no_legacy_cogs_listener_and_revenue_still_posts(): void
    {
        $listeners = Event::getRawListeners()[InvoicePosted::class] ?? [];
        self::assertNotContains(
            'App\\Modules\\Inventory\\Listeners\\PostCOGSOnInvoice',
            $listeners,
            'The invoice-keyed legacy COGS listener must be retired by the cutover.',
        );

        $product = $this->physicalProduct(costPrice: '50.000000');
        $invoice = $this->postedInvoiceFor($product, quantity: '10.0000');

        self::assertSame(
            0,
            $this->journalEntryCount($invoice->id, 'cogs'),
            'Invoice posting must not create the retired invoice-keyed COGS entry.',
        );

        // …and the fixture is genuinely alive: the OTHER InvoicePosted listener
        // still produced the invoice's own GL entry, so `post()` really did
        // dispatch the event past DB::afterCommit.
        self::assertSame(
            1,
            $this->journalEntryCount($invoice->id, AccountingService::DOCUMENT_SOURCE_TYPE),
            'The invoice GL entry proves InvoicePosted fired; only the COGS listener was removed.',
        );
    }

    // =================================================================
    // (a) invoice-keyed COGS is retired
    // =================================================================

    public function test_a_posted_invoice_with_physical_lines_creates_no_invoice_keyed_cogs_entry(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $invoice = $this->postedInvoiceFor($product, quantity: '10.0000');

        self::assertSame(0, $this->journalEntryCount($invoice->id, 'cogs'));
        self::assertSame(1, $this->journalEntryCount($invoice->id, AccountingService::DOCUMENT_SOURCE_TYPE));
    }

    // =================================================================
    // (b) DN confirm posts movement-keyed COGS
    // =================================================================

    public function test_a_delivery_note_confirm_creates_a_classified_stock_movement(): void
    {
        // ✅ INVERTED BY T2 (this commit). BEFORE T2 the assertion here was
        // `assertNull($movement->reason)` — "today the DN exit movement is
        // unclassified". T2 makes it MovementReason::Delivery, which is what
        // makes the Wave-3 exit seam reachable at all. The persisted
        // `reference_type` byte is unchanged ('Document'); see
        // ExitMovementClassificationTest for the byte-identity guard.
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $deliveryNote = $this->confirmedDeliveryNoteFor($product, quantity: '10.0000');

        /** @var StockMovement $movement */
        $movement = StockMovement::query()
            ->where('reference_id', $deliveryNote->id)
            ->firstOrFail();

        self::assertSame(MovementReason::Delivery, $movement->reason);
        self::assertSame('Document', $movement->reference_type);
        self::assertSame(0, bccomp($this->numericString($movement->quantity), '-10', 4), 'sale magnitude is negative');
        self::assertSame(1, $this->journalEntryCount($movement->id, 'inventory_exit'));
    }

    // =================================================================
    // (c) both live POS writers are costed and post movement-keyed GL
    // =================================================================

    public function test_the_two_live_pos_writers_create_costed_movements(): void
    {
        // ✅ INVERTED BY T5. BEFORE T5 both assertions here were
        // `assertNull($movement->unit_cost)` / `->total_cost` — the POS writers
        // produced uncosted rows, so the Wave-3 exit seam (which reads the cost
        // from the MOVEMENT ROW) would have booked no COGS for any POS sale.
        //
        // NOTE (deviation recorded in the task report): plan T1 says "all three
        // POS writers (§0.6)". §0.6 as CORRECTED in Revision 2 (and §0b.8) lists
        // TWO live writers; `ReceiptCreationService::decrementStock` is a
        // RETIRED chokepoint (410 Gone) and was explicitly dropped from T5/T16.
        // Characterising it would characterise dead code, so this test pins the
        // two live writers and asserts the third path stays retired.
        $product = $this->physicalProduct(costPrice: '7.500000');
        $this->seedStock($product->id, '20.0000');

        $sale = $this->posSaleReceiptEvent(productId: $product->id, variantId: null, quantity: '2');
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($sale);

        $refund = $this->posRefundReceiptEvent($sale, productId: $product->id, variantId: null, quantity: '1');
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($refund);

        /** @var StockMovement $saleMovement */
        $saleMovement = StockMovement::query()
            ->where('company_id', $this->companyId)
            ->where('reason', 'pos_sale')
            ->firstOrFail();
        /** @var StockMovement $returnMovement */
        $returnMovement = StockMovement::query()
            ->where('company_id', $this->companyId)
            ->where('reason', 'pos_return')
            ->firstOrFail();

        self::assertSame('7.500000', $this->numericString($saleMovement->unit_cost));
        self::assertSame('15.000000', $this->numericString($saleMovement->total_cost));
        self::assertSame('7.500000', $this->numericString($returnMovement->unit_cost));
        self::assertSame('7.500000', $this->numericString($returnMovement->total_cost));

        // Both already carry the classification Wave 3 depends on.
        self::assertSame('pos_receipt', $saleMovement->reference_type);
        self::assertSame('pos_receipt', $returnMovement->reference_type);
        self::assertSame(1, $this->journalEntryCount($saleMovement->id, 'inventory_exit'));
        self::assertSame(1, $this->journalEntryCount($returnMovement->id, 'inventory_entry'));

        $saleInventoryEntry = JournalEntry::query()
            ->where('source_type', 'inventory_exit')
            ->where('source_id', $saleMovement->id)
            ->sole();
        $saleReceipt = Receipt::query()->findOrFail($saleMovement->reference_id);
        self::assertSame(
            $saleReceipt->posted_at->toDateString(),
            $saleInventoryEntry->entry_date->toDateString(),
            'inventory_exit retains the same receipt.posted_at basis used by pos_receipt GL',
        );
    }

    // =================================================================
    // (d) RN confirm posts a movement-keyed inventory entry
    // =================================================================

    public function test_a_return_note_confirm_creates_a_movement_keyed_inventory_entry(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $returnNote = $this->confirmedReturnNoteFor($product, quantity: '4.0000');

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reference_id', $returnNote->id)->sole();
        self::assertSame(1, $this->journalEntryCount($movement->id, 'inventory_entry'));
    }

    public function test_t11c_pair_one_delivery_writer_posts_only_after_its_inventory_loop(): void
    {
        $product = $this->physicalProduct(costPrice: '8.000000');
        $this->seedStock($product->id, '20.0000');

        $this->assertCompanyAdvisoryIsTerminal(
            fn (): Document => $this->confirmedDeliveryNoteFor($product, quantity: '2.0000'),
        );
    }

    public function test_t11c_pair_two_return_writer_posts_only_after_its_inventory_loop(): void
    {
        $product = $this->physicalProduct(costPrice: '8.000000');
        $this->seedStock($product->id, '20.0000');

        $this->assertCompanyAdvisoryIsTerminal(
            fn (): Document => $this->confirmedReturnNoteFor($product, quantity: '2.0000'),
        );
    }

    public function test_t11c_pair_three_pos_writer_posts_only_after_its_inventory_loop(): void
    {
        $product = $this->physicalProduct(costPrice: '8.000000');
        $this->seedStock($product->id, '20.0000');
        $sale = $this->posSaleReceiptEvent(productId: $product->id, variantId: null, quantity: '2');

        $this->assertCompanyAdvisoryIsTerminal(function () use ($sale): void {
            app(CompanyContext::class)->clear();
            $this->app->make(PosCoreReceiptProjection::class)->apply($sale);
        });
    }

    public function test_delivery_third_line_gl_failure_rolls_back_movements_seal_and_chain_sequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('[PG] the mid-flush failure probe uses a PostgreSQL trigger.');
        }

        $product = $this->physicalProduct(costPrice: '8.000000');
        $this->seedStock($product->id, '20.0000');
        $deliveryNote = $this->draftDocument(DocumentType::DeliveryNote, $product, '1.0000', 'DN-ROLLBACK');
        foreach ([2, 3] as $lineNumber) {
            $deliveryNote->lines()->create([
                'line_number' => $lineNumber,
                'product_id' => $product->id,
                'location_id' => $this->locationId,
                'description' => $product->name,
                'quantity' => '1.0000',
                'unit_price' => '100.00',
                'tax_rate' => 0,
                'line_total' => '100.00',
            ]);
        }
        $deliveryNote->update(['total' => '300.00']);
        $deliveryNote = $deliveryNote->fresh(['lines']);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION w3_m2_reject_third_inventory_exit() RETURNS trigger AS $$
            BEGIN
                IF NEW.source_type = 'inventory_exit'
                   AND (SELECT count(*) FROM journal_entries
                        WHERE company_id = NEW.company_id AND source_type = 'inventory_exit') >= 2 THEN
                    RAISE EXCEPTION 'w3 m2 induced third inventory exit failure' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER w3_m2_reject_third_inventory_exit
            BEFORE INSERT ON journal_entries
            FOR EACH ROW EXECUTE FUNCTION w3_m2_reject_third_inventory_exit();
            SQL);

        try {
            $this->app->make(DeliveryNoteService::class)->confirm($deliveryNote);
            self::fail('The third movement GL insert should fail.');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS w3_m2_reject_third_inventory_exit ON journal_entries; DROP FUNCTION IF EXISTS w3_m2_reject_third_inventory_exit();');
        }

        $deliveryNote->refresh();
        self::assertSame(DocumentStatus::Draft, $deliveryNote->status);
        self::assertNull($deliveryNote->fiscal_hash);
        self::assertNull($deliveryNote->chain_sequence);
        self::assertSame(0, StockMovement::query()->where('reference_id', $deliveryNote->id)->count());

        $confirmed = $this->app->make(DeliveryNoteService::class)->confirm($deliveryNote->fresh(['lines']));
        self::assertSame(1, $confirmed->chain_sequence);
        self::assertSame(3, StockMovement::query()->where('reference_id', $deliveryNote->id)->count());
    }

    public function test_delivery_without_inventory_accounts_still_confirms_and_warns(): void
    {
        Account::query()
            ->where('company_id', $this->companyId)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::CostOfGoodsSold,
                SystemAccountPurpose::Inventory,
            ])
            ->delete();
        Log::spy();

        $product = $this->physicalProduct(costPrice: '8.000000');
        $this->seedStock($product->id, '20.0000');
        $confirmed = $this->confirmedDeliveryNoteFor($product, quantity: '2.0000');
        $movement = StockMovement::query()->where('reference_id', $confirmed->id)->sole();

        self::assertSame(DocumentStatus::Confirmed, $confirmed->status);
        self::assertSame(0, $this->journalEntryCount($movement->id, 'inventory_exit'));
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => str_contains($message, 'GL accounts are not mapped'),
        )->once();
    }

    public function test_return_entry_uses_the_delivery_time_cost_after_live_wac_moves(): void
    {
        $product = $this->physicalProduct(costPrice: '3.000000');
        $invoice = $this->postedInvoiceFor($product, quantity: '4.0000');
        $product->update(['cost_price' => '12.000000']);

        $returnNote = $this->draftDocument(DocumentType::ReturnNote, $product, '2.0000', 'RN-COST');
        $returnNote->update(['source_document_id' => $invoice->id]);
        $confirmed = $this->app->make(ReturnNoteService::class)->confirm($returnNote->fresh(['lines']));

        $movement = StockMovement::query()->where('reference_id', $confirmed->id)->sole();
        self::assertSame('3.000000', (string) $movement->unit_cost);
        $entry = JournalEntry::query()
            ->where('source_type', 'inventory_entry')
            ->where('source_id', $movement->id)
            ->with('lines')
            ->sole();
        self::assertSame(0, bccomp('6.000', (string) $entry->lines->sum('debit'), 3));
        self::assertSame(0, bccomp('6.000', (string) $entry->lines->sum('credit'), 3));

        $basis = $confirmed->payload['return_cost_basis'][0] ?? null;
        self::assertSame('exit_movement', $basis['source'] ?? null);
        self::assertSame('3.000000', $basis['unit_cost'] ?? null);
        self::assertNotEmpty($basis['movement_ids'] ?? []);
    }

    public function test_pre_cutover_device_event_replayed_after_cutover_posts_cogs_by_server_creation_time(): void
    {
        $product = $this->physicalProduct(costPrice: '8.000000');
        $this->seedStock($product->id, '20.0000');
        $sale = $this->posSaleReceiptEvent(productId: $product->id, variantId: null, quantity: '2');
        Company::query()->findOrFail($this->companyId)->update([
            'inventory_gl_cutover_at' => $sale->event_time_device->copy()->addMinute(),
        ]);

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($sale);

        $movement = StockMovement::query()
            ->where('company_id', $this->companyId)
            ->where('reason', MovementReason::POSSale)
            ->sole();
        self::assertFalse((bool) $movement->is_historical);
        self::assertSame(1, $this->journalEntryCount($movement->id, 'inventory_exit'));
    }

    public function test_new_company_cutover_watermark_matches_its_creation_instant(): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        self::assertNotNull($company->inventory_gl_cutover_at);
        $deltaSeconds = DB::table('companies')
            ->where('id', $company->id)
            ->selectRaw('abs(extract(epoch from (inventory_gl_cutover_at - created_at))) AS delta_seconds')
            ->value('delta_seconds');
        self::assertLessThanOrEqual(
            1,
            (float) $deltaSeconds,
        );
    }

    // =================================================================
    // (e) service-only invoice → no COGS
    // =================================================================

    public function test_a_service_only_invoice_creates_no_cogs_entry(): void
    {
        $service = $this->nonPhysicalProduct();

        $invoice = $this->postedInvoiceFor($service, quantity: '3.0000');

        self::assertSame(0, $this->journalEntryCount($invoice->id, 'cogs'));
        self::assertSame(
            1,
            $this->journalEntryCount($invoice->id, AccountingService::DOCUMENT_SOURCE_TYPE),
            'revenue still posts',
        );
    }

    // =================================================================
    // (f) zero-cost movement changes stock but posts no zero-value GL
    // =================================================================

    public function test_a_zero_cost_product_is_silently_skipped(): void
    {
        $product = $this->physicalProduct(costPrice: '0.000000');

        $invoice = $this->postedInvoiceFor($product, quantity: '5.0000');
        $movement = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::Delivery)
            ->sole();

        self::assertSame(0, $this->journalEntryCount($invoice->id, 'cogs'));
        self::assertSame(0, $this->journalEntryCount($movement->id, 'inventory_exit'));
        self::assertSame(DocumentStatus::Posted, $invoice->fresh()?->status);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function journalEntryCount(string $sourceId, string $sourceType): int
    {
        return JournalEntry::query()
            ->where('source_id', $sourceId)
            ->where('source_type', $sourceType)
            ->count();
    }

    private function assertCompanyAdvisoryIsTerminal(callable $writer): void
    {
        /** @var list<array{sql: string, bindings: array<int, mixed>}> $trace */
        $trace = [];
        DB::listen(static function (QueryExecuted $query) use (&$trace): void {
            $trace[] = ['sql' => strtolower($query->sql), 'bindings' => array_values($query->bindings)];
        });

        $writer();

        $firstCompanyAdvisory = null;
        $lastInventoryStatement = null;
        foreach ($trace as $index => $query) {
            if ($firstCompanyAdvisory === null
                && str_contains($query['sql'], 'pg_advisory_xact_lock(hashtextextended')
                && ($query['bindings'][0] ?? null) === $this->companyId) {
                $firstCompanyAdvisory = $index;
            }
            if (str_contains($query['sql'], '"stock_levels"')
                || str_contains($query['sql'], '"stock_movements"')) {
                $lastInventoryStatement = $index;
            }
        }

        self::assertNotNull($firstCompanyAdvisory, 'production writer did not reach movement-keyed GL');
        self::assertNotNull($lastInventoryStatement, 'production writer did not reach inventory persistence');
        self::assertGreaterThan($lastInventoryStatement, $firstCompanyAdvisory);
    }
}
