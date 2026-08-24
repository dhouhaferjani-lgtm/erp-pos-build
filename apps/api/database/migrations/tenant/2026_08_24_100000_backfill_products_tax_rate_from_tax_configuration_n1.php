<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign defect N-1 (P0), 2026-08-24 — repair every product whose
 * denormalised `products.tax_rate` disagrees with the tax configuration its
 * `default_tax_configuration_id` points at.
 *
 * WHY IT MUST RUN, AND IN THIS DEPLOY. `products.tax_rate` is not a cached
 * convenience; it is the number the tills and the documents actually charge.
 * `ReceiptCreationService` reads `$product->tax_rate` for every POS line with
 * no client override available (`StoreReceiptRequest` has no
 * `lines.*.tax_rate` rule), and the resulting rate is written to
 * `pos_receipt_lines` / `pos_receipt_vat_details` and sealed into the fiscal
 * hash chain. `DocumentLineTaxResolver` falls back to the same column. Until
 * the code landing in THIS deploy, nothing in the product write path ever read
 * `tax_configurations.percentage_rate`: `ProductController::store()` derived
 * the rate from `categories.default_tax_rate` / `companies.default_tax_rate`
 * only, and `::update()` had no tax handling at all. A tenant that picked
 * "TVA 7 %" therefore stored the right configuration id beside the company's
 * 19 % default and over-charged VAT on every sale of that product, with the
 * correct rate displayed on screen the whole time.
 *
 * The code fix repairs products saved from now on. It cannot repair the rows
 * already written — `::update()` only re-derives when a request actually
 * carries `default_tax_configuration_id`, so a stale product stays stale until
 * a human happens to re-save it, product by product. This migration closes
 * that window in the same deploy, before another receipt seals another wrong
 * rate.
 *
 * THE PREDICATE — as narrow as the defect. A product is corrected when, and
 * only when, ALL of the following hold:
 *
 *  (a) `default_tax_configuration_id` IS NOT NULL. A product with no
 *      configuration has made no statement to contradict; its `tax_rate` came
 *      from the category/company ladder and is left exactly alone.
 *  (b) The configuration is a `LINE_ITEMS` PERCENTAGE row in the tenant's
 *      table. `tax_configurations` also holds `DOCUMENT_TOTAL` rows (Tunisian
 *      stamp duty: `percentage_rate` NULL, `fixed_amount` set). Copying a NULL
 *      or a dinar amount into a VAT rate would be a second, worse defect, so
 *      those rows are skipped and reported.
 *  (c) `tax_rate` ACTUALLY DIFFERS from `percentage_rate`. Compared with
 *      `IS DISTINCT FROM` so a NULL `tax_rate` counts as different (and is
 *      repaired) rather than dropping out of the comparison, which is what a
 *      plain `<>` would do.
 *
 * DELIBERATELY NOT SCOPED BY COUNTRY. The join is on the FK the product itself
 * stores, not on `company.country_code`. `tax_configurations` is a
 * country-partitioned reference table and a row whose country disagrees with
 * its company is refused 422 at the API by `TaxConfigurationCountryCoherent`
 * — but a pre-existing mismatched row, if one exists, still describes a REAL
 * percentage a human chose, and copying it is strictly better than leaving the
 * company default in place. Re-deriving a country here would mean guessing.
 *
 * SELF-GUARDING AND IDEMPOTENT, as `tenants:migrate` demands (pushing to
 * origin/dev auto-deploys and runs this unattended on every tenant database):
 *  - EITHER TABLE ABSENT: reports `status=skipped` with the reason and
 *    returns. It REPORTS rather than returning silently, because absence of
 *    the token line is how a deploy gate detects a tenant that died mid-run —
 *    a silent skip is indistinguishable from a crash at the log.
 *  - IDEMPOTENT BY CONSTRUCTION: the predicate is "the two disagree". Once a
 *    row is corrected it no longer matches, so a second run updates zero rows
 *    and does not even touch `updated_at`. It is also self-correcting: if a
 *    configuration's `percentage_rate` is later amended, a re-run realigns the
 *    products on it.
 *  - FORWARD-ONLY ON WHAT IT TOUCHES: it never writes a rate that is not the
 *    configuration's own. A product an operator deliberately put on a
 *    different rate than its configuration cannot survive this — but that
 *    state is precisely the bug, it is unreachable through the API from this
 *    deploy on, and there is no way to tell a deliberate divergence from the
 *    N-1 corruption in the data. Correcting toward the configuration the
 *    operator explicitly selected is the only defensible direction.
 *  - NEVER THROWS, and the failure is CONTAINED IN A SAVEPOINT. On PostgreSQL
 *    a caught QueryException inside the migrator's own transaction would leave
 *    that transaction aborted (SQLSTATE 25P02) and the migration repository's
 *    bookkeeping INSERT would then fail, killing the tenant's entire run.
 *    `DB::transaction()` opens a SAVEPOINT when a transaction is already
 *    active and rolls back to that alone, which is what makes the catch below
 *    an honest guarantee.
 *
 * MIGRATION-BEARING — PRE-FLIGHT CENSUS (run per tenant DB before deploying;
 * this migration adds no constraint, so there is no violation that can abort a
 * tenant, but the blast radius must be known because the rows it rewrites are
 * fiscal):
 *
 *   SELECT p.id, p.sku, p.name, p.tax_rate AS stale_rate,
 *          tc.code, tc.percentage_rate AS correct_rate
 *   FROM products p
 *   JOIN tax_configurations tc ON tc.id = p.default_tax_configuration_id
 *   WHERE tc.applies_to = 'LINE_ITEMS'
 *     AND tc.tax_type = 'PERCENTAGE'
 *     AND tc.percentage_rate IS NOT NULL
 *     AND p.tax_rate IS DISTINCT FROM tc.percentage_rate
 *   ORDER BY p.sku;
 *
 * And the rows this migration will REFUSE to touch (case (b)), which need a
 * human decision rather than a backfill:
 *
 *   SELECT p.id, p.sku, tc.code, tc.applies_to, tc.tax_type
 *   FROM products p
 *   JOIN tax_configurations tc ON tc.id = p.default_tax_configuration_id
 *   WHERE tc.applies_to <> 'LINE_ITEMS'
 *      OR tc.tax_type <> 'PERCENTAGE'
 *      OR tc.percentage_rate IS NULL;
 *
 * IT HAS A SECOND HALF, AND THAT HALF WRITES NOTHING. Repairing the product
 * alone leaves the quotes and orders already written from it carrying the stale
 * rate on their own lines, and conversion copies a line's `tax_rate` verbatim —
 * so one of those, converted after this deploy, would mint a NEW wrong-VAT
 * invoice. {@see censusUnpostedDocumentLines()} therefore REPORTS every such
 * line — one WARNING worklist entry per document, with its number, type, status
 * and stale → correct rates — so the deploy log is the list a human works
 * through in the editor. An earlier revision of this migration UPDATEd those
 * lines and re-totalled their documents; that was withdrawn after two
 * adversarial gates found four separate derived columns it left stale
 * (deductible VAT on purchase lines, `documents.balance_due`,
 * `document_tax_details`, and the type-blindness that let it rewrite credit
 * notes and supplier invoices at all). The full reasoning, and why REPORTING is
 * the right trade for a population of four documents on one tenant, is in that
 * method's docblock.
 *
 * WHAT THIS MIGRATION DOES NOT REPAIR — and now says so out loud, per document.
 * Anything already SEALED (POS receipts, posted/sealed/voided documents) is
 * hash-chained or carries GL entries derived from its tax; correcting one is a
 * fiscal correction (credit note / refund), never an UPDATE. Unposted document
 * lines are repairable but are repaired BY A HUMAN through the ordinary write
 * path, which maintains every derived column this file cannot — they are listed
 * in the log, not rewritten here. This migration writes exactly one thing:
 * `products.tax_rate`, a denormalised column with no derivation chain hanging
 * off it, which is what stops NEW wrong rates from being written and sealed.
 *
 * PORTABILITY. Written as one raw statement per driver rather than the query
 * builder: `UPDATE ... FROM` (PostgreSQL) and `UPDATE ... SET x = (SELECT ...)`
 * (SQLite) have no common builder spelling, and the default test harness runs
 * every tenant migration under SQLite via `RefreshDatabase`. Both spellings
 * carry the same predicate; `IS DISTINCT FROM` is native on PostgreSQL and
 * expanded explicitly for SQLite. Both columns are `decimal(5,2)` and are
 * compared by the database's own numeric semantics — no PHP-side float ever
 * touches a rate (agent rule 19).
 */
