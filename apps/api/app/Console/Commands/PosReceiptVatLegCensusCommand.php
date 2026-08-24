<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Console\Command;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * REPORT-ONLY census of POS receipts whose journal entries carry NO output-VAT
 * leg (W4-9). Writes nothing, ever.
 *
 * Before W4-9 the POS credited the whole gross tender to `70x` and posted no
 * `VatCollected` (4457 on the TN chart) line at all — revenue overstated by
 * exactly the VAT, VAT payable unrecorded, and the trial balance still closing,
 * so there was no symptom to notice. The fix only changes what NEW receipts
 * post; already-posted entries stay wrong until they are corrected, and journal
 * entries are immutable (rule 8), so this command is the deploy check: run it
 * after deploying, and a non-zero count means that tenant has pre-fix receipts
 * whose books disagree with their own VAT declaration.
 *
 * On a greenfield tenant the remedy is re-provisioning. On a trading tenant it
 * is a correcting entry per period, decided by the accountant — deliberately
 * NOT automated here: minting corrections from a census is exactly the kind of
 * unjustified GL mutation the document-per-action principle forbids.
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: `pos_receipts`,
 *   `journal_lines` and `accounts` are all TENANT tables, so post-2026-05-28
 *   (database-per-tenant) each invocation reports on ONLY the tenant database
 *   bound around it. A fleet-wide pass is an external `tenants:run` loop; a bare
 *   run against CENTRAL raises 42P01.
 */
final class PosReceiptVatLegCensusCommand extends Command
{
    /**
     * Both POS source types are covered. A refund reversal that debits revenue
     * gross is the mirror of the same defect — it would leave `4457`
     * overstated once sales start crediting it — so it belongs in the same
     * census, not a second one.
     *
     * @var list<string>
     */
    private const POS_SOURCE_TYPES = ['pos_receipt', 'pos_receipt_refund'];

    protected $signature = 'pos:census-vat-legs
        {--tenant= : Optional tenant UUID filter on the current connection}
        {--company= : Optional company UUID filter}
        {--limit=50 : Maximum receipts to list individually}';

    protected $description = 'Report-only: POS receipts carrying sealed VAT whose journal entries have no output-VAT leg';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $companyId = $this->option('company');
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : 50;

        // The 4457 account is resolved by SYSTEM PURPOSE, never by code: the
        // TN, FR, UK and IT charts all number output VAT differently and the
        // code lives in seeded country defaults.
        $vatAccounts = DB::table('accounts')
            ->where('system_purpose', SystemAccountPurpose::VatCollected->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($vatAccounts === []) {
            $this->error(
                'No account carries the `'.SystemAccountPurpose::VatCollected->value.'` system purpose on this '
                .'tenant database. Every POS receipt on it is by definition missing its output-VAT leg, and the '
                .'chart of accounts must be provisioned before the census can say anything more precise.'
            );

            return self::FAILURE;
        }

        $query = DB::table('pos_receipts')
            ->join('pos_receipt_vat_details', 'pos_receipt_vat_details.receipt_id', '=', 'pos_receipts.id')
            ->leftJoin('journal_entries', function (JoinClause $join): void {
                $join->on('journal_entries.source_id', '=', 'pos_receipts.id')
                    ->whereIn('journal_entries.source_type', self::POS_SOURCE_TYPES);
            })
            ->leftJoin('journal_lines', function (JoinClause $join) use ($vatAccounts): void {
                $join->on('journal_lines.journal_entry_id', '=', 'journal_entries.id')
                    ->whereIn('journal_lines.account_id', $vatAccounts);
            })
            ->groupBy('pos_receipts.id', 'pos_receipts.receipt_number', 'pos_receipts.posted_at', 'pos_receipts.currency')
            ->havingRaw('COUNT(journal_lines.id) = 0')
            ->havingRaw('SUM(pos_receipt_vat_details.vat_amount) > 0')
            ->select([
                'pos_receipts.id',
                'pos_receipts.receipt_number',
                'pos_receipts.posted_at',
                'pos_receipts.currency',
                DB::raw('SUM(pos_receipt_vat_details.vat_amount) AS sealed_vat'),
                DB::raw('COUNT(DISTINCT journal_entries.id) AS entry_count'),
            ])
            ->orderBy('pos_receipts.posted_at');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('pos_receipts.tenant_id', $tenantId);
        }
        if (is_string($companyId) && $companyId !== '') {
            $query->where('pos_receipts.company_id', $companyId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('POS output-VAT leg census: none — every POS receipt carrying sealed VAT has a VAT leg.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'POS output-VAT leg census: %d receipt(s) carry sealed VAT but no `%s` journal line.',
            $rows->count(),
            SystemAccountPurpose::VatCollected->value,
        ));

        foreach ($rows->take($limit) as $row) {
            $this->line(sprintf(
                '%s  posted=%s  sealed_vat=%s %s  pos_entries=%s  receipt_id=%s',
                (string) ($row->receipt_number ?? '(no number)'),
                (string) ($row->posted_at ?? '(unposted)'),
                (string) $row->sealed_vat,
                (string) ($row->currency ?? ''),
                (string) $row->entry_count,
                (string) $row->id,
            ));
        }

        if ($rows->count() > $limit) {
            $this->line(sprintf('… and %d more (raise --limit to list them).', $rows->count() - $limit));
        }

        // `entry_count = 0` and `entry_count > 0` are different problems: the
        // first is a receipt that never reached the GL at all, the second is a
        // receipt booked by the pre-W4-9 writer. Separating them keeps an
        // operator from chasing the wrong remedy.
        $neverPosted = $rows->filter(static fn (object $row): bool => (int) $row->entry_count === 0)->count();
        $this->line(sprintf(
            'Breakdown: %d never reached the GL (no pos_receipt entry at all), %d were booked without a VAT leg.',
            $neverPosted,
            $rows->count() - $neverPosted,
        ));

        return self::FAILURE;
    }
}
