<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use InvalidArgumentException;

/**
 * The typed content of a correcting-entry document (R2-F4).
 *
 * Lives in the EXISTING `documents.payload` jsonb column
 * (`2025_11_30_080000_create_documents_table.php:34` — "for additional data per
 * document type"), under the namespaced key {@see self::PAYLOAD_KEY} so it can
 * never collide with the column's other tenants (credit-note reasons,
 * supplier-invoice fan-out, POS metadata, …). That reuse is what lets this lane
 * ship with ZERO schema change, per the c4 premise verification.
 *
 * A correcting entry has no product lines: `document_lines` describes goods and
 * services, and a correction describes ACCOUNTS. The legs live here instead.
 */
final class CorrectingEntryPayload
{
    /**
     * The namespaced key inside `documents.payload`.
     */
    public const PAYLOAD_KEY = 'correcting_entry';

    /**
     * @param  list<CorrectingEntryLegData>  $legs
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $legs,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A correcting entry must state WHY it exists — the reason is the audit trail.',
            );
        }

        if ($legs === []) {
            throw new InvalidArgumentException(
                'A correcting entry must carry at least one leg; an empty entry corrects nothing '
                .'and would seal noise into the journal chain.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $reason = $data['reason'] ?? null;
        $legs = $data['legs'] ?? null;

        if (! is_string($reason)) {
            throw new InvalidArgumentException('A correcting-entry payload needs a string reason.');
        }

        if (! is_array($legs)) {
            throw new InvalidArgumentException('A correcting-entry payload needs a legs array.');
        }

        $parsed = [];
        foreach ($legs as $leg) {
            if (! is_array($leg)) {
                throw new InvalidArgumentException('Each correcting-entry leg must be an object.');
            }

            /** @var array<string, mixed> $leg */
            $parsed[] = CorrectingEntryLegData::fromArray($leg);
        }

        return new self($reason, $parsed);
    }

    /**
     * Read the payload back off a `Document::$payload` array.
     *
     * @param  array<string, mixed>  $documentPayload
     */
    public static function fromDocumentPayload(?array $documentPayload): self
    {
        $section = $documentPayload[self::PAYLOAD_KEY] ?? null;

        if (! is_array($section)) {
            throw new InvalidArgumentException(sprintf(
                "This document carries no '%s' payload section — it is not a well-formed correcting entry.",
                self::PAYLOAD_KEY,
            ));
        }

        /** @var array<string, mixed> $section */
        return self::fromArray($section);
    }

    /**
     * @return array{reason: string, legs: list<array{account_id: string, debit: string, credit: string, description: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'legs' => array_map(
                static fn (CorrectingEntryLegData $leg): array => $leg->toArray(),
                $this->legs,
            ),
        ];
    }

    /**
     * The value to persist into `documents.payload`, namespaced.
     *
     * @return array<string, mixed>
     */
    public function toDocumentPayload(): array
    {
        return [self::PAYLOAD_KEY => $this->toArray()];
    }
}
