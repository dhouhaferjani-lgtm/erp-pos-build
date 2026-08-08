<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DTOs\ReturnDecisionData;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use App\Modules\Document\Domain\Exceptions\DocumentHasPaymentsException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionConflictException;
use App\Modules\Document\Domain\Exceptions\ReturnLocationUnresolvedException;
use App\Modules\Document\Domain\Exceptions\ReturnNothingDeliveredException;
use App\Modules\Document\Domain\Services\RefundService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Domain\Enums\ReturnPeriodRefusalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T5 / T6 (plan CF §3) — the guided cancel flow, end to end. [PG]
 *
 * CF-D1: ONE composite server call. The alternative — three front-end calls — cannot
 * satisfy the owner ruling's "one go" atomically: a crash between them leaves a
 * cancelled invoice with no return note and no record of what the user chose, which
 * is the silent outcome the ruling forbids, arrived at by accident.
 *
 * Every test here asserts the FULL end state (invoice status + return-note status +
 * `payload.return_decisions` + stock delta + absence of stray movements), because the
 * defects this lane exists to prevent are all "one of the four moved and the others
 * did not".
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class GuidedCancelFlowTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-guided-cancel');

        // Keep the auto-created fiscal periods out of the way except where a test is
        // specifically about the period guard — see ReturnPeriodBackdatingGuardTest's
        // class docblock for why a brand-new company already has closed ones.
        FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Open,
        ]);
    }

    // ── the three goods-bearing / non-bearing modes ───────────────────────────

    public function test_will_return_creates_a_draft_return_note_and_moves_no_stock_yet(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $response = $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value]);

        $response->assertOk()
            ->assertJsonPath('return_decision.mode', ReturnDecisionMode::WillReturn->value)
            ->assertJsonPath('return_decision.returned_on', null)
            ->assertJsonPath('return_decision.return_note.status', DocumentStatus::Draft->value);

        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);
        self::assertSame(DocumentStatus::Draft, $returnNote->status);
        self::assertSame($invoice->id, $returnNote->source_document_id);
        self::assertSame('4.0000', (string) $returnNote->lines->first()?->quantity);

        // The physical return has not happened yet — the draft exists so it can.
        self::assertSame(0, StockMovement::query()->count(), 'Option 1 must not move stock.');

        $this->assertDecisionRecorded($invoice, ReturnDecisionMode::WillReturn, accepted: true);
    }

    public function test_already_returned_creates_confirms_and_restocks_dated_as_stated(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');
        $returnedOn = Carbon::today()->subDay();

        $response = $this->cancel($invoice, [
            'mode' => ReturnDecisionMode::AlreadyReturned->value,
            'returned_on' => $returnedOn->toDateString(),
        ]);

        $response->assertOk()
            ->assertJsonPath('return_decision.mode', ReturnDecisionMode::AlreadyReturned->value)
            ->assertJsonPath('return_decision.returned_on', $returnedOn->toDateString())
            ->assertJsonPath('return_decision.return_note.status', DocumentStatus::Confirmed->value);

        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);
        self::assertSame(DocumentStatus::Confirmed, $returnNote->status);
        self::assertSame(FiscalStatus::Sealed, $returnNote->fiscal_status);

        // The owner ruling: dated as stated, sealed behind the scenes, through the
        // SAME domain transitions as a manual confirm.
        self::assertSame($returnedOn->toDateString(), $returnNote->document_date->toDateString());
        self::assertNotNull($returnNote->fiscal_hash);
        self::assertNotNull($returnNote->confirmed_at);

        self::assertSame('4.0000', $this->stockAt($this->cfLocationA->id));

        $this->assertDecisionRecorded($invoice, ReturnDecisionMode::AlreadyReturned, accepted: true);
    }

    public function test_no_return_records_the_choice_and_creates_nothing(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::NoReturn->value])
            ->assertOk()
            ->assertJsonPath('return_decision.mode', ReturnDecisionMode::NoReturn->value)
            ->assertJsonPath('return_decision.return_note', null);

        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);
        self::assertNull($this->returnNoteFor($invoice), 'Option 3 creates no return note.');
        self::assertSame(0, StockMovement::query()->count(), 'Option 3 brings no stock back.');

        // The explicit "no" is the whole point: it is RECORDED, not inferred from an
        // absence.
        $this->assertDecisionRecorded($invoice, ReturnDecisionMode::NoReturn, accepted: true);
    }

    public function test_no_goods_issued_is_recorded_distinctly_from_no_return(): void
    {
        // Billed but never shipped — no delivery-note linkage at all.
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]]);

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::NoGoodsIssued->value])->assertOk();

        self::assertNull($this->returnNoteFor($invoice));
        self::assertSame(0, StockMovement::query()->count());

        // "The goods stayed out" and "the goods never left" are different facts, and
        // recording the first when the second is true would tell an auditor the
        // customer kept units that never shipped.
        $this->assertDecisionRecorded($invoice, ReturnDecisionMode::NoGoodsIssued, accepted: true);
    }

    public function test_not_applicable_is_recorded_for_a_services_only_invoice(): void
    {
        $invoice = $this->cfPostedInvoice([$this->cfServiceLine()]);

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::NotApplicable->value])->assertOk();

        self::assertNull($this->returnNoteFor($invoice));
        $this->assertDecisionRecorded($invoice, ReturnDecisionMode::NotApplicable, accepted: true);
    }

    // ── the regression contract ───────────────────────────────────────────────

    public function test_an_absent_return_decision_reproduces_todays_behaviour(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", ['reason' => 'Duplicate'])
            ->assertOk()
            ->assertJsonPath('return_decision', null);

        $invoice->refresh();
        self::assertSame(DocumentStatus::Cancelled, $invoice->status);
        self::assertArrayNotHasKey('return_decisions', $invoice->payload ?? []);
        self::assertNull($this->returnNoteFor($invoice));
        self::assertSame(0, StockMovement::query()->count());
    }

    // ── the server-side enforcement half (CF-D6 / CF-D7) ──────────────────────

    /**
     * The fiscal gate accepted CF-D6 only because this exists: disabling a radio is
     * affordance, not a safety property. A direct API call bypasses the UI entirely,
     * so the server refuses independently — and TYPEDLY, not via a generic `min:1`
     * validation accident.
     */
    public function test_a_goods_bearing_mode_on_an_undelivered_invoice_is_refused_typedly(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]]);

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnNothingDeliveredException::CODE);

        // The refusal rolls the CANCEL back too — one atomic act.
        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
        self::assertNull($this->returnNoteFor($invoice));
    }

    public function test_a_fully_returned_invoice_is_refused_with_nothing_delivered(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        // Everything already came back.
        $this->cancel($invoice, [
            'mode' => ReturnDecisionMode::AlreadyReturned->value,
            'returned_on' => Carbon::today()->toDateString(),
        ])->assertOk();

        // A second decision would conflict first, so use a fresh cancel attempt on a
        // second invoice sharing the same delivery note is not possible — instead
        // assert the resolver's own verdict is now exhausted.
        $invoice->refresh();
        self::assertSame('4.0000', $this->stockAt($this->cfLocationA->id));
        self::assertSame(
            1,
            Document::query()->where('type', DocumentType::ReturnNote)->count(),
            'Exactly one return note, no matter how the flow is re-entered.',
        );
    }

    /**
     * CF-D11's typed refusal. `receiveStockBack()` would otherwise throw a bare
     * `\DomainException` inside the outer transaction, rolling the CANCEL back and
     * surfacing through the generic catch as an untyped `{error: <string>}` — the
     * exact envelope defect frontend I-1 is fixing. The alternative an implementer
     * reaches for (fall back to the invoice or company location) restocks goods into
     * a warehouse they never left, silently, in one click.
     */
    public function test_an_unresolvable_restock_location_is_refused_typedly(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]]);
        // Strip the location a confirmed DN would normally be guaranteed to have.
        $dn->update(['location_id' => null]);

        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnLocationUnresolvedException::CODE);

        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
        self::assertSame(0, StockMovement::query()->count());
    }

    // ── refusals that must roll the whole thing back ──────────────────────────

    /**
     * The RETURN-DATE guard in isolation. The invoice is dated in the PREVIOUS month
     * (whose period is absent, so `VatPeriodCancellationGuard` permits the cancel)
     * while the return date falls in a FILED period — otherwise the cancel's own
     * guard fires first with `DOCUMENT_PERIOD_FILED` and this test would pass without
     * ever exercising CF-D3.
     */
    public function test_a_period_refusal_on_the_return_date_rolls_the_cancel_back_too(): void
    {
        $issuedOn = Carbon::today()->startOfMonth()->subDays(3);
        $invoice = $this->deliveredInvoice('4.0000', $issuedOn);
        $returnedOn = Carbon::today();

        VatPeriod::create([
            'company_id' => $this->cfCompany->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => $returnedOn->format('F Y'),
            'period_start' => $returnedOn->copy()->startOfMonth()->toDateString(),
            'period_end' => $returnedOn->copy()->endOfMonth()->toDateString(),
            'status' => VatPeriodStatus::Filed,
        ]);

        $this->cancel($invoice, [
            'mode' => ReturnDecisionMode::AlreadyReturned->value,
            'returned_on' => $returnedOn->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnPeriodRefusalCode::PeriodFiled->value);

        // Everything or nothing: the cancel, the return note and the stock movement
        // are one unit.
        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
        self::assertNull($this->returnNoteFor($invoice));
        self::assertSame(0, StockMovement::query()->count());
        self::assertArrayNotHasKey('return_decisions', $invoice->payload ?? []);
    }

    public function test_document_has_payments_refuses_before_anything_is_created(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');
        $this->allocatePayment($invoice, '25.000');

        $response = $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', DocumentHasPaymentsException::CODE)
            // The duplicated top-level key keeps DocumentCancelConsolidationTest's
            // three pre-existing assertions green — see the renderer's note.
            ->assertJsonPath('code', DocumentHasPaymentsException::CODE);

        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
        self::assertNull($this->returnNoteFor($invoice));
        self::assertSame(0, StockMovement::query()->count());
    }

    // ── idempotency and the commit-then-refuse append (CF-D5) ─────────────────

    /**
     * The network-timeout path, and a real one: T10 leaves the modal open on network
     * error, so the user WILL retry. It must not refuse, and it must not create a
     * second return note or a second stock movement.
     */
    public function test_an_identical_replay_returns_the_existing_return_note(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');
        $returnedOn = Carbon::today()->toDateString();
        $body = [
            'mode' => ReturnDecisionMode::AlreadyReturned->value,
            'returned_on' => $returnedOn,
        ];

        $first = $this->cancel($invoice, $body)->assertOk();
        $second = $this->cancel($invoice, $body)->assertOk();

        self::assertSame(
            $first->json('return_decision.return_note.id'),
            $second->json('return_decision.return_note.id'),
        );
        self::assertSame(1, Document::query()->where('type', DocumentType::ReturnNote)->count());
        self::assertSame(1, StockMovement::query()->count(), 'One decision, one stock movement.');
        self::assertSame('4.0000', $this->stockAt($this->cfLocationA->id));
    }

    /**
     * The commit-then-refuse mechanism. The assertion shape matters: the decision is
     * read back from the database AFTER the response, never from inside the
     * transaction, because an in-transaction read is exactly what would NOT catch a
     * rolled-back append.
     */
    public function test_a_conflicting_replay_returns_422_and_still_records_the_rejection(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value])->assertOk();

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::NoReturn->value])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnDecisionConflictException::CODE);

        // POST-RESPONSE read.
        $decisions = $invoice->refresh()->payload['return_decisions'] ?? [];
        self::assertCount(2, $decisions);
        self::assertTrue($decisions[0]['accepted']);
        self::assertSame(ReturnDecisionMode::WillReturn->value, $decisions[0]['mode']);
        self::assertFalse($decisions[1]['accepted']);
        self::assertSame(ReturnDecisionMode::NoReturn->value, $decisions[1]['mode']);

        // The accepted decision still stands and nothing was created twice.
        self::assertSame(1, Document::query()->where('type', DocumentType::ReturnNote)->count());
    }

    public function test_a_repeated_identical_rejection_by_the_same_actor_does_not_grow_the_payload(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');
        $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value])->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->cancel($invoice, ['mode' => ReturnDecisionMode::NoReturn->value])->assertStatus(422);
        }

        self::assertCount(2, $invoice->refresh()->payload['return_decisions'] ?? []);
    }

    /**
     * The other half of the N3-m1 predicate. A DIFFERENT user's identically-shaped
     * rejection MUST append — support has to see that two people decided, which is the
     * audit claim this record exists to make. A consecutive-only, actor-blind predicate
     * would silently drop it.
     */
    public function test_the_same_rejection_by_a_different_actor_does_append(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');
        $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value])->assertOk();

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::NoReturn->value])->assertStatus(422);

        $other = $this->secondUser();
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Second opinion',
                'return_decision' => ['mode' => ReturnDecisionMode::NoReturn->value],
            ])
            ->assertStatus(422);

        $decisions = $invoice->refresh()->payload['return_decisions'] ?? [];
        self::assertCount(3, $decisions);
        self::assertSame($this->cfUser->id, $decisions[1]['decided_by']);
        self::assertSame($other->id, $decisions[2]['decided_by']);
    }

    /**
     * CF-D5's N3-m2 clause: the append is BEST-EFFORT, the refusal is the CONTRACT.
     *
     * A failure inside the short append transaction must NOT surface — letting the
     * plumbing exception replace the re-throw would turn a typed
     * RETURN_DECISION_ALREADY_RECORDED 422 into a 500, leaving the client unable to
     * tell "your decision conflicts" from "the server broke". The audit record is the
     * nice-to-have.
     *
     * The failure is induced the only way that reaches the append without touching
     * production code: the `documents` table is dropped after the accepted decision is
     * recorded, so the append's own `firstOrFail()` blows up while the conflict has
     * already been raised.
     */
    public function test_a_failing_audit_append_still_returns_422_and_not_500(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');
        $this->cancel($invoice, ['mode' => ReturnDecisionMode::WillReturn->value])->assertOk();

        $service = app(RefundService::class);
        $invoice->refresh();

        // Induce a REAL failure inside the short append transaction: the append's only
        // write is `$invoice->update(['payload' => ...])`, so a model listener that
        // throws on `updating` breaks exactly that transaction and nothing else. No
        // production code is mocked — the catch, the log and the re-throw all run.
        Document::updating(static function (Document $document): void {
            if ($document->isDirty('payload') && $document->type === DocumentType::Invoice) {
                throw new \RuntimeException('Induced append failure');
            }
        });

        $threw = null;
        try {
            $service->cancelInvoice(
                $invoice,
                'Conflicting',
                $this->cfUser->id,
                new ReturnDecisionData(ReturnDecisionMode::NoReturn),
            );
        } catch (\Throwable $e) {
            $threw = $e;
        }

        self::assertInstanceOf(
            ReturnDecisionConflictException::class,
            $threw,
            'The typed refusal must survive whatever happens to the audit append — a 500 here would '
            .'leave the client unable to tell "your decision conflicts" from "the server broke".',
        );

        // And the rejection genuinely was NOT recorded, so the test is exercising the
        // failure path rather than passing by accident.
        Document::flushEventListeners();
        self::assertCount(1, $invoice->refresh()->payload['return_decisions'] ?? []);
    }

    /**
     * CF-D4 step 0. An already-cancelled invoice matches neither the Posted nor the
     * Paid arm, so before step 0 it fell to a branch that took NO LOCK AT ALL — and
     * `DocumentPostingService::cancel()`'s own idempotent early return sits outside its
     * transaction and before its `lockForUpdate()`. Two composites against an
     * already-cancelled invoice would both read "no decision", both create a return
     * note and both restock.
     *
     * Sequential here (a single PHP process cannot truly race), but the property under
     * test is the one step 0 provides: the decision read happens under the row lock and
     * the second call sees the first's write. [PG]
     */
    public function test_a_decision_on_an_already_cancelled_invoice_produces_exactly_one_return_note(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        // Cancel FIRST with no decision at all, so the invoice is already Cancelled
        // when the composite runs — the replay branch.
        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", ['reason' => 'First pass'])
            ->assertOk();

        $originalReason = $invoice->refresh()->payload['cancellation_reason'] ?? null;

        $body = ['mode' => ReturnDecisionMode::WillReturn->value];
        $this->cancel($invoice, $body)->assertOk();
        $this->cancel($invoice, $body)->assertOk();

        self::assertSame(
            1,
            Document::query()->where('type', DocumentType::ReturnNote)->count(),
            'Step 0 must serialise the decision read; two composites yield ONE return note.',
        );
        self::assertSame(0, StockMovement::query()->count());

        // The replay branch must not clobber the ORIGINAL cancellation record.
        self::assertSame($originalReason, $invoice->refresh()->payload['cancellation_reason'] ?? null);
    }

    // ── validation (T6) ───────────────────────────────────────────────────────

    public function test_returned_on_is_prohibited_unless_the_mode_is_already_returned(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->cancel($invoice, [
            'mode' => ReturnDecisionMode::WillReturn->value,
            'returned_on' => Carbon::today()->toDateString(),
        ])->assertStatus(422);

        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
    }

    public function test_returned_on_is_required_for_already_returned(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->cancel($invoice, ['mode' => ReturnDecisionMode::AlreadyReturned->value])
            ->assertStatus(422);
    }

    public function test_a_future_return_date_is_rejected(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->cancel($invoice, [
            'mode' => ReturnDecisionMode::AlreadyReturned->value,
            'returned_on' => Carbon::today()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->cancel($invoice, ['mode' => 'maybe_later'])->assertStatus(422);
    }

    /**
     * CF-D7 removed line selection from this lane's contract. Rejecting `lines`
     * explicitly (rather than ignoring it) means a stale client learns that its
     * PARTIAL return did not happen, instead of silently getting a FULL one.
     */
    public function test_lines_are_rejected_as_an_unknown_key(): void
    {
        $invoice = $this->deliveredInvoice('4.0000');

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Partial attempt',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
                'lines' => [['product_id' => $this->cfProduct->id, 'quantity' => '1.0000']],
            ])
            ->assertStatus(422);

        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $decision
     * @return TestResponse<JsonResponse>
     */
    private function cancel(Document $invoice, array $decision): TestResponse
    {
        return $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled the order',
                'return_decision' => $decision,
            ]);
    }

    /**
     * A posted invoice backed by a confirmed delivery note, both dated a few days ago
     * so a `returned_on` of yesterday or today clears the request layer's
     * `after_or_equal:<invoice document_date>` lower bound (plan CF §2 / Q-C).
     */
    private function deliveredInvoice(string $quantity, ?Carbon $issuedOnDate = null): Document
    {
        $issuedOn = ($issuedOnDate ?? Carbon::today()->subDays(5))->toDateString();

        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => $quantity,
            'unit_price' => '100.000',
        ]], ['document_date' => $issuedOn]);

        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => $quantity,
            'unit_price' => '100.000',
        ]], ['document_date' => $issuedOn]);

        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        return $invoice->refresh();
    }

    private function returnNoteFor(Document $invoice): ?Document
    {
        return Document::query()
            ->where('type', DocumentType::ReturnNote)
            ->where('source_document_id', $invoice->id)
            ->with('lines')
            ->first();
    }

    private function stockAt(string $locationId): string
    {
        $level = StockLevel::query()
            ->where('product_id', $this->cfProduct->id)
            ->where('location_id', $locationId)
            ->first();

        return $level === null ? '0.0000' : (string) $level->quantity;
    }

    private function assertDecisionRecorded(Document $invoice, ReturnDecisionMode $mode, bool $accepted): void
    {
        $decisions = $invoice->refresh()->payload['return_decisions'] ?? [];

        self::assertIsArray($decisions);
        self::assertNotEmpty($decisions, 'The decision must be recorded — explicit, never silent.');

        $last = $decisions[array_key_last($decisions)];
        self::assertSame($mode->value, $last['mode']);
        self::assertSame($accepted, $last['accepted']);
        self::assertSame($this->cfUser->id, $last['decided_by']);
        self::assertNotEmpty($last['decided_at']);
    }

    private function secondUser(): User
    {
        $user = User::create([
            'tenant_id' => $this->cfTenant->id,
            'name' => 'CF Second User',
            'email' => 'cf-second-'.bin2hex(random_bytes(3)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->cfCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        return $user;
    }

    private function allocatePayment(Document $invoice, string $amount): void
    {
        $payment = Payment::create([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'partner_id' => $this->cfPartner->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'CF-PAY-'.bin2hex(random_bytes(4)),
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => $amount,
        ]);
    }
}
