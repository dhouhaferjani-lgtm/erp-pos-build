<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use App\Modules\Treasury\Presentation\Rules\DiscountAboveTolerance;
use Illuminate\Support\Facades\DB;

/**
 * Pre-deploy data audit (spec §15) — sweep existing documents for any
 * line-level or header-level discount that would now fail the §7
 * boundary check, without applying any silent migration. Surfaces the
 * data owner's decision: edit, drop, or accept the violation.
 *
 * Scope is intentionally narrow:
 *   - DocumentType IN (sales_order, invoice) — same surface where the
 *     {@see DiscountAboveTolerance}
 *     validator fires for new writes.
 *   - DocumentLine.discount_amount > 0 OR documents.discount_amount > 0.
 *
 * The command runs read-only by default and exits non-zero when
 * violations are found (so CI / deploy pipelines can gate). The
 * `--dry-run` flag flips that to always-zero so a developer can inspect
 * locally without flagging the run as failed.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter) behind an EXPLICIT scope,
 * converted 2026-08-05 (cat-(b) wave 2).
 *
 * The pre-conversion annotation called this a "pre-deploy CI gate" that
 * "sweeps the entire documents / document_lines surface". Both halves were
 * false by 2026-08-05: no workflow in `.github/workflows/` invokes
 * `tolerance:audit-discounts` (checked against ci.yml, smoke-test.yml,
 * react-doctor.yml, sonarcloud.yml), and `documents` / `document_lines` are
 * TENANT tables that do not exist on the console's CENTRAL connection, so the
 * sweep raised 42P01. It is an operator command, and it now says so.
 *
 * The scope must be named — `--tenant=<uuid>` or `--all-tenants` — because a
 * gate that quietly audits zero tenants and exits 0 is worse than no gate.
 *
 * The tolerance-boundary LOGIC and the exit-code contract (non-zero on
 * violations, always zero with `--dry-run`) are untouched.
 */
final class AuditDiscountsCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'tolerance:audit-discounts
        {--tenant= : Tenant UUID to audit (required unless --all-tenants)}
        {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
        {--dry-run : Report violations but exit success regardless of count}';

    /** @var string */
    protected $description = 'Report any document discounts that fall at-or-below the tolerance margin (sub-tolerance abuse risk).';

    public function __construct(
        CompanyContext $companyContext,
        private readonly DiscountToleranceBoundary $boundary,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $violations = 0;

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use (&$violations): int {
                $tenantViolations = $this->auditLines($this->boundary, $tenant)
                    + $this->auditHeaders($this->boundary, $tenant);

                $violations += $tenantViolations;

                $this->line(sprintf(
                    'TENANT %s (%s): %d violation(s).',
                    $tenant->id,
                    $tenant->slug,
                    $tenantViolations,
                ));

                return self::SUCCESS;
            },
        );

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        if ($violations === 0) {
            $this->info('Audit complete — no violations.');
        } else {
            $this->warn("Audit complete — {$violations} violation(s) reported.");
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        return $violations > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function auditLines(DiscountToleranceBoundary $boundary, Tenant $tenant): int
    {
        $violations = 0;

        // The explicit tenant predicate is redundant once tenancy is bound and
        // load-bearing in single-schema compatibility mode, where one shared
        // database holds every tenant's documents.
        DB::table('document_lines as dl')
            ->join('documents as d', 'd.id', '=', 'dl.document_id')
            ->where('d.tenant_id', $tenant->id)
            ->whereNotNull('dl.discount_amount')
            ->where('dl.discount_amount', '>', 0)
            ->whereIn('d.type', ['sales_order', 'invoice'])
            ->select([
                'dl.id as line_id',
                'dl.document_id',
                'dl.discount_amount',
                'dl.quantity',
                'dl.unit_price',
                'd.company_id',
                'd.currency',
                'd.document_number',
            ])
            ->orderBy('dl.id')
            ->chunk(500, function ($rows) use ($boundary, &$violations): void {
                foreach ($rows as $row) {
                    /** @var numeric-string $quantity */
                    $quantity = (string) $row->quantity;
                    /** @var numeric-string $unitPrice */
                    $unitPrice = (string) $row->unit_price;
                    $subtotal = bcmul($quantity, $unitPrice, 4);

                    try {
                        $boundary->assertDiscountAboveTolerance(
                            discountAmount: (string) $row->discount_amount,
                            subtotal: $subtotal,
                            companyId: (string) $row->company_id,
                            currencyCode: (string) ($row->currency ?? ''),
                        );
                    } catch (DiscountBelowToleranceException $e) {
                        $violations++;
                        $this->error(sprintf(
                            'LINE  doc=%s line=%s discount=%s margin=%s subtotal=%s',
                            (string) $row->document_number,
                            (string) $row->line_id,
                            $e->discountAmount,
                            $e->toleranceMargin,
                            $subtotal,
                        ));
                    }
                }
            });

        return $violations;
    }

    private function auditHeaders(DiscountToleranceBoundary $boundary, Tenant $tenant): int
    {
        $violations = 0;

        DB::table('documents')
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('discount_amount')
            ->where('discount_amount', '>', 0)
            ->whereIn('type', ['sales_order', 'invoice'])
            ->select(['id', 'document_number', 'discount_amount', 'subtotal', 'company_id', 'currency'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($boundary, &$violations): void {
                foreach ($rows as $row) {
                    $subtotal = (string) ($row->subtotal ?? '0');

                    try {
                        $boundary->assertDiscountAboveTolerance(
                            discountAmount: (string) $row->discount_amount,
                            subtotal: $subtotal,
                            companyId: (string) $row->company_id,
                            currencyCode: (string) ($row->currency ?? ''),
                        );
                    } catch (DiscountBelowToleranceException $e) {
                        $violations++;
                        $this->error(sprintf(
                            'HEADER doc=%s discount=%s margin=%s subtotal=%s',
                            (string) $row->document_number,
                            $e->discountAmount,
                            $e->toleranceMargin,
                            $subtotal,
                        ));
                    }
                }
            });

        return $violations;
    }
}
