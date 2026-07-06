<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Procurement\Application\SupplierInvoiceMatchSnapshotService;
use App\Modules\Procurement\Application\SupplierInvoiceMatcher;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * @cross-tenant-by-design Single tenant connection per invocation; fleet-wide operation is an external operator loop.
 *
 * Tenant-scoped rematch for the currently bootstrapped tenant connection.
 *
 * Operators should loop over tenant connections externally for fleet-wide runs.
 */
final class RematchDraftSupplierInvoicesCommand extends Command
{
    protected $signature = 'procurement:rematch-drafts
        {--company= : Specific company UUID to rematch}
        {--dry-run : Report changes without writing snapshots or statuses}';

    protected $description = 'Refresh draft supplier invoice match snapshots and match_status';

    public function __construct(
        private readonly SupplierInvoiceMatchSnapshotService $matchSnapshotService,
        private readonly SupplierInvoiceMatcher $matcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company');

        $query = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('status', DocumentStatus::Draft)
            ->with('lines')
            ->orderBy('company_id')
            ->orderBy('id');

        if (is_string($companyId) && $companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $scanned = 0;
        $rematched = 0;
        $transitions = [];

        /** @var Document $invoice */
        foreach ($query->get() as $invoice) {
            $scanned++;
            $before = $invoice->match_status;
            $changed = false;
            $legacySnapshotMissing = $invoice->lines->contains(
                static fn (DocumentLine $line): bool => $line->source_line_id !== null
                    && ! (bool) ($line->is_bonus_line ?? false)
            );

            $apply = function () use ($invoice, &$changed): SupplierInvoiceMatchStatus {
                /** @var DocumentLine $line */
                foreach ($invoice->lines as $line) {
                    if ($line->source_line_id === null || (bool) ($line->is_bonus_line ?? false)) {
                        continue;
                    }

                    $snapshot = $this->snapshotForLine($line);
                    if (
                        $this->normalizeNullableBasis($line->price_match_basis) !== $snapshot['price_match_basis']
                        || $line->matched_receipt_line_id !== $snapshot['matched_receipt_line_id']
                    ) {
                        $changed = true;
                    }

                    $line->price_match_basis = $snapshot['price_match_basis'];
                    $line->matched_receipt_line_id = $snapshot['matched_receipt_line_id'];
                }

                return $this->matcher->match($invoice);
            };

            if ($dryRun) {
                $after = $apply();
                if ($legacySnapshotMissing || $after !== $before) {
                    $changed = true;
                }
            } else {
                $after = DB::transaction(function () use ($invoice, $apply, &$changed): SupplierInvoiceMatchStatus {
                    $afterStatus = $apply();

                    /** @var DocumentLine $line */
                    foreach ($invoice->lines as $line) {
                        if ($line->isDirty(['price_match_basis', 'matched_receipt_line_id'])) {
                            $line->save();
                        }
                    }

                    if ($invoice->match_status !== $afterStatus) {
                        $changed = true;
                    }

                    $invoice->match_status = $afterStatus;
                    $invoice->save();

                    return $afterStatus;
                });
            }

            if ($changed) {
                $rematched++;
                $transitions[] = sprintf(
                    '%s: %s -> %s',
                    $invoice->document_number,
                    $before instanceof SupplierInvoiceMatchStatus ? $before->value : 'null',
                    $after->value,
                );
            }
        }

        foreach ($transitions as $transition) {
            $this->line($transition);
        }

        $this->info(sprintf(
            'dry_run=%s scanned=%d rematched=%d',
            $dryRun ? 'yes' : 'no',
            $scanned,
            $rematched,
        ));
        $this->line('Release note: tenants using match_enforcement=block should run procurement:rematch-drafts before posting legacy drafts.');

        return self::SUCCESS;
    }

    /**
     * @return array{price_match_basis: numeric-string|null, matched_receipt_line_id: string|null}
     */
    private function snapshotForLine(DocumentLine $invoiceLine): array
    {
        return $this->matchSnapshotService->forInvoiceLine($invoiceLine);
    }

    private function normalizeNullableBasis(mixed $basis): ?string
    {
        if ($basis === null) {
            return null;
        }

        return CurrencyScale::bcformatStrict((string) $basis, 6);
    }
}
