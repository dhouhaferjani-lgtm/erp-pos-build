# X/Z Refund-VAT Inclusion — Implementation Scope

> **Status:** SCOPING ONLY — no code changed. Read-only census at local dev tip `cee085bbd`.
> **Owner ruling:** B-6(ii), 2026-08-23 — *"owner sees no reason X/Z reports exclude refund VAT — 'it's probably part of it, we need to do it properly'; correctness items land before the first client."*
> (`docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md:25`)
> **Related ruling:** B-6(i) research lane (declaration standards, TN focus) — `OWNER-SHEET…:24`. **This scope must not pre-empt it** (see §6 dependency D-2).
> **Interacts with:** LEDGER C-2, C-6 (merged-unpushed device fixes), F-4 server tripwire, G-4 (VAT declaration netting), B-13 (X-report gate — explicitly OUT of scope).

---

## 0. Headline finding — the ruling's premise is half wrong, and the true defect is worse

The owner sheet records the state as *"shift X/Z reports **exclude** refund VAT (own positive refunds block)"*. The census does not support that as a blanket statement. The actual state, per surface:

| Surface | `vat_breakdown[].vat_amount` | headline `tax_amount` | refund VAT visible? |
|---|---|---|---|
| **Device signed Z / X / SESSION_CLOSE** (`zReportService.ts`) | **NET** — refund VAT is subtracted (`:873`) | **SALE-ONLY** (`:960`, reached only after the refund `continue` at `:895`) | netted, invisibly |
| **Device local X** (`api/reportApi.ts`) | **NET** (`:546`) | **SALE-ONLY** (`:583`) | netted, invisibly |
| **Device EOD preview** (`offline/endOfDayPreview.ts`) | **NET** (`:324`, sign-carried) | **SALE-ONLY** (`:233`, `:260`) | netted, invisibly |
| **Server aggregation** (`ReportGenerationService::calculateShiftTotals`) | **EXCLUDED ENTIRELY** — return rows `continue` at `:1047` before the `vatDetails` loop at `:1057` | **SALE-ONLY** (`:1053`) | absent |
| **NF525 Z export** (`Nf525XmlBuilder.php:320-324`) | **not exported at all** | `MontantTaxe` ← sale-only `tax_amount` (`:323`) | absent |
| **Printed POS Z ticket** (`printing.ts:270-285`) | hardcoded `[]`, `show_vat_breakdown: false` | hardcoded `'0.00'` | no VAT of any kind |

So the answer to *"is refund VAT (a) reported gross in a separate refunds section, (b) absent entirely, or (c) both depending on surface?"* is **(c), plus a fourth state nobody documented: silently netted**.

**The real, user-visible defect on a v3 fleet is an internal contradiction on a single report.** A Z from a shift that took a return prints:

- a headline VAT figure (`tax_amount`) that is **gross of refunds**, and
- a per-rate VAT table (`vat_breakdown`) that is **net of refunds**,

two numbers on the same document that disagree by exactly the refund VAT. This is the same defect class C-2/C-6 just closed (two disagreeing figures on one signed report), and it is precisely why the F-4 tripwire has to **stop asserting** `Σ vat_amount == tax_amount` as soon as `refunds_totals.count != 0`:

> *"(2) is gated on `refunds_totals.count == 0` because the device's `vat_breakdown` is SIGNED (a refund is SUBTRACTED from it) while the headline totals are SALE-ONLY by design (refunds live in `refunds_totals`). Once a refund exists the two cannot reconcile, and no Z field carries the refund's VAT split with which to bridge them."*
> — `FiscalPayloadConstraintValidator.php:827-833`

That docblock is the authoritative statement of the defect. The fix's job is to make the two figures reconcile so that gate is no longer papering over a contradiction.

---

## 1. Census — where refund VAT lives today (file:line)

### 1.1 Device — signed content (`apps/pos/src/lib/offline/zReportService.ts`)

One `aggregateReportData()` (`:803`) produces the `vatBreakdown` that feeds **all three** signed event types (Z_REPORT, X_REPORT, SESSION_CLOSE — C-2 finding F-1).

