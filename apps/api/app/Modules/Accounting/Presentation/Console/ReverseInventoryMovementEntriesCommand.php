<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use DateTimeImmutable;

/**
 * Forward-only rollback for the Wave-3 inventory-GL cutover.
 *
 * This operator-only command is never called by a normal application path. It
 * mirrors Posted inventory_exit / inventory_entry entries into new hash-chained
 * compensating entries; originals remain immutable.
 */
final class ReverseInventoryMovementEntriesCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'accounting:reverse-inventory-movement-entries
        {--from= : Inclusive UTC timestamp for original entry creation}
        {--confirm : Confirm creation of immutable compensating entries}';

    /** @var string */
    protected $description = 'Post compensating entries for movement-keyed inventory entries since an explicit timestamp.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly GeneralLedgerService $generalLedger,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $fromValue = $this->option('from');
        if (! is_string($fromValue) || trim($fromValue) === '') {
            $this->error('The --from option is required.');

            return self::INVALID;
        }
        if ($this->option('confirm') !== true) {
            $this->error('The --confirm flag is required.');

            return self::INVALID;
        }

        try {
            $from = new DateTimeImmutable($fromValue);
        } catch (\Exception) {
            $this->error('The --from option must be a valid timestamp.');

            return self::INVALID;
        }

        $reversed = 0;
        $exit = $this->forEachTenant(function (Tenant $tenant) use ($from, &$reversed): int {
            $companies = Company::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->get();

            foreach ($companies as $company) {
                $originals = JournalEntry::query()
                    ->where('company_id', $company->id)
                    ->where('status', JournalEntryStatus::Posted)
                    ->whereIn('source_type', ['inventory_exit', 'inventory_entry'])
                    ->where('created_at', '>=', $from)
                    ->with('lines')
                    ->orderBy('chain_sequence')
                    ->orderBy('id')
                    ->get();

                foreach ($originals as $original) {
                    $alreadyReversed = JournalEntry::query()
                        ->where('company_id', $company->id)
                        ->where('source_type', GeneralLedgerService::INVENTORY_MOVEMENT_REVERSAL_SOURCE_TYPE)
                        ->where('source_id', $original->id)
                        ->exists();
                    if ($alreadyReversed) {
                        continue;
                    }

                    $this->generalLedger->reverseInventoryMovementEntry($original, new DateTimeImmutable('now'));
                    $reversed++;
                }
            }

            return self::SUCCESS;
        });

        $this->info("Posted {$reversed} inventory movement reversal entr".($reversed === 1 ? 'y.' : 'ies.'));

        return $exit;
    }
}
