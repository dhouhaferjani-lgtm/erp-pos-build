<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The goods decision that took effect on a cancelled invoice, as the client sees it.
 *
 * Plan CF T16 / CF-D5. A real DTO rather than a bare `?array` on {@see DocumentData}
 * ON PURPOSE: the TypeScript transformer renders an untyped PHP array as
 * `Array<any>`, which would give the front end no compile-time shape at all for a
 * value it has to branch on (`mode`) and navigate from (`return_note_id`). Rule 7 says
 * types flow from the backend and generated types are never hand-edited — so the way
 * to give the client a real type is to give the generator a real class.
 *
 * Only the ACCEPTED decision is projected. Rejected entries stay in `payload` as an
 * audit trail for support; a document view that presented one as state would report
 * that the goods decision is something nobody's cancellation actually applied.
 */
#[TypeScript]
final class ReturnDecisionProjection extends Data
{
    public function __construct(
        public ReturnDecisionMode $mode,
        /** The date the goods came back; only ever set for `already_returned`. */
        public ?string $returned_on,
        /** The return note this decision produced, when the mode bears goods. */
        public ?string $return_note_id,
        public ?string $decided_by,
        public string $decided_at,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  One `payload.return_decisions[]` record.
     */
    public static function fromAuditRecord(array $entry): self
    {
        return new self(
            mode: ReturnDecisionMode::from((string) ($entry['mode'] ?? '')),
            returned_on: isset($entry['returned_on']) ? (string) $entry['returned_on'] : null,
            return_note_id: isset($entry['return_note_id']) ? (string) $entry['return_note_id'] : null,
            decided_by: isset($entry['decided_by']) ? (string) $entry['decided_by'] : null,
            decided_at: (string) ($entry['decided_at'] ?? ''),
        );
    }
}
