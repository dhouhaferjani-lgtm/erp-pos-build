I read the M2 section of the brief (§5 wave table `:173`, spec `§7.5` wave 2), the committed round‑1 register, and reviewed `af176b09e..HEAD` (4 commits) against the code.

**Lens applicability:** `fiscal-pos` applies — the round‑2 delta touches the frozen `GET /pos/receipts` wire allowlist and its regression lock. `frontend-conventions` applies — EN/FR copy and its guarding assertion. No treasury/GL, tenancy/authz, migration, or queue surface changed in this range.

**Round‑1 blocker disposition — CLOSED.** Round‑1 finding 1 (P2, list row drifted from the gate‑PASSED exhaustive allowlist with no amendment and no guard) is genuinely repaired on both halves:
- `docs/handoff/SPEC-pos-receipts-reporting-2026-08-11.md:302` now names `receipt_type` **and** `is_voided` in §3.b.5(i), with a labelled `:304` amendment stating why. Round 2 caught `receipt_type` as well, which round 1 missed — that field was pre‑existing undeclared drift.
- `apps/api/tests/Feature/POS/ReceiptIndexEnvelopeTest.php:75-83` adds an order‑sensitive `assertSame(array_keys(...))` over `data.data.0`. I verified it matches `ReceiptListItemData.php:13-29` constructor order exactly, is non‑vacuous (`array_keys(null)` is a PHP 8 `TypeError`, and the same test already asserts `data.data.0.currency`), and that the fixture row is not refund‑like (`RefundReportingEnricher.php:53-58` — plain SALE, no merge), so the pinned shape is the base allowlist.

Round‑1 finding 3 is also repaired: `en/pos.json:429` is now `"Resolved window: from {{from}}, ending before {{to}}"`, parallel to FR `:429`, and `ReceiptListPage.test.tsx:128-130` replaced the substring check with an anchored `/^Resolved window: from .+, ending before .+$/` — which the pre‑fix copy cannot satisfy. I ran the three touched Vitest files: **3 files, 28 tests, all passing.**

## Register

**1 — P3 · CONFIRMED · doc-to-code-mismatch · `docs/handoff/SPEC-pos-receipts-reporting-2026-08-11.md:304`**
The amendment states both fields "must be locked by **BT-2's** row-key assertion". BT-2 is `ReceiptIndexTypeFilterTest` (spec `:673`); the assertion actually landed in BT-5's `ReceiptIndexEnvelopeTest` (`:75-83`), and neither the BT-2 row (`:673`) nor the BT-5 row (`:677`) was updated to describe a row-key assertion. Failure scenario: a contributor asked to verify the allowlist lock reads `:304`, opens `ReceiptIndexTypeFilterTest`, finds no `array_keys` assertion, and either duplicates the guard or concludes the spec's claim is false. This is the same class as round‑1 finding 7 (a follow-up doc that misdescribes the state), which round 2 fixed for the ticket but reintroduced in the spec.

**2 — P3 · CONFIRMED · partial-coverage · `apps/api/tests/Feature/POS/RefundReportingFieldsTest.php:52-63`**
Round‑1 finding 1 is closed for the SALE row shape only. §3.b.5(i) `:306` declares that refund/void rows additionally carry exactly five S-12 fields, and no test asserts that row's key set: `RefundReportingFieldsTest` asserts individual values, and BT-15's recursive forbidden-key sweep (`ReceiptResourceNoCanonicalBytesRecursiveTest.php:22-28`) walks only `GET /pos/receipts/{id}`, never the index. Failure scenario: a field added to `RefundReportingData`, or an unconditional key written into `$row` inside the `$reporting !== null` branch (`ReceiptController.php:220-223`), ships on `/pos/receipts/refunds` with zero test resistance — including a hash- or `canonical_bytes`-derived value. Residual risk is narrow (a field added to the shared `ReceiptListItemData` *is* now caught), which is why this is P3 and not a re-raise of the P2.

**3 — P3 · CONFIRMED · revision-log-honesty · `docs/handoff/SPEC-pos-receipts-reporting-2026-08-11.md:3`**
The r5 header still asserts "**r5 applies exactly those two and nothing else**" and §9.4 is unchanged, while `:302-304` now carries a content-bearing change to a *frozen* allowlist. The spec's own gate history raised **R4-2 (MINOR)** for precisely a revision-log honesty defect (`:888`). Failure scenario: the OpenAPI contract lane or the DN-consolidation build, which diff this spec by header revision and §9, conclude the r5 wire contract is unchanged since the gate and regenerate against the old 15-field list row.

