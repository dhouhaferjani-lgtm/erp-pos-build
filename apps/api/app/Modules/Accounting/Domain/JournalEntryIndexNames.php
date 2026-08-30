<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

final class JournalEntryIndexNames
{
    public const string TENANT_ENTRY_NUMBER_UNIQUE = 'journal_entries_tenant_id_entry_number_unique';

    public const string COMPANY_ENTRY_NUMBER_UNIQUE = 'journal_entries_company_id_entry_number_unique';
}