- Refund branch `:842-896`: `refundsCount++`, `refundsAmount += bcabs(receipt.total)` (`:848`); per line, `lineVat = bcabs(line.tax_amount)`, `lineNet = lineGross − lineVat`, then **subtracted** from the shared `vatByRate` map (`:872-874`); `continue` at `:895`.
- Sale branch `:898-995`: `grossSales += receipt.total` (`:916`); `netSales += receipt.subtotal − receipt.tax_amount` (`:959`, the C-6 identity); `taxAmount += receipt.tax_amount` (`:960`); per-rate **added** (`:989-991`, the C-2 decomposition).
- Emission `:1063-1074`: `gross_sales`, `net_sales`, `tax_amount` (sale-only), `refunds_count`, `refunds_amount` (positive magnitude), `vat_breakdown` (net), `payment_methods` (net of refund payout legs).

**Sign-era note (latent divergence, worth a lane check):** `zReportService.ts:867-869` and `api/reportApi.ts:541-543` use `bcabs(...)` then **subtract** — era-safe. `endOfDayPreview.ts:317-327` instead **adds** and relies on the row already being negative-signed (`"Already negative-signed on the row (§7.2), so these stay additive"`). On the v4 path all three agree; a positive-signed legacy refund row would make EOD preview add where the other two subtract. Not a B-6(ii) defect, but it sits in the same code and the lane will be reading it.

### 1.2 Device — signed payload shape (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts`)

