# Ticket — two pre-existing defects adjacent to the B-6(ii) lane

> **Raised by:** B-6(ii) fix round (`fix/b6ii-xz-refund-vat-display`), 2026-08-23, from gate r1 findings **F-7** and **F-8**.
> **Both are PRE-EXISTING.** Verified at the lane base `3bc279856` as well as at HEAD. Filed rather than fixed, per rule 4 and both reviewers' own "do not ask the lane to fix this in-scope" note.

---

## A. `ZReportPdfTest` is 12/12 red on PostgreSQL (F-7)

**File:** `apps/api/tests/Feature/POS/ZReportPdfTest.php:338, :448`

Its fixture builder creates a `CLOSED` shift with no `closed_at` / `closed_by`:

```php
Shift::create([... 'status' => 'CLOSED']);   // :448
```

PostgreSQL's `pos_shifts_closed_logic` CHECK (`database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:84`) requires **both** columns on a CLOSED shift:

```sql
(status = 'OPEN'   AND closed_at IS NULL     AND closed_by IS NULL) OR
(status = 'CLOSED' AND closed_at IS NOT NULL AND closed_by IS NOT NULL)
```

so every test errors in the builder with `SQLSTATE[23514]`, **0 assertions reached**. Green on the default SQLite config (which does not enforce the CHECK), which is why it went unnoticed.

**Why it matters now:** the blade PDF path is exactly what B-6(ii) changed, and this suite is its main pre-existing coverage — so that coverage is **silently dark on the driver that counts**. Coverage is not zero (the lane's own `ZReportRefundVatDisclosureTest` renders the blade and runs green on PG), but the older suite contributes nothing there.

**Fix:** add `closed_at` + `closed_by` to the fixture, exactly as the B-6(ii) lane had to do in its own two new test files (`ZReportRefundVatDisclosureTest::createZReport()` and `ZReportVatDeclarationReconciliationTest::createZReportFor()` — both carry the constraint in an in-place comment). One-line change per builder; the suite should then be green on both drivers.

**Related, same class:** `tests/Feature/POS/ZReportVatDeclarationReconciliationTest` also had to pin `countries.currency_decimal_places => 3`, because `CurrencyScaleResolver` prefers the country record over the company currency and the column defaults to 2. Any TND fixture that omits it silently runs at scale 2. Worth a sweep of other country-inserting tests.

---

## B. The web `VatBreakdownEntry` contract does not match what the server emits (F-8)

**Files:** `apps/web/src/features/pos/api/reportApi.ts:4-9`, consumed at `ZReportDetailPage.tsx` (sale rows).

The web type declares:

```ts
export interface VatBreakdownEntry { rate: string; net: string; vat: string; gross: string }
```

The server emits `{tax_rate, net_amount, vat_amount, gross_amount}` — verified at `ZReportProjection.php:154` (v3 device-payload pass-through) and `ReportGenerationService.php` (legacy `calculateShiftTotals`). The `{rate,net,vat,gross}` shape survives only in **stale phpdoc** on `ZReport::getVatBreakdown()` and `XReport.php`, plus this type.

The web test fixture (`ZReportDetailPage.test.tsx`, `vat_breakdown: [{rate: 20, net: …}]`) is therefore a **fake payload that matches the type rather than the server** — the "never fake API payloads" rule.

**Consequence, and why B-6(ii) makes it visible:** the lane appends correctly-keyed refund rows (`row.net_amount` etc.) to that same table. On a real Z the **sale rows render blank while the new refund rows render populated**, which will read as a lane regression at a glance even though the lane is the only correctly-keyed half.

**Fix:** correct `VatBreakdownEntry` to the server keys, update `ZReportDetailPage`'s sale-row cells, replace the fixture with a real server-shaped payload, and delete the stale phpdoc on both PHP models. Small but touches a page the UI-audit lane also owns — worth sequencing with it.

**Not a device bug:** the PHP disclosure defensively reads `$row['vat_amount'] ?? $row['vat']`, and the POS TS helper reads only `vat_amount`, which is what every device writer emits. The asymmetry is confined to the web type.
