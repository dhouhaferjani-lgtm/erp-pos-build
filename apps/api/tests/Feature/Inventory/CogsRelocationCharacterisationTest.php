<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Listeners\PostCOGSOnInvoice;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Factories\CompanyFactory;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsPosSaleReceiptEvents;

/**
 * DPA Wave 3 · sub-wave 3A · **T1 — characterisation**.
 *
 * Pins TODAY's COGS/exit behaviour, BEFORE Wave 3 relocates COGS from the
 * invoice to the stock exit. Six facts (plan §4 T1 a–f):
 *
 *  (a) posting an invoice with physical lines creates exactly ONE
 *      `source_type='cogs'` journal entry keyed on the INVOICE id;
 *  (b) a delivery-note confirm creates a stock movement with `reason IS NULL`
 *      — **inverted by T2** (see the test's own note);
 *  (c) the two LIVE POS writers create movements with `unit_cost IS NULL`
 *      — **inverted by T5**;
 *  (d) a return-note confirm creates NO journal entry at all (§0.16 — the RN-GL
 *      leg is greenfield);
 *  (e) a service-only invoice creates no COGS entry;
 *  (f) a zero-cost product is silently skipped (`PostCOGSOnInvoice:145-152`).
 *
 * ## The `DB::afterCommit` mechanism — MEASURED, not assumed
 *
 * `InvoicePosted` is dispatched from `DB::afterCommit(...)`
 * (`DocumentPostingService::postWithFiscalChain`), so this suite only means
 * anything if those callbacks actually fire under the test harness.
 *
 * Plan §0b.9 (fiscal I-8) states they never do under `RefreshDatabase`, citing
 * `Illuminate\Database\DatabaseTransactionsManager::afterCommitCallbacksShouldBeExecuted()`
 * (`$level === 0`) against the level-1 test transaction, and therefore mandates
 * `connectionsToTransact() === []`. **That is false on this tree.**
 * `RefreshDatabase::beginDatabaseTransaction()` installs
 * `Illuminate\Foundation\Testing\DatabaseTransactionsManager` — a TEST-ONLY
 * subclass whose override returns `$level === 1`
 * (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/DatabaseTransactionsManager.php:56-59`,
 * Laravel 12.58.0), precisely so `afterCommit` fires inside the wrapping test
 * transaction. Measured both ways on PostgreSQL: identical results.
 *
 * This class therefore keeps the DEFAULT transactional `RefreshDatabase` — full
 * per-test isolation, no leaked rows — and proves the mechanism instead of
 * asserting it: `test_a_characterisation_that_cannot_see_the_listener_is_worthless()`
 * runs FIRST and de-registers only `PostCOGSOnInvoice`; the COGS entry must then
 * disappear while the invoice's own GL entry survives. Per the plan's acceptance
 * criterion, a characterisation that cannot detect the listener's absence is not
 * a characterisation.
 */
final class CogsRelocationCharacterisationTest extends TestCase
{
    use BuildsPosSaleReceiptEvents;
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        /** @var Company $company */
        $company = CompanyFactory::new()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        app(CompanyContext::class)->setCompanyId($this->companyId);

        (new FranceChartOfAccountsSeeder)->run($this->companyId, $this->tenantId);