**4 — P3 · CONFIRMED · traceability · `docs/handoff/progress/receipts-build.progress.yaml:121-122`; `docs/superpowers/tickets/2026-08-18-receipt-reporting-terminal-audit-followups.md`**
Round‑1's P3s 2, 4 and 5 are dispositioned nowhere except inside the committed register itself. The ticket gained only item 5 (round‑1 finding 7), and the two new YAML `findings:` lines describe `d9d129d09` and the ticket — neither points at `docs/handoff/reviews/receipts-build/M2-terminal-round1.md` nor records its residuals, unlike every prior round (`:115-118` each name their register). Still-live, unticketed: the duplicate badge — `ReceiptDetailPage.tsx:173-176` renders `fiscalStatuses.voided` for `is_voided` immediately above the unconditional `fiscal_status` badge, so a legacy row with both states shows two identical "Voided" / "Annulé" badges; and the unreachable-null defensive path at `RefundReceiptListPage.tsx:76,83-85,91` whose test asserts a shape the server cannot emit (`Reflect.set`, `RefundReceiptListPage.test.tsx:141-143`). Mitigated by the register being committed under the YAML's `review_register:` directory.

**5 — P3 · CONFIRMED · evidence-gap · commit `78928eafc`**
No mutation evidence is recorded for the new list-row lock, and neither the handback nor the YAML was updated for this commit at all (the last handback entry stops at `d9d129d09`). I verified non-vacuity by inspection and by running the FE suite, but the PG assertion itself is unexecuted evidence here — see below.

## Verified clean (no finding)

- **Rule 19:** the round‑2 delta introduces no float, `parseFloat`, `Number(...)`, `toFixed` or `(float)`. `ReceiptIndexEnvelopeTest`'s fixture is 3dp strings and satisfies `pos_receipts_totals` (`10.000 + 2.345 − 0.000 + 0 = 12.345`); `bcformatStrict('12.3450', 3)` is `bcadd(...,'0',3)` (`CurrencyScale.php:130-145`) → `'12.345'`, matching the asserted value. `getScale($receipt->currency)` passes explicit currency (`ReceiptController.php:194`).
- **Contract flow:** `is_voided` propagates DTO → `packages/shared/types/generated.d.ts:1592` → `RefundReceiptListItem` (`receiptApi.ts:24-26`) with no hand-edited TS. `is_voided` is validated (`IndexReceiptsRequest.php:37`) and covered by `ReceiptIndexTypeFilterTest::test_voided_filter_emits_the_visible_voided_state` in both directions.
- **i18n:** EN/FR parity holds for `resolvedWindow`; both strings interpolate both variables; the exclusivity claim matches `posted_at >= $from` / `< $to` (`ReceiptController.php:185-192`). AR carries no `receipts` namespace at all — pre-existing and lane-wide, not introduced here.
- **Frontend conventions:** `activeCompany` gate on the detail page (`ReceiptDetailPage.tsx:32-34,41,53`) now matches the identical pattern already used by both list pages (`ReceiptListPage.tsx:43-46,58`, `RefundReceiptListPage.tsx:96-99,112`); all hooks precede early returns; new markup adds only layout classes, no hardcoded colors; `unknown` + `Array.isArray` guard, no `any`.
- `receipt_type` is emitted but rendered nowhere in `apps/web/src/features/pos` — satisfying §3.b.5's "labelled as legacy **or not rendered**".
- No CI, migration, queue, permission, seeder or `apps/pos/**` change in the round‑2 delta; working tree clean, nothing staged or committed by me.

## Bypasses attempted that FAILED to find a defect

- Tried to make the new row-key assertion vacuous — needed `data.data.0` to be absent or the row to be refund-enriched; `isRefundLike` (`RefundReportingEnricher.php:53-58`) rejects a plain SALE, and a missing row raises `TypeError` rather than passing.
- Tried to defeat the assertion via a field added downstream of `toArray()` — the only such site is the refund merge, which is finding 2, not a bypass of the SALE lock.
- Tried to make the anchored `resolvedWindow` regex pass against the pre-fix EN copy — `^Resolved window: from ` cannot match `Resolved window: {{from}} before`.
- Checked whether the new "Voided" `findByText` assertions could collide with `types.VOID` on the refunds register — EN `Void`/`Voided` and FR `Annulation`/`Annulé` are distinct, so no ambiguous-match false pass.
- Searched for a refund-row key-set assertion that would refute finding 2 — none exists; BT-15 is detail-only.

## Not reproduced

I could not execute the new PostgreSQL assertion: this worktree still has no `apps/api/.env`, so `php artisan test -c phpunit-pgsql.xml` cannot run without provisioning one, and creating it would violate the read-only constraint. The static analysis above (DTO order, enricher selection, CHECK-constraint arithmetic, `bcformatStrict` semantics) is consistent with the assertion passing. The FE claim I reproduced exactly: 3 files, 28 tests.

All five findings are P3 — documentation, traceability, and a narrow residual coverage gap on the refund row variant. The round‑1 P2 blocker is closed on both required halves, with a lock that is order-sensitive, non-vacuous, and stronger than the finding asked for.

VERDICT: ACCEPT