return new class extends Migration
{
    /**
     * Deploy-gate token for the AUTOMATIC (`tenants:migrate`) path. One line
     * per tenant, exactly once, at WARNING level — production runs
     * LOG_LEVEL=warning and drops info entirely, so an info-level gate line
     * would let a checklist's grep pass against an empty log.
     */
    private const GATE_TOKEN = 'PRODUCT TAX-RATE N1 BACKFILL MIGRATION:';

    /**
     * The token for the per-document worklist lines the second half emits.
     * Separate from GATE_TOKEN so a deploy gate can still assert EXACTLY ONE
     * gate line per tenant while the worklist is as long as it needs to be.
     */
    private const CENSUS_TOKEN = 'PRODUCT TAX-RATE N1 UNPOSTED-DOC CENSUS:';

    /**
     * Ceiling on per-document worklist lines. The counters in the gate line
     * stay exact; only the enumeration is capped, with an explicit
     * `truncated=true` line when it bites. Under `tenants:migrate` every tenant
     * shares one log, and an unbounded per-row dump is its own incident.
     */
    private const CENSUS_REPORT_CAP = 50;

    /**
     * Lines a human can still legally repair: product carries a LINE_ITEMS
     * percentage configuration that disagrees with the stored rate, on a
     * document that is not sealed (`fiscal_status`/`fiscal_hash`), not posted
     * or cancelled (`status`), and not soft-deleted (`deleted_at` — r1 treasury
     * finding 6; `Document` uses SoftDeletes and the declaration query filters
     * it, so a trashed row must not appear on an operator worklist).
     *
     * DELIBERATELY NOT TYPE-FILTERED, because this statement no longer WRITES.
     * A drifted draft supplier invoice or credit note must never be rewritten
     * from the sale-side product master — but it is still worth NAMING in the
     * worklist, with its type, so whoever reads the log can route it to the
     * right correction path instead of discovering it later. Every line is
     * emitted with `type=` for exactly that reason.
     *
     * `IS DISTINCT FROM` is spelled out longhand so the one statement runs
     * unchanged on both drivers.
     */
    private const CENSUS_SQL = <<<'SQL'
        SELECT d.document_number AS document_number,
               d.type AS type,
               d.status AS status,
               dl.tax_rate AS stale_rate,
               tc.percentage_rate AS correct_rate
        FROM document_lines dl
        JOIN documents d ON d.id = dl.document_id
        JOIN products p ON p.id = dl.product_id
        JOIN tax_configurations tc ON tc.id = p.default_tax_configuration_id
        WHERE tc.applies_to = 'LINE_ITEMS'
          AND tc.tax_type = 'PERCENTAGE'
          AND tc.percentage_rate IS NOT NULL
          AND d.status IN ('draft', 'confirmed')
          AND d.fiscal_status = 'DRAFT'
          AND d.fiscal_hash IS NULL
          AND d.deleted_at IS NULL
          AND (dl.tax_rate IS NULL OR dl.tax_rate <> tc.percentage_rate)
        ORDER BY d.document_number
        SQL;

    public function up(): void
    {
        // Under `tenants:migrate` all tenants share one laravel.log, so an
        // unattributed line cannot be acted on.
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        foreach (['products', 'tax_configurations'] as $table) {
            if (! Schema::hasTable($table)) {
                Log::warning(sprintf(
                    '%s tenant=%s status=skipped reason=%s-table-absent.',
                    self::GATE_TOKEN,
                    $tenantKey,
                    str_replace('_', '-', $table),
                ));

                return;
            }
        }

        $connection = DB::connection($this->getConnection());

        try {
            $repaired = 0;

            $connection->transaction(function () use ($connection, &$repaired): void {
                $repaired = $connection->getDriverName() === 'pgsql'
                    ? $connection->affectingStatement(
                        <<<'SQL'
                        UPDATE products
                        SET tax_rate = tc.percentage_rate,
                            updated_at = CURRENT_TIMESTAMP
                        FROM tax_configurations tc
                        WHERE tc.id = products.default_tax_configuration_id
                          AND tc.applies_to = 'LINE_ITEMS'
                          AND tc.tax_type = 'PERCENTAGE'
                          AND tc.percentage_rate IS NOT NULL
                          AND products.tax_rate IS DISTINCT FROM tc.percentage_rate
                        SQL
                    )
                    : $connection->affectingStatement(
                        <<<'SQL'
                        UPDATE products
                        SET tax_rate = (
                                SELECT tc.percentage_rate
                                FROM tax_configurations tc
                                WHERE tc.id = products.default_tax_configuration_id
                            ),
                            updated_at = CURRENT_TIMESTAMP
                        WHERE EXISTS (
                            SELECT 1
                            FROM tax_configurations tc
                            WHERE tc.id = products.default_tax_configuration_id
                              AND tc.applies_to = 'LINE_ITEMS'
                              AND tc.tax_type = 'PERCENTAGE'
                              AND tc.percentage_rate IS NOT NULL
                              AND (
                                    products.tax_rate IS NULL
                                 OR products.tax_rate <> tc.percentage_rate
                              )
                        )
                        SQL
                    );
            });

            $documentLines = $this->censusUnpostedDocumentLines($connection);

            Log::warning(sprintf(
                '%s tenant=%s status=ok repaired=%d flagged_doc_lines=%d flagged_docs=%d.',
                self::GATE_TOKEN,
                $tenantKey,
                $repaired,
                $documentLines['lines'],
                $documentLines['documents'],
            ));
        } catch (Throwable $e) {
            // One tenant's failure must not brick the unattended run for every
            // other tenant. Same gate token so a `status=FAILED` grep catches
            // this path too. A tenant left unrepaired keeps charging the wrong
            // VAT on its stale products until the census query above is run by
            // hand — visibly wrong, not silently corrupted further, because
            // the code fix still prevents NEW drift.
            Log::error(sprintf(
                '%s tenant=%s status=FAILED reason=exception. %s',
                self::GATE_TOKEN,
                $tenantKey,
                $e->getMessage(),
            ));
        }
    }

    /**
     * SECOND HALF — REPORT ONLY. It writes NOTHING (r2 decision, below).
     *
     * WHAT IT REPORTS. Every document line whose product carries a LINE_ITEMS
     * percentage configuration that DISAGREES with the line's stored
     * `tax_rate`, on a document that is not sealed, not posted and not
     * soft-deleted — i.e. the lines a human can still legally repair by
     * re-picking the product in the editor. One WARNING line per document with
     * its number, type, status and the stale → correct rates, so the deploy log
     * IS the worklist. Nothing here mutates a row.
     *
     * WHY IT NO LONGER REPAIRS — THE R2 DECISION, IN FULL.
     * Round r1 shipped an actual UPDATE of these lines plus a re-total of their
     * documents. Two independent adversarial gates then found, by execution,
     * four separate ways it was wrong, and they were not the same four:
     *
     *  - It was TYPE-BLIND. Draft CREDIT NOTES (which exist to mirror a SEALED
     *    invoice and whose rate disagrees with the product BY DESIGN), draft
     *    SUPPLIER INVOICES (whose `tax_rate` is what the supplier charged on
     *    their paper, not what our sale-side product master says), supplier
     *    credit notes, expenses, return notes and delivery notes were all in
     *    the predicate and all provably rewritten.
     *  - It left `document_lines.recoverable_tax_amount` /
     *    `non_recoverable_tax_amount` stale, which made a repaired draft
     *    supplier invoice PERMANENTLY UNPOSTABLE: the GR-IR clearing entry
     *    refuses to post when `total != billedHT + recoverableVAT + …`.
     *  - It left `documents.balance_due` stale — the cache trigger fires on
     *    `payment_allocations`, never on a `documents` UPDATE — and
     *    `Document::outstandingBalance()` treats a non-null `balance_due` as
     *    authoritative. That is the AR/AP outstanding figure and the payment
     *    ceiling. A confirmed sales order with a prepayment allocation could be
     *    left over-allocated.
     *  - It left `document_tax_details` stale. The VAT declaration reads that
     *    SNAPSHOT, not the live lines, so a repaired confirmed invoice would
     *    have declared the old rate to the DGI while showing the new one.
     *
     * Each of those is fixable in isolation. The pattern is not: a migration
     * that rewrites a document is re-implementing the derivation chain the
     * application performs on save (totals, the balance cache, the tax
     * snapshot, line-level deductible VAT, exemption and stamp-duty
     * re-derivation), and two rounds of review found a new missing link each
     * time. Closing four known instances would not establish there is no fifth,
     * and this runs UNATTENDED on every tenant database on push.
     *
     * Against that: the entire population the repair existed for is FOUR
     * documents on ONE tenant (the campaign tenant — QT-2026-0002/0003/0004 and
     * SO-2026-0001, 7 lines, all quote/sales_order, none sealed). With the
     * product fix in this same deploy, an operator repairs each by re-picking
     * the product on the line — the editor then resolves the correct band
     * through the ordinary write path, which maintains every derived column
     * this migration could not. A census that names those four documents makes
     * that a two-minute job; an UPDATE that gets one of the four derived
     * columns wrong is a fiscal defect on every tenant.
     *
     * So: the products half writes (it is a single denormalised column with no
     * derivation chain hanging off it), and the document half reports. What is
     * NOT repaired is therefore reported precisely rather than silently left.
     *
     * BOUNDED. At most self::CENSUS_REPORT_CAP document lines are logged; the
     * summary counters are always exact, and a truncation line is emitted when
     * there are more. An unbounded per-row dump into a shared fleet log is its
     * own incident.
     *
     * PRE-FLIGHT / POST-DEPLOY CENSUS (identical to what this logs):
     *
     *   SELECT d.document_number, d.type, d.status, dl.tax_rate AS stale_rate,
     *          tc.code, tc.percentage_rate AS correct_rate
     *   FROM document_lines dl
     *   JOIN documents d ON d.id = dl.document_id
     *   JOIN products p ON p.id = dl.product_id
     *   JOIN tax_configurations tc ON tc.id = p.default_tax_configuration_id
     *   WHERE tc.applies_to = 'LINE_ITEMS' AND tc.tax_type = 'PERCENTAGE'
     *     AND tc.percentage_rate IS NOT NULL
     *     AND d.status IN ('draft', 'confirmed')
     *     AND d.fiscal_status = 'DRAFT'
     *     AND d.fiscal_hash IS NULL
     *     AND d.deleted_at IS NULL
     *     AND dl.tax_rate IS DISTINCT FROM tc.percentage_rate
     *   ORDER BY d.document_number;
     *
     * @return array{lines: int, documents: int}
     */
    private function censusUnpostedDocumentLines(Connection $connection): array
    {
        foreach (['documents', 'document_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                return ['lines' => 0, 'documents' => 0];
            }
        }

        // SAVEPOINT, even though this only READS. On PostgreSQL a statement
        // that errors inside a transaction aborts the whole transaction
        // (SQLSTATE 25P02) — every later statement, including the migrator's
        // own bookkeeping INSERT and anything the caller does afterwards, then
        // fails too. `DB::transaction()` opens a SAVEPOINT when a transaction
        // is already active and rolls back to that alone, which is what keeps
        // a schema-drifted tenant's failure contained to this half. Proven on
        // PostgreSQL: without it, the failure-path test dies with 25P02 on the
        // NEXT query instead of reading the FAILED gate line.
        /** @var array<int, object> $rows */
        $rows = $connection->transaction(
            static fn (): array => $connection->select(self::CENSUS_SQL),
        );

        if ($rows === []) {
            return ['lines' => 0, 'documents' => 0];
        }

        $documents = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $columns */
            $columns = (array) $row;

            $number = (string) ($columns['document_number'] ?? 'unknown');

            $documents[$number] ??= [
                'type' => (string) ($columns['type'] ?? 'unknown'),
                'status' => (string) ($columns['status'] ?? 'unknown'),
                'rates' => [],
            ];

            $documents[$number]['rates'][] = sprintf(
                '%s->%s',
                $this->formatRate($columns['stale_rate'] ?? null),
                $this->formatRate($columns['correct_rate'] ?? null),
            );
        }

        $reported = 0;

        foreach ($documents as $number => $document) {
            if ($reported >= self::CENSUS_REPORT_CAP) {
                Log::warning(sprintf(
                    '%s truncated=true reported=%d of documents=%d. Run the census query in this migration\'s docblock for the full list.',
                    self::CENSUS_TOKEN,
                    $reported,
                    count($documents),
                ));

                break;
            }

            Log::warning(sprintf(
                '%s document=%s type=%s status=%s lines=%d rates=%s NOT REPAIRED BY THIS MIGRATION - re-pick the product on each line in the editor.',
                self::CENSUS_TOKEN,
                $number,
                $document['type'],
                $document['status'],
                count($document['rates']),
                implode(',', $document['rates']),
            ));

            $reported++;
        }

        return ['lines' => count($rows), 'documents' => count($documents)];
    }

    /**
     * Percent at 2 dp, driver-stably and WITHOUT a float (agent rule 19).
     *
     * PostgreSQL hands back `numeric(5,2)` as '19.00'; SQLite's numeric
     * affinity hands the same value back as '19'. A worklist line that reads
     * `19->7` on one driver and `19.00->7.00` on the other is a line nobody can
     * grep. `bcadd($v, '0', 2)` normalises through bcmath — never
     * `number_format`, which would route a rate through a float.
     */
    private function formatRate(mixed $value): string
    {
        if ($value === null || ! is_scalar($value)) {
            return 'null';
        }

        $raw = trim((string) $value);

        if ($raw === '' || ! is_numeric($raw)) {
            return 'null';
        }

        return bcadd($raw, '0', 2);
    }

    public function down(): void
    {
        // Not reversed. This is a data CORRECTION: after it runs, a repaired
        // row is indistinguishable from one an operator saved correctly
        // through the fixed controller, so any reverse would have to guess
        // which products to put back on a wrong rate — and putting a product
        // back on a wrong VAT rate is never the safe direction. Rolling the
        // code back does not un-charge the tax; re-running up() is safe.
    }
};
