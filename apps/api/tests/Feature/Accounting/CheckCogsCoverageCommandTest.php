<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\InvoicedBeforeDeliveryScanner;
use App\Modules\Compliance\Services\UndeliveredGoodsLineScanner;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\PhysicalLinePredicate;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T23**: the lane-separation detector.
 *
 * Every check fires on a constructed POSITIVE and stays silent on a constructed
 * NEGATIVE. That pairing is the whole contract of a detector: one without the
 * other is either an alarm nobody can trust or an alarm nobody hears.
 *
 * Wave 3C completes D-a through D-g and the POS / goods-receipt D-f arms.
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

    public function test_df_delivery_note_arm_applies_the_company_cutover_watermark(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $live = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        StockMovement::query()->where('reference_id', $live->id)->delete();

        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-f]')
                && ($context['arm'] ?? null) === 'delivery_note'
                && ($context['document_id'] ?? null) === $live->id,
        );

        DB::table('documents')->where('id', $live->id)->update([
            'created_at' => now()->subHours(2),
        ]);

        $this->assertSame([], app(UndeliveredGoodsLineScanner::class)->scan(
            $this->dpCompany->id,
            $this->dpCompany->inventory_gl_cutover_at,
        ));
    }

    public function test_document_scanners_return_empty_for_a_missing_company(): void
    {
        $missingCompanyId = (string) Str::uuid();

        $this->assertSame([], app(UndeliveredGoodsLineScanner::class)->scan($missingCompanyId));
        $this->assertSame([], app(InvoicedBeforeDeliveryScanner::class)->scan($missingCompanyId));
    }

    /**
     * R-5: both set-based scanner filters must be the SQL counterpart of the
     * scoped row predicate, including its tenant boundary. A forged line can
     * point at a physical product whose company_id matches but tenant_id does
     * not; company-only SQL would report it as goods while forLine() refuses it.
     */
    public function test_scanner_sql_physical_predicates_match_the_scoped_row_predicate(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignProduct = Product::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $this->dpCompany->id,
            'is_physical' => true,
        ]);
        $line = [
            'product_id' => $foreignProduct->id,
            'location_id' => $this->dpLocation->id,
            'description' => 'cross-tenant physical product',
            'quantity' => '1.0000',
            'unit_price' => '10.000',
        ];

        $invoice = $this->dpConfirmedInvoice([$line]);
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', 'r5-foreign-invoice'),
            'chain_sequence' => 1,
        ]);
        $deliveryNote = $this->dpDraftDeliveryNote([$line]);
        $deliveryNote->update([
            'status' => DocumentStatus::Confirmed,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', 'r5-foreign-delivery'),
            'chain_sequence' => 1,
        ]);

        $invoiceLine = $invoice->lines()->firstOrFail();
        $deliveryLine = $deliveryNote->lines()->firstOrFail();
        $this->assertFalse(PhysicalLinePredicate::forLine(
            $invoiceLine,
            $this->dpTenant->id,
            $this->dpCompany->id,
        ));
        $this->assertFalse(PhysicalLinePredicate::forLine(
            $deliveryLine,
            $this->dpTenant->id,
            $this->dpCompany->id,
        ));

        $this->assertSame([], app(InvoicedBeforeDeliveryScanner::class)->scan($this->dpCompany->id));
        $this->assertSame([], app(UndeliveredGoodsLineScanner::class)->scan($this->dpCompany->id));
    }

    // ── Wave 3C movement-keyed checks ───────────────────────────────────────

    public function test_da_fires_only_above_the_watermark_after_the_two_hour_grace(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHours(4)]);
        $live = $this->movement(MovementReason::Delivery, '5.000000', now()->subHours(3));
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-a]')
                && ($context['movement_id'] ?? null) === $live->id,
        );

        $live->delete();
        $this->movement(MovementReason::Delivery, '5.000000', now()->subHours(5));
        $this->movement(MovementReason::Delivery, '5.000000', now()->subMinute());
        $historical = $this->movement(MovementReason::Delivery, '5.000000', now()->subHours(3));
        $historical->update(['is_historical' => true]);
        $adjustment = $this->movement(MovementReason::Damage, '5.000000', now()->subHours(3));
        $adjustment->update(['reference_type' => 'stock_adjustment']);
        $coveredByReversal = $this->movement(MovementReason::Delivery, '5.000000', now()->subHours(3));
        JournalEntry::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'entry_number' => 'JE-DA-REVERSAL',
            'entry_date' => now(),
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'batch_write_off_reversal',
            'source_id' => $coveredByReversal->id,
            'journal_code' => JournalCode::Misc,
        ]);
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_db_fires_on_zero_cost_but_excludes_stock_adjustments(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $zero = $this->movement(MovementReason::Damage, null, now()->subMinutes(30));
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-b]')
                && ($context['movement_id'] ?? null) === $zero->id,
        );

        $zero->update(['reference_type' => 'stock_adjustment']);
        $historical = $this->movement(MovementReason::POSReturn, null, now()->subMinutes(30));
        $historical->update(['is_historical' => true]);
        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_de_fires_for_non_cogs_gl_movements_and_excludes_stock_adjustments(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $movement = $this->movement(MovementReason::SupplierReturn, '4.000000', now()->subMinutes(30));
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-e]')
                && ($context['movement_id'] ?? null) === $movement->id,
        );

        $movement->update(['reference_type' => 'stock_adjustment']);
        $this->movement(
            MovementReason::CountCorrection,
            '4.000000',
            now()->subMinutes(30),
            referenceType: 'inventory_counting',
        );
        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_dg_fires_only_for_return_lines_using_current_cost(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $return = $this->returnWithBasis('current_cost');
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-g]')
                && ($context['document_id'] ?? null) === $return->id,
        );

        $return->update(['payload' => ['return_cost_basis' => [[
            'line_id' => $return->lines()->value('id'),
            'source' => 'exit_movement',
        ]]]]);
        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);

        $historical = $this->returnWithBasis('current_cost');
        DB::table('documents')->where('id', $historical->id)->update(['created_at' => now()->subHours(2)]);
        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_df_pos_arm_has_no_grace_window_and_stays_silent_when_movement_exists(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subMinute()]);
        $receipt = $this->posReceiptWithLine();
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-f]')
                && ($context['arm'] ?? null) === 'pos'
                && ($context['receipt_id'] ?? null) === $receipt->id,
        );

        $this->movement(
            MovementReason::POSSale,
            '5.000000',
            now(),
            referenceType: 'pos_receipt',
            referenceId: $receipt->id,
        );
        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_df_pos_arm_stays_silent_for_intentional_no_movement_refunds(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $sale = $this->posReceiptWithLine();
        $this->movement(
            MovementReason::POSSale,
            '5.000000',
            now(),
            referenceType: 'pos_receipt',
            referenceId: $sale->id,
        );

        $returnAttributes = [
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $sale->id,
            'return_reason' => ReturnReason::Other,
        ];
        $this->posReceiptWithLine($returnAttributes, [
            'disposition' => ReturnLineDisposition::NotReceived,
            'stock_movement_expected' => false,
        ]);
        $this->dpProduct->update(['restock_policy' => RestockPolicy::Never]);
        $this->posReceiptWithLine($returnAttributes, [
            'disposition' => ReturnLineDisposition::Restock,
            'stock_movement_expected' => false,
        ]);
        $scrap = $this->posReceiptWithLine($returnAttributes, [
            'disposition' => ReturnLineDisposition::Scrap,
            'stock_movement_expected' => true,
        ]);
        $this->movement(
            MovementReason::POSReturn,
            '5.000000',
            now(),
            referenceType: 'pos_receipt',
            referenceId: $scrap->id,
        );
        // The detector reads the immutable projection outcome, not today's
        // mutable catalogue policy.
        $this->dpProduct->update(['restock_policy' => RestockPolicy::DefaultAllow]);
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_df_pos_arm_stays_silent_when_the_sale_writer_marks_no_stock_grain(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $this->posReceiptWithLine([], ['stock_movement_expected' => false]);

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_df_pos_arm_is_not_silenced_by_a_cross_company_movement(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $receipt = $this->posReceiptWithLine();
        $foreignCompany = Company::factory()->create([
            'tenant_id' => $this->dpTenant->id,
            'currency' => $this->dpCompany->currency,
        ]);
        $foreignMovement = $this->movement(
            MovementReason::POSSale,
            '5.000000',
            now(),
            referenceType: 'pos_receipt',
            referenceId: $receipt->id,
        );
        $foreignMovement->update(['company_id' => $foreignCompany->id]);

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
    }

    public function test_df_pos_arm_matches_movements_at_null_safe_variant_grain(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        $coveredVariant = ProductVariant::factory()->create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
        ]);
        $missingVariant = ProductVariant::factory()->create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
        ]);
        $receipt = $this->posReceiptWithLine([], ['variant_id' => $coveredVariant->id]);
        $missingLine = ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 2,
            'product_id' => $this->dpProduct->id,
            'variant_id' => $missingVariant->id,
            'product_code' => 'DPA-DETECTOR-VARIANT',
            'product_name' => $this->dpProduct->name,
            'quantity' => '1.0000',
            'unit' => 'unit',
            'unit_price' => '100.000',
            'line_total' => '100.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'stock_movement_expected' => true,
        ]);
        $coveredMovement = $this->movement(
            MovementReason::POSSale,
            '5.000000',
            now(),
            referenceType: 'pos_receipt',
            referenceId: $receipt->id,
        );
        $coveredMovement->update(['variant_id' => $coveredVariant->id]);

        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-f]')
                && ($context['arm'] ?? null) === 'pos'
                && ($context['line_id'] ?? null) === $missingLine->id,
        );
    }

    public function test_df_goods_receipt_arm_uses_the_line_link_and_source_tuple(): void
    {
        $this->dpCompany->update(['inventory_gl_cutover_at' => now()->subHour()]);
        [$receipt, $line] = $this->goodsReceiptWithLine();
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(1);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '[D-f]')
                && ($context['arm'] ?? null) === 'goods_receipt'
                && ($context['line_id'] ?? null) === $line->id,
        );

        $movement = $this->movement(
            MovementReason::GoodsReceipt,
            '5.000000',
            now(),
            referenceType: 'Document',
            referenceId: $receipt->purchase_order_id,
        );
        DB::table('stock_movements')->where('id', $movement->id)->update(['reason' => null]);
        $line->update(['movement_id' => $movement->id]);
        Log::spy();
        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
    }

    public function test_df_work_order_population_is_explicitly_not_reported(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'location_id' => $this->dpLocation->id,
            'customer_partner_id' => $this->dpPartner->id,
            'opened_by_user_id' => $this->dpUser->id,
        ]);
        WorkOrderLine::factory()->create([
            'work_order_id' => $workOrder->id,
            'product_id' => $this->dpProduct->id,
        ]);
        Log::spy();

        $this->artisan('accounting:check-cogs-coverage')->assertExitCode(0);
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

    private function movement(
        MovementReason $reason,
        ?string $unitCost,
        \DateTimeInterface $createdAt,
        string $referenceType = 'Document',
        ?string $referenceId = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'movement_type' => $reason->getMovementType() === 'in' ? MovementType::Receipt : MovementType::Issue,
            'reason' => $reason,
            'quantity' => '1.0000',
            'quantity_before' => $reason->getMovementType() === 'in' ? '0.0000' : '2.0000',
            'quantity_after' => $reason->getMovementType() === 'in' ? '1.0000' : '1.0000',
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId ?? (string) Str::uuid(),
            'is_historical' => false,
            'occurred_at' => $createdAt,
        ]);
        DB::table('stock_movements')->where('id', $movement->id)->update([
            'created_at' => $createdAt,
            'occurred_at' => $createdAt,
        ]);

        return $movement->refresh();
    }

    private function returnWithBasis(string $source): Document
    {
        $return = $this->dpCreateDocument([
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'RN-DETECTOR-'.bin2hex(random_bytes(3)),
        ], [$this->dpPhysicalLine('1.0000')]);
        $return->update(['payload' => ['return_cost_basis' => [[
            'line_id' => $return->lines()->value('id'),
            'product_id' => $this->dpProduct->id,
            'quantity' => '1.0000',
            'unit_cost' => '60.000000',
            'source' => $source,
            'movement_ids' => [],
        ]]]]);

        return $return->refresh();
    }

    /**
     * @param  array<string, mixed>  $receiptAttributes
     * @param  array<string, mixed>  $lineAttributes
     */
    private function posReceiptWithLine(array $receiptAttributes = [], array $lineAttributes = []): Receipt
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'location_id' => $this->dpLocation->id,
        ]);
        $receipt = Receipt::factory()->create(array_merge([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'location_id' => $this->dpLocation->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->dpUser->id,
            'currency' => 'TND',
        ], $receiptAttributes));
        ReceiptLine::create(array_merge([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $this->dpProduct->id,
            'product_code' => 'DPA-DETECTOR',
            'product_name' => $this->dpProduct->name,
            'quantity' => '1.0000',
            'unit' => 'unit',
            'unit_price' => '100.000',
            'line_total' => '100.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ], $lineAttributes));

        return $receipt;
    }

    /** @return array{GoodsReceipt, GoodsReceiptLine} */
    private function goodsReceiptWithLine(): array
    {
        $purchaseOrder = $this->dpCreateDocument([
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-DETECTOR-'.bin2hex(random_bytes(3)),
        ], [$this->dpPhysicalLine('1.0000')]);
        $receipt = GoodsReceipt::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'purchase_order_id' => $purchaseOrder->id,
            'location_id' => $this->dpLocation->id,
            'receipt_number' => 'GR-DETECTOR-'.bin2hex(random_bytes(3)),
            'status' => GoodsReceiptStatus::Posted,
            'received_at' => now(),
            'received_by' => $this->dpUser->id,
        ]);
        $line = GoodsReceiptLine::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'goods_receipt_id' => $receipt->id,
            'po_line_id' => $purchaseOrder->lines()->value('id'),
            'product_id' => $this->dpProduct->id,
            'received_qty' => '1.0000',
            'free_qty' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'free_quantity_invoiced' => '0.0000',
        ]);

        return [$receipt, $line];
    }
}
