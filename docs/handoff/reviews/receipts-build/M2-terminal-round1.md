M2 (round 1 of the parent terminal-audit repair) — adversarial merge-gate review of `af176b09e..HEAD` (2 commits: `d9d129d09` repair, `236938c56` ticket/handback).

**Lens applicability:** `fiscal-pos` applies (list/detail wire envelope, void semantics, aggregate-identity fixtures, PG CHECK/trigger interaction). `frontend-conventions` applies (tokens, i18n EN+FR, canonical components, route/nav semantics, tenant-scoped keys). No treasury/GL or tenancy-authz surface changed in this range.

## Register

**1 — P2 · CONFIRMED · contract-drift · `apps/api/app/Modules/POS/Application/DTOs/ReceiptListItemData.php:20`, `ReceiptController.php:205`**
`is_voided` was added to the `GET /pos/receipts` row, but §3.b.5(i) of the gate-PASSED spec freezes the list row as an exhaustive allowlist — *"Every payload below is an allowlist: a field not named here is not emitted"* (`docs/handoff/SPEC-pos-receipts-reporting-2026-08-11.md:296,302`) — and `is_voided` is not in it. Unlike the detail row, which BT-6 pins with a key-set equality assertion (`tests/Feature/POS/ReceiptShowResourceTest.php:78-86`), **no test asserts the list-row key set**: `ReceiptIndexEnvelopeTest` pins only the outer envelope and `meta` keys (`:73-77`). Failure scenario: the index payload has silently diverged from the binding contract, and the next field a contributor adds to `ReceiptListItemData` — including one the spec forbids, e.g. a hash or `canonical_bytes`-derived value — ships with zero test resistance. The spec's own gate history (r1 defect 6, a leak a root-only check missed) is exactly this failure mode. Fix is cheap and in-lane: amend §3.b.5(i) (or record the deviation where the spec's rulings live) **and** add a list-row `array_keys` equality assertion to `ReceiptIndexEnvelopeTest`.

**2 — P3 · CONFIRMED · defensive-code-vs-contract · `apps/web/src/features/pos/pages/RefundReceiptListPage/RefundReceiptListPage.tsx:76,91`; `ReceiptDetailPage.tsx:236-238`**
`RefundReason`'s `source` was widened to `string | null` and `normalizeRefundPolicyAlerts` was added, but both states are unreachable: `RefundReportingData.php:17-19` declares `string $refund_reason_source` / `array $refund_policy_alerts` (non-nullable), and the server already coalesces alerts to `[]` at `RefundReportingEnricher.php:85` and `ReceiptDetailData.php:150`. Spec §4.5 is explicit that `refund_reason_source` is *"Always emitted wherever `refund_reason` is — never optional, never inferred client-side."* The accompanying test fabricates the forbidden shape with `Reflect.set` (`RefundReceiptListPage.test.tsx:141-143`), so it passes regardless of the real contract. Failure scenario: a future server regression that drops `refund_reason_source` now renders the same `—` placeholder used for a missing *reason*, making a provenance loss visually indistinguishable from a legacy value — the precise defect S-12 exists to prevent — instead of failing loudly.

**3 — P3 · CONFIRMED · i18n-parity · `apps/web/src/locales/en/pos.json:429` vs `fr/pos.json:429`**
EN became `"Resolved window: {{from}} before {{to}}"` while FR became `"Période résolue : à partir du {{from}}, avant {{to}}"`. The exclusivity claim is correct — `ReceiptController.php:185-186` uses `posted_at >= $from` and `posted_at < $to` — but the EN string is ungrammatical and drops the inclusive-start signal FR keeps. Rendered: *"Resolved window: 16/08 23:00 before 17/08 23:00"*. The guarding test only asserts the substring `before` (`ReceiptListPage.test.tsx:126`), so any grammatical repair stays green.

**4 — P3 · CONFIRMED · label-reuse · `apps/web/src/features/pos/pages/ReceiptDetailPage/ReceiptDetailPage.tsx:173-176`**
The `is_voided` badge borrows `pos:receipts.fiscalStatuses.voided` — the label of a *different* field, `FiscalStatus::Voided`, which the list also offers as a status filter (`ReceiptListPage.tsx:28-34`). On detail, both badges render unconditionally, so a legacy row with `fiscal_status='voided'` **and** `is_voided=true` shows two identical "Voided" / "Annulé" badges side by side with no way to tell which state each represents.

