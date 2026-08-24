<?php

declare(strict_types=1);

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Services\DocumentTotalsCalculator;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
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
 * IT HAS A SECOND HALF. Repairing the product alone leaves the quotes and
 * orders already written from it carrying the stale rate on their own lines,
 * and conversion copies a line's `tax_rate` verbatim — so converting one after
 * this deploy would mint a NEW wrong-VAT invoice. {@see repairUnpostedDocumentLines()}
 * therefore also repairs UNPOSTED (`draft`/`confirmed`, `fiscal_status=DRAFT`,
 * no `fiscal_hash`) document lines whose product carries a configuration, and
 * re-totals those documents through the module's own calculator. It has its own
 * savepoint and its own counters in the gate line.
 *
 * WHAT THIS MIGRATION DOES NOT REPAIR. Anything already SEALED: POS receipts,
 * and any posted/sealed/voided document. Those rows are hash-chained or carry
 * GL entries derived from their tax; correcting one is a fiscal correction
 * (credit note / refund), never an UPDATE. This migration fixes the SOURCE and
 * everything still legally repairable, so no further wrong rate is sealed. Any
 * tenant with a non-zero census count above has mis-declared VAT for the
 * affected products and needs the sealed part handled through the fiscal
 * correction path.
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
     * The documents holding at least one repairable line, read BEFORE the
     * update so they can be re-totalled after it. Identical predicate to the
     * UPDATE in {@see repairUnpostedDocumentIds}; `IS DISTINCT FROM` is spelled
     * out longhand so the one statement runs unchanged on both drivers.
     */
    private const AFFECTED_DOCUMENT_IDS_SQL = <<<'SQL'
        SELECT DISTINCT dl.document_id AS document_id
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
          AND (dl.tax_rate IS NULL OR dl.tax_rate <> tc.percentage_rate)
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

            $documentLines = $this->repairUnpostedDocumentLines($connection);

            Log::warning(sprintf(
                '%s tenant=%s status=ok repaired=%d doc_lines=%d docs_retotalled=%d.',
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
     * SECOND HALF (gate r1 finding 3): repair the UNPOSTED document lines that
     * already copied the wrong rate, and re-total the documents that held them.
     *
     * WHY THIS IS NOT OPTIONAL. Repairing `products.tax_rate` alone leaves the
     * quotes and orders that were written from the stale product carrying the
     * company default on their own lines, and conversion copies a line's
     * `tax_rate` VERBATIM into the destination document
     * (`Conversion/Concerns/CopiesDocumentData::copyLine()`). Converting one of
     * those after this deploy would MINT A NEW WRONG-VAT INVOICE — a fresh
     * fiscal document created after the fix shipped, which is worse than the
     * historical rows. On the campaign tenant these are four real documents
     * (QT-2026-0002/0003/0004, SO-2026-0001) sitting one click from that.
     *
     * WHAT IS DELIBERATELY OUT OF REACH — three independent guards, all of
     * which must hold, because a repaired row here is a rewritten fiscal
     * number:
     *  - `documents.status IN ('draft','confirmed')`: never `posted`, `paid`,
     *    `received` or `cancelled`. A posted document has GL entries derived
     *    from its tax; rewriting the line under them would desynchronise the
     *    ledger.
     *  - `documents.fiscal_status = 'DRAFT'`: never `SEALED`, never `VOIDED`.
     *  - `documents.fiscal_hash IS NULL`: belt and braces. Anything that has
     *    ever been hash-chained is untouchable by a migration; correcting a
     *    sealed document is a credit note, not an UPDATE.
     *
     * THE RATE COMES FROM THE PRODUCT'S CONFIGURATION, not from
     * `products.tax_rate` — even though the first half has just aligned the
     * two. Reading the configuration directly makes this half independent of
     * the first half's outcome and keeps one single source of truth for "what
     * rate is this?", which is the whole point of N-1. Lines with no product
     * (free-text/service lines) state their own rate and are never touched.
     *
     * RE-TOTALLING IS PART OF THE REPAIR, not a nicety. Leaving the header at
     * the 19 %-derived `tax_amount` / `total` while the lines say 7 % would
     * ship a document that contradicts itself on screen and on paper. The
     * module's OWN calculator does it — `DocumentTotalsCalculator::recalculate()`,
     * the same one the draft path calls — so stamp duty (TN timbre fiscal),
     * line discounts and partner exemptions are handled by the code that owns
     * them rather than re-derived in SQL here. It is context-free: the scale
     * comes from the document's own currency, exactly as
     * `CopiesDocumentData::recalculateTotals()` resolves it, so this is safe in
     * the no-CompanyContext world of `tenants:migrate` (agent rule 20). The
     * container is used to build it — a migration is a script, not an injected
     * service, and hand-wiring the tax engine here would be a second copy of
     * its dependency graph.
     *
     * IDEMPOTENT: the line predicate is "the two disagree", so a second run
     * matches nothing; and `recalculate()` writes through Eloquent, which
     * issues no UPDATE when the recomputed totals equal the stored ones.
     *
     * ITS OWN SAVEPOINT. A failure here rolls back only this half and is
     * reported by the caller's `status=FAILED` line — the product repair, which
     * is the part that stops NEW wrong rates, has already committed.
     *
     * PRE-FLIGHT CENSUS (per tenant, before deploying):
     *
     *   SELECT d.document_number, d.type, d.status, dl.tax_rate AS stale_rate,
     *          tc.code, tc.percentage_rate AS correct_rate
     *   FROM document_lines dl
     *   JOIN documents d ON d.id = dl.document_id
     *   JOIN products p ON p.id = dl.product_id
     *   JOIN tax_configurations tc ON tc.id = p.default_tax_configuration_id
     *   WHERE tc.applies_to = 'LINE_ITEMS' AND tc.tax_type = 'PERCENTAGE'
     *     AND tc.percentage_rate IS NOT NULL
     *     AND d.status IN ('draft','confirmed')
     *     AND d.fiscal_status = 'DRAFT'
     *     AND d.fiscal_hash IS NULL
     *     AND dl.tax_rate IS DISTINCT FROM tc.percentage_rate
     *   ORDER BY d.document_number;
     *
     * @return array{lines: int, documents: int}
     */
    private function repairUnpostedDocumentLines(Connection $connection): array
    {
        foreach (['documents', 'document_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                return ['lines' => 0, 'documents' => 0];
            }
        }

        $result = ['lines' => 0, 'documents' => 0];

        $connection->transaction(function () use ($connection, &$result): void {
            // The ids are read BEFORE the update, with the same predicate: once
            // the rates agree the rows no longer match, so there would be
            // nothing left to identify for re-totalling afterwards.
            /** @var array<int, string> $documentIds */
            $documentIds = array_values(array_unique(array_map(
                // `select()` hands back stdClass rows; reading the single
                // aliased column through an array view keeps this honest under
                // static analysis without asserting a shape.
                static fn (object $row): string => (string) ((array) $row)['document_id'],
                $connection->select(self::AFFECTED_DOCUMENT_IDS_SQL),
            )));

            if ($documentIds === []) {
                return;
            }

            $result['lines'] = $connection->getDriverName() === 'pgsql'
                ? $connection->affectingStatement(
                    <<<'SQL'
                    UPDATE document_lines
                    SET tax_rate = tc.percentage_rate,
                        updated_at = CURRENT_TIMESTAMP
                    FROM products p, tax_configurations tc, documents d
                    WHERE p.id = document_lines.product_id
                      AND tc.id = p.default_tax_configuration_id
                      AND d.id = document_lines.document_id
                      AND tc.applies_to = 'LINE_ITEMS'
                      AND tc.tax_type = 'PERCENTAGE'
                      AND tc.percentage_rate IS NOT NULL
                      AND d.status IN ('draft', 'confirmed')
                      AND d.fiscal_status = 'DRAFT'
                      AND d.fiscal_hash IS NULL
                      AND document_lines.tax_rate IS DISTINCT FROM tc.percentage_rate
                    SQL
                )
                : $connection->affectingStatement(
                    <<<'SQL'
                    UPDATE document_lines
                    SET tax_rate = (
                            SELECT tc.percentage_rate
                            FROM products p
                            JOIN tax_configurations tc ON tc.id = p.default_tax_configuration_id
                            WHERE p.id = document_lines.product_id
                        ),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE EXISTS (
                        SELECT 1
                        FROM products p
                        JOIN tax_configurations tc ON tc.id = p.default_tax_configuration_id
                        JOIN documents d ON d.id = document_lines.document_id
                        WHERE p.id = document_lines.product_id
                          AND tc.applies_to = 'LINE_ITEMS'
                          AND tc.tax_type = 'PERCENTAGE'
                          AND tc.percentage_rate IS NOT NULL
                          AND d.status IN ('draft', 'confirmed')
                          AND d.fiscal_status = 'DRAFT'
                          AND d.fiscal_hash IS NULL
                          AND (
                                document_lines.tax_rate IS NULL
                             OR document_lines.tax_rate <> tc.percentage_rate
                          )
                    )
                    SQL
                );

            $result['documents'] = $this->retotal($documentIds);
        });

        return $result;
    }

    /**
     * Re-total the documents whose lines were just repaired, through the
     * module's own calculator. Returns how many were actually rewritten.
     *
     * @param  array<int, string>  $documentIds
     */
    private function retotal(array $documentIds): int
    {
        /** @var DocumentTotalsCalculator $calculator */
        $calculator = app(DocumentTotalsCalculator::class);
        /** @var CurrencyScaleResolverInterface $scaleResolver */
        $scaleResolver = app(CurrencyScaleResolverInterface::class);

        $retotalled = 0;

        Document::query()
            ->whereIn('id', $documentIds)
            ->with('lines')
            ->each(function (Document $document) use ($calculator, $scaleResolver, &$retotalled): void {
                // Same guard as CopiesDocumentData::recalculateTotals(): a blank
                // currency must not reach getScale(), which would silently
                // compute at scale 2 instead of the company's true scale.
                $currency = $document->currency;
                $scale = $currency !== ''
                    ? $scaleResolver->getScale($currency)
                    : $scaleResolver->getScaleSafe(null, 3);

                $before = [$document->subtotal, $document->tax_amount, $document->total];

                $calculator->recalculate($document, $scale);

                if ([$document->subtotal, $document->tax_amount, $document->total] !== $before) {
                    $retotalled++;
                }
            });

        return $retotalled;
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
