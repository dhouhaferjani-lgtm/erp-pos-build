# M0 — Preflight record (z-sale-branch-decomposition)

Wave: `z-sale-branch-decomposition` · brief:
`docs/handoff/CODEX-DISPATCH-z-sale-branch-decomposition-2026-08-18.md` · harness:
`docs/handoff/SELF-REVIEW-HARNESS.md`.

No production code in this milestone. This file is the committed M0 artifact evidence
(the session report under `docs/sessions/` is gitignored — `.gitignore:58` — so it cannot
carry gate evidence).

---

## 1. Base pin + the three-part base check

```
$ git -C <repo> rev-parse dev
9d14cb8e1afae9b9cd3e5afa9d8bfac8dd2aabd3

$ git -C <repo> log -1 --format='%H %ci %s' dev
9d14cb8e1afae9b9cd3e5afa9d8bfac8dd2aabd3 2026-08-21 17:59:04 +0100 z-decomp M0.0: gate + dispatch
the C-2 device Z sale-branch brief — apply 6 brief-gate findings (M1 ruling channel via
parent-dispatched fiscal-pos gate, progress YAML created, verdict parse hardened fail-closed,
real-writer red-first in M2 + masking fixtures enumerated, C-2 row bound, lint:ratchet), harden
scripts/adversarial-review.sh final-line verdict parse

$ git -C <repo> rev-parse origin/dev
c6d6308ae301b003ae97c66011653aeaf6c52fe3

$ git -C <repo> status --porcelain
(empty)

$ git merge-base --is-ancestor c6d6308ae301b003ae97c66011653aeaf6c52fe3 9d14cb8e1afae9b9cd3e5afa9d8bfac8dd2aabd3 && echo yes
yes
```

`base_sha = 9d14cb8e1afae9b9cd3e5afa9d8bfac8dd2aabd3` — the LOCAL `dev` tip at worktree
creation, exactly as the brief's ⛔ HARD PREREQUISITES §1 defines it (the commit carrying this
gated brief + the progress YAML). Its first-parent chain reaches `origin/dev` `c6d6308ae`,
verified above. The brief's §"OPEN AT DISPATCH" item 1 says `base_sha = c6d6308ae` on the
assumption "origin/dev == local dev tip at dispatch"; that assumption is **stale** — the
dispatch commit `9d14cb8e1` itself landed on local `dev` after `c6d6308ae`. §1's normative rule
("`base_sha` = the LOCAL dev tip at worktree creation (`git rev-parse dev`)") governs and is
what is pinned. **Recorded as citation-inventory drift item D-1** (below); it is a metadata
inconsistency inside the brief, not an unresolved citation.

**Three-part base check:**

| # | Check | Result |
|---|---|---|
| 1 | `git merge-base --is-ancestor <base_sha> HEAD` | PASS (HEAD == base_sha at branch creation) |
| 2 | `git diff --stat <base_sha>..HEAD` empty at branch creation | PASS (empty) |
| 3 | The asymmetry still present at HEAD in all three files | PASS — all three sale branches carry the gross-as-net derivation; see §2 |

Worktree `.worktrees/z-sale-decomposition`, branch
`codex/z-sale-branch-decomposition-2026-08-18`, created from `9d14cb8e1`.

---

## 2. Citation inventory — every `file:line` in the brief re-derived at `base_sha`