**5 — P3 · CONFIRMED · reconciliation · `ReceiptController.php:128-130`; `ReceiptListPage.tsx:85-97`**
The API accepts `is_voided` but no UI control exposes it, and the sales register neither excludes voided rows nor strikes their amount — while every reporting path excludes them (`SalesReportService.php:67,117,169,228`, `ZReportProjection.php:227`, `OwnerSalesSummaryService.php:102`). A day's register row set therefore cannot be reconciled against the Z-report or sales report. Impact is bounded today: the register renders no aggregate, and no live writer sets `is_voided=true` (the projection writes `false` at `PosCoreReceiptProjection.php:375`) — this is a legacy-data affordance only.

**6 — P3 · CONFIRMED · red-first-evidence · commit `d9d129d09`**
Tests and implementation landed in one commit; the handback documents mutation evidence for the `is_voided` field only. I verified by inspection that each new test is non-vacuous against the pre-fix code (the company-hydration test fails pre-fix because `enabled` lacked the `activeCompany !== null` term; the voided-badge tests fail because the badges did not exist; the window test fails because the copy contained `to`, not `before`), and ran the three touched suites: **3 files, 28 tests, all passing**. Evidence is adequate but not commit-demonstrated.

**7 — P3 · CONFIRMED · follow-up-ticket-accuracy · `docs/superpowers/tickets/2026-08-18-receipt-reporting-terminal-audit-followups.md:41-44`**
Item 5 describes "the single interpolated alert-count key", but `alertCount` + `alertCount_other` already exist in both locales (`en/pos.json:331-332`, `fr/pos.json:331-332`). The actual gap is the missing `_one` suffix (i18next currently resolves the singular by falling back to the bare key). A follow-up session working from this text will misdiagnose the state.

## Verified clean (no finding)

- **Rule 19:** no float, `parseFloat`, `Number(...)`, `toFixed` or `(float)` introduced anywhere in the diff; all fixture money is 3dp strings; `getScale($receipt->currency)` passes explicit currency (`ReceiptController.php:194`).
- **PG fixture rewrite is sound:** `array_merge` puts caller attributes last so overrides win (`ReceiptReportingTestCase.php:88-97`); `Factory::makeInstance` runs under `Model::unguarded`, so non-fillable keys are not silently dropped. Every rewritten fixture satisfies the PG CHECKs — `pos_receipts_totals` rounding-aware identity (`2026_07_28_100200_add_cash_rounding_to_pos_receipts.php:158-170`; e.g. `-5.000 + -0.250 - 0.500 = -5.750` ✓), `pos_receipts_return_logic` (`original_receipt_id` + `return_reason` now set on every return fixture), `pos_receipts_void_logic` (the new voided test sets `voided_at` **and** `voided_by`), and `pos_receipts_hash_length`.
- **CI filter:** all 19 added class names resolve to real files under `tests/Feature/POS`; no other workflow line changed.
- **A11y/nav change is correct:** `NavLink` supplies `aria-current="page"` from route matching; the component is used only by the two list pages with `active` matching the route, so visual and assistive state cannot diverge; no residual `role="tab"` assertions remain in `apps/web/src/features/pos`.
- **Detail-page gate:** all hooks precede the early returns; the Zustand selector returns the company object itself (stable ref, no render loop). The removed `companyTimezone ? … : undefined` guard is **not** a crash risk — `formatDateTime` re-applies the same falsy guard at `lib/format.ts:314`, and `dateOptions` has no other consumer.
- Generated types regenerated in DTO order; single `new ReceiptListItemData` construction site; `StatusBadge` supports `tone="danger"`; `tenantScopedKey` unchanged.

## Bypasses attempted that FAILED to find a defect

- Searched for a list-row key-set assertion that would have caught finding 1 — none exists (only detail/lineage). Confirms the gap rather than refuting it.
- Searched for other `new ReceiptListItemData(...)` sites that the added constructor param would break — only one.
- Searched for surviving `getByRole('tab')`/`role="tab"` assertions against `ReceiptRegisterTabs` — none.
- Tried to make the dropped timezone guard crash `Intl.DateTimeFormat` — blocked by `lib/format.ts:314`.
- Tried to reach a null `refund_policy_alerts`/`refund_reason_source` on the wire — blocked by server-side coalescing at two sites.

## Not reproduced

I could **not** independently verify the handback's PostgreSQL numbers ("71 tests, 454 assertions"): this worktree has no `apps/api/.env`, so `php artisan test -c phpunit-pgsql.xml` cannot be run here without provisioning env (and per the standing rule I did not run a broad suite). That claim rests on the executor's report; the static PG-constraint analysis above is consistent with it. The FE claim ("3 files, 28 tests") I did reproduce exactly.

Finding 1 is the only blocker: a binding, gate-PASSED wire contract drifted with neither an amendment nor a regression guard.

VERDICT: CHANGES-REQUIRED
