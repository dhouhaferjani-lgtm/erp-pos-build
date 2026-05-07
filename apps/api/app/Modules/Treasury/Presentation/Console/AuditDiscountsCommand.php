<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use App\Modules\Treasury\Presentation\Rules\DiscountAboveTolerance;
use Illuminate\Console\Command;
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
 * @cross-tenant-by-design Pre-deploy CI gate that sweeps the entire documents / document_lines surface for tolerance violations; cross-tenant by design because the gate must check the whole dataset before a deploy.
 */
final class AuditDiscountsCommand extends Command
{
    /** @var string */
    protected $signature = 'tolerance:audit-discounts
        {--dry-run : Report violations but exit success regardless of count}';

    /** @var string */
    protected $description = 'Report any document discounts that fall at-or-below the tolerance margin (sub-tolerance abuse risk).';

    public function handle(DiscountToleranceBoundary $boundary): int
    {
        $violations = 0;
        $violations += $this->auditLines($boundary);
        $violations += $this->auditHeaders($boundary);

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

    private function auditLines(DiscountToleranceBoundary $boundary): int
    {
        $violations = 0;

        DB::table('document_lines as dl')
            ->join('documents as d', 'd.id', '=', 'dl.document_id')
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

    private function auditHeaders(DiscountToleranceBoundary $boundary): int
    {
        $violations = 0;

        DB::table('documents')
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