**Unresolved: 0.** Every citation resolves to a real semantic anchor. Four carry a line-number
drift (recorded, not fatal — the brief itself states line numbers are "evidence … not
addresses").

| # | Brief citation | Actual at `9d14cb8e1` | Symbol / semantic anchor | Status |
|---|---|---|---|---|
| 1 | `cartStore.ts:175-201` — `recalcLineTotal()` derives `line_total` then extracts tax | `apps/pos/src/stores/cartStore.ts:175-207` | `function recalcLineTotal(item, newQty)`; `:179` `grossTotal = bcmul(item.unit_price, qty, decimals)`, `:194` `rawTotal = bcsub(grossTotal, discountAmount)`, `:204` `line_total: lineTotal`, `:205` `tax_amount: computeTaxAmount(lineTotal, item.tax_rate)`; `computeTaxAmount` at `:163-173` is explicitly **tax-inclusive extraction** (`:166-167` "extract tax from price that already includes it") | EXACT (function body runs to `:207`; the cited `175-201` is the head of the same function) |
| 2 | `zReportService.ts:803` — `aggregateReportData` | `apps/pos/src/lib/offline/zReportService.ts:803` | `function aggregateReportData(receipts, paymentMethodMap, decimals, refundRecords): ZReportData` | EXACT |
| 3 | `zReportService.ts:864-876` — CORRECT refund derivation | `:864-876` | `:867` `lineVat = bcabs(line.tax_amount)`, `:868` `lineGross = bcabs(line.line_total, decimals)`, `:869` `lineNet = bcsub(lineGross, lineVat, decimals)`, `:872-874` subtract into `vatByRate` | EXACT |
| 4 | `zReportService.ts:850-863` — explanatory comment | `:850-863` | "Wave-2 review fix (finding 4 / codex C-3): `lines[].line_total` is the GROSS/TTC line amount, NOT the net." | EXACT |
| 5 | **`zReportService.ts:903-916` — DEFECT (sale branch)** | `:903-916` | `:903` `// VAT breakdown from receipt lines`, `:907` `lineVat = line.tax_amount ?? '0'`, **`:908` `const lineNet = line.line_total ?? '0'`** (gross-as-net), `:909` `lineGross = bcadd(lineNet, lineVat)` — **no scale arg**, `:912-914` accumulate — **no scale arg** | EXACT — **defect present** |
| 6 | `zReportService.ts:898-901` — headline totals (must NOT change) | `:898-901` | `salesCount++`, `grossSales = bcadd(grossSales, receipt.total)`, `netSales = bcadd(…, receipt.subtotal)`, `taxAmount = bcadd(…, receipt.tax_amount)` | EXACT |
| 7 | `endOfDayPreview.ts:263-278` — comment stating `line_total` is GROSS "(as on a sale row)" and the sale branch was left alone | `apps/pos/src/lib/offline/endOfDayPreview.ts:267-282` | `:267` `// VAT breakdown from receipt lines.`; `:274-275` "on a refund row (as on a sale row) `line_total` is the GROSS/TTC line amount"; `:280-282` "The SALE branch is left byte-identical (its own gross-as-net treatment predates this lane…)" | **DRIFT +4** (D-2) — anchor confirmed |
| 8 | `endOfDayPreview.ts:284-295` — CORRECT refund derivation | loop opens `:284`; refund block `:288-299`; arithmetic `:291-296` | `:291` `lineGross = line.line_total`, `:292` `lineVat = line.tax_amount`, `:293` `lineNet = bcsub(lineGross, lineVat, scale)`, `:294-296` accumulate **with `scale`** | **DRIFT +4** (D-2) — anchor confirmed |
| 9 | **`endOfDayPreview.ts:297-304` — DEFECT (sale branch)** | `:301-308` | `:301` `lineVat = line.tax_amount ?? '0'`, **`:302` `const lineNet = line.line_total ?? '0'`** (gross-as-net), `:303` `lineGross = bcadd(lineNet, lineVat)` — **no scale arg**, `:305-307` accumulate — **no scale arg** | **DRIFT +4** (D-2) — **defect present** |
| 10 | `reportApi.ts:392` — `generateLocalXReport` | `apps/pos/src/api/reportApi.ts:392` | `async function generateLocalXReport(terminalId, opts): Promise<XReportResponse>` | EXACT |
| 11 | `reportApi.ts:459-470` — CORRECT refund derivation | `:459-470` | `:462` `lineVat = bcabs(line.tax_amount, decimals)`, `:463` `lineGross = bcabs(line.line_total, decimals)`, `:464` `lineNet = bcsub(lineGross, lineVat, decimals)`, `:466-468` subtract **with `decimals`** | EXACT |
| 12 | `reportApi.ts:426-442` — comment | `:426-442` | "…VAT and payment-method breakdowns SUBTRACTED, with net derived as gross − vat (finding 4 — `lines[].line_total` is GROSS/TTC)" | EXACT |
| 13 | **`reportApi.ts:488-498` — DEFECT (sale branch)** | `:488-498` | `:491` `lineVat = line.tax_amount ?? '0'`, **`:492` `const lineNet = line.line_total ?? '0'`** (gross-as-net), `:494-496` accumulate — **no scale arg**, `:496` `bcadd(lineNet, lineVat)` inline | EXACT — **defect present** |
| 14 | `decimal.ts:22-28` — default scale 3 | `apps/pos/src/lib/decimal.ts:22-28` | `bcadd(a, b, scale: number = 3)` `:22-24`; `bcsub(a, b, scale: number = 3)` `:26-28` | EXACT |
| 15 | `zReportService.test.ts:104-124` — masking sale fixture (`line_total 42.00` = subtotal on a 50.00/8.00 receipt) | `apps/pos/src/lib/offline/__tests__/zReportService.test.ts:103-124`; the line itself at **`:123`** | `makeReceiptRows()` — docblock `:103` "One CASH receipt: total=50, subtotal=42, tax=8"; `:123` `line_total: '42.00'` with `tax_amount: '8.00'`, `tax_rate: '19'` | EXACT (range) — masking confirmed |
| 16 | `endOfDayPreview.test.ts:24-51` — masking sale fixture (`line_total 8.40` = net) | `apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts:24-51`; lines at **`:37`** and **`:50`** | `:37` `{ tax_rate: '19', tax_amount: '1.60', line_total: '8.40' }` against `total '10.00' / subtotal '8.40'`; `:50` `{ …, tax_amount: '3.19', line_total: '16.81' }` against `total '20.00' / subtotal '16.81'` | EXACT — masking confirmed |
| 17 | `.github/workflows/ci.yml:1189-1195` — `pnpm lint:ratchet` in `frontend-lint` | `.github/workflows/ci.yml:1189-1196` | `- name: Run ESLint warning ratchet` … `run: pnpm lint:ratchet`, `working-directory: .` | EXACT |
| 18 | LEDGER row **D-1** (device build stack v60/61→v62→v63→v66→v67) | `docs/handoff/LEDGER.md:68` | row D-1, status OPEN, owner Device/deploy operator | EXACT |
| 19 | LEDGER row **D-3** (SV-11 device build before SV-9 migration promotion) | `docs/handoff/LEDGER.md:70` | row D-3, OPEN — armed at sv-stage1 merge | EXACT |
| 20 | LEDGER row **C-2** (this defect) | `docs/handoff/LEDGER.md:111` | row C-2, OPEN — cites the three sites; its `endOfDayPreview.ts:297-304` inherits drift D-2 | EXACT (row) / D-2 (its inner citation) |
| 21 | `refundReportingEndToEnd.test.ts` — the REAL-WRITER pattern to mirror | `apps/pos/src/lib/offline/__tests__/refundReportingEndToEnd.test.ts:1-392` | Drives real `createRefundReceipt()` against a real `SqliteTestAdapter` + real `FiscalEventEngine`, then reads the row back through `generateZReport` `:321`, `buildEndOfDayPreview` `:336`, `generateXReport` `:352`; asserts hand-computed net/vat/gross `:331-333`, `:345-347`, `:365-367` | EXACT |
| 22 | Lane C wave-2 consolidated findings, **finding 4** | `docs/superpowers/reviews/2026-08-01-lane-c-wave2-consolidated-findings.md` finding 4 | "Z/EOD gross-as-net VAT double-count … Wave-2 tests masked this with net-valued fixtures. Fix: treat local line_total as gross (net = gross − vat at currency scale)." | EXACT |
| 23 | Ticket of record | `docs/superpowers/tickets/2026-08-01-device-z-sale-branch-gross-as-net.md:1-23` | Prescribes: own micro-lane, fiscal-pos-reviewer gate, sealing/versioning decision, real-writer E2E fixtures | EXACT |

### Citation drift register

| id | Drift | Consequence |
|---|---|---|
| **D-1** | Brief §"OPEN AT DISPATCH" item 1 states `base_sha = c6d6308ae` ("origin/dev == local dev tip at dispatch"); the actual local `dev` tip at worktree creation is `9d14cb8e1` (the dispatch commit itself). | None — brief §1 and the progress YAML both make the executor pin `git rev-parse dev` at M0, and `c6d6308ae` is verified in `9d14cb8e1`'s ancestry exactly as §1 requires. Pinned `9d14cb8e1`. Recorded so a reviewer reading item 1 does not read the mismatch as an unpinned base. |
| **D-2** | `endOfDayPreview.ts` citations are **+4 lines** stale: comment `263-278`→`267-282`, refund `284-295`→`288-299`, **sale defect `297-304`→`301-308`**. Same +4 drift in LEDGER row C-2's copy of the sale-site citation. | None to scope — the semantic anchors are all present and unambiguous. M2 must cite `301-308` for the endOfDayPreview sale branch, not `297-304`. |

---

## 3. Third-site confirmation (brief M0 §3)

**CONFIRMED — there are three structurally separate consumers, not two.** The scope does not
shrink.

Evidence:

```
$ grep -rn "aggregateReportData" apps/pos/src/
apps/pos/src/lib/offline/zReportService.ts:232:  const reportData = aggregateReportData(receipts, paymentMethodMap, decimals, refundRecords);
apps/pos/src/lib/offline/zReportService.ts:238:  // …comment…
apps/pos/src/lib/offline/zReportService.ts:803:function aggregateReportData(
apps/pos/src/lib/offline/endOfDayPreview.ts:312:  // …comment referring to it…
apps/pos/src/lib/offline/refundReceiptService.ts:283:  // …comment referring to it…
apps/pos/src/lib/offline/__tests__/zReportService.test.ts:811,853  // …comments…
```

- `aggregateReportData` is a **module-private** function (`function`, not `export function`,
  `zReportService.ts:803`) with exactly **one** call site, `zReportService.ts:232`, inside its
  own file. Every other hit is a prose comment.
- `apps/pos/src/api/reportApi.ts` imports from `@/lib/offline/zReportService` only
  `generateZReport` (`:12`) and the type `GenerateZReportOpts` (`:13`) — **not** the
  aggregator. `generateLocalXReport` carries its own inline loop at `:452-504`, with its own
  `vatByRate` map declared at `:449`.
- `apps/pos/src/lib/offline/endOfDayPreview.ts` likewise carries its own loop (`:284-309`) and
  its own `vatByRate`, and imports nothing from `zReportService`.

Three independent copies ⇒ three fixes, and R-2's "structurally identical" obligation is real
work, not a refactor opportunity (rule 4 forbids collapsing them into a shared helper in this
lane).

**Fourth-consumer negative check.** `grep -rn "vatByRate" apps/pos/src` (production files only)
returns **four** files, not three. The fourth is `apps/pos/src/lib/buildReceiptData.ts:270-283`
— and it is **out of scope, already correct, and corroborating**:

- it consumes `CartItem[]` directly, **not** `offline_receipts.lines[]` (`:255` parameter
  `cartItems: CartItem[]`), so it is not a reader of the SQLite row at all;
- its map shape is `{ taxable, tax }` (`:270`), not the report's `{ net, vat, gross }`;
- it feeds the **printed receipt**'s `vat_breakdown` (`:318-324`), not a signed `Z_REPORT` /
  `X_REPORT` event;
- **`:274` already derives `taxable = bcsub(item.line_total, item.tax_amount, decimals)`** —
  gross minus VAT, at the currency scale, with the scale argument passed. That is exactly the
  R-2/R-3 pattern this lane must apply to the three report sale branches, applied here to the
  same cart values. It is independent evidence that `line_total` is GROSS on a sale line and
  that the three report sale branches are the outliers.

No further consumer of `offline_receipts` `lines[]` builds a per-rate decomposition. The scope
stays at exactly three sites.

---

## 4. Declared regression set (re-enumerated, not inherited)

Enumerated by `grep -rl` for each of the three production modules across
`apps/pos/src/**/*.test.ts(x)` at `base_sha`. **Tests run BY PATH only** (house rules); vitest
zombie worker pools get killed after any hang (`ps aux | grep 'node (vitest'`).

**Tier 1 — direct aggregation coverage (must be green at every milestone):**

| Suite | Why |
|---|---|
| `apps/pos/src/lib/offline/__tests__/zReportService.test.ts` | Site 1's own suite; carries masking fixture `:123` |
| `apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts` | Site 1, rounding path; authors `line_total: input.total` (`:166`) |
| `apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts` | Site 2's own suite; masking fixtures `:37,:50,:144,:193,:247,:323,:390,:450,:488,:523,:555,:622` |
| `apps/pos/src/api/__tests__/reportApi.test.ts` | Site 3's own suite |
| `apps/pos/src/api/__tests__/reportApi.localTimestamps.test.ts` | Site 3, SQLite TEXT-timestamp boundary (rule 20); `line_total` fixtures `:136,:143` |
| `apps/pos/src/lib/offline/__tests__/refundReportingEndToEnd.test.ts` | The REAL-WRITER reference across all three consumers — the pattern M3 mirrors, and the guard that the refund branches stay correct |

**Tier 2 — downstream consumers of the three modules (regression, must not break):**

| Suite | Module it exercises |
|---|---|
| `apps/pos/src/lib/offline/__tests__/cashTenderedFormula.test.ts` | `endOfDayPreview` |
| `apps/pos/src/components/pos/EndOfDayPreviewModal.test.tsx` | `endOfDayPreview` |
| `apps/pos/src/components/pos/CashReconciliationSection.test.tsx` | `endOfDayPreview` |
| `apps/pos/src/components/pos/FiscalReportModals.test.tsx` | `reportApi` |
| `apps/pos/src/components/pos/TodaySalesPanel.test.tsx` | `reportApi` |
| `apps/pos/src/components/pos/SaleDetailModal.test.tsx` | `reportApi` |
| `apps/pos/src/components/__tests__/Header.test.tsx` | `reportApi` |
| `apps/pos/src/pages/ZReportListPage.test.tsx` | `reportApi` |

**Tier 3 — writer-side, touched only if a fixture correction reaches them (R-4 register):**
`apps/pos/src/lib/offline/__tests__/receiptService.test.ts`,
`receiptService.cashRounding.test.ts`, `offlineCheckoutService.test.ts`,
`idempotencyRetry.integration.test.ts`, `voucherCheckout.integration.test.ts`,
`getOfflineReceiptForPrint.test.ts`. These author `line_total` too, but they assert the
**writer**, not the per-rate decomposition; they are listed so an M3 fixture correction that
touches one is a declared, named change rather than a silent one.

**Environment note:** the worktree had no `node_modules` at creation (`apps/pos/node_modules`
and root `node_modules` both absent — worktrees do not share the main checkout's install).
`pnpm install --frozen-lockfile` was run from the worktree root during M0 so M2/M3 can run
tests by path. No test execution is claimed in M0.

---

## 5. M0 verdict

All three defect sites present at `base_sha`; base check 3/3 PASS; citation inventory
**unresolved = 0** with two recorded drift items (D-1, D-2); third site independently
confirmed; regression set declared in three tiers. `status: passed`, no review lens
(`review_lenses: []` — setup only).
