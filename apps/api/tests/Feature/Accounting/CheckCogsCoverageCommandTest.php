<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Compliance\Services\InvoicedBeforeDeliveryScanner;
use App\Modules\Compliance\Services\UndeliveredGoodsLineScanner;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Inventory\Domain\StockMovement;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T23**: the lane-separation detector.
 *
 * Every check fires on a constructed POSITIVE and stays silent on a constructed
 * NEGATIVE. That pairing is the whole contract of a detector: one without the
 * other is either an alarm nobody can trust or an alarm nobody hears.
 *
 * 🚧 D-a, D-b, D-e and D-g are DEFERRED TO 3C — they ask whether a COGS-bearing
 * stock movement got its journal entry, and on this branch no inventory GL seam,
 * no `InventoryGlSourceTypes` and no cutover watermark exist. See the command's
 * class docblock.
 */
class CheckCogsCoverageCommandTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    // ── D-c ──────────────────────────────────────────────────────────────────

    /**
     * The POSITIVE: a legacy invoice posted before the policy existed. It is
     * reported, and reported as `pre_policy` — which is what makes the register
     * readable instead of alarming.
     */
    public function test_dc_fires_on_a_posted_goods_invoice_with_no_delivery(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        // Posted WITHOUT going through the gate — exactly how the legacy
        // population came to exist.
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', 'legacy'),
            'chain_sequence' => 1,
        ]);

        $findings = app(InvoicedBeforeDeliveryScanner::class)->scan($this->dpCompany->id);

        $this->assertCount(1, $findings);
        $this->assertSame($invoice->id, $findings[0]['id']);
        $this->assertSame('pre_policy', $findings[0]['policy_at_post_time']);
    }

    /**
     * 🆕 The regression assertion of the WHOLE sub-wave (T24.2): after the policy
     * takes effect, nothing new can join this bucket.
     */
    public function test_dc_is_empty_for_an_invoice_posted_after_the_policy_took_effect(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        app(DocumentPostingService::class)->post($invoice);

        $this->assertSame([], app(InvoicedBeforeDeliveryScanner::class)->scan($this->dpCompany->id));
    }

    /**
     * The NEGATIVE that D-29 exists for: delivered, then fully returned. It WAS
     * delivered. Listing it would be a false positive on the most sensitive
     * report in the lane.
     */
    public function test_dc_stays_silent_on_a_delivered_then_fully_returned_invoice(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('3.0000')]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('3.0000')]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);
        app(DocumentPostingService::class)->post($invoice);

        $this->dpReturnEverything($invoice, '3.0000');

        $this->assertSame([], app(InvoicedBeforeDeliveryScanner::class)->scan($this->dpCompany->id));
    }

    public function test_dc_stays_silent_on_a_service_only_invoice(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);
        app(DocumentPostingService::class)->post($invoice);

        $this->assertSame([], app(InvoicedBeforeDeliveryScanner::class)->scan($this->dpCompany->id));
    }

    public function test_dc_stays_silent_on_an_unposted_invoice(): void
    {
        $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        $this->assertSame([], app(InvoicedBeforeDeliveryScanner::class)->scan($this->dpCompany->id));
    }

    // ── D-f ──────────────────────────────────────────────────────────────────

    /**
     * The POSITIVE: a confirmed delivery note whose goods line produced no stock
     * movement — the silent skip. Nothing that starts from a movement can see
     * this document, which is exactly why the check exists.
     */
    public function test_df_fires_on_a_confirmed_delivery_note_that_moved_no_stock(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);

        // Simulate the skip: the document is confirmed and sealed, but no
        // movement exists (no stock_levels row / unresolvable location).
        StockMovement::query()->where('reference_id', $deliveryNote->id)->delete();

        $findings = app(UndeliveredGoodsLineScanner::class)->scan($this->dpCompany->id);

        $this->assertCount(1, $findings);
        $this->assertSame($deliveryNote->id, $findings[0]['document_id']);
        $this->assertSame($this->dpProduct->id, $findings[0]['product_id']);
    }

    public function test_df_stays_silent_when_the_movement_exists(): void
    {
        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);

        $this->assertSame([], app(UndeliveredGoodsLineScanner::class)->scan($this->dpCompany->id));
    }

    public function test_df_stays_silent_on_a_draft_delivery_note(): void
    {
        // A draft note has not issued anything yet — by design, not by defect.
        $this->dpDraftDeliveryNote([$this->dpPhysicalLine()]);

        $this->assertSame([], app(UndeliveredGoodsLineScanner::class)->scan($this->dpCompany->id));
    }

    // ── the command ──────────────────────────────────────────────────────────

    /**
     * The exit contract: non-zero when anything fires, so the scheduler's
     * `onFailure()` hook runs. A detector that exits 0 on a finding is a log
     * line nobody reads.
     */
    public function test_the_command_exits_non_zero_when_a_finding_exists(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', 'legacy-cmd'),
            'chain_sequence' => 1,
        ]);

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
    }

    public function test_the_command_exits_zero_on_a_clean_company(): void
    {
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    /**
     * The schedule must run IN-PROCESS: `runInBackground()` forks a detached
     * process whose exit code the scheduler never observes, so `onFailure()`
     * would silently never fire and every finding would live only in the log.
     */
    public function test_the_schedule_entry_does_not_run_in_background(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'check-cogs-coverage'),
        ));

        $this->assertCount(1, $events, 'The detector is scheduled exactly once.');
        $this->assertFalse($events[0]->runInBackground);
    }
}