        $location = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyId,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => true,
        ]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId]);
        $this->operatorId = $user->id;
        $this->actingAs($user);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $this->customer = Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => PartnerType::Customer,
            'name' => 'Characterisation Customer',
            'code' => 'CUST-'.Str::upper(Str::random(6)),
            'country_code' => 'FR',
        ]);
    }

    // =================================================================
    // ACCEPTANCE CRITERION — run this first: the suite must be able to
    // SEE the listener. A green characterisation that survives the
    // listener's removal is proving nothing.
    // =================================================================

    public function test_a_characterisation_that_cannot_see_the_listener_is_worthless(): void
    {
        $removed = $this->deregisterCogsListener();
        self::assertSame(
            1,
            $removed,
            'PostCOGSOnInvoice must be registered on InvoicePosted exactly once '
            .'(InventoryServiceProvider:77) — otherwise the rest of this suite is vacuous.',
        );

        $product = $this->physicalProduct(costPrice: '50.000000');
        $invoice = $this->postedInvoiceFor($product, quantity: '10.0000');

        self::assertSame(
            0,
            $this->journalEntryCount($invoice->id, 'cogs'),
            'With PostCOGSOnInvoice de-registered there must be NO cogs entry — '
            .'if one appears, the fixture is not driving the listener at all.',
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
    // (a) one COGS entry, keyed on the invoice id
    // =================================================================

    public function test_a_posted_invoice_with_physical_lines_creates_exactly_one_cogs_entry_keyed_on_the_invoice(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $invoice = $this->postedInvoiceFor($product, quantity: '10.0000');

        self::assertSame(1, $this->journalEntryCount($invoice->id, 'cogs'));

        /** @var JournalEntry $cogs */
        $cogs = JournalEntry::query()
            ->where('source_id', $invoice->id)
            ->where('source_type', 'cogs')
            ->with('lines')
            ->firstOrFail();

        // The POSTED LEDGER is the assertion surface: two lines, COGS debited
        // and Inventory credited for 10 x 50.
        self::assertSame(2, $cogs->lines->count());

        $debit = $cogs->lines->firstWhere(
            fn ($line): bool => bccomp($this->numeric($line->debit), '0', 3) > 0,
        );
        $credit = $cogs->lines->firstWhere(
            fn ($line): bool => bccomp($this->numeric($line->credit), '0', 3) > 0,
        );
        self::assertNotNull($debit);
        self::assertNotNull($credit);
        self::assertSame(0, bccomp($this->numeric($debit->debit), '500', 3), 'COGS debit is 10 x 50');
        self::assertSame(0, bccomp($this->numeric($credit->credit), '500', 3), 'Inventory credit is 10 x 50');
        self::assertSame('603', $this->accountCode($debit->account_id), 'FR CostOfGoodsSold purpose account');
        self::assertSame('37', $this->accountCode($credit->account_id), 'FR Inventory purpose account');
    }

    // =================================================================
    // (b) a DN confirm writes a movement with NO reason
    // =================================================================

    public function test_a_delivery_note_confirm_creates_a_stock_movement_with_a_null_reason(): void
    {
        // ⚠ INVERTED BY T2 — after T2 this movement carries
        // MovementReason::Delivery and `reference_type` is written through the
        // StockMovementReferenceType enum (same persisted string 'Document').
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $deliveryNote = $this->confirmedDeliveryNoteFor($product, quantity: '10.0000');

        /** @var StockMovement $movement */
        $movement = StockMovement::query()
            ->where('reference_id', $deliveryNote->id)
            ->firstOrFail();

        self::assertNull($movement->reason, 'Today the DN exit movement is unclassified.');
        self::assertSame('Document', $movement->reference_type);
        self::assertSame(0, bccomp($this->numeric($movement->quantity), '-10', 4), 'sale magnitude is negative');
    }

    // =================================================================
    // (c) both LIVE POS writers leave unit_cost NULL
    // =================================================================

    public function test_the_two_live_pos_writers_create_movements_with_a_null_unit_cost(): void
    {
        // ⚠ INVERTED BY T5 — after T5 both movements carry a non-null
        // `unit_cost`/`total_cost` snapshot at COST_SCALE = 6.
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
        $saleMovement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();
        /** @var StockMovement $returnMovement */
        $returnMovement = StockMovement::query()->where('reason', 'pos_return')->firstOrFail();

        self::assertNull($saleMovement->unit_cost, 'decrementStockForLines writes no cost today');
        self::assertNull($saleMovement->total_cost);
        self::assertNull($returnMovement->unit_cost, 'restockForLines writes no cost today');
        self::assertNull($returnMovement->total_cost);

        // Both already carry the classification Wave 3 depends on.
        self::assertSame('pos_receipt', $saleMovement->reference_type);
        self::assertSame('pos_receipt', $returnMovement->reference_type);
    }

    // =================================================================
    // (d) an RN confirm posts nothing to the ledger
    // =================================================================

    public function test_a_return_note_confirm_creates_no_journal_entry(): void
    {
        $product = $this->physicalProduct(costPrice: '50.000000');
        $this->seedStock($product->id, '100.0000');

        $before = JournalEntry::query()->where('company_id', $this->companyId)->count();

        $returnNote = $this->confirmedReturnNoteFor($product, quantity: '4.0000');

        self::assertSame(
            0,
            JournalEntry::query()->where('source_id', $returnNote->id)->count(),
            'RN-GL is greenfield in Wave 3 (§0.16) — nothing is posted today.',
        );
        self::assertSame(
            $before,
            JournalEntry::query()->where('company_id', $this->companyId)->count(),
            'An RN confirm must not post ANY journal entry today.',
        );

        // The stock DID come back — the movement exists without a ledger twin.
        self::assertSame(
            1,
            StockMovement::query()->where('reference_id', $returnNote->id)->count(),
        );
    }

    // =================================================================
    // (e) service-only invoice → no COGS
    // =================================================================

    public function test_a_service_only_invoice_creates_no_cogs_entry(): void
    {
        $service = Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'sku' => 'SVC-'.Str::upper(Str::random(6)),
            'name' => 'Labour',
            'type' => ProductType::Service,
            'unit' => 'hour',
            'cost_price' => '20.000000',
            'sale_price' => '80.00',
            'tax_rate' => 0,
            'is_active' => true,
            'is_physical' => false,
        ]);

        $invoice = $this->postedInvoiceFor($service, quantity: '3.0000');

        self::assertSame(0, $this->journalEntryCount($invoice->id, 'cogs'));
        self::assertSame(
            1,
            $this->journalEntryCount($invoice->id, AccountingService::DOCUMENT_SOURCE_TYPE),
            'revenue still posts',
        );
    }

    // =================================================================
    // (f) zero-cost physical product → silently skipped
    // =================================================================

    public function test_a_zero_cost_product_is_silently_skipped(): void
    {
        $product = $this->physicalProduct(costPrice: '0.000000');

        $invoice = $this->postedInvoiceFor($product, quantity: '5.0000');

        self::assertSame(
            0,
            $this->journalEntryCount($invoice->id, 'cogs'),
            'A zero-cost line is dropped by extractPhysicalProductLines and the '
            .'whole entry is skipped — silently, with only a debug log.',
        );
        self::assertSame(DocumentStatus::Posted, $invoice->fresh()?->status);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * De-register ONLY `PostCOGSOnInvoice` from `InvoicePosted`, keeping every
     * other listener registered.
     *
     * @return int how many `PostCOGSOnInvoice` registrations were removed
     */
    private function deregisterCogsListener(): int
    {
        /** @var array<string, list<mixed>> $raw */
        $raw = Event::getRawListeners();
        $listeners = $raw[InvoicePosted::class] ?? [];

        Event::forget(InvoicePosted::class);

        $removed = 0;
        foreach ($listeners as $listener) {
            if ($listener === PostCOGSOnInvoice::class) {
                $removed++;

                continue;
            }

            Event::listen(InvoicePosted::class, $listener);
        }

        return $removed;
    }

    private function physicalProduct(string $costPrice): Product
    {
        return Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'sku' => 'PROD-'.Str::upper(Str::random(6)),
            'name' => 'Characterisation Widget',
            'type' => ProductType::Part,
            'unit' => 'piece',
            'cost_price' => $costPrice,
            'sale_price' => '100.00',
            'purchase_price' => '45.00',
            'tax_rate' => 0,
            'is_active' => true,
            'is_physical' => true,
        ]);
    }

    private function seedStock(string $productId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function postedInvoiceFor(Product $product, string $quantity): Document
    {
        $invoice = $this->draftDocument(DocumentType::Invoice, $product, $quantity, 'INV');
        $invoice->update(['status' => DocumentStatus::Confirmed]);

        /** @var Document $confirmed */
        $confirmed = $invoice->fresh(['lines']);

        return $this->app->make(DocumentPostingService::class)->post($confirmed);
    }

    private function confirmedDeliveryNoteFor(Product $product, string $quantity): Document
    {
        $deliveryNote = $this->draftDocument(DocumentType::DeliveryNote, $product, $quantity, 'DN');

        return $this->app->make(DeliveryNoteService::class)->confirm($deliveryNote);
    }

    private function confirmedReturnNoteFor(Product $product, string $quantity): Document
    {
        $returnNote = $this->draftDocument(DocumentType::ReturnNote, $product, $quantity, 'RN');

        return $this->app->make(ReturnNoteService::class)->confirm($returnNote);
    }

    private function draftDocument(DocumentType $type, Product $product, string $quantity, string $prefix): Document
    {
        /** @var numeric-string $quantity */
        $lineTotal = bcmul($quantity, '100.00', 2);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'partner_id' => $this->customer->id,
            'location_id' => $this->locationId,
            'document_number' => $prefix.'-W3A-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => $lineTotal,
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'location_id' => $this->locationId,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'tax_rate' => 0,
            'line_total' => $lineTotal,
        ]);

        /** @var Document $fresh */
        $fresh = $document->fresh(['lines']);

        return $fresh;
    }

    private function journalEntryCount(string $sourceId, string $sourceType): int
    {
        return JournalEntry::query()
            ->where('source_id', $sourceId)
            ->where('source_type', $sourceType)
            ->count();
    }

    /**
     * Narrow a decimal-column read to `numeric-string` for bcmath (no float ever
     * touches money — house rule 19).
     *
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        $string = (string) $value; // @phpstan-ignore-line cast.string
        self::assertTrue(is_numeric($string), 'decimal column must read back numeric');

        /** @var numeric-string $string */
        return $string;
    }

    private function accountCode(string $accountId): string
    {
        /** @var object{code: string} $row */
        $row = DB::table('accounts')->where('id', $accountId)->first(['code']);

        return $row->code;
    }
}
