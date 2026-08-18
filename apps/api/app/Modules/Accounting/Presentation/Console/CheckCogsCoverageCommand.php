<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\InvoicedBeforeDeliveryScanner;
use App\Modules\Compliance\Services\UndeliveredGoodsLineScanner;
use App\Modules\Compliance\Services\UninvoicedDeliveryNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\InventoryGlSourceTypes;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The lane-separation DETECTOR (Wave 3 T23 / D-26).
 *
 * Nightly, per tenant, per active company: report the documents that fell
 * between the goods lane and the money lane. It never repairs anything — it
 * reports, logs structured context and exits NON-ZERO so the scheduler's
 * `onFailure()` hook fires.
 *
 * ── CHECKS IMPLEMENTED HERE ──
 *  - **D-a** costed COGS-bearing movements above the cutover watermark,
 *    outside the two-hour grace window, with no movement-keyed GL entry.
 *  - **D-b** above-watermark COGS movements with a null/zero cost, excluding
 *    the deliberately non-posting stock-adjustment document lane.
 *  - **D-c** invoiced-before-delivery: posted goods invoices with no delivery
 *    behind them. After T25b this is a LEGACY / EXCEPTION register — no new
 *    invoice can join it — and each row carries the policy in force when it was
 *    posted, so pre-policy legacy is distinguishable from a hole.
 *  - **D-d** delivered-not-invoiced: the mirror, already implemented by
 *    `UninvoicedDeliveryNoteService`; surfaced here so one run answers both
 *    halves of the lane separation.
 *  - **D-f** goods lines that moved no stock: a confirmed delivery note or
 *    POS / posted goods receipt carrying a physical product line for which NO
 *    `stock_movements` row exists. This is the silent skip — a missing
 *    `stock_levels` row, or a line whose location could not be resolved — and it
 *    is invisible to every movement-keyed check by construction, because the
 *    movement it would have keyed on was never written.
 *
 *  - **D-e** non-COGS `requiresGLEntry()` movements with no movement-keyed GL
 *    entry, with the same stock-adjustment exclusion as D-b.
 *  - **D-g** return-note lines whose recorded basis fell back to current cost.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter) — iterates via
 * `TenantScopedCommand::forEachTenant()`, then queries companies scoped to the
 * bound tenant. No cross-tenant query is issued from the command body.
 */
