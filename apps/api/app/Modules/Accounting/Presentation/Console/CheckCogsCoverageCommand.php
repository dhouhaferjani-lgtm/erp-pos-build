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
use App\Modules\Tenant\Domain\Tenant;
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
 *  - **D-c** invoiced-before-delivery: posted goods invoices with no delivery
 *    behind them. After T25b this is a LEGACY / EXCEPTION register — no new
 *    invoice can join it — and each row carries the policy in force when it was
 *    posted, so pre-policy legacy is distinguishable from a hole.
 *  - **D-d** delivered-not-invoiced: the mirror, already implemented by
 *    `UninvoicedDeliveryNoteService`; surfaced here so one run answers both
 *    halves of the lane separation.
 *  - **D-f** goods lines that moved no stock: a confirmed delivery note or
 *    posted goods receipt carrying a physical product line for which NO
 *    `stock_movements` row exists. This is the silent skip — a missing
 *    `stock_levels` row, or a line whose location could not be resolved — and it
 *    is invisible to every movement-keyed check by construction, because the
 *    movement it would have keyed on was never written.
 *
 * ── 🚧 CHECKS DEFERRED TO SUB-WAVE 3C (D-a, D-b, D-e, D-g) ──
 * D-a/D-b/D-e ask whether a COGS-bearing stock movement got its journal entry.
 * On this branch **no inventory GL seam exists yet** — 3C builds it, along with
 * `InventoryGlSourceTypes::ALL` (the accepted source-type set), the per-company
 * `inventory_gl_cutover_at` WATERMARK those checks report above, and the
 * `return_cost_basis` attribution D-g reads. Implementing them now would be
 * worse than not implementing them: with no seam and no watermark, D-a would
 * fire on EVERY historical movement in every tenant on its first scheduled run,
 * and an alert that always fires is an alert nobody reads. 3C adds them to this
 * class; the schedule, the exit contract and the per-tenant iteration are
 * already here for it.
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
    protected $description = 'Report documents where the goods lane and the money lane disagree (invoiced-not-delivered, delivered-not-invoiced, goods lines that moved no stock).';

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
        foreach ($this->undeliveredGoodsLines->scan($company->id) as $row) {
            $findings++;
            $this->report('D-f', 'Goods line produced no stock movement.', [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'document_id' => $row['document_id'],
                'document_number' => $row['document_number'],
                'document_type' => $row['document_type'],
                'product_id' => $row['product_id'],
                'quantity' => $row['quantity'],
            ]);
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
