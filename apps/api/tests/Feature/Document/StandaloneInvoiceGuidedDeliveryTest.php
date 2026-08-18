<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DeliveryRequiredBeforeInvoiceException;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T25c / D-30**: the REQUIRED path, end to end.
 *
 * A compliance gate whose compliant path is unreachable does not produce
 * compliance; it produces back-dating and cancel-and-recreate. T25b refuses the
 * standalone goods invoice — this is where that invoice goes.
 *
 * The load-bearing step is **step 2, the linkage write**. Revision 2 of the plan
 * omitted it entirely, and without it create → confirm → re-post is refused
 * AGAIN: nothing else in the system writes
 * `invoice.payload['source_delivery_note_ids']` except the DN → invoice
 * converter. {@see test_without_the_linkage_the_repost_is_still_refused} pins
 * exactly that, because it is the defect this task fixes.
 */
class StandaloneInvoiceGuidedDeliveryTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    /**
     * A physical line that names no location of its own.
     *
     * @return array<string, mixed>
     */
    private function dpUnlocatedPhysicalLine(string $quantity): array
    {
        $line = $this->dpPhysicalLine($quantity);
        unset($line['location_id']);

        return $line;
    }

    /**
     * 🚨 ONE TEST, ONE LEDGER — the acceptance criterion of D-30.
     */
    public function test_the_guided_flow_creates_confirms_links_and_posts(): void
    {
        $invoice = $this->dpConfirmedInvoice([
            $this->dpPhysicalLine('2.0000'),
            $this->dpServiceLine(),
        ]);

        $movementsBefore = StockMovement::query()->count();

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'posted');

        $invoice->refresh();

        // The invoice is posted and sealed.
        $this->assertSame(DocumentStatus::Posted, $invoice->status);
        $this->assertNotNull($invoice->fiscal_hash);

        // A delivery note was created AND confirmed.
        $deliveryNote = Document::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(DocumentStatus::Confirmed, $deliveryNote->status);
        $this->assertNotNull($deliveryNote->fiscal_hash);
        $this->assertSame($invoice->id, $deliveryNote->payload['invoice_id']);
        $this->assertSame(DeliveryNoteBillingLane::PrePostDelivery->value, $deliveryNote->payload['invoiced_via']);
        $this->assertDatabaseHas('delivery_note_billing_marks', [
            'delivery_note_id' => $deliveryNote->id,
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::PrePostDelivery->value,
            'company_id' => $this->dpCompany->id,
        ]);

        // Step 2 — the linkage the resolver reads.
        $this->assertSame(
            [$deliveryNote->id],
            $invoice->payload['source_delivery_note_ids'],
        );

        // Exactly ONE stock movement per PHYSICAL line — the service line moves
        // nothing, and nothing is issued twice.
        $this->assertSame(
            $movementsBefore + 1,
            StockMovement::query()->count(),
            'One inventory exit per physical line; the service line must not move stock.',
        );

        // 📌 DEVIATION, DISCLOSED: the factory copies a NULL-`product_id` line
        // (the service shape) exactly as `SalesOrderToInvoiceConverter` always
        // did — it skips only lines whose product resolves and is NOT physical.
        // T25c preserves that rather than tightening it, because the tightening
        // is D-19 / T4's disclosed behaviour change and T4 owns
        // `PhysicalLinePredicate`. It is inert here: the assertion above proves
        // the service line moves no stock.
        $this->assertCount(2, $deliveryNote->lines);
        $this->assertSame(
            1,
            $deliveryNote->lines->whereNotNull('product_id')->count(),
            'Exactly one goods line, and it is the physical one.',
        );
        $invoice->refresh()->load('lines');
        foreach ($invoice->lines as $sourceLine) {
            $this->assertSame(
                0,
                bccomp((string) $sourceLine->quantity, (string) $sourceLine->quantity_delivered, 4),
                'The unchanged factory must keep stamping each copied source line as fully delivered.',
            );
        }
    }

    public function test_a_guided_claim_failure_rolls_back_delivery_stock_linkage_and_posting(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);
        $before = [
            'delivery_notes' => Document::query()->where('type', DocumentType::DeliveryNote)->count(),
            'movements' => StockMovement::query()->count(),
            'claims' => DB::table('delivery_note_billing_marks')->count(),
            'delivery_numbers' => (int) DB::table('document_sequences')
                ->where('company_id', $this->dpCompany->id)
                ->where('type', DocumentType::DeliveryNote->value)
                ->sum('last_number'),
            'stored_events' => DB::table('stored_events')->count(),
            'audit_events' => DB::table('audit_events')->count(),
            'payload' => $invoice->payload,
            'quantity_delivered' => $invoice->lines
                ->mapWithKeys(static fn ($line): array => [$line->id => (string) $line->quantity_delivered])
                ->all(),
        ];
        $forced = false;

        DB::listen(static function ($query) use (&$forced): void {
            if (! $forced
                && str_contains(strtolower($query->sql), 'insert into')
                && str_contains($query->sql, 'delivery_note_billing_marks')) {
                $forced = true;
                throw new \DomainException('Force the real claim boundary to fail.');
            }
        });

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OPERATION_FAILED');

        $this->assertTrue($forced, 'The real marker insert boundary was not exercised.');
        $this->assertSame(
            $before['delivery_notes'],
            Document::query()->where('type', DocumentType::DeliveryNote)->count(),
        );
        $this->assertSame($before['movements'], StockMovement::query()->count());
        $this->assertSame($before['claims'], DB::table('delivery_note_billing_marks')->count());
        $this->assertSame(
            $before['delivery_numbers'],
            (int) DB::table('document_sequences')
                ->where('company_id', $this->dpCompany->id)
                ->where('type', DocumentType::DeliveryNote->value)
                ->sum('last_number'),
        );
        $this->assertSame($before['stored_events'], DB::table('stored_events')->count());
        $this->assertSame($before['audit_events'], DB::table('audit_events')->count());
        $this->assertSame(DocumentStatus::Confirmed, $invoice->refresh()->status);
        $this->assertSame($before['payload'], $invoice->payload);
        $this->assertSame(
            $before['quantity_delivered'],
            $invoice->lines
                ->mapWithKeys(static fn ($line): array => [$line->id => (string) $line->quantity_delivered])
                ->all(),
            'A failed claim must roll back the factory quantity-delivered stamps.',
        );
    }

    public function test_guided_delivery_preserves_the_source_line_location(): void
    {
        $lineLocation = Location::create([
            'company_id' => $this->dpCompany->id,
            'code' => 'DP-LINE-WH',
            'name' => 'Line Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ]);
        StockLevel::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'location_id' => $lineLocation->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);
        $line = $this->dpPhysicalLine('2.0000');
        $line['location_id'] = $lineLocation->id;
        $invoice = $this->dpConfirmedInvoice([$line]);

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(200);

        $movement = StockMovement::query()->where('reference_type', 'Document')->latest('created_at')->firstOrFail();
        $this->assertSame($lineLocation->id, $movement->location_id);
    }

    public function test_guided_delivery_refuses_a_failed_fefo_allocation_loudly(): void
    {
        $this->dpProduct->update(['requires_batch_tracking' => true]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DELIVERY_CANNOT_BE_GENERATED');
        $response->assertJsonPath('error.reason', 'FEFO_ALLOCATION_FAILED_CONFIRM_MANUALLY_WITH_BATCH');
        $response->assertJsonPath(
            'error.message',
            'Automatic FEFO allocation failed. Confirm the delivery manually and choose the batch explicitly.',
        );
        $this->assertSame(0, Document::query()
            ->where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $invoice->id)
            ->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_guided_delivery_fefo_refusal_has_a_french_operator_remedy(): void
    {
        $this->dpProduct->update(['requires_batch_tracking' => true]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $this->actingAs($this->dpUser)
            ->withHeader('X-Language', 'fr')
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_CANNOT_BE_GENERATED')
            ->assertJsonPath('error.reason', 'FEFO_ALLOCATION_FAILED_CONFIRM_MANUALLY_WITH_BATCH')
            ->assertJsonPath(
                'error.message',
                "L'allocation FEFO automatique a échoué. Confirmez la livraison manuellement et choisissez explicitement le lot.",
            );
    }

    /**
     * 🆕 THE NEGATIVE THAT NAMES THE DEFECT.
     *
     * Create a delivery note and confirm it, but skip the linkage write. The
     * resolver cannot see it, `hasEverIssuedGoods()` stays false, and the invoice
     * is refused a second time — with a confirmed delivery note and real stock
     * movements already on the ledger. That is the trap D-30 exists to close.
     */
    public function test_without_the_linkage_the_repost_is_still_refused(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        // A real, confirmed delivery note for the same goods — but no linkage.
        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('2.0000')]);

        $this->expectException(DeliveryRequiredBeforeInvoiceException::class);

        app(DocumentPostingService::class)->post($invoice);
    }

    /**
     * The endpoint is for the refused population ONLY. An order-sourced invoice
     * with draft delivery notes has a different remedy (confirm-deliveries-and-post)
     * and must not be routed here.
     */
    public function test_the_endpoint_refuses_an_invoice_that_does_not_need_a_delivery_note(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DELIVERY_CREATION_NOT_APPLICABLE');
        $this->assertSame(DocumentStatus::Confirmed, $invoice->refresh()->status);
    }

    /**
     * No regression on the order-sourced flow: it still goes through
     * confirm-deliveries-and-post, which the extraction did not touch.
     */
    public function test_the_order_sourced_flow_is_unchanged(): void
    {
        $draft = $this->dpDraftDeliveryNote([$this->dpPhysicalLine()], ['payload' => ['auto_created' => true]]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$draft]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/confirm-deliveries-and-post");

        $response->assertStatus(200);
        $this->assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
    }

    /**
     * The whole composite is ONE transaction: nothing is left half-done. Post the
     * same invoice twice — the second call finds it already posted and must not
     * generate a second delivery note or a second stock movement.
     */
    public function test_the_composite_is_not_repeatable_once_the_invoice_is_posted(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(200);

        $movementsAfterFirst = StockMovement::query()->count();

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(422);

        $this->assertSame($movementsAfterFirst, StockMovement::query()->count());
        $this->assertSame(
            1,
            Document::query()
                ->where('type', DocumentType::DeliveryNote)
                ->where('source_document_id', $invoice->id)
                ->count(),
        );
    }

    /**
     * 🚨 FIX ROUND 1 / inventory P2-3, DIRECTION A — the launch risk.
     *
     * The feasibility predicate required a location flagged `is_default`; the
     * endpoint resolved through `LocationContext::getDefaultLocation()`, which
     * FALLS BACK to the first active location. A tenant that never set the flag —
     * and nothing in the app forces it — therefore had every standalone goods
     * invoice declared un-generatable by the gate, with the endpoint that would
     * have worked unreachable behind it, and NO in-app remedy. That is D-30's
     * named failure mode.
     */
    public function test_the_guided_path_works_for_a_tenant_with_no_default_location_flag(): void
    {
        $this->dpLocation->update(['is_default' => false]);

        // A line that names NO location — the shape that makes the document fall
        // through to the company's location resolver, which is where the two
        // sides disagreed.
        $invoice = $this->dpConfirmedInvoice([$this->dpUnlocatedPhysicalLine('2.0000')]);

        // The gate must agree that the guided path is offerable...
        try {
            app(DocumentPostingService::class)->post($invoice);
            $this->fail('Expected the pre-delivery refusal.');
        } catch (DeliveryRequiredBeforeInvoiceException $e) {
            $this->assertTrue(
                $e->canAutoConfirm,
                'The only active location IS resolvable — the endpoint uses it.',
            );
            $this->assertNull($e->blockedReason);
        }

        // ...and the endpoint must actually run.
        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(200);

        $this->assertSame(
            $this->dpLocation->id,
            Document::query()
                ->where('type', DocumentType::DeliveryNote)
                ->where('source_document_id', $invoice->id)
                ->firstOrFail()
                ->location_id,
        );
    }

    /**
     * 🚨 FIX ROUND 1 / inventory P2-3, DIRECTION B — the mirror disagreement.
     *
     * The predicate stopped looking the moment the document named ANY location,
     * without checking that the location resolves for this company; the endpoint
     * scoped its lookup by `company_id` and refused when it did not. So the gate
     * promised a guided path the endpoint then declined. Both now ask the SAME
     * resolver, so they cannot disagree in either direction.
     */
    public function test_an_unresolvable_document_location_falls_back_the_same_way_on_both_sides(): void
    {
        $foreignLocation = Location::create([
            'company_id' => Company::create([
                'tenant_id' => $this->dpTenant->id,
                'name' => 'Other Co',
                'legal_name' => 'Other Co SARL',
                'tax_id' => 'OTHER-TAX',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'status' => CompanyStatus::Active,
            ])->id,
            'code' => 'FOREIGN-WH',
            'name' => 'Foreign Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        $invoice = $this->dpConfirmedInvoice(
            [$this->dpPhysicalLine('2.0000')],
            ['location_id' => $foreignLocation->id],
        );

        try {
            app(DocumentPostingService::class)->post($invoice);
            $this->fail('Expected the pre-delivery refusal.');
        } catch (DeliveryRequiredBeforeInvoiceException $e) {
            $this->assertTrue($e->canAutoConfirm);
        }

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(200);

        // The company's OWN default location, never the foreign one.
        $this->assertSame(
            $this->dpLocation->id,
            Document::query()
                ->where('type', DocumentType::DeliveryNote)
                ->where('source_document_id', $invoice->id)
                ->firstOrFail()
                ->location_id,
        );
    }

    /**
     * The alignment is not a softening: with NO usable location at all, BOTH
     * sides refuse, and the refusal names the reason.
     */
    public function test_with_no_active_location_both_sides_refuse_with_the_same_reason(): void
    {
        $this->dpLocation->update(['is_active' => false]);

        $invoice = $this->dpConfirmedInvoice([$this->dpUnlocatedPhysicalLine('2.0000')]);

        try {
            app(DocumentPostingService::class)->post($invoice);
            $this->fail('Expected the pre-delivery refusal.');
        } catch (DeliveryRequiredBeforeInvoiceException $e) {
            $this->assertFalse($e->canAutoConfirm);
            $this->assertSame('NO_RESOLVABLE_LOCATION', $e->blockedReason);
        }

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_CANNOT_BE_GENERATED');
    }

    /**
     * 🚨 FIX ROUND 1 / inventory P1-2 — THE LOSER OF A CONCURRENT DOUBLE-SUBMIT.
     *
     * The test above is a SEQUENTIAL replay: the second call arrives after the
     * first committed, so the pre-transaction `isConfirmed()` guard stops it. The
     * dangerous shape is the other one — both requests read the invoice while it
     * is still Confirmed and gate-refused, and the loser then applies a STALE
     * payload inside its own transaction:
     *
     *   - it clobbers `source_delivery_note_ids` and the T25e audit stamp on a
     *     document the winner has already SEALED (`payload` is not covered by the
     *     immutability trigger);
     *   - it confirms a FRESH draft delivery note, so the only-draft guard cannot
     *     fire, and the SAME goods are issued from stock a SECOND time;
     *   - `post()` then takes its idempotent early return, so the operator gets a
     *     200 and never learns.
     *
     * ── HOW THE RACE IS SIMULATED DETERMINISTICALLY ──
     * The winner's committed state is applied at `TransactionBeginning`: after the
     * loser has passed every pre-transaction check, at the exact instant its
     * transaction opens. A raw query-builder update is used deliberately — the
     * winner's effect must arrive in the DATABASE, underneath the loser's stale
     * in-memory model, which is precisely what a concurrent commit does. The fix
     * is to take the row lock as the FIRST statement in the transaction and
     * re-evaluate on the LOCKED row (the idiom this file's own
     * `DocumentPostingService::cancel()` already uses).
     */
    public function test_the_loser_of_a_concurrent_double_submit_is_refused_in_transaction(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        // The winner's delivery note: created and confirmed for real, but NOT yet
        // linked — so the loser's pre-transaction checks still see a refused,
        // eligible invoice, exactly as they would mid-race.
        $winnerNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('2.0000')]);

        $movementsBefore = StockMovement::query()->count();
        $payloadBefore = $invoice->refresh()->payload;
        $applied = false;

        Event::listen(TransactionBeginning::class, function () use (&$applied, $invoice, $winnerNote): void {
            if ($applied) {
                return;
            }
            $applied = true;

            // The winner commits: linkage written, invoice posted and sealed.
            DB::table('documents')->where('id', $invoice->id)->update([
                'status' => DocumentStatus::Posted->value,
                'payload' => json_encode(['source_delivery_note_ids' => [$winnerNote->id]]),
            ]);
        });

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post");

        $this->assertTrue($applied, 'The race was never simulated — the guard was not exercised.');
        $response->assertStatus(422);

        // No second delivery note.
        $this->assertSame(
            0,
            Document::query()
                ->where('type', DocumentType::DeliveryNote)
                ->where('source_document_id', $invoice->id)
                ->count(),
            'The loser must not create a second delivery note for the same goods.',
        );

        // No second stock issuance.
        $this->assertSame(
            $movementsBefore,
            StockMovement::query()->count(),
            'The loser must not issue the same goods from stock a second time.',
        );

        // 📌 The loser wrote NOTHING. That is the property that protects the
        // winner's sealed payload — its linkage and its T25e stamp — because the
        // only thing that could have overwritten them is this request's stale
        // `payload` assignment, and it never ran.
        //
        // (The winner's own row state cannot be asserted here: the simulated
        // commit is applied at `TransactionBeginning`, so it shares the loser's
        // transaction and rolls back with it. Injecting it on a second, truly
        // independent connection is not expressible against the shared in-memory
        // test database — the PG leg runs the identical assertions.)
        $this->assertSame(
            $payloadBefore,
            $invoice->refresh()->payload,
            'The loser must not write to a document another request is committing.',
        );
    }
}
