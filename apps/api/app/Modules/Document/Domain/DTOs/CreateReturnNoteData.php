<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

use Carbon\CarbonInterface;

/**
 * Input for `ReturnNoteService::createDraft()`.
 *
 * Plan CF T2. Extracting the draft-create body out of
 * `ReturnNoteController::store()` is what lets the guided cancel flow (CF-D1: one
 * composite server call) create a return note through the SAME domain path a
 * manual `POST /return-notes` takes — the owner ruling's "no side-channel
 * writers" constraint. The controller becomes a thin adapter over this DTO.
 *
 * `locationId` is the document-level location and is deliberately **nullable and
 * never defaulted inside the service**. The standalone path resolves it through
 * `LocationContext`'s fallback chain in Presentation; the composite path leaves it
 * NULL and writes the real location onto each line instead, because CF-D11
 * forbids restocking into a location the goods never left — no invoice-location,
 * document-location or company-default fallback is permitted there.
 */
final class CreateReturnNoteData
{
    /**
     * @param  list<CreateReturnNoteLineData>  $lines
     */
    public function __construct(
        public readonly string $partnerId,
        public readonly CarbonInterface $documentDate,
        public readonly array $lines,
        public readonly ?string $currency = null,
        public readonly ?string $sourceDocumentId = null,
        public readonly ?string $locationId = null,
        public readonly ?string $notes = null,
        public readonly ?string $returnReason = null,
        public readonly ?string $returnCondition = null,
    ) {}
}