final class CheckCogsCoverageCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'accounting:check-cogs-coverage';

    /** @var string */
    protected $description = 'Run the seven DPA inventory/COGS coverage checks (D-a through D-g).';

    public function __construct(
        CompanyContext $companyContext,
        private readonly InvoicedBeforeDeliveryScanner $invoicedBeforeDelivery,
        private readonly UninvoicedDeliveryNoteService $uninvoicedDeliveryNotes,
        private readonly UndeliveredGoodsLineScanner $undeliveredGoodsLines,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $findings = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$findings): int {
            $companies = Company::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', CompanyStatus::Active)
                ->get();

            foreach ($companies as $company) {
                $findings += $this->scanCompany($tenant, $company);
            }

            return self::SUCCESS;
        });

        if ($findings > 0) {
            $this->error(sprintf('%d lane-separation finding(s) reported. See the application log.', $findings));

            return self::FAILURE;
        }

        return $exit;
    }

    private function scanCompany(Tenant $tenant, Company $company): int
    {
        $findings = 0;
        $cutoverAt = $company->inventory_gl_cutover_at;

        $findings += $this->scanMovementChecks($tenant, $company, $cutoverAt);

        // ── D-c ──────────────────────────────────────────────────────────────
        foreach ($this->invoicedBeforeDelivery->scan($company->id) as $row) {
            $findings++;
            $this->report('D-c', 'Posted goods invoice with no delivery behind it.', [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'document_id' => $row['id'],
                'document_number' => $row['document_number'],
                'total' => $row['total'],
                'currency' => $row['currency'],
                'policy_at_post_time' => $row['policy_at_post_time'],
                'policy_source_at_post_time' => $row['policy_source_at_post_time'],
            ]);
        }

        // ── D-d ──────────────────────────────────────────────────────────────
        foreach ($this->uninvoicedDeliveryNotes->getUninvoicedDeliveryNotes($company->id) as $row) {
            $findings++;
            $this->report('D-d', 'Confirmed delivery note with no invoice.', [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'document_id' => $row['id'],
                'document_number' => $row['document_number'],
                'total' => $row['total'],
            ]);
        }

        // ── D-f ──────────────────────────────────────────────────────────────
        $findings += $this->scanMissingMovementLines($tenant, $company, $cutoverAt);
        $findings += $this->scanMissingWorkOrderMovementLines();
        $findings += $this->scanCurrentCostReturns($tenant, $company, $cutoverAt);

        return $findings;
    }

    /**
     * Deliberately deferred D-f work-order arm.
     *
     * Work orders have no parts goods lane, so scanning them would report every
     * historical work-order line by construction. Keep this named no-op until
     * docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md is
     * resolved and a real stock-issuance identity exists.
     */
    private function scanMissingWorkOrderMovementLines(): int
    {
        return 0;
    }

    private function scanMovementChecks(Tenant $tenant, Company $company, \DateTimeInterface $cutoverAt): int
    {
        $findings = 0;
        $cogsReasons = array_values(array_map(
            static fn (MovementReason $reason): string => $reason->value,
            array_filter(MovementReason::cases(), static fn (MovementReason $reason): bool => $reason->affectsCOGS()),
        ));
        $nonCogsGlReasons = array_values(array_map(
            static fn (MovementReason $reason): string => $reason->value,
            array_filter(
                MovementReason::cases(),
                static fn (MovementReason $reason): bool => $reason->requiresGLEntry() && ! $reason->affectsCOGS(),
            ),
        ));

        $missingEntry = static function ($query): void {
            $query->selectRaw('1')
                ->from('journal_entries')
                ->whereColumn('journal_entries.source_id', 'stock_movements.id')
                ->whereIn('journal_entries.source_type', InventoryGlSourceTypes::ALL);
        };

        $dA = StockMovement::query()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $cutoverAt)
            ->where('occurred_at', '<=', now()->subHours(2))
            ->where('is_historical', false)
            ->whereIn('reason', $cogsReasons)
            ->where(function ($query): void {
                $query->whereNull('reference_type')->orWhere('reference_type', '!=', StockMovementReferenceType::StockAdjustment->value);
            })
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '!=', 0)
            ->whereNotExists($missingEntry)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        foreach ($dA as $movement) {
            $findings++;
            $this->reportMovement('D-a', 'Costed inventory movement has no GL entry.', $tenant, $movement);
        }

        $dB = StockMovement::query()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $cutoverAt)
            ->where('is_historical', false)
            ->whereIn('reason', $cogsReasons)
            ->where(function ($query): void {
                $query->whereNull('reference_type')->orWhere('reference_type', '!=', StockMovementReferenceType::StockAdjustment->value);
            })
            ->where(function ($query): void {
                $query->whereNull('unit_cost')->orWhere('unit_cost', 0);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        foreach ($dB as $movement) {
            $findings++;
            $this->reportMovement('D-b', 'COGS-bearing inventory movement has no usable cost.', $tenant, $movement);
        }

        $dE = StockMovement::query()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $cutoverAt)
            ->whereIn('reason', $nonCogsGlReasons)
            // D-20 deliberately leaves the stock-adjustment document lane out
            // of the GL buffer even though its reasons require a GL entry.
            ->where(function ($query): void {
                $query->whereNull('reference_type')->orWhere('reference_type', '!=', StockMovementReferenceType::StockAdjustment->value);
            })
            // T21 is an M5 writer. Until it lands, inventory-counting
            // corrections are intentionally movement-only; reporting them in
            // 3C would make every completed count a permanent false alarm.
            ->where(function ($query): void {
                $query->whereNull('reference_type')->orWhere('reference_type', '!=', StockMovementReferenceType::InventoryCounting->value);
            })
            ->whereNotExists($missingEntry)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        foreach ($dE as $movement) {
            $findings++;
            $this->reportMovement('D-e', 'Inventory movement requiring GL has no entry.', $tenant, $movement);
        }

        return $findings;
    }

    private function reportMovement(string $check, string $message, Tenant $tenant, StockMovement $movement): void
    {
        $at = $movement->occurred_at ?? $movement->created_at ?? now();
        $this->report($check, $message, [
            'tenant_id' => $tenant->id,
            'company_id' => $movement->company_id,
            'movement_id' => $movement->id,
            'document_number' => $movement->reference ?? $movement->reference_id,
            'document_date' => $at->toDateString(),
            'amount' => $movement->total_cost,
            'age_days' => (int) floor(Carbon::instance($at)->diffInDays(now(), true)),
        ]);
    }

    private function scanMissingMovementLines(Tenant $tenant, Company $company, \DateTimeInterface $cutoverAt): int
    {
        $findings = 0;

        foreach ($this->undeliveredGoodsLines->scan($company->id, $cutoverAt) as $row) {
            $findings++;
            $this->report('D-f', 'Goods line produced no stock movement.', [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'arm' => 'delivery_note',
                'line_id' => $row['line_id'],
                'product_id' => $row['product_id'],
                'document_id' => $row['document_id'],
                'document_number' => $row['document_number'],
                'document_date' => $row['document_date'],
                'age_days' => $row['age_days'],
                'amount' => null,
            ]);
        }

        $posRows = DB::table('pos_receipt_lines as lines')
            ->join('pos_receipts as receipts', 'receipts.id', '=', 'lines.receipt_id')
            ->join('products', 'products.id', '=', 'lines.product_id')
            ->where('receipts.company_id', $company->id)
            ->where('receipts.created_at', '>=', $cutoverAt)
            ->where('products.tenant_id', $tenant->id)
            ->where('products.company_id', $company->id)
            // R-6 exception: D-f asks why no movement exists, so it has no
            // immutable movement snapshot to classify. The live flag is the
            // only available physical-goods signal; the cutover watermark
            // bounds its historical exposure. See the release-note ticket.
            ->where('products.is_physical', true)
            ->where('lines.stock_movement_expected', true)
            ->where('lines.quantity', '>', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('stock_movements')
                    ->whereColumn('stock_movements.reference_id', 'receipts.id')
                    ->whereColumn('stock_movements.product_id', 'lines.product_id')
                    ->where('stock_movements.reference_type', 'pos_receipt');
            })
            ->select([
                'lines.id as line_id',
                'lines.product_id',
                'receipts.id as receipt_id',
                'receipts.receipt_number as document_number',
                'receipts.posted_at as document_date',
                'receipts.created_at',
            ])
            ->orderBy('receipts.created_at')
            ->orderBy('lines.id')
            ->get();
        foreach ($posRows as $row) {
            $findings++;
            $this->report('D-f', 'POS line produced no stock movement.', [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'arm' => 'pos',
                'line_id' => (string) $row->line_id,
                'product_id' => (string) $row->product_id,
                'receipt_id' => (string) $row->receipt_id,
                'document_number' => (string) $row->document_number,
                'document_date' => Carbon::parse((string) $row->document_date)->toDateString(),
                'age_days' => (int) floor(Carbon::parse((string) $row->created_at)->diffInDays(now(), true)),
                'amount' => null,
            ]);
        }

        $grRows = DB::table('goods_receipt_lines as lines')
            ->join('goods_receipts as receipts', 'receipts.id', '=', 'lines.goods_receipt_id')
            ->join('products', 'products.id', '=', 'lines.product_id')
            ->leftJoin('stock_movements as paid_movement', function (JoinClause $join): void {
                $join->on('paid_movement.id', '=', 'lines.movement_id')
                    ->on('paid_movement.product_id', '=', 'lines.product_id')
                    ->on('paid_movement.reference_id', '=', 'receipts.purchase_order_id')
                    ->where('paid_movement.reference_type', 'Document');
            })
            ->leftJoin('stock_movements as free_movement', function (JoinClause $join): void {
                $join->on('free_movement.id', '=', 'lines.free_movement_id')
                    ->on('free_movement.product_id', '=', 'lines.product_id')
                    ->on('free_movement.reference_id', '=', 'receipts.purchase_order_id')
                    ->where('free_movement.reference_type', 'Document');
            })
            ->where('receipts.company_id', $company->id)
            ->where('receipts.status', GoodsReceiptStatus::Posted->value)
            ->where('receipts.created_at', '>=', $cutoverAt)
            ->where('products.tenant_id', $tenant->id)
            ->where('products.company_id', $company->id)
            // Same unavoidable R-6 D-f exception as the POS arm above.
            ->where('products.is_physical', true)
            ->where(function ($query): void {
                $query->where(function ($paid): void {
                    $paid->where('lines.received_qty', '>', 0)->whereNull('paid_movement.id');
                })->orWhere(function ($free): void {
                    $free->where('lines.free_qty', '>', 0)->whereNull('free_movement.id');
                });
            })
            ->select([
                'lines.id as line_id',
                'lines.product_id',
                'receipts.id as receipt_id',
                'receipts.receipt_number as document_number',
                'receipts.received_at as document_date',
                'receipts.created_at',
            ])
            ->orderBy('receipts.created_at')
            ->orderBy('lines.id')
            ->get();
        foreach ($grRows as $row) {
            $findings++;
            $this->report('D-f', 'Goods-receipt line produced no valid stock movement.', [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'arm' => 'goods_receipt',
                'line_id' => (string) $row->line_id,
                'product_id' => (string) $row->product_id,
                'receipt_id' => (string) $row->receipt_id,
                'document_number' => (string) $row->document_number,
                'document_date' => Carbon::parse((string) $row->document_date)->toDateString(),
                'age_days' => (int) floor(Carbon::parse((string) $row->created_at)->diffInDays(now(), true)),
                'amount' => null,
            ]);
        }

        return $findings;
    }

    private function scanCurrentCostReturns(
        Tenant $tenant,
        Company $company,
        \DateTimeInterface $cutoverAt,
    ): int {
        $findings = 0;
        $returns = Document::query()
            ->where('company_id', $company->id)
            ->where('type', DocumentType::ReturnNote)
            ->where('status', DocumentStatus::Confirmed)
            ->where('created_at', '>=', $cutoverAt)
            ->whereNotNull('payload')
            ->orderBy('document_date')
            ->orderBy('id')
            ->get(['id', 'document_number', 'document_date', 'created_at', 'payload']);

        foreach ($returns as $return) {
            $records = is_array($return->payload['return_cost_basis'] ?? null)
                ? $return->payload['return_cost_basis']
                : [];
            foreach ($records as $record) {
                if (! is_array($record) || ($record['source'] ?? null) !== 'current_cost') {
                    continue;
                }
                $findings++;
                $this->report('D-g', 'Return line used current cost fallback.', [
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'line_id' => $record['line_id'] ?? null,
                    'product_id' => $record['product_id'] ?? null,
                    'document_id' => $return->id,
                    'document_number' => $return->document_number,
                    'document_date' => $return->document_date->toDateString(),
                    'amount' => $record['unit_cost'] ?? null,
                    'age_days' => $return->created_at !== null
                        ? (int) floor($return->created_at->diffInDays(now(), true))
                        : 0,
                ]);
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function report(string $check, string $message, array $context): void
    {
        Log::warning('['.$check.'] '.$message, $context);

        $this->line(sprintf(
            '%s %s document=%s',
            $check,
            $message,
            (string) ($context['document_number'] ?? $context['document_id'] ?? '?'),
        ));
    }
}
