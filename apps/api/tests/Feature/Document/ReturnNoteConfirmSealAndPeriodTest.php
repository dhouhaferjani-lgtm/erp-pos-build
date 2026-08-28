<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteData;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteLineData;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Shared\Domain\Enums\ReturnPeriodRefusalCode;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T4 (plan CF §3) — `ReturnNoteService::confirm()` persists the seal moment, guards
 * the date, and becomes composable. [PG]
 *
 * Three properties, each with its own failure mode:
 *
 * (a) **The seal moment is persisted.** `confirmWithFiscalChain()` hashes
 *     `$confirmedAt->toDateString()` but used to write neither `confirmed_at` nor
 *     `confirmed_by`, unlike `DeliveryNoteService`. That destroyed the hash's own
 *     date input at write time, so a return note whose `document_date` differs from
 *     its confirm day could not be re-verified. Backdating — option 2 of the guided
 *     cancel flow — makes that the NORMAL case, which is why T13 files the verifier
 *     ticket as a live P1.
 *
 * (b) **The date is guarded, once, for both paths.** The guard sits in
 *     `confirmWithin()` and therefore binds the manual route and the composite
 *     alike. A refusal must write NOTHING — no stock, no seal, no chain slot.
 *
 * (c) **`confirmWithin()` is not a side channel.** The type and status assertions
 *     moved INTO it (fiscal gate I-6), so the composite cannot use it to seal a
 *     non-draft or non-return-note document.
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class ReturnNoteConfirmSealAndPeriodTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private ReturnNoteService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-confirm-seal');
        $this->service = app(ReturnNoteService::class);
    }

    // ── (a) seal moment ────────────────────────────────────────────────────────

    public function test_a_confirm_persists_confirmed_at_and_confirmed_by(): void
    {
        $returnNote = $this->draftReturnNote(Carbon::today());

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $confirmed = $this->service->confirm($returnNote, $this->cfUser->id);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        self::assertSame(DocumentStatus::Confirmed, $confirmed->status);
        self::assertSame(FiscalStatus::Sealed, $confirmed->fiscal_status);
        self::assertNotNull($confirmed->confirmed_at);
        self::assertSame($this->cfUser->id, $confirmed->confirmed_by);
        self::assertNotNull($confirmed->fiscal_hash);
        $this->assertConditionalNumberAndStatusUpdate(
            $queries,
            $returnNote->id,
            'Return-note allocation and Draft → Confirmed must share one document UPDATE.',
        );
    }

    public function test_confirmation_locks_the_target_return_note_row(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL exposes SELECT ... FOR UPDATE in the query log.');
        }

        $returnNote = $this->draftReturnNote(Carbon::today());
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });

        $this->service->confirm($returnNote, $this->cfUser->id);

        self::assertTrue(
            collect($queries)->contains(
                static fn (array $query): bool => str_contains($query['sql'], 'from "documents"')
                    && str_contains($query['sql'], 'for update')
                    && in_array($returnNote->id, $query['bindings'], true),
            ),
            'Return-note confirmation must lock the target document row before sealing it.',
        );
    }

    /**
     * The backdating case the persisted seal moment exists for: `document_date` is
     * the return date the user stated, `confirmed_at` is when the seal was actually
     * computed, and the two DIFFER. Without both stored, nothing can recompute the
     * hash input.
     */
    public function test_a_backdated_confirm_keeps_document_date_and_confirmed_at_distinct(): void
    {
        $returnedOn = Carbon::today()->subDays(3);
        $returnNote = $this->draftReturnNote($returnedOn);

        $confirmed = $this->service->confirm($returnNote, $this->cfUser->id);

        self::assertSame($returnedOn->toDateString(), $confirmed->document_date->toDateString());
        self::assertNotNull($confirmed->confirmed_at);
        self::assertNotSame(
            $confirmed->document_date->toDateString(),
            $confirmed->confirmed_at->toDateString(),
            'A backdated confirm must keep the seal moment distinct from the stated return date.',
        );
    }

    // ── (b) the period guard ───────────────────────────────────────────────────

    public function test_a_confirm_into_a_closed_vat_period_refuses_and_writes_nothing(): void
    {
        $returnedOn = Carbon::today();
        $returnNote = $this->draftReturnNote($returnedOn);
        $this->closedVatPeriodCovering($returnedOn);

        try {
            $this->service->confirm($returnNote, $this->cfUser->id);
            self::fail('Expected ReturnPeriodLockedException.');
        } catch (ReturnPeriodLockedException $e) {
            self::assertSame(ReturnPeriodRefusalCode::PeriodClosed, $e->refusalCode);
        }

        $returnNote->refresh();
        self::assertSame(DocumentStatus::Draft, $returnNote->status);
        self::assertSame(FiscalStatus::Draft, $returnNote->fiscal_status);
        self::assertNull($returnNote->fiscal_hash);
        self::assertNull($returnNote->chain_sequence);
        self::assertNull($returnNote->confirmed_at);

        // Nothing restocked — the guard runs BEFORE receiveStockBack().
        self::assertSame(
            0,
            StockLevel::query()->where('product_id', $this->cfProduct->id)->count(),
            'A refused confirm must not move stock.',
        );
    }

    /**
     * A locked `fiscal_periods` row refuses too, with its own code — the second half
     * of CF-D3's both-tables requirement.
     */
    public function test_a_confirm_into_a_locked_fiscal_period_refuses_with_return_period_locked(): void
    {
        $returnedOn = Carbon::today();
        $returnNote = $this->draftReturnNote($returnedOn);

        FiscalPeriod::query()
            ->where('company_id', $this->cfCompany->id)
            ->where('start_date', '<=', $returnedOn)
            ->where('end_date', '>=', $returnedOn)
            ->update(['status' => PeriodStatus::Locked]);

        try {
            $this->service->confirm($returnNote, $this->cfUser->id);
            self::fail('Expected ReturnPeriodLockedException.');
        } catch (ReturnPeriodLockedException $e) {
            self::assertSame(ReturnPeriodRefusalCode::PeriodLocked, $e->refusalCode);
        }

        self::assertSame(DocumentStatus::Draft, $returnNote->refresh()->status);
    }

    /**
     * CF-D3's Q1 closure, exercised end to end: the refusal is a one-PATCH recovery,
     * not a dead end. This is the test the plan says the closure depends on.
     */
    public function test_the_escape_hatch_works_patch_the_draft_date_then_confirm(): void
    {
        $lockedDate = Carbon::today()->subMonths(3);
        $returnNote = $this->draftReturnNote($lockedDate);
        $this->closedVatPeriodCovering($lockedDate);

        // Refused first.
        try {
            $this->service->confirm($returnNote, $this->cfUser->id);
            self::fail('Expected ReturnPeriodLockedException.');
        } catch (ReturnPeriodLockedException) {
            // expected
        }

        // The draft is still a draft, so PATCH is allowed — `fiscal_status` is DRAFT
        // so the immutability trigger takes its early return.
        $response = $this->actingAs($this->cfUser, 'sanctum')
            ->patchJson("/api/v1/return-notes/{$returnNote->id}", [
                'document_date' => Carbon::today()->toDateString(),
            ]);
        $response->assertOk();

        $confirmed = $this->service->confirm($returnNote->refresh(), $this->cfUser->id);

        self::assertSame(DocumentStatus::Confirmed, $confirmed->status);
        self::assertSame(Carbon::today()->toDateString(), $confirmed->document_date->toDateString());
    }

    /**
     * The typed refusal must reach the client as its own code, not be flattened into
     * `RETURN_NOTE_CONFIRMATION_FAILED` by the controller's generic
     * `\DomainException` catch — `ReturnPeriodLockedException` extends
     * `DomainException`, so this is a real trap rather than a hypothetical one.
     */
    public function test_the_confirm_endpoint_returns_the_typed_period_code(): void
    {
        $returnedOn = Carbon::today();
        $returnNote = $this->draftReturnNote($returnedOn);
        $this->closedVatPeriodCovering($returnedOn);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/return-notes/{$returnNote->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnPeriodRefusalCode::PeriodClosed->value)
            ->assertJsonPath('error.recoverable', true);
    }

    // ── (c) confirmWithin is not a side channel ────────────────────────────────

    public function test_confirm_within_still_refuses_a_non_draft_document(): void
    {
        $returnNote = $this->draftReturnNote(Carbon::today());
        $this->service->confirm($returnNote, $this->cfUser->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only draft return notes can be confirmed');

        DB::transaction(fn (): Document => $this->service->confirmWithin($returnNote->refresh(), $this->cfUser->id));
    }

    public function test_confirm_within_still_refuses_a_document_that_is_not_a_return_note(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '1.0000',
            'unit_price' => '100.000',
        ]], ['status' => DocumentStatus::Draft, 'fiscal_status' => FiscalStatus::Draft, 'fiscal_hash' => null, 'chain_sequence' => null]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only return notes can be confirmed');

        DB::transaction(fn (): Document => $this->service->confirmWithin($invoice, $this->cfUser->id));
    }

    public function test_line_product_ids_is_public_and_deduplicates(): void
    {
        $returnNote = DB::transaction(fn (): Document => $this->service->createDraft(
            new CreateReturnNoteData(
                partnerId: $this->cfPartner->id,
                documentDate: Carbon::today(),
                lines: [
                    new CreateReturnNoteLineData(
                        productId: $this->cfProduct->id,
                        description: 'A',
                        quantity: '1.0000',
                        unitPrice: '100.000',
                        locationId: $this->cfLocationA->id,
                    ),
                    new CreateReturnNoteLineData(
                        productId: $this->cfProduct->id,
                        description: 'A again, other location',
                        quantity: '1.0000',
                        unitPrice: '100.000',
                        locationId: $this->cfLocationB->id,
                    ),
                    new CreateReturnNoteLineData(
                        productId: $this->cfProductTwo->id,
                        description: 'B',
                        quantity: '1.0000',
                        unitPrice: '50.000',
                        locationId: $this->cfLocationA->id,
                    ),
                ],
                currency: 'TND',
            ),
            $this->cfCompany,
        ));

        $ids = $this->service->lineProductIds($returnNote);

        // Three lines, two distinct products — the CF-D11 tuple split repeats a
        // product across locations, so the cost-lock set must dedupe or the
        // up-front sorted acquire loses its point.
        self::assertCount(2, $ids);
        self::assertContains($this->cfProduct->id, $ids);
        self::assertContains($this->cfProductTwo->id, $ids);
    }

    private function draftReturnNote(Carbon $documentDate): Document
    {
        return DB::transaction(fn (): Document => $this->service->createDraft(
            new CreateReturnNoteData(
                partnerId: $this->cfPartner->id,
                documentDate: $documentDate,
                lines: [new CreateReturnNoteLineData(
                    productId: $this->cfProduct->id,
                    description: 'CF Physical Product',
                    quantity: '2.0000',
                    unitPrice: '100.000',
                    locationId: $this->cfLocationA->id,
                )],
                currency: 'TND',
            ),
            $this->cfCompany,
        ));
    }

    private function closedVatPeriodCovering(Carbon $date): VatPeriod
    {
        // Take the fiscal-period half out of the picture so this test isolates the
        // VAT verdict (a new company's older months are auto-Closed — see
        // ReturnPeriodBackdatingGuardTest's class docblock).
        FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Open,
        ]);

        return VatPeriod::create([
            'company_id' => $this->cfCompany->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => $date->format('F Y'),
            'period_start' => $date->copy()->startOfMonth()->toDateString(),
            'period_end' => $date->copy()->endOfMonth()->toDateString(),
            'status' => VatPeriodStatus::Closed,
        ]);
    }

    /**
     * @param  array<array-key, array{query: string, bindings: array<array-key, mixed>, time: float|null}>  $queries
     */
    private function assertConditionalNumberAndStatusUpdate(array $queries, string $documentId, string $message): void
    {
        $update = collect($queries)->first(static function (array $query) use ($documentId): bool {
            $sql = strtolower($query['query']);

            return str_starts_with($sql, 'update "documents"')
                && str_contains($sql, '"document_number"')
                && str_contains($sql, '"status"')
                && in_array($documentId, $query['bindings'], true);
        });

        self::assertIsArray($update, $message);
        self::assertMatchesRegularExpression(
            '/where "id" = \? and "status" = \? and "document_number" is null$/',
            strtolower($update['query']),
            $message.' The write must be guarded by id, expected status, and a NULL number.',
        );
    }
}
