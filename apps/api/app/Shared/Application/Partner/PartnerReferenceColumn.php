<?php

declare(strict_types=1);

namespace App\Shared\Application\Partner;

/**
 * One column that references `partners.id`, optionally guarded by a
 * discriminator.
 *
 * Most partner references are a plain uuid column and need nothing more
 * than the column name — `PartnerReferenceTable` accepts a bare string for
 * those. This VO exists for POLYMORPHIC anchors, where the id column alone
 * is not a partner reference at all: `loyalty_members.loyaltyable_id` holds
 * a partner id only when `loyaltyable_type = 'partner'`, and matching it
 * blindly would block a partner because some unrelated contact happened to
 * share its uuid.
 *
 * The `where` conditions are AND'ed with the partner match, and the whole
 * conjunction is then OR'd against the table's other columns — so a row is
 * still counted once no matter how many of its columns qualify.
 */
final readonly class PartnerReferenceColumn
{
    /**
     * @param  string  $column  The column holding the partner id.
     * @param  array<string, string|int|bool>  $where  Extra equality conditions
     *                                                 that must ALSO hold for a row to count as a reference
     *                                                 through this column. Empty for ordinary columns.
     */
    public function __construct(
        public string $column,
        public array $where = [],
    ) {}
}
