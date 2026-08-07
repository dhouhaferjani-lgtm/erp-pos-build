<?php

declare(strict_types=1);

namespace App\Shared\Partner;

/**
 * One table a module owns that carries a pointer to `partners.id`.
 *
 * Declared by `TableBackedPartnerReferenceSource::tables()` in the module
 * that owns the table, so the table/column names live next to the
 * migration that created them rather than inside the Partner module.
 */
final readonly class PartnerReferenceTable
{
    /**
     * @param  string  $table  Physical table name; also the key that appears
     *                         in the delete endpoint's 409 `error.details`.
     * @param  non-empty-list<string>  $columns  Every column on this table that
     *                                           references `partners.id`. A row matching on more
     *                                           than one of them is counted ONCE (the columns are
     *                                           OR'd inside a single count query), so a voucher
     *                                           whose holder and issuee are the same partner is
     *                                           not double-counted.
     * @param  bool  $hasSoftDeletes  When true, rows with a non-null
     *                                `deleted_at` are ignored — a soft-deleted row is already
     *                                invisible and must not block the partner's deletion.
     */
    public function __construct(
        public string $table,
        public array $columns = ['partner_id'],
        public bool $hasSoftDeletes = false,
    ) {}
}
