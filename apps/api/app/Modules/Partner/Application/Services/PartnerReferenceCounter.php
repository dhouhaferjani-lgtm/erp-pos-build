<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use Illuminate\Database\ConnectionInterface;

/**
 * Counts the financial records that still point at a partner.
 *
 * BUG-007: `PartnerController::destroy` had no business guard, so an admin
 * could soft-delete a partner that still carried invoices, payments or POS
 * receipts. Because the partner is SOFT-deleted, the database FKs never fire
 * (`pos_receipts.partner_id` is `nullOnDelete`, `payments`/`documents` are
 * `restrict`) — the rows silently keep pointing at a row that no longer
 * surfaces anywhere, orphaning the partner's financial history.
 *
 * Cross-module reads use the query builder against table names rather than
 * importing Document/Payment/Receipt models, so the Partner module keeps no
 * compile-time dependency on those modules (same approach as
 * `Fiscal\...\OutboxIngestor` reading `pos_terminals`).
 */
final class PartnerReferenceCounter
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Reference counts per table, omitting tables with no references.
     *
     * An empty array means the partner is safe to delete.
     *
     * @return array<string, int>
     */
    public function countFor(string $partnerId): array
    {
        $counts = [
            // Quotes, orders, invoices, credit notes, delivery notes. Documents
            // are soft-deleted, and a soft-deleted document must not block.
            'documents' => $this->db->table('documents')
                ->where('partner_id', $partnerId)
                ->whereNull('deleted_at')
                ->count(),
            // Treasury payments — these carry the partner's open balance.
            // The table has no soft deletes.
            'payments' => $this->db->table('payments')
                ->where('partner_id', $partnerId)
                ->count(),
            // Fiscal POS receipts — immutable by design (NF525), never deleted.
            'pos_receipts' => $this->db->table('pos_receipts')
                ->where('partner_id', $partnerId)
                ->count(),
        ];

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