- X_REPORT `:363-398`, SESSION_CLOSE `:401-444`, Z_REPORT `:447-500`.
- All three carry: `refunds_totals: { amount, count }` (`:379-382`, `:423-426`, and Z's `:481-484`) — **gross TTC magnitude, no VAT split**; `sales_totals` / `receipt_totals` `{ gross_sales, net_sales, tax_amount }` — **sale-only**; `vat_breakdown` — **net**.

### 1.3 Server — validator (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`)

- `validateZReportFamilyPayload` `:764-772` → `validateZFamilyVatBreakdownConsistency` `:869-1000` (the F-4 tripwire).
- Rule 1 (per-group `gross == net + vat`, 1-ulp slack) `:955-967`; Rule 2 (Σ identities, EXACT) `:990-1000`; the refund gate `:972-987` (fail-closed on a non-integer count, `return` when `count != 0`).
- Runs **retroactively over sealed bytes** via `VerifyEventChainCommand`, `QuarantineBestEffortParseController`, `BestEffortPayloadParser`, not only at ingest (`:785-793`).
- The device has **no** mirror of this tripwire: `FiscalEventEngine.ts:3748-3760` validates key set + four UUIDs + date + bool only. F-4 is server-only.

### 1.4 Server — aggregation and render

- `ReportGenerationService::calculateShiftTotals` `:994-1100` — return receipts branch at `:1026` and `continue` at `:1047`, so `vat_breakdown` (`:1057-1069`) is **sale-only**. Docblock states it as intent: *"VAT breakdown stays SALE-ONLY, unchanged: refunds are their own block"* (`:984`).
  - **Reachability:** `generateXReport` `:113` and `generateZReport` `:182` both call `assertServerReportAuthoringAllowed` `:84-89`, which **throws for `fiscal_schema_version >= 3`**. Migration `2026_07_31_000001_default_pos_terminals_fiscal_schema_version_3.php` made 3 the default. So on tenant #1's fleet this whole aggregation is **dead** — it is a legacy v1/v2 path only.
- `viewDataFor` `:760-771` → `preparePdfData` `:776-812` renders `$zReport->report_data` **verbatim** (`:801-802`). For a v3 terminal that is the device payload passed through by `ZReportProjection::legacyReportData` (`:131-166`, `tax_amount` at `:150`, `vat_breakdown` at `:154`). So the PDF shows the net table next to the sale-only headline.
- Blade `apps/api/resources/views/pos/z-report.blade.php` — sales summary `:276-308` (`tax_amount` at `:293`, refunds line `:296-297` = count + gross amount), VAT table `:334-357`. **No refund-VAT column, no "of which refunds" line.**

### 1.5 NF525 export (`apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php`)

`addZReports()` `:281-333` emits exactly five `Donnees` fields (`:320-324`): `NombreVentes`, `VentesBrutes`, `VentesNettes`, `MontantTaxe` ← **sale-only `tax_amount`**, `NombreAnnulations`. **No refund terms and no per-rate TVA ventilation on the Z** — `vat_breakdown` never reaches the XML. `addGrandTotals()` `:341+` likewise carries no refund term.

This is the one place where "excludes refund VAT" is unambiguously and fiscally true.

### 1.6 Display surfaces (full list in §4)

Notable gaps found: the **printed POS Z ticket carries no VAT at all** (`printing.ts:270-285` hardcodes `'0.00'` / `vat_breakdown: []` / `show_vat_breakdown: false`); the **EOD preview modal has no refunds row** (`EndOfDayPreviewModal.tsx:300-312`); the **POS X modal renders `refunds_count` but never `refunds_amount`** (`XReportModal.tsx:52-59`); the **web X-report response is never rendered at all** (`ShiftOperationsMenu.tsx:86-104` only toasts).

---

## 2. The pivotal question — is netting derivable without touching signed content?

### **YES for the net figure. It is already the signed field.**

`vat_breakdown[].vat_amount` on every device-authored Z/X/SESSION_CLOSE **is** sales VAT − refund VAT, per rate, at the currency scale. Nothing needs to be added to the signed payload to obtain net VAT per rate.

### **NO for a separate refund-VAT disclosure.**

The signed payload cannot be decomposed back into "sales VAT" and "refund VAT" — `refunds_totals` carries `{count, amount}` only. Confirmed by the validator's own words (`:830`) and by the payload builders (§1.2).

But a refund-VAT **display line** does not require a signed field:

- **Server-side:** derive from `pos_receipt_vat_details` joined to `pos_receipts` where `receipt_type = 'return'` over the Z's shift window — the *identical* source and predicate the G-4 declaration arm uses (`EloquentVatDataRepository.php:94-113`), so the two reconcile by construction rather than by coincidence.
- **Device-side:** derive locally from the `offline_receipts` refund rows the three consumers already iterate — a fourth accumulator alongside the existing net map, used for **display only** and never fed into the payload builders.

### Cost of the alternative (if a signed field is ever wanted anyway)

- **B1 — new top-level key** (e.g. `refund_vat_breakdown`): `validatePayloadKeySet` `:449-484` rejects **both** missing and extra keys, and runs retroactively over sealed bytes. Adding a required key fails every historical Z. It therefore needs a **versioned key set** — `payloadKeysFor()` `:427-437` shows the SALE_RECEIPT v3/v4 precedent — plus the mirrored device constants in `FiscalEventEngine.ts` (which the file itself pins: *"Any change there MUST land here in the same commit"*, `:1037`), across all three Z-family types. Rule 8 territory. **XL.**
- **B2 — nested under `refunds_totals`** (e.g. `refunds_totals.vat_breakdown`): does **not** trip `validatePayloadKeySet` (top-level only) and `refunds_totals` has **no** nested key-set validation — only `count` is type-checked (`:972-987`). Old events keep passing. Cheaper, but still: changes canonical bytes and the Z hash for new events (`zReportHashService.ts:51-52,:102` warns a `refunds_amount` semantic change needs a `schema_version` bump), needs device+server in lockstep, breaks the NF525 snapshot and `ZReportProjectionTest` canonical-bytes assertions, and — most dangerously — it *invites* tightening F-4 rule 2 to run unconditionally, which is a retroactive-corpus decision the C-6 gate deliberately settled the other way. **L.**

**Recommendation: neither. Take the display/derivation route (§5, Option A).**

---

## 3. Reconciliation target — the identity that should hold

### 3.1 Primary identity (Z ↔ VAT declaration, per rate, per period)

For a company `C`, VAT period `P`, and tax rate `r > 0`:

```
  Σ  over every Z_REPORT z with business_date ∈ P, across ALL terminals of C
     ( z.vat_breakdown[r].vat_amount )

  ==

  VatAggregation(OUTPUT, r).vat_amount  for period P
     =  Σ_{sale rows}   prvd.vat_amount
      −  Σ_{return rows} ABS(prvd.vat_amount)
```

Right-hand side is `EloquentVatDataRepository.php:112-113`. Both sides are already net-of-refunds per rate, so **on a v3 fleet this identity holds today for `vat_amount`** — which is why the fix is a presentation/derivation problem, not an arithmetic one.

The same identity for the **base**:

```
  Σ z.vat_breakdown[r].net_amount  ==  VatAggregation(OUTPUT, r).base_amount     (r > 0 only)
```

### 3.2 Stated wedges — the lane must assert these as preconditions, not assume them

1. **Zero/exempt rates.** The declaration arm filters `prvd.tax_rate > 0` (`EloquentVatDataRepository.php:108`); the device `vat_breakdown` keys on `line.tax_rate ?? '0'` and therefore **includes a rate-0 group**. Harmless for `vat_amount` (0), material for `net_amount`/base. The identity holds only when restricted to `r > 0`.
2. **Scope.** A Z is **terminal**-scoped; the declaration is **company**-scoped. The LHS must sum over all terminals.
3. **Window.** Declaration uses `r.posted_at BETWEEN dateFrom AND dateTo` (`:105`); Z uses the shift window. The known **last-day-of-period boundary defect** (`docs/superpowers/tickets/2026-08-21-vat-period-last-day-boundary.md`) breaks the identity at every period edge. **Out of scope — cite, do not fix here.**
4. **Voided / training.** Declaration excludes both (`:106-107`); the device excludes training and reports voids in their own block. Aligned, but pin it with a test.
5. **Legacy v1/v2 terminals.** `calculateShiftTotals` excludes refund VAT from `vat_breakdown` entirely (§1.4), so the identity **fails** on server-authored Zs until that is fixed. Part of this lane (§5, A3).
6. **`tax_amount` is not in the identity.** The sale-only headline must never be used as a declaration input. If §5/A1 relabels rather than changes it, that constraint has to be written into the code comment.

### 3.3 X-report — what differs

X runs the same aggregation with the same netting over a shift-to-date window (`api/reportApi.ts:471-620`; device X payload `zSessionAuthoring.ts:363-398`). X is **never** a declaration input. Its only obligation is **internal consistency with the Z that follows it** — same headline/table relationship as §0, same fix.

**B-13 interaction (flag only, DO NOT SCOPE HERE).** `docs/superpowers/tickets/2026-08-21-xreport-blind-count-gap.md` — the X report discloses per-tender CASH takings to any operator with no gate, and B-13 was ruled *fix-properly* (`OWNER-SHEET…:30`, `:52`). Two consequences for this lane:
- Adding a refund-**VAT** line does not worsen that leak (VAT is not tender), but
- any new figure on `/reports` or the X surface must be checked against the blind-count concealment logic before it ships, because B-13(i) established that a concealed cash figure can be *arithmetically re-derived* from visible siblings. A refund-VAT line is a new sibling.

Record this as a **cross-check obligation on the B-13 lane**, not work in this one.

---

## 4. Surfaces the lane must touch (and the ones it must not)

### 4.1 Device — display only, no signed bytes

| File | Change |
|---|---|
| `apps/pos/src/components/pos/ZReportModal.tsx:156-200` | headline VAT → net; add derived "of which refunds" line |
| `apps/pos/src/components/pos/XReportModal.tsx:45-86` | same; also renders `refunds_count` but drops `refunds_amount` (`:52-59`) — surface it |
| `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:300-312, :342-372` | same; **has no refunds row at all** today |
| `apps/pos/src/lib/offline/endOfDayPreview.ts:92-102` | add derived refund-VAT/refund-total fields to the **preview** shape (not a payload) |
| `apps/pos/src/api/reportApi.ts:471-620` | same derivation for local X display |
| `apps/pos/src/lib/offline/zReportService.ts` | derivation for display consumers **only** — payload emission `:1063-1074` byte-identical |
| `apps/pos/src/lib/printing.ts:225-296` | **largest single item**: the printed Z ticket has no VAT at all (`:270-285` all-zero). Adding it means extending `BuildZReceiptDataInput` and the Rust formatter contract, not just the caller (`Header.tsx:535-556`). **Candidate for a follow-on** — see §5 sizing. |

### 4.2 Server — display + legacy aggregation

| File | Change |
|---|---|
| `apps/api/resources/views/pos/z-report.blade.php:276-357` | net VAT headline + refund-VAT sub-line in the VAT table |
| `ReportGenerationService.php:776-812` (`preparePdfData`) | supply the derived refund-VAT rows to the view |
| `ReportGenerationService.php:994-1100` (`calculateShiftTotals`) | **A3:** net refund VAT into `vat_breakdown` at the `:1026-1047` return branch (legacy v1/v2 only); update the `:984` docblock |
| `apps/web/src/features/pos/pages/ZReportDetailPage/ZReportDetailPage.tsx:232-237, :272-306` | net headline + refund-VAT column/line |
| `apps/web/src/features/pos/pages/ZReportListPage/ZReportListPage.tsx:258-264` | list `tax_amount` column is sale-only — relabel or switch to net |
| `apps/web/src/features/pos/api/shiftApi.ts:53-66` | X type is missing `refunds_amount` (server sends it) |

### 4.3 NF525 — **owner/standards decision required, do not change unilaterally**

`Nf525XmlBuilder.php:320-324` `MontantTaxe` ← sale-only `tax_amount`. Whether an NF525 Z's `MontantTaxe` is meant to be gross or net of refunds is a **standards** question, and it is squarely inside the **B-6(i) research lane** (`OWNER-SHEET…:24` — *"research the declaration standards… anything that must be declared is included"*). Additionally, NF525 is a **French** regime while tenant #1 is **TN**, so this may not be on the first-client critical path at all.

**Scope decision: carve NF525 out of the implementation lane and route it to B-6(i).** Changing it is server-only and cheap (no device build, no signed bytes) — but it is a fiscal export contract change with snapshot tests (`Nf525ExportSnapshotTest`, `Nf525ExportCanonicalRoundTripTest`, `Nf525CanonicalZGrandTotalPeriodTotalsTest`) and it must follow the standard, not intuition. Note that the `:297-319` comment block already documents a deliberate `VentesNettes + MontantTaxe != VentesBrutes` wedge — any change here has to be reconciled against it.

---

## 5. Recommended option and lane shape

### Option A — display/derivation only ✅ **RECOMMENDED**

**No signed-schema change. No PAYLOAD_KEYS change. No canonical-byte change. No F-4 change. No new device-build coupling.**

- **A1 — Consistent VAT presentation.** Every X/Z/EOD surface shows `Σ vat_breakdown[].vat_amount` (net) as the VAT total, labelled net-of-refunds. The sale-only `tax_amount` is either dropped from display or shown explicitly as "VAT on sales" beside a "VAT on refunds" counter-line so the two visibly sum to the net.
- **A2 — Derived refund-VAT line.** Server: from `pos_receipt_vat_details` × `receipt_type='return'` over the shift window, using the same `-ABS` normalization as `EloquentVatDataRepository.php:112-113`. Device: a display-only accumulator in the loops that already branch on refunds. **Never written into a payload builder.**
- **A3 — Server legacy aggregation fix.** `calculateShiftTotals:1026-1047` — net refund VAT into `vat_breakdown` so v1/v2 terminals match device semantics and satisfy §3.1.
- **A4 — Reconciliation test.** Pin §3.1 end-to-end: a shift with sales and a return, asserting Z `vat_breakdown` per rate equals the declaration arm's output for the same period+rate.

**Why it is safe:** signed bytes are untouched, so F-4 rule 2 keeps its `refunds_count == 0` gate exactly as gated, the sealed corpus is unaffected, the Z hash is unchanged, and the device half carries **zero quarantine risk** — if a display change missed a build, the degradation is cosmetic, not fiscal.

**Size: M.** (~6 device display sites + 3 web/blade + 1 server aggregation + ~6 tests.)
**Size: S** if `printing.ts` (§4.1, Rust formatter contract) is split into a follow-on — **recommended**, since the printed ticket shows no VAT at all today and fixing that is a separate feature ("put VAT on the Z ticket"), not a refund-netting correction.

### Option A-minus — server-only floor

A2 (server half) + A3 + blade + web, **no device change at all**. Zero build risk, ships at promotion with the C-6 server halves. Leaves the device-printed/modal figures inconsistent. **Size: S.** State it to the owner as the true zero-risk floor; it probably does not satisfy "properly".

### Option B — signed schema change ❌ **NOT RECOMMENDED**

B1 (XL) / B2 (L) per §2. Buys nothing for the reconciliation target, since the declaration reads projections, not Z payloads. Only justified if the owner later wants a Z that is *self-sufficient* as a declaration source document.

---

## 6. Sequencing

**Q: can this ride the same D-1 build as C-2/C-6? — YES, and it should.**

- C-2 and C-6 are merged on **unpushed local dev**; LEDGER C-2 confirms *"no C-2-only build exists or can exist — C-2 lives only on unpushed local dev and no device build has been cut since."* The device build stack is D-1 (`LEDGER.md:72`, v60/61 → v62 → v63 → v66 → v67).
- Option A's device changes are **display/print only**. They add **no new coupling** to the mandatory C-2+C-6 device coupling, because they cannot produce a Z whose signed content disagrees with anything. They ride the same build purely for the owner's "correctness before first client" requirement.
- Option A's server changes (A2 server half, A3, blade, web) land at **promotion**, alongside the C-6 server halves (F-4 tripwire + F-5 NF525 GrandsTotaux) that are already queued there.

**Ordering within the lane:** A3 and A2-server first (independently testable, no build dependency) → A4 reconciliation test → A1/A2-device → optional `printing.ts` follow-on.

**Dependencies:**
- **D-1:** must be merged into local dev **after** C-2/C-6, never rebased ahead of them — A1's labelling assumes the C-6 headline identity is already in place.
- **D-2:** NF525 (`MontantTaxe`) is **blocked on B-6(i)** research. Do not touch it in this lane.
- **D-3:** B-13 lane owes a cross-check that any new refund-VAT figure does not re-open the blind-count arithmetic derivability finding (§3.3). Not a blocker on this lane.

---

## 7. DO-NOT-TOUCH list

The implementing lane must not modify any of the following. Each one is load-bearing for a fix that landed in the last 72 hours.

1. **`apps/pos/src/lib/payment/cartTotals.ts:36`** — `subtotal` = Σ **gross** `line_total`. **(Note: LEDGER rows C-2/C-6 cite this as `apps/pos/src/lib/pos/cartTotals.ts:36` — that path does not exist; the file is under `lib/payment/`. Line 36 is correct. Worth a LEDGER correction.)** C-6 deliberately derives net as `subtotal − tax_amount` **on top of** this misnomer because that reproduces the sealed canonical `subtotalNet` byte-for-byte. "Fixing" cartTotals breaks the sealed-corpus tie *and* the F-4 identity.
2. **F-4 tripwire semantics** — `FiscalPayloadConstraintValidator.php:764-1000`. Specifically: rule 1's **one-ulp** slack, rule 2's **exactness**, the **`refunds_count == 0` gate**, the **fail-closed** non-integer count check, and the **fully-legacy-payload PASSES** design. In particular **do not tighten rule 2 to assert on refund-bearing shifts** — the validator runs retroactively over the entire sealed corpus (`:785-793`) and that change would quarantine history. Option A removes the *contradiction* the gate hides without removing the *gate*.
3. **C-2's three consumer blocks** — the sale-branch decomposition `lineNet = lineGross − lineVat` at `zReportService.ts:983-993`, `endOfDayPreview.ts:330-343`, `reportApi.ts:596-604`, and their refund-branch counterparts (`zReportService.ts:864-876`, `endOfDayPreview.ts:317-328`, `reportApi.ts:538-549`). Read them; do not restructure them.
4. **C-6's headline derivation** — `netSales += subtotal − tax_amount` at `zReportService.ts:959`, `endOfDayPreview.ts:259`, `reportApi.ts:582`. Not `total − tax_amount` (rejected: different bases, breaks `Σ net_amount == net_sales`).
5. **Signed payload emission** — `zReportService.ts:1063-1074` and the three builders `zSessionAuthoring.ts:363-500`. Byte-identical output is a lane exit criterion.
6. **`refunds_amount` positive-magnitude semantics** — server-pinned by `ZReportV3AggregationTest`, consumed by `GrandtotalService` / `computeGrandTotals` as `gross − refunds` (`ReportGenerationService.php:933`). `zReportHashService.ts:102` warns that re-normalizing it requires a `schema_version` bump. Do not re-sign it.
7. **Key-set constants** — `ZReportPayload::PAYLOAD_KEYS`, `XReportPayload`, `SessionClosePayload`, and their device mirrors in `FiscalEventEngine.ts`. Untouched under Option A.
8. **`computeGrandTotals` / grand-total chain** — perpetual counters, not a VAT surface.
9. **B-13 manager-gate logic** — out of scope entirely (§3.3).

---

## 8. Gates and verification

**Mandatory:**
- **`fiscal-pos-reviewer`** — device event/payload/projection surface. Non-negotiable.
- **`frontend-conventions-reviewer`** — the web and POS display work.

**Conditional:** `treasury-reviewer` **if** any payment/drawer/tender figure moves. Under Option A none should — if the lane finds itself touching `payment_methods` or `cash_drawer_totals`, that is a scope breach, stop and re-gate.

**Tests the lane owes:**

| Test | Pins |
|---|---|
| **Signed-bytes-unchanged regression** (device) | Z/X/SESSION_CLOSE payload byte-identical before/after — *the key safety test for Option A* |
| Device vitest × 3 consumers, refund-bearing shift | `Σ vat_breakdown[].vat_amount == sales VAT − refund VAT`; derived display line correct |
| `ReportGenerationServiceTest` (PHPUnit) | A3 legacy netting; return rows now reach `vat_breakdown` |
| **Reconciliation test (A4)** | §3.1 identity: Z per-rate net VAT == `EloquentVatDataRepository` OUTPUT for the same period+rate, `r > 0`, all terminals |
| Blade / `ZReportPdfTest.php:292` | VAT table markup incl. the refund line |
| Web component tests | `ZReportDetailPage.test.tsx:44-59` fixture extended with a refund-bearing shift |

**Existing tests that will need updating** (they pin today's behaviour, so a green run before the change is expected to go red): `ZReportPdfTest.php:292`, `ZReportDetailPage.test.tsx`, `ZReportListPage.test.tsx`, `FiscalReportModals.test.tsx`, `EndOfDayPreviewModal.test.tsx`, `endOfDayPreview.test.ts`, `refundReportingEndToEnd.test.ts:271`, `ReportGenerationServiceTest.php`. **`printing.test.ts:4-60` only if the `printing.ts` item is kept in-lane.**

**Must stay green untouched:** `ZReportProjectionTest.php:105` (canonical bytes), `zReportHashService.test.ts` + `.legacyStability.test.ts`, `zSessionAuthoring.test.ts`, `FiscalPayloadConstraintValidatorTest.php`, all three NF525 snapshot/round-trip tests. Any red here means the lane has drifted into Option B.

---

## 9. Open questions for the owner

| # | Question |
|---|---|
| Q-1 | **Presentation shape.** Show one net VAT figure, or three lines (VAT on sales / VAT on refunds / net VAT)? Three lines is more auditable and makes the arithmetic self-evident; one line is cleaner on a 58mm thermal ticket. Recommend three on web/PDF, net-plus-counter-line on device modals. |
| Q-2 | **Printed Z ticket.** It currently shows **no VAT at all** (`printing.ts:270-285`). In-lane, or a follow-on "put VAT on the Z ticket" feature? Recommend follow-on (drops this lane M → S). |
| Q-3 | **NF525 `MontantTaxe`.** Confirm this routes to the B-6(i) standards research rather than being fixed by intuition here — and confirm whether the French regime is even on the first-client path. |
| Q-4 | **A-minus acceptable?** If the device build slips, is the server-only floor (§5) enough to call B-6(ii) satisfied for the first client? |
