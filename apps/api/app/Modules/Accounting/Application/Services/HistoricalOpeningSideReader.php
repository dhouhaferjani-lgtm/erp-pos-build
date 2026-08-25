<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Shared\Contracts\Accounting\HistoricalOpeningSide;
use App\Shared\Contracts\Accounting\HistoricalOpeningSideReaderInterface;

/**
 * C-0a0 / F-2 — the AR/AP side of a historical opening, read from the import row
 * that minted the document.
 *
 * WHY THE IMPORT ROW AND NOT THE PARTNER. `documents.partner_id → partners.type`
 * looks like a cheaper discriminator, but `PartnerType::Both` exists and
 * `Partner::scopeCustomers()` / `scopeSuppliers()` both admit it
 * (`Partner.php:257-271`), so a `both` partner can legitimately hold an opening
 * on either side and the partner row cannot say which. The import row can: it
 * carries the batch's own `row_type`, stamped at insert from
 * `OpeningBatchType::rowType()`, and it is claimed to exactly one document by
 * `markRowsPosted()`.
 *
 * A row whose `row_type` is neither `AR` nor `AP` (a GL or INVENTORY opening)
 * cannot have minted a partner document, and is treated as no evidence.
 */
final readonly class HistoricalOpeningSideReader implements HistoricalOpeningSideReaderInterface
{
    public function sideFor(string $documentId): ?HistoricalOpeningSide
    {
        /** @var string|null $rowType */
        $rowType = OpeningBalanceImportRow::query()
            ->where('mapped_entity_id', $documentId)
            ->value('row_type');

        if ($rowType === null) {
            return null;
        }

        return match ($rowType) {
            OpeningBatchType::ArOpenItems->rowType() => HistoricalOpeningSide::AccountsReceivable,
            OpeningBatchType::ApOpenItems->rowType() => HistoricalOpeningSide::AccountsPayable,
            default => null,
        };
    }
}
