<?php

declare(strict_types=1);

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
 * WHAT THIS MIGRATION DOES NOT REPAIR. Receipts and document lines already
 * written keep the wrong rate. The POS rows are hash-chained and must not be
 * rewritten; correcting a sealed receipt is a fiscal correction (credit
 * note / refund), never an UPDATE. This migration fixes the SOURCE so no
 * further wrong rate is sealed. Any tenant with a non-zero census count above
 * has mis-declared VAT for the affected products and needs that handled
 * through the fiscal correction path.
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

            Log::warning(sprintf(
                '%s tenant=%s status=ok repaired=%d.',
                self::GATE_TOKEN,
                $tenantKey,
                $repaired,
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
