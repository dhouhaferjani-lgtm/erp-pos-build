# Adversarial merge gate — round 1, frontend-conventions lens
**Lane:** `fix/b6ii-xz-refund-vat-display`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b6ii-xz-refund-vat`
**Range:** `3bc279856..410272b06` (3 commits)
**Date:** 2026-08-23 · Reviewer: frontend-conventions gate (read-only; no merge, no push)

---

## 0. Gates I ran myself (nothing accepted as reported)

| Gate | Command | Result |
|---|---|---|
| web lint (full chain) | `pnpm --filter @autoerp/web lint` | **exit 0** |
| web design-system audit | `pnpm audit:design-system` | `807 acknowledged, 0 new, 0 stale` |
| web tanstack keys | `pnpm audit:keys` | `Gate C: 0 unscoped, 0 new, 0 stale` |
| web quantity display | `pnpm audit:quantity` | `0 total, 0 new` |
| web i18n baseline authority | `pnpm audit:i18n:local` | `OK — 55 namespaces … 2763 known gaps held at baseline` |
| web typecheck | `pnpm typecheck` | clean |
| pos typecheck | `pnpm typecheck` | clean |
| pos lint + RuleTester | `pnpm lint` | `84 problems (0 errors, 84 warnings)` — **ratchet 84 holds** |
| web tests (touched dir) | `pnpm vitest run src/features/pos` | **52 files / 457 passed** (matches claim) |
| pos tests (touched files) | `vitest run` on the 6 touched suites | **6 files / 129 passed** |
| pos tests (touched dirs) | `vitest run src/components/pos src/lib/reports src/lib/offline/__tests__` | **46 files / 491 passed** |

No zombie vitest workers left behind (default pool, all runs exited).

**Baseline honesty:** `apps/web/tools/` and `apps/pos/tools/` are **untouched** by the diff
(`git diff --stat 3bc279856..HEAD -- apps/web/tools/ apps/pos/tools/` → empty).
`audit-design-system-baseline.json` still holds 808 entries; the audit reports `0 new`.
No `--write-baseline` absorption. **PASS.**

**Mechanism audit:** the improved metrics (pos ratchet, DS audit) improved by *not adding
violations*, not by indirection. `eslint . -f json` filtered to the lane's files reports
exactly one message — `endOfDayPreview.ts:558 precision/no-parsefloat-on-money` — which is
**pre-existing** (`parseFloat` on `tax_rate`, a rate not a money value, outside the diff hunks).
No suppression comments, no alias tables, no renamed-but-equivalent literals. **PASS.**

**Locale conservation (additive-only proof).** Flattened base-vs-head key diff on all five
touched locale files:

```
apps/pos  en/pos.json   changed/removed: 0   added: 12
apps/pos  fr/pos.json   changed/removed: 0   added: 12
apps/web  en/pos.json   changed/removed: 0   added: 5
apps/web  fr/pos.json   changed/removed: 0   added: 5
apps/web  ar/pos.json   changed/removed: 0   added: 5
```

Backend blade backfill: `apps/api/lang/en/pos.php` +45/-0, `apps/api/lang/fr/pos.php` +40/-0,
zero deletion lines. **No existing key's value changed anywhere. PASS.**

---

## Findings

### BLOCKER

**B-1 — `vatUnreconciled` is unreachable dead UI; the corpus inconsistency the lane claims to
"surface rather than absorb" is silently absorbed.**
`apps/pos/src/components/pos/VatDisclosureSummary.tsx:37-38` (early return) vs `:57` (warning).

The derivation makes `isReconciled === false` logically imply `hasRefundVat === false`:

- `apps/pos/src/lib/reports/vatDisclosure.ts:89-90` — `refundVat` is clamped to `'0'` whenever
  the wedge is not strictly positive.
- `apps/pos/src/lib/reports/vatDisclosure.ts:99` — `isReconciled = (salesVat − refundVat) == netVat`.
  When the wedge is positive, `refundVat === wedge`, so `salesVat − refundVat === netVat`
  **exactly** (Big.js, same scale) → always reconciled. When the wedge is `0` → reconciled.
  So `isReconciled` is false **only** in the clamped branch, where `refundVat === '0'` and
  `hasRefundVat` (`:96`) is therefore `false`.
- `VatDisclosureSummary.tsx:37-38` returns `null` on `!hasRefundVat`.

⇒ the `{!disclosure.isReconciled && …}` branch at `:57` can never render on any input.
The lane's own unit test proves it: `apps/pos/src/lib/reports/__tests__/vatDisclosure.test.ts:96-104`
asserts `hasRefundVat === false` and `isReconciled === false` **together**, and no component test
asserts the warning ever appears. On the one shift shape this safety net exists for (net table
larger than the sale-only headline), the X/Z/EOD modals render **no disclosure at all** and the
headline card falls back to the raw sale-only `tax_amount` — i.e. exactly the pre-B-6(ii) defect,
with no on-screen trace. Two shipped strings (`reports.vatUnreconciled`,
`reports.endOfDay.vatUnreconciled`, en+fr, 4 values) are dead on arrival.
This is the "UI must not overstate system guarantees" rule and the OQ-11 dead-branch rule at once.

*Fix:* change the guard at `VatDisclosureSummary.tsx:37` to
`if (!disclosure.hasRefundVat && disclosure.isReconciled) return null;` and render the amount
rows only when `hasRefundVat` (or render `salesVat`/`netVat` with the warning). Add a component
test with `tax_amount: '10.000'` / `vat_breakdown: [{ vat_amount: '13.000' }]` asserting the
warning renders and the modal does not silently fall back to the sale-only headline.

**B-2 — The web Z detail page ignores the server's `is_reconciled` and presents a possibly
non-adding three-line bridge as authoritative.**
`apps/web/src/features/pos/pages/ZReportDetailPage/ZReportDetailPage.tsx:229, 250-259`.

`disclosure` is destructured at `:229` and only `has_refund_vat` is ever read (`:250`, `:317`,
`:340`). Unlike the device helper, the **server** figure is genuinely capable of
`has_refund_vat === true && is_reconciled === false`: `refund_vat` is aggregated from projected
`pos_receipt_vat_details` rows independently of `net_vat`
(`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:879-886`), and the
DTO's own contract says so — `RefundVatDisclosureData.php` docblock:
*"False means the projected refund rows and the signed table disagree … surfaced rather than hidden."*
Today it is **hidden**: the page renders `VAT on sales / −refund_vat / Net VAT` as three
authoritative rows that, in that state, visibly do not add up, with no explanation, plus per-rate
negative rows at `:344-348` that don't tie to the table above them. Verification/integrity
surfaces presenting a known-incomplete verifier as a verdict is a standing gate rule.
No web test covers `is_reconciled: false` (`ZReportDetailPage.test.tsx:119-126` sets it `true` only).

*Fix:* render an inline warning (new key `pos:zReports.detail.vatUnreconciled` in en+fr+ar) when
`disclosure.has_refund_vat && !disclosure.is_reconciled`, and add a test fixture with
`is_reconciled: false` asserting it appears.

### MAJOR

**M-1 — `EndOfDayPreview.refund_vat_amount` is a write-only field; the modal displays a second,
independently derived and *less era-safe* figure for the same number.**
Declared `apps/pos/src/lib/offline/endOfDayPreview.ts:125`, accumulated `:238, :378`, emitted `:582`.
Production readers: **none** (`grep -rn refund_vat_amount apps/pos/src apps/web/src` → only the
declaration/assignment plus three test fixtures). `EndOfDayPreviewModal.tsx:142` instead calls
`deriveVatDisclosure(preview, decimals)`, which reconstructs the figure from `tax_amount` −
Σ`vat_breakdown[].vat_amount`. The lane's own comment at `endOfDayPreview.ts:356-366` records that
this file's per-rate VAT loop **adds a sign-carrying row** where the other two consumers
`bcabs`-then-subtract — so the *displayed* (derived) figure inherits the sign-era exposure while
the *era-safe* accumulator (`bcabs`-then-add, `:378`) sits unused. Two sources of truth for one
fiscal figure, and the one advertised as era-safe is not the one on screen.
The new test cannot tell them apart: `EndOfDayPreviewModal.test.tsx:894-899` sets
`refund_vat_amount: '2.00'` *and* `tax_amount: '7.18'` / `vat_amount: '5.18'`, so both paths yield
`-2.00` and the assertion passes either way — a non-discriminating green.

*Fix:* pick one. Either feed `preview.refund_vat_amount` into the EOD disclosure (preferred — it is
era-safe and this preview is unsigned/unhashed, so no hash argument applies) and make the test
fixture's two sources **disagree** so the assertion discriminates; or delete the accumulator and its
three fixture entries and drop the era-safety claim in the docblock at `:114-124`.

**M-2 — `VatDisclosureInput.vat_amount` is optional, turning a shape mismatch into a silent
catastrophic mis-render.**
`apps/pos/src/lib/reports/vatDisclosure.ts:71` — `vat_breakdown: ReadonlyArray<{ vat_amount?: string }>`.
All three real callers declare it **required** (`apps/pos/src/api/reportApi.ts:20-29`,
`apps/pos/src/lib/offline/endOfDayPreview.ts:70-79`). The optional widening buys nothing and
disarms the type system: any future caller whose breakdown uses a different key (the web's own
`ZReportReportData.vat_breakdown` uses `rate/net/vat/gross` — `reportApi.ts` fixture in
`ZReportDetailPage.test.tsx:118`) would typecheck fine and silently produce `netVat = 0`,
`refundVat = salesVat`, i.e. a modal reading "VAT on refunds −57.00 / Net VAT 0.00" on a
refund-free shift. `numericOrZero` (`:113-118`) converts the mismatch to zero instead of failing.

*Fix:* make the field required (`vat_amount: string`) — all current callers satisfy it — and keep
`numericOrZero` only for malformed *values*, not missing *keys*.

### MINOR

**m-1 — Runtime-composed i18n keys defeat static key extraction.**
`VatDisclosureSummary.tsx:48, 52, 56, 58` use `t(\`${keyPrefix}.vatOnSales\`)` etc. with `keyPrefix`
supplied by the caller (`"reports"` at `XReportModal.tsx:70` / `ZReportModal.tsx:184`,
`"reports.endOfDay"` at `EndOfDayPreviewModal.tsx:353-357`). I verified all 8 resulting keys exist
in pos en+fr, so nothing is broken today, but no scanner can follow this and POS has no key-existence
audit. *Fix:* pass the four resolved strings as props, or accept a typed union of the two prefixes
and keep a literal `t()` call per branch.

**m-2 — New web table cells use physical `text-right` on a table that now ships Arabic strings.**
`ZReportDetailPage.tsx:345-347`. Consistent with the adjacent pre-existing rows (`:332-334`), but
these are net-new lines added in the same commit that adds `ar/pos.json` translations for this exact
table (`zReports.detail.vatOnRefunds`), and the sibling list page already uses logical
`text-start`/`text-end` (`ZReportListPage.tsx:230-240`). *Fix:* use `text-end` on the four new cells;
optionally sweep the three pre-existing rows in the same block.

**m-3 — Labels built by concatenating translated fragments.**
`XReportModal.tsx:62, 91`; `ZReportModal.tsx:174, 204`; `EndOfDayPreviewModal.tsx:323, 393`;
`ZReportDetailPage.tsx:317`. `` `${t('taxAmount')} (${t('vatNetOfRefunds')})` `` and
`` ` — ${t('vatNetOfRefunds')}` `` hard-code the parenthesis/em-dash glue outside i18n; the web one
now renders in Arabic, where an em-dash-joined bidi fragment pair is not reliably ordered.
*Fix:* one key with interpolation, e.g. `"taxAmountNetOfRefunds": "VAT Amount ({{qualifier}})"`.

**m-4 — Orphaned keys left behind / shipped unused.**
`apps/web/src/locales/{en,fr}/pos.json:367 "zReports.taxCollected"` has no remaining reference in
`apps/web/src` after the relabel at `ZReportListPage.tsx:240`. `reports.endOfDay.refundsAmount` was
added to pos en+fr but is never called (the EOD refunds row at `EndOfDayPreviewModal.tsx:334-343`
labels with `refundsCount` only). *Fix:* remove `taxCollected` (en+fr) and either use or drop
`reports.endOfDay.refundsAmount`.

**m-5 — `refunds_count` renders unmasked under the blind-count boundary.**
`EndOfDayPreviewModal.tsx:336-338` — `Refunds: {preview.refunds_count}` is outside the
`hideFinancialAmounts` ternary that guards the amount at `:340-342`. A count is not a tender figure
and B-13's re-derivation concern does not apply to it, so this is informational rather than a leak —
but flag it for the B-13 owner if counts are in scope for that regime.

---

## Checklist verdicts (brief items 1–9)

1. **Money handling — PASS.** Zero `parseFloat`/`Number()` on money in new code. The helper routes
   every operation through `@/lib/decimal` (Big.js, `Big.RM = 1`), passes the **currency** scale
   explicitly on every call rather than taking `decimal.ts`'s default of 3
   (`vatDisclosure.ts:84-99`, pinned by the scale-2 test at `vatDisclosure.test.ts:65-78`), and the
   validation guard is a regex, not a numeric coercion (`:110-118`). The claimed `bcabs`-then-add
   era-safe shape **is** present at `endOfDayPreview.ts:294, 378` — see M-1 for the catch that the
   era-safe figure is not the one rendered. `precision/no-parsefloat-on-money` is clean on every
   lane file. Display goes through `useCurrency().format` (POS) / raw server strings (web), never
   through arithmetic in JSX.
2. **i18n — PASS with m-1/m-3/m-4.** All new strings behind `t()`; en+fr complete on both apps
   (verified by resolving all 16 pos keys and all 8 web keys programmatically); ar covered on web
   (5 keys) and correctly **skipped on POS**, which ships only `en`/`fr` locale dirs — no namespace
   was added, so the `i18n.ts` three-places rule does not apply and correctly was not touched.
   Backfill is strictly additive on all 7 locale/lang files (proof above).
3. **Design tokens (rule 18) — PASS.** No new hardcoded Tailwind colors. Web additions use
   `borderColors.light` / `textColors.primary` / `tokens.card.base`; POS additions use the POS
   semantic classes already used by their siblings (`bg-surface-sunken`, `text-ink-muted`,
   `text-warning-strong`, `border-border-subtle`). No token interpolation with a variant prefix or
   opacity modifier anywhere in the diff. DS audit `0 new`; pos ratchet exactly 84.
   *Note:* the brief's "baseline 6449" does not match this tree — the audit reports **807**
   C1–C6 violations / 808 baseline entries. The gate that matters (`0 new`, `0 stale`) holds.
4. **Types (rule 7) — PASS.** `410272b06` is a clean regen: `generated.d.ts` gains exactly the two
   DTO types, additive, no existing type moved (`+14/-0`). Field-by-field match against
   `RefundVatDisclosureData.php` / `RefundVatDisclosureRowData.php` confirmed (6 and 4 properties,
   all `string`/`bool`/array as declared). The only other file in that commit is the intended
   consumer swap replacing the hand-written mirror with the generated aliases
   (`apps/web/src/features/pos/api/reportApi.ts:41-42`) — declared in the commit message, not a
   smuggled edit. No hand-edited domain interface.
5. **TanStack keys — PASS.** No query key added or changed; `ZReportDetailPage` continues to use
   `tenantScopedKey`. `audit-tanstack-keys.mjs`: 0 unscoped, 0 new.
6. **API handling — PASS.** No new endpoint consumption; `fetchZReport` untouched, no unwrap change,
   no `apiGet`/`api.get` misuse introduced. The web type additions (`refund_vat_disclosure?` on
   `ZReportItem`, `refunds_amount` on `XReportResponse`) mirror the server resources and are
   correctly marked detail-only/optional.
7. **Component reuse + B-13 — PASS on reuse, PASS on the concealment test, see B-1.** The new UI
   reuses `Modal`/`SummaryCard`/`DataTable` and factors the bridge into one shared
   `VatDisclosureSummary` used by all three POS surfaces rather than three bespoke blocks. The
   claimed SECURITY test **exists in the same commit** and is real:
   `EndOfDayPreviewModal.test.tsx:908-924` asserts labels render while `7.18`, `-2.00`, `5.18` and
   `12.00` are all absent under `require_blind_cash_count: true`. I verified the render path matches:
   `masked={hideFinancialAmounts}` at `EndOfDayPreviewModal.tsx:356` and the em-dash substitution at
   `VatDisclosureSummary.tsx:41, 49, 53`. X/Z modals carry no cash figures at all, so no masking gap
   there. The new EOD fields reach **no** signed payload — `handleEndOfDayConfirm`
   (`Header.tsx:372-446`) consumes only `preview.payment_methods` and `preview.expected_cash`.
8. **Tests — PASS (re-run, not accepted).** Counts above. The web claim (457/457) reproduces exactly;
   the pos claim reproduces and exceeds at the directory level (491/46). No hung workers.
   Caveat: the EOD refund-VAT assertion is non-discriminating (M-1).
9. **Scope — PASS.** The FE diff is confined to the declared surfaces: 3 POS modals + 1 new shared
   component + 1 new helper + `endOfDayPreview.ts`, and on web the Z detail page, two API type files
   and a one-line column relabel on the Z list page (`ZReportListPage.tsx:240`, justified in-comment
   because that column is sale-only and now demonstrably so). No drive-by color migration of
   untouched lines, no unrelated refactor, no baseline or tooling edits.

---

## Verdict rationale

The lane is well-built on every mechanical axis — gates are genuinely green, the baseline is
untouched, the regen is pure, the money path is string-clean and scale-correct, and the B-13 test
is real rather than claimed. It fails the gate on one class of defect it introduces twice: an
inconsistency-disclosure mechanism that is **advertised in code comments, DTO docblocks and the
commit message as "surfaced rather than hidden", but is unreachable on the device (B-1) and
unread on the web (B-2)**. On a fiscal display that is precisely the "UI must not overstate system
guarantees" failure, and both are small, local fixes.

Fix B-1, B-2, M-1, M-2 (m-1..m-5 at author's discretion, m-2/m-4 cheap), then re-gate.

VERDICT: CHANGES-REQUIRED
