<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W4R2-2 — data migration: re-type the two payment shapes that were written with
 * an `isIncoming() === true` type while their money moved OUT.
 *
 * ## Why this backfill exists
 *
 * Two writers stamped the wrong `payment_type`, and the dashboard's
 * "Payments Received" tile — which filters on `PaymentType::isIncoming()` and is
 * itself correct — counted both as money that had come in. On the campaign tenant
 * the tile read 1 580,400 TND against 452,000 genuinely received:
 *
 *   452,000  POS sale                (genuinely received)
 *  + 42,800  POS refund              money OUT of the drawer, typed `pos`
 *  + 85,600  POS refund              money OUT of the drawer, typed `pos`
 *  +500,000  supplier payment        money OUT of the bank, typed `document_payment`
 *  +200,000  supplier payment        money OUT of the bank, typed `document_payment`
 *  +300,000  supplier payment        money OUT of the bank, typed `document_payment`
 *  =1 580,400
 *
 * The writers are fixed on this branch. This migration corrects the history so
 * the tile is right for periods already closed, and it does so from the GENERAL
 * LEDGER — never from an amount heuristic, never from a `reference LIKE`.
 *
 * ## Predicate rationale — the evidence is the journal entry, not the row
 *
 * ### (a1) IMMEDIATE supplier payments: `document_payment` -> `supplier_payment`
 *
 * `PaymentController::store()` posts the supplier arm through
 * `GeneralLedgerService::createSupplierPaymentJournalEntry()`, the ONLY writer of
 * `journal_entries.source_type = 'supplier_payment'`, which stamps
 * `source_id = <payment id>` (`GeneralLedgerService.php:1025-1027`). Its debit leg
 * is the supplier-payable account — the company's `401` — resolved by
 * `system_purpose = 'supplier_payable'`. So the predicate is, literally,
 * "this payment has a POSTED Dr-401 journal entry that names it as its source":
 *
 *   payment_type = 'document_payment'
 *   AND EXISTS (posted je WHERE je.source_type = 'supplier_payment'
 *                           AND je.source_id = payments.id
 *                           AND je.company_id = payments.company_id
 *                     JOIN a posted line on the supplier_payable account
 *                     WITH debit > 0)
 *
 * Each conjunct earns its place:
 *  - `payment_type = 'document_payment'` — only rows carrying the WRONG value are
 *    candidates. Rows already typed `supplier_payment` (by the fixed writer, or by
 *    a re-run of this migration) match nothing. This is also what makes the
 *    statement idempotent.
 *  - `source_type = 'supplier_payment'` AND `source_id = payments.id` — the pair
 *    is written by one builder with one caller. `source_id` alone is NOT globally
 *    unique across `journal_entries` (see the memory note
 *    `reference_journal_entries_no_global_source_uniqueness`), which is exactly
 *    why the type is matched too, and why `company_id` is matched as well.
 *  - `status = 'posted'` — a Draft entry is money we cannot prove was recognised.
 *    Fail-closed: an unposted candidate keeps its old type and shows up in the
 *    residual census below rather than being silently rewritten.
 *  - the `debit > 0` line on `system_purpose = 'supplier_payable'` — the Dr-401
 *    leg itself. This is the belt: it refuses to re-type a payment whose entry
 *    carries the supplier source type but no payable debit, which is a data
 *    integrity problem of a different kind and needs a human, not a backfill.
 *
 * A company whose chart never mapped `supplier_payable` yields no matching line
 * and therefore no re-type. That is the correct answer: with no 401 there is no
 * evidence, and the migration leaves history alone.
 *
 * ### (a2) DEFERRED supplier payments (cheque / traite): `document_payment` -> `supplier_payment`
 *
 * **Gate r1 [Critical].** Arm (a1) alone misses an entire live shape, and it is
 * the shape a Tunisian tenant uses most: a supplier paid with an OUTBOUND
 * INSTRUMENT. `PaymentController::store()` routes that through an `if` branch
 * that sits ABOVE the `elseif` arm (a1) matches
 * (`PaymentController.php:1163-1174`, `$isDeferredSupplier`), into
 * `OutboundInstrumentIssuer::issueExisting()` (`OutboundInstrumentIssuer.php:180`)
 * -> `GeneralLedgerService::createOutboundInstrumentIssueEntry()`. That builder
 * stamps `source_type = 'instrument'` and `source_id = <INSTRUMENT id>`
 * (`GeneralLedgerService.php:1199,1201`) — never `('supplier_payment', payment.id)`.
 * The two branches are mutually exclusive, so arm (a1) can never see one of these
 * rows, and every historical deferred supplier payment would have kept
 * `document_payment` (`isIncoming() === true`) and STAYED IN THE TILE — while the
 * fixed writer types new ones `supplier_payment`. Writer and backfill would have
 * disagreed about what a supplier payment is.
 *
 * The evidence for this shape is just as unambiguous as arm (a1)'s, and it is
 * reached by a DIFFERENT link. `PaymentController.php:1219-1221` stamps
 * `payments.journal_entry_id = <the instrument-issue entry>`, so the join is the
 * direct `journal_entry_id` one — the same exact link arm (b) uses, with no
 * `source_id` ambiguity to guard against (`source_id` there is the INSTRUMENT id,
 * so matching it against `payments.id` would find nothing):
 *
 *   payment_type = 'document_payment'
 *   AND EXISTS (posted je WHERE je.id = payments.journal_entry_id
 *                           AND je.company_id = payments.company_id
 *                           AND je.source_type = 'instrument'
 *                     JOIN a line on the supplier_payable account
 *                     WITH debit > 0)
 *
 * The `debit > 0 on supplier_payable` belt is what makes `source_type='instrument'`
 * safe to match on, because that source type is shared by the whole outbound
 * instrument lifecycle. Only the ISSUE entry debits 401
 * (`createOutboundInstrumentIssueEntry()` = Dr 401 partner-tagged / Cr
 * ChecksToPay|EffetsPayable, `GeneralLedgerService.php:1064-1087`); CLEARING is
 * Dr payable-instrument / Cr bank, DISHONOR is Dr bank / Cr payable-instrument,
 * and CANCELLATION *credits* 401 — none of them can satisfy `debit > 0` on that
 * account. A deferred CUSTOMER payment cannot match either: it takes
 * `$isDeferredCustomer`, which falls through to `createPaymentReceivedJournalEntry()`
 * and links a `source_type = 'customer_payment'` entry.
 *
 * `status = 'posted'` is required here as in arm (a1) — `issueExisting()` calls
 * `postEntryNow()` in-transaction, so a genuine issue entry always satisfies it.
 *
 * ### (b) POS refund legs: `pos` -> `pos_refund`
 *
 * `TreasuryReceiptBridge` links every payment leg it writes to the entry it
 * posted, via `payments.journal_entry_id`. For a refund/void receipt that entry
 * comes from `GeneralLedgerService::createPOSRefundReversalEntry()`, the ONLY
 * writer of `source_type = 'pos_receipt_refund'`
 * (`GeneralLedgerService.php:4204`) — the reversal entry, distinct from the sale
 * entry's source type by design. A direct `journal_entry_id` join is therefore
 * exact, with no source_id ambiguity to guard against at all.
 *
 * `status` is deliberately NOT constrained on this arm: the bridge posts the
 * reversal SYNCHRONOUSLY IN-TRANSACTION with the payment row (`postEntryNow`), so
 * a leg linked to a `pos_receipt_refund` entry IS a refund leg whatever the
 * entry's status says, and a rare Draft must be re-typed too — leaving it as
 * `pos` is precisely the over-count being fixed. Note the asymmetry with arm (a),
 * where the entry is posted through a different path and `posted` is the honest
 * evidence bar.
 *
 * ## What is NOT re-typed, and why that is the ruling
 *
 * A supplier payment whose journal entry is missing, Draft, or carries no
 * supplier-payable debit KEEPS `document_payment`, on EITHER route. The evidence
 * is ambiguous there and this migration does not guess. Post-migration residual
 * census — a non-zero count is a finding to hand to a human, NOT a reason to
 * widen the predicate. With arms (a1)+(a2) in place this should now be EMPTY on a
 * tenant with no unposted AP:
 *
 *   SELECT p.id, p.amount, p.payment_date
 *     FROM payments p
 *     JOIN payment_allocations pa ON pa.payment_id = p.id
 *     JOIN documents d ON d.id = pa.document_id
 *    WHERE p.payment_type = 'document_payment'
 *      AND d.type = 'supplier_invoice';
 *
 * ## Per-tenant census to run BEFORE the fleet migration (row counts to expect)
 *
 *   -- (a1) rows this migration will re-type to 'supplier_payment' (immediate)
 *   SELECT COUNT(*) FROM payments p
 *    WHERE p.payment_type = 'document_payment'
 *      AND EXISTS (
 *          SELECT 1 FROM journal_entries je
 *            JOIN journal_lines jl ON jl.journal_entry_id = je.id
 *            JOIN accounts a ON a.id = jl.account_id
 *           WHERE je.source_id = p.id
 *             AND je.source_type = 'supplier_payment'
 *             AND je.company_id = p.company_id
 *             AND je.status = 'posted'
 *             AND a.system_purpose = 'supplier_payable'
 *             AND CAST(jl.debit AS NUMERIC) > 0);
 *
 *   -- (a2) rows this migration will re-type to 'supplier_payment' (deferred:
 *   --      cheque / traite, linked to their outbound-instrument issue entry)
 *   SELECT COUNT(*) FROM payments p
 *    WHERE p.payment_type = 'document_payment'
 *      AND EXISTS (
 *          SELECT 1 FROM journal_entries je
 *            JOIN journal_lines jl ON jl.journal_entry_id = je.id
 *            JOIN accounts a ON a.id = jl.account_id
 *           WHERE je.id = p.journal_entry_id
 *             AND je.source_type = 'instrument'
 *             AND je.company_id = p.company_id
 *             AND je.status = 'posted'
 *             AND a.system_purpose = 'supplier_payable'
 *             AND CAST(jl.debit AS NUMERIC) > 0);
 *
 *   -- (b) rows this migration will re-type to 'pos_refund'
 *   SELECT COUNT(*) FROM payments p
 *    WHERE p.payment_type = 'pos'
 *      AND EXISTS (
 *          SELECT 1 FROM journal_entries je
 *           WHERE je.id = p.journal_entry_id
 *             AND je.company_id = p.company_id
 *             AND je.source_type = 'pos_receipt_refund');
 *
 * FLEET-ABORT RISK: none of its own. Both statements are plain UPDATEs with no
 * constraint to violate ONCE `2026_08_25_150000_widen_payments_payment_type_check_for_pos_refund`
 * has run — which is why that file's timestamp is earlier. If the widening is
 * skipped, arm (b) raises SQLSTATE 23514 against `chk_payments_payment_type_enum`
 * and aborts that tenant; the two migrations must travel together.
 *
 * ## Idempotency
 *
 * All THREE predicates require the row to still carry the OLD value, so a re-run
 * matches zero rows. (a1) and (a2) are also mutually exclusive by construction:
 * one matches `source_type='supplier_payment'` keyed on `source_id`, the other
 * `source_type='instrument'` keyed on `journal_entry_id`, and a single entry
 * carries one source type.
 *
 * Portable DML (no DDL), so the SQLite test driver runs it too and the lane's
 * migration tests can exercise both arms.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasTable('journal_entries')) {
            return;
        }

        if (Schema::hasTable('journal_lines') && Schema::hasTable('accounts')) {
            DB::statement(
                <<<'SQL'
                UPDATE payments
                SET payment_type = 'supplier_payment'
                WHERE payment_type = 'document_payment'
                  AND EXISTS (
                      SELECT 1
                      FROM journal_entries je
                      JOIN journal_lines jl ON jl.journal_entry_id = je.id
                      JOIN accounts a ON a.id = jl.account_id
                      WHERE je.source_id = payments.id
                        AND je.source_type = 'supplier_payment'
                        AND je.company_id = payments.company_id
                        AND je.status = 'posted'
                        AND a.system_purpose = 'supplier_payable'
                        AND CAST(jl.debit AS NUMERIC) > 0
                  )
                SQL
            );

            // Arm (a2) — DEFERRED supplier payments (cheque / traite). Separate
            // statement rather than an OR inside arm (a1) so each predicate stays
            // readable against the writer it mirrors, and so a census can count
            // the two shapes apart. See the docblock section (a2) for why
            // `source_type='instrument'` is safe once the Dr-401 belt is applied.
            DB::statement(
                <<<'SQL'
                UPDATE payments
                SET payment_type = 'supplier_payment'
                WHERE payment_type = 'document_payment'
                  AND EXISTS (
                      SELECT 1
                      FROM journal_entries je
                      JOIN journal_lines jl ON jl.journal_entry_id = je.id
                      JOIN accounts a ON a.id = jl.account_id
                      WHERE je.id = payments.journal_entry_id
                        AND je.source_type = 'instrument'
                        AND je.company_id = payments.company_id
                        AND je.status = 'posted'
                        AND a.system_purpose = 'supplier_payable'
                        AND CAST(jl.debit AS NUMERIC) > 0
                  )
                SQL
            );
        }

        DB::statement(
            <<<'SQL'
            UPDATE payments
            SET payment_type = 'pos_refund'
            WHERE payment_type = 'pos'
              AND EXISTS (
                  SELECT 1
                  FROM journal_entries je
                  WHERE je.id = payments.journal_entry_id
                    AND je.company_id = payments.company_id
                    AND je.source_type = 'pos_receipt_refund'
              )
            SQL
        );
    }

    public function down(): void
    {
        // Intentionally not reversible: re-typing back would re-introduce the
        // over-count this migration exists to remove. To inspect what was changed,
        // run the three census queries in the docblock with the type conditions
        // inverted ('supplier_payment' / 'pos_refund').
    }
};
