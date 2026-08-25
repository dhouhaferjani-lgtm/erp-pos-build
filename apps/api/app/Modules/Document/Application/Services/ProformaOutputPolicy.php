<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;

/**
 * Decides whether a RENDERED document is a proforma — SPEC §2.4 (F-13, F-64, F-95).
 *
 * A proforma rendering carries no VAT mention, no rate column, no tax breakdown,
 * no seal/hash/chain/QR block and no "posted" wording; it shows an estimated
 * total and names itself non-fiscal. Everything else about the document is
 * unchanged, and a POSTED document renders exactly as it always did.
 *
 * THE PREDICATE IS THE SEAL, NOT THE LIFECYCLE STATUS. This is N-6 fiscal gate
 * F-4's ruling, and it is load-bearing in both directions:
 *
 *   - A CANCELLED invoice keeps its `fiscal_hash` and carries `fiscal_status =
 *     VOIDED`. It WAS issued with VAT; hiding the VAT on its reprint would make
 *     the reprint disagree with the ledger and with the copy the customer holds.
 *     It gets the cancelled marker instead.
 *   - An opening-balance row (`ArApOpeningService`: Posted, NON_FISCAL, no hash)
 *     is real migrated data that WAS posted — in the previous system. It is not
 *     an estimate and must not be re-labelled as one.
 *   - A `Paid`-but-never-sealed invoice (the INV-2026-0003 shape the
 *     `documents:repair-paid-never-posted` command exists for) has no GL entry
 *     and no VAT anywhere but on the paper. It IS a proforma, and the fail-safe
 *     default of OQ-14 says so: unsealed and unexplained ⇒ non-definitive.
 *
 * Non-fiscal TYPES (quote, order, delivery note, purchase order …) are never
 * proformas: they are not documents the VAT authority reads, and the templates
 * that render them are unaffected by this lane.
 */
final class ProformaOutputPolicy
{
    public function isProforma(Document $document): bool
    {
        if (! in_array($document->type, DocumentPostingService::getFiscalDocumentTypes(), true)) {
            return false;
        }

        if ($document->fiscal_hash !== null) {
            return false;
        }

        if ($document->fiscal_status === FiscalStatus::Voided
            || $document->status === DocumentStatus::Cancelled) {
            return false;
        }

        if ($document->isHistorical() || $document->fiscal_category === FiscalCategory::NonFiscal) {
            return false;
        }

        return true;
    }
}
