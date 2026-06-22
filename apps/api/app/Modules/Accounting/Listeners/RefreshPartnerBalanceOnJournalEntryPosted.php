<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\JournalEntry;

final class RefreshPartnerBalanceOnJournalEntryPosted
{
    public function __construct(
        private readonly PartnerBalanceService $partnerBalanceService,
    ) {}

    public function handle(JournalEntryPosted $event): void
    {
        $entry = JournalEntry::query()
            ->where('company_id', $event->companyId)
            ->with('lines')
            ->find($event->entryId);

        if (! $entry instanceof JournalEntry) {
            return;
        }

        $partnerIds = [];
        foreach ($entry->lines as $line) {
            if ($line->partner_id === null || $line->partner_id === '') {
                continue;
            }

            $partnerIds[$line->partner_id] = true;
        }

        foreach (array_keys($partnerIds) as $partnerId) {
            $this->partnerBalanceService->refreshPartnerBalance($event->companyId, $partnerId);
        }
    }
}
