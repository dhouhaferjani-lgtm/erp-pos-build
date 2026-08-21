<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use PHPUnit\Framework\TestCase;

/**
 * R2-F4 — the correcting entry is a DOCUMENT TYPE, not a free-floating manual
 * journal entry.
 *
 * Owner ruling c4 (`docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`),
 * branch (a) strengthened: "everything needs to be documented" — a correction
 * requires creating a correcting DOCUMENT linked to the original it refers to.
 *
 * This test pins the enum contract itself, including the two properties that
 * decide whether the new case can be persisted at all without a migration:
 *   - `documents.type` is `varchar(20)` (`2025_11_30_080000_create_documents_table.php:19`)
 *     with NO check constraint, so any value of at most 20 characters is
 *     storable as-is;
 *   - `document_sequences.type` is likewise `varchar(20)`
 *     (`2025_11_30_080002_create_document_sequences_table.php:16`).
 * If either assertion below ever fails, the zero-schema claim of this lane has
 * been invalidated and a migration is owed.
 */
final class CorrectingEntryDocumentTypeTest extends TestCase
{
    public function test_the_correcting_entry_case_exists_with_its_pinned_backing_value(): void
    {
        self::assertSame('correcting_entry', DocumentType::CorrectingEntry->value);
    }

    /**
     * The zero-schema claim. `documents.type` and `document_sequences.type` are
     * both `varchar(20)`; a longer backing value would silently truncate on
     * PostgreSQL-strict inserts (or error), which is precisely the migration this
     * lane claims not to need.
     */
    public function test_every_document_type_value_fits_the_varchar_20_column(): void
    {
        foreach (DocumentType::cases() as $type) {
            self::assertLessThanOrEqual(
                20,
                strlen($type->value),
                "DocumentType::{$type->name} does not fit documents.type varchar(20)",
            );
        }
    }

    public function test_it_carries_its_own_numbering_prefix_and_label(): void
    {
        self::assertSame('CE', DocumentType::CorrectingEntry->getPrefix());
        self::assertSame('Correcting Entry', DocumentType::CorrectingEntry->label());
    }

    /**
     * Every prefix must stay unique: `document_sequences` is keyed on
     * `(tenant_id, type, year)` but the human-facing number is
     * `PREFIX-YEAR-NNNN`, so two types sharing a prefix would mint
     * indistinguishable numbers from independent counters.
     */
    public function test_the_new_prefix_collides_with_no_existing_type(): void
    {
        $prefixes = array_map(
            static fn (DocumentType $type): string => $type->getPrefix(),
            DocumentType::cases(),
        );

        self::assertSame(
            count($prefixes),
            count(array_unique($prefixes)),
            'Document type prefixes must be unique',
        );
    }

    /**
     * A correcting entry moves the GENERAL LEDGER, never the partner's
     * receivable position: it exists to repair a journal entry the original
     * document already sealed, and the original is what carries the AR balance.
     * Booking it into AR would double-count the customer's debt.
     */
    public function test_it_does_not_touch_accounts_receivable(): void
    {
        self::assertFalse(DocumentType::CorrectingEntry->affectsReceivable());
        self::assertSame(0, DocumentType::CorrectingEntry->receivableDirection());
    }

    /**
     * Nothing is ever "paid" against a correcting entry — it has no balance due.
     */
    public function test_it_never_transitions_to_paid(): void
    {
        self::assertFalse(DocumentType::CorrectingEntry->canTransitionToPaid());
    }

    /**
     * P3-9 (fiscal gate). The zero-schema claim's LOAD-BEARING property.
     *
     * `chk_fiscal_category_enum` admits only
     * ('NON_FISCAL','FISCAL_RECEIPT','TAX_INVOICE','CREDIT_NOTE'), so a
     * correcting entry that resolved to anything else would need the constraint
     * widened — and the lane's whole premise is that it does not.
     *
     * It currently falls out of `fromDocumentType()`'s `default` arm rather than
     * a named one, which is exactly why this test exists: a future edit that
     * gives CorrectingEntry an explicit fiscal category would compile, migrate
     * nothing, and break the INSERT at runtime on Postgres only.
     */
    public function test_the_type_is_non_fiscal_which_is_what_keeps_the_lane_zero_schema(): void
    {
        self::assertSame(
            FiscalCategory::NonFiscal,
            FiscalCategory::fromDocumentType(DocumentType::CorrectingEntry),
        );
        self::assertSame('NON_FISCAL', FiscalCategory::NonFiscal->value);
    }
}
