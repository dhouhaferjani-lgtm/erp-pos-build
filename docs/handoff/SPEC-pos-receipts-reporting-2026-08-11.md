# SPEC — POS Receipts web view + receipt-level reporting (2026-08-11)

**Revision:** **r5.1 (2026-08-18, implementation terminal-audit amendment)** — supersedes r5 (which superseded r4, r3, r2, r1). Gate r1 = **REVISE** (15 fixes); gate r2 = **FAIL** (7 new internal-contract defects N-1…N-7); gate r3 = **FAIL**: **N-3/N-4/N-6/N-7 PASS**, **N-1/N-2/N-5 PARTIAL**, three new defects **R3-1 (MAJOR), R3-2 (MINOR), R3-3 (MAJOR)** — all three applied at r4. Gate r4 = **FAIL** (`docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r4.md`): it verified **R3-2 and R3-3 as PASS** and **OI-17 as holding**, but marked **R3-1 PARTIAL** (the "exhaustive" training-axis table and BT-1's matrix omitted three legal non-TRAINING mixed code subsets under both toggle states) and the **revision-history honesty PARTIAL** (§9.3 called that table "complete" and miscounted six changed test rows as five), raising **R4-1 (MAJOR)** and **R4-2 (MINOR)**. r5 applies exactly those two; r5.1 then records the parent terminal-audit ruling that the existing legacy `receipt_type` axis and newly surfaced `is_voided` axis belong in the exhaustive list-row allowlist. All revision logs are in §9 (§9.1 r1→r2, §9.2 r2→r3, §9.3 r3→r4, §9.4 r4→r5, §9.5 r5→r5.1). Where a gate REFUTED or corrected a claim, **the gate's version is adopted** — it was verified against source; where the gate left a name or a mechanism open, **the revision verified it against source itself** and the citation is given inline.

**Status:** DRAFT r5 for adversarial gate r5 → owner read-through → Codex build dispatch.
**Lane:** POS reporting / first-tenant launch. **Scope:** POS only.
**Rulings of record:** `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md` §"Research-round rulings" (Receipts row) and §"First-tenant POS research rulings".
**Consolidates (does not re-research):**
- `R12` = `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/12-research-receipts-web-view.md`
- `R14` = `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/14-research-pos-module-first-tenant-reporting.md`
- `OP` = `docs/handoff/FINDINGS-other-problems-2026-08-11.md`

Every substantive claim below cites `R12 §x` / `R14 §x` / `OP-nn`. Where this spec verified something in code **during spec authoring** (going beyond the research), the claim is marked **[VERIFIED 2026-08-11]** with a `file:line`.

---

## 1. Goal and non-goals

### 1.1 Goal

Give the web app a **fiscal register for POS receipts**: a canonical list, a receipt detail page at the URL two existing features already deep-link to, a **separately-listed** refunds & voids register, and a reachable receipt-chain verification. Plus the launch-required accountant access repair (A-1/A-2).

Framing, per `R12 §5`: *this is a fiscal register with a contextual header, not a dashboard.* The aggregate story is already told by `/pos/analytics` (8 endpoints) and `/reports` (8 endpoints) (`R12 §2.6`). What no surface can answer today is *"show me that transaction"*, *"show me every refund last week"*, *"prove this receipt is in the chain"* (`R12 §5`).

Ruling being executed (`OWNER-DECISIONS…:59`): **build properly; refunds listed SEPARATELY (gross + a separate refunds register; never netted); receipt detail cross-links to its refund(s) and vice versa; POS scope only.**

### 1.2 Non-goals — explicitly OUT

| # | Out of scope | Why |
|---|---|---|
| NG-1 | **B2B refunds / credit notes** | Owner ruling: *"B2B-flow refunds (credit notes) are explicitly out of scope here"* (`OWNER-DECISIONS…:59`). The returns-convention ruling for documents is OQ-12 (two separate return-note flows) and lives in the DN/sales lane. |
| NG-2 | **New analytics dashboards / a third chart grid** | 16 aggregate endpoints already exist (`R12 §2.6`, `R14 §2.3`). A third grid would also create a **third disagreeing daily total** (`R12 §4.5`). |
| NG-3 | **Any reprint action on a list row** | Every PDF fetch writes a fiscal reprint-audit row and increments `copy_number` (`R12 §4.7`; `OP-16` — *"reprint must always be a deliberate, confirmed detail-page action, never a row-level list button"*). |
| NG-4 | **Any write surface on receipts** (void, return, edit, payment) | `POST /pos/receipts`, `/{id}/void`, `/{id}/payments` are 410 tombstones; `/{id}/return` is device-only (`R12 §2.1`). Re-growing a write surface is precisely what got `ReceiptSearchPage` deleted (`R12 §1`, commit `973834a13`). |
| NG-5 | **Creating a POS module (`ModuleName::POS`, `module:POS`, `hasModule('POS')`)** | POS is not a module today — no enum case, no `verticals.php` key, no FE usage (`R14 §0 fact 5`, `§5.1`). Owner escalation: *"separate packaging lane"* (`OWNER-DECISIONS…:67`). Pages ship **permission-gated** (`R14 §5.3 item 1`). |
| NG-6 | **`/pos/shift-history` fixes** (location scoping, `parseFloat` on money, date util) | Owned by `OP-15` as a standalone FE fix. Cross-reference only — do **not** touch that page in this build. |
| NG-7 | **Shift-detail page / shift↔receipt joins** | `pos_receipts` has **no shift column**; the shift↔receipt set is a `terminal_id + cashier_id + posted_at BETWEEN` heuristic (`R12 §2.4`). The correct home for cash-variance context is a shift-detail page (`R14 §3.5`, gap `B-3`), not this lane. |
| NG-8 | **Totals-strip endpoint (S-8) in wave 1–2** | Gated on the owner's totals-convention ruling `B-9` (`R14 §6(b)`). See §4.1 S-8 and §8 OI-3. |
| NG-9 | **Bulk/CSV export** | `R14 §6(b) B-8`; the accountant's monthly ask, but a separate build. Flagged, not built. |
| NG-10 | **Fixing the CLI honest-verification defect ES-07** | Owned by the fixes session, Lane A0 (`docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md:50,119`). See §3.4. |

---

## 2. Personas and user stories

Personas mapped to real seeded roles (`R14 §2.1`).

### 2.1 Owner-operator (`admin`) — end of day

| # | Story | Screen |
|---|---|---|
| U-1 | *"Show me every transaction on this terminal today, so I can find the one the customer is asking about."* | (a) list, date-default = today |
| U-2 | *"Open that receipt and read exactly what was sold, at what VAT, paid how."* | (b) detail |
| U-3 | *"Show me today's refunds and voids on their own, not blended into sales."* | (c) refunds & voids register — owner ruling, `R14 §6(b) B-1` |
| U-4 | *"Give the customer a duplicate of this ticket — and I understand that creates a duplicate record."* | (b) detail, confirmed reprint (`OP-16`) |
| U-5 | *"Did anything today fail to seal or fail to sync?"* | (a) list, `fiscal_status` filter (`R14 §C-6`, A-6) |

### 2.2 Partner-accountant (`accountant`) — monthly / compliance

Today this persona can reach **exactly one** of twelve monthly/fiscal + EOD surfaces (the VAT pages) — `R14 §2.5`. It holds **zero `pos.*` permissions** [VERIFIED 2026-08-11 — `apps/api/database/seeders/RolesAndPermissionsSeeder.php` accountant block, no `pos.*` key present], no `dashboard.owner` (`permissionsMap.generated.ts:52`), yet holds `compliance.view_reprint_log`, `compliance.verify_chains`, `compliance.export_jet`, `audit.view`, `reports.financial` [VERIFIED — same block].

| # | Story | Screen |
|---|---|---|
| U-6 | *"Reach the compliance page that hosts my own grants (JET export, chain verify, reprint log)."* | A-1 re-gate (§4.2) — `OP-02`, `R14 §C-5`, launch-required |
| U-7 | *"List last month's POS receipts to tie the VAT declaration to source documents."* | (a) list, with A-2 grant |
| U-8 | *"Show me every refund in the period, separately, with its reason and its original."* | (c) register |
| U-9 | *"Prove the receipt chain for this terminal is unbroken."* | (d) chain-verify panel — `R14 §C-7` (*"highest value-per-line in the lane"*). ⚠️ **r2: deferred to wave 2b, hard-blocked on Lane A0** (§3.d) — today's endpoint cannot honestly answer this story |

### 2.3 Back-office clerk — voucher follow-up

| # | Story | Screen |
|---|---|---|
| U-10 | *"A refund/exchange-surplus voucher cites a source receipt — take me to it."* | (b) detail at the pinned URL `/pos/receipts/:id`. Today both links fall through `path="*"` to `/dashboard` (`R12 §3.2`, `LedgerHistoryTable.tsx:108`, `ProvenanceSection.tsx:28`). |
| U-11 | *"From that original, show me which refund(s) came off it."* | (b) detail, refund lineage (needs S-5) |

### 2.4 Non-persona (boundary, stated so nobody builds for it)

`cashier` holds `pos.view_receipts` but works on the device; the web register is a back-office surface (`R14 §2.1`). No cashier-specific affordance is built.

---

## 3. Screens

Shared shell canon (`R12 §5(a)`): `ListPageLayout` → `PageHeader` → typed `DataTable` → `OffsetPagination` [VERIFIED 2026-08-11 — all three exist: `apps/web/src/components/molecules/ListPageLayout/ListPageLayout.tsx`, `components/molecules/DataTable/DataTable.tsx`, `components/ui/OffsetPagination.tsx`]. Structural exemplar: `features/documents/DocumentListPage.tsx:427-507`. **Do NOT copy `ZReportListPage` or `VoucherListPage`** — both use the deprecated `DataTable` markup-passthrough form and `ZReportListPage.tsx:293-321` hand-rolls a paginator (`R12 §5(a)`).

Design rubric (`R12 §5`, from `03-product-pages-critique.md:261,291`): **one emphasis per screen; one coloured signal per row; everything else grayscale typographic hierarchy.**

### 3.0 Naming (binding)

Nav label **"POS Receipts" / "Tickets de caisse"**, never bare "Receipts" — `/purchases/receipts` (goods receipts) already owns that word (`R12 §3.1`, §7).

---

### (a) `/pos/receipts` — receipts list

**Route** — replaces the quarantine comment at `routes/index.tsx:2925-2929` [VERIFIED 2026-08-11 — comment block present at those lines], inside `<Route path="pos">`:

```tsx
<Route path="receipts" element={
  <RequirePermission permission="pos.view_receipts">
    <SuspenseWrapper><ReceiptListPage /></SuspenseWrapper>
  </RequirePermission>
} />
```

Keep a one-line comment stating the **write** surface stays retired, so the next reader does not re-add a void button (`R12 §5(d)`).

**Sidebar** — POS group (`Sidebar.tsx:235-242`), between `shiftHistory` and `zReports`. ⚠️ see §4.2 GATE-3: a sidebar `permission:` value that is not a `MODULE_PERMISSIONS` key **fails open**.

**Emphasis:** there is no primary action (read-only), so the **single emphasis is the date-range control** (`R12 §5(a)`).

**Columns**

| Column | Source | Treatment |
|---|---|---|
| Receipt # | `receipt_number` | link to detail; `font-mono`; the row identity |
| Date / time | `posted_at` | shared locale-aware date util — **never** `new Date(...).toLocaleString()` (`R12 §3.3`) |
| Type | `invoice_type_code` (**S-2**) | `StatusBadge` **only** when ≠ `SALE`. On this register the **only** value that can appear is `TRAINING` (and only with the opt-in ON) — REFUND/VOID rows are excluded because **this screen never requests those codes** (it has no control that can), see "Disjoint registers". Plain sales render nothing (one coloured signal per row) |
| Location | `location_name` (**S-1**) | hide the whole column when the tenant has one location (`useLocation().hasMultipleLocations`) |
| Terminal | `terminal_code` | text |
| Cashier | `cashier_name` | text |
| Total | `total` + `currency` | `numeric: true` (→ `text-right tabular-nums`), `formatCurrency`. Refunds rendered as a **per-row magnitude**, never a blanket sign flip (`R12 §2.2`; reference impl `ReceiptController.php:511-532`) |

**Deliberately not columns** (`R12 §5(a)`): `subtotal`, `tax_amount` (they invite the §3.b arithmetic mistake), a "view" link column, a reprint button (NG-3), `fiscal_hash`.

**Filters** — one row in the `filters` slot:

| Filter | Wire param | Default | Note |
|---|---|---|---|
| Date range | `from_date` / `to_date` | **today** in the **company timezone** (see S-13) | THE emphasis control |
| Receipt # search | `receipt_number` (server `LIKE %…%`) | — | debounced `SearchInput` |
| ~~Type~~ | — | — | **REMOVED in r2 (gate r1 defect 1).** There is no type selector on this register: `/pos/receipts` is the **SALE register** and is disjoint from `/pos/receipts/refunds` by contract (see "Disjoint registers" below). The only type axis left on this screen is the training toggle. |
| Location | `location_ids[]` (**S-1**) | from `useViewScope` | serializer shape: `features/pos/api/reportApi.ts:101-113` |
| Terminal | `terminal_id` | All | options from **S-7**, not `/pos/terminals` (§4.2 GATE-2) |
| Cashier | `cashier_id` | All | options from **S-7** |
| `fiscal_status` | `fiscal_status` (**S-3**) | All | `pending_seal` / `sync_failed` are the operationally interesting rows (`R14 §C-6`) |
| **Include training receipts** | `include_training` (**S-2**) | **OFF** | server **default-excludes** `training_flag = true`. It is the **only** training switch on the wire; when ON the screen also adds `'TRAINING'` to `invoice_type_codes[]` — see "Training axis — precedence, exhaustive" below (r4) |

**Disjoint registers — binding (r2, gate r1 defect 1).**

The owner ruling is *gross view **plus a separate refunds register**, never netted* (`OWNER-DECISIONS…:54-60`). r1 satisfied the "never netted" half but violated the "separately listed" half by defaulting `/pos/receipts` to **All** and offering Refunds/Voids tabs inside it, duplicating the rows on `/pos/receipts/refunds`. r2 made the two registers disjoint but expressed it as a *server* prohibition, which collided with its own refunds-register wiring; **r3 keeps the disjointness and moves its enforcement to the screens** (see the r3 note immediately below):

**⚠️ r3 (gate r2 defect N-1) — the one implementable rule.** r2 stated two server contracts that cannot both hold: *"REFUND and VOID are never returned by this endpoint shape, whatever the client sends"* **and** *"the refunds register calls that same index action with both codes"*. r3 adopts the gate's recommended resolution: **the API honours the validated codes; disjointness is a contract of the two SCREENS, not a server prohibition.** "Whatever the client sends" is deleted.

| Register | Codes the SCREEN sends | Notes |
|---|---|---|
| `/pos/receipts` (a) | `['SALE']` — plus `'TRAINING'` **only** when `include_training=true` | The screen offers **no control** that can emit `REFUND`/`VOID`: no type tabs, no type selector, no URL-driven type state. Enforced by **FT-16** (asserts the outgoing request payload), not by a server ban |
| `/pos/receipts/refunds` (c) | `['REFUND','VOID']` | Server answers with **one** paginated query over the mixed set — never a client-side merge of two calls, never a client-side concat (**BT-2b**) |

Wire contract (replaces r1's singular equality filter):
- `GET /pos/receipts` accepts `invoice_type_codes[]` (**array**, `min:1`, validated `in:SALE,TRAINING,REFUND,VOID`, §4.6) and **honours exactly what validation admits** — and validation admits **no** request whose two type axes contradict each other (rule 2 of "Training axis" below, r4). **Default when absent:** `['SALE']`, plus `TRAINING` iff `include_training=true`. One endpoint serves both registers; the *screen* (a) never sends REFUND/VOID. A hand-crafted request naming REFUND is answered normally — it is the same permission (`pos.view_receipts`) and the same rows the refunds register already shows, so there is nothing to protect by refusing it, and a server-side ban would make the (c) register unimplementable on this action.
- `GET /pos/receipts/refunds` is **not** a second endpoint: the refunds register calls the same index action with `invoice_type_codes[]=REFUND&invoice_type_codes[]=VOID`. The server builds a **single** `whereIn` so `meta.total` / `last_page` / page slicing are correct across the mixed set. (An implementer may instead add a dedicated server predicate/route; what is binding is *one* paginated SQL result, not two merged pages.)
- The singular `invoice_type_code` param from r1 is **dropped**; the array form is the only type axis. Still **never `receipt_type`** — the legacy enum collapses REFUND and VOID into `return` and projects TRAINING as `sale` (`R12 §2.2`).
- **Where disjointness is enforced, exhaustively:** (i) screen (a) renders no type tabs and its request payload never contains `REFUND`/`VOID` — **FT-16**; (ii) the server default with no type param is SALE-only — **BT-2**; (iii) the mixed register paginates in one server query — **BT-2b**. No test asserts a server refusal, because there is none.
- ❓ If the owner decides that showing refunds *both* on (c) and as a tab on (a) still counts as "separate", the tabs may return — **OI-13, owner-owed**. Until then: no type tabs on (a).

**Training axis — precedence, exhaustive (binding; r4, gate r3 defect R3-1).**

r3 left one admitted combination undecided: `invoice_type_codes[]=TRAINING` with `include_training` absent/false was simultaneously "honoured exactly" (⇒ training rows) and default-excluded (⇒ no training rows). r4 closes it at **validation**, so the "honours exactly what validation admits" sentence above becomes true rather than being weakened.

**Source facts this rule rests on** [all VERIFIED 2026-08-11]:
- `pos_receipts.invoice_type_code VARCHAR(16) NOT NULL DEFAULT 'SALE'` and `training_flag BOOLEAN NOT NULL DEFAULT FALSE` are two physical columns; the migration documents `training_flag` as **denormalized from `invoice_type_code == 'TRAINING'`**, kept separately only because the index path is `WHERE training_flag = FALSE` (`2026_05_20_120000_add_invoice_type_code_and_training_flag_to_pos_receipts.php:15-32,44-45`).
- The canonical validator **enforces the equivalence** before any payload can be signed and stored: invariant N-03, `(invoice_type_code === 'TRAINING') ⟺ training_flag`, else `payload_training_flag_mismatch` (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:901-914`). So on every fiscal-era row the two "axes" are **one** axis.
- Legacy rows (`fiscal_event_id IS NULL`) carry the column defaults `'SALE'` / `false` (same migration, `:19-20,34-37`).
- **Therefore `invoice_type_codes[]=TRAINING` + `include_training=false` requests a provably empty set** — it is not a narrow filter, it is a self-contradiction. Returning `200` with zero rows would silently mislead; the correct answer is to refuse the request.

**Rules, binding:**
1. `include_training` is the **only** switch that admits `training_flag = true` rows. Default `false` ⇒ the predicate `training_flag = false` is applied. `true` ⇒ the predicate is **dropped** (it never *adds* rows on its own — the code array still bounds the set).
2. **Contradiction is a 422, not a silent empty page.** `IndexReceiptsRequest` rejects any request where `TRAINING ∈ invoice_type_codes[]` **and** `include_training` is absent/false, with the message key *"`invoice_type_codes` may include TRAINING only when `include_training=true`"* (implemented as a cross-field `after`/`withValidator` closure, §4.6). No other combination is rejected on this axis.
3. **Default code set:** `invoice_type_codes[]` absent ⇒ `['SALE']` when `include_training` is false/absent, `['SALE','TRAINING']` when it is `true`. Present ⇒ honoured verbatim (rule 2 has already removed the only contradictory shape).
4. `invoice_type_codes[]` present and empty (`[]`) ⇒ **422** (`min:1`) — an empty array is not "no filter".
5. **No screen ever emits a 422 shape:** (a) sends `['SALE']`, or `['SALE','TRAINING']` **together with** `include_training=true` when the toggle is ON; (c) sends `['REFUND','VOID']` and **omits** `include_training` entirely.

**Every combination, exhaustively** (`codes` = `invoice_type_codes[]`; `inc` = `include_training`) — **r5 (gate r4 defect R4-1): the three mixed non-TRAINING subsets (`SALE+REFUND`, `SALE+VOID`, `SALE+REFUND+VOID`) were omitted at r4 and are now listed explicitly. With them the table states an outcome for all 15 non-empty subsets of the four codes × both toggle states, plus the absent, empty and out-of-domain cases — the "exhaustively" claim is true as written:**

| `codes` | `inc` | Server behaviour |
|---|---|---|
| absent | absent/`false` | **200** — SALE only; `training_flag = false` applied. Screen (a) default |
| absent | `true` | **200** — SALE ∪ TRAINING; predicate dropped, default set widened to `['SALE','TRAINING']` (rule 3) |
| `['SALE']` | absent/`false` | **200** — SALE only |
| `['SALE']` | `true` | **200** — SALE only. The toggle lifts the predicate but the explicit array still bounds the set, and by N-03 no row can be both `SALE` and `training_flag=true`. **Legal, not an error** |
| `['TRAINING']` | absent/`false` | **422** (rule 2) |
| `['TRAINING']` | `true` | **200** — TRAINING only |
| `['SALE','TRAINING']` | absent/`false` | **422** (rule 2) |
| `['SALE','TRAINING']` | `true` | **200** — SALE ∪ TRAINING. Screen (a) with the toggle ON |
| `['REFUND']`, `['VOID']`, `['REFUND','VOID']` | absent/`false` | **200** — exactly that set. The `training_flag = false` predicate is applied and is **vacuous**: by N-03 a REFUND/VOID row can never carry `training_flag=true`. Register (c) |
| `['REFUND']`, `['VOID']`, `['REFUND','VOID']` | `true` | **200** — byte-identical result to the row above; the toggle is a documented **no-op** here. Legal, not an error |
| `['SALE','REFUND']` | absent/`false` | **200** (r5) — exactly SALE ∪ REFUND, honoured verbatim (rule 3). The `training_flag = false` predicate is applied and is **vacuous**: by N-03 neither a SALE nor a REFUND row can carry `training_flag=true`. Legal; no screen sends it |
| `['SALE','REFUND']` | `true` | **200** (r5) — byte-identical to the row above. The toggle only lifts a predicate that excluded nothing, and the explicit array still bounds the set: a documented **no-op** on this axis. Legal, not an error |
| `['SALE','VOID']` | absent/`false` | **200** (r5) — exactly SALE ∪ VOID, honoured verbatim; the `training_flag = false` predicate is applied and is **vacuous** by N-03. Legal; no screen sends it |
| `['SALE','VOID']` | `true` | **200** (r5) — byte-identical to the row above; the toggle is a documented **no-op** on this axis. Legal, not an error |
| `['SALE','REFUND','VOID']` | absent/`false` | **200** (r5) — exactly SALE ∪ REFUND ∪ VOID, honoured verbatim; the `training_flag = false` predicate is applied and is **vacuous** by N-03. Legal; no screen sends it |
| `['SALE','REFUND','VOID']` | `true` | **200** (r5) — byte-identical to the row above; the toggle is a documented **no-op** on this axis. Legal, not an error |
| any set containing `TRAINING` **and** any other code | `true` | **200** — plain union, honoured verbatim. No screen sends it |
| any set containing `TRAINING` **and** any other code | absent/`false` | **422** (rule 2) |
| `[]` | either | **422** (rule 4) |
| any value outside `{SALE,TRAINING,REFUND,VOID}` | either | **422** (`in:` rule, §4.6) |

**Enforced by:** BT-1 (the 422 matrix + **every legal 200 shape in the table above, including the three mixed non-TRAINING subsets under both toggle states** — r5, gate r4 defect R4-1), BT-2 (server default = SALE only), FT-2 (the toggle sends `include_training=true` **and** adds `'TRAINING'`, so the FE can never produce the 422 shape), FT-16 ((c) sends both refund codes and no `include_training`).

**Training receipts — non-negotiable (`R12 §4.1`, `R14 §C-3`).** `ReceiptController::index` today filters neither `training_flag` nor `is_training` and returns neither, so a training receipt is indistinguishable from a real sale. Every owner/report service already excludes it (`SalesReportService.php:68`, `LiveSalesReportService.php:60`, `OwnerSalesSummaryService.php:103`). Shipping a list that blends them is a **fiscal-presentation defect**, not a UX nit. Rule: **exclude by default; explicit opt-in toggle; unmistakable row treatment when included** (badge + muted row), and the toggle state must be visible in the header when ON.

**Shift filter: OUT of v1** (NG-7, `R12 §2.4`, OQ-7 recommendation).

**Caching / scoping** — mirror `ZReportListPage.tsx:27-42`:

```ts
const { hasTenantScope } = usePosTenantScope()
const { scope, effectiveLocationIds } = useViewScope()
const scopedFilters = { ...filters, location_ids: effectiveLocationIds }
useQuery({
  queryKey: locationScopedKey(['pos', 'receipts', scopedFilters], scope),
  queryFn: () => fetchReceipts(scopedFilters),
  enabled: hasTenantScope,
})
```

`locationScopedKey` bakes `{locScope}` as a non-leading segment and delegates tenant/company suffixes to `tenantScopedKey` [VERIFIED — `apps/web/src/lib/locationScopedKey.ts:11-17`]. Required by the `audit-tanstack-keys.mjs` gate (CLAUDE.md rule 14).

**Filter state:** `useTableState({ syncToURL: true })` [VERIFIED — `apps/web/src/hooks/useTableState.ts:4-33`, `syncToURL` defaults `true`]. Rationale: the accountant workflow (*"the refunds in July"*) and the support workflow (*"send me that filtered view"*) both want a shareable URL. This is a deliberate, justified deviation from the local-`useState` canon exemplar; `R12 §9 Q4` left it open.

**Response envelope — do not "fix" it.** `GET /pos/receipts` returns **double-nested** `{data:{data:[…],meta:{…}}}` (`ReceiptController.php:135-143`), unlike its neighbours. `apiGet` unwraps `response.data.data` once, so `apiGet<PaginatedReceipts>(…)` correctly yields `{data, meta}`. **Do not double-unwrap and do not change the envelope** (`R12 §4.8`; CLAUDE.md rule 14).

**Empty state** — three distinct copies, never one generic "no data":
1. no filters applied, no rows → *"No POS receipts yet"* + a line saying sales are authored on the POS device.
2. filters applied, no rows → *"No receipts match these filters"* + a **Clear filters** action.
3. `hasTenantScope === false` → the shared no-scope state used by sibling POS pages.

**Sort:** fixed `posted_at DESC` (server, `ReceiptController.php:107`). Column sorting is **deferred** (S-10).

---

### (b) `/pos/receipts/:id` — receipt detail

**This URL is a pinned requirement**, not a design choice: `LedgerHistoryTable.tsx:108` and `ProvenanceSection.tsx:28` target exactly `/pos/receipts/:id` (`R12 §3.2`). Routed page (not a drawer) — 70 `*DetailPage` routes follow the convention and POS itself does (`/pos/z-reports/:zNumber`) (`R12 §5(b)`).

**Emphasis:** receipt number + total as the `PageHeader` title/subtitle pair. Nothing else competes.

**Sections, in order** (`R12 §5(b)`):

1. **Header** — `receipt_number`, `posted_at`, type badge (only when ≠ SALE), total, location/terminal/cashier as subtitle metadata. Actions: **Download PDF** and **Print**, both secondary, both behind the confirm of §3.b.1.
2. **Lines** — product, quantity, unit price, VAT rate, line net, `returned_quantity`.
3. **VAT breakdown** — `vatDetails`: rate / net / VAT / gross.
4. **Payments** — `payments`: method, amount, `card_last_four`, `instrument_serial`, `transaction_reference`, `authorization_code`; plus `change_due`.
5. **Refund lineage** — both directions (§3.b.2).
6. **Fiscal provenance** — collapsed by default (§3.b.3).

**Deliberately absent:** any void/return/edit/payment control (NG-4).

#### 3.b.1 Reprint is a fiscal act, not a convenience

`streamPdf` (`ReceiptController.php:634-645`) and `downloadPdf` (`:600-609`) both call `receiptPrintAuditService->recordPrint(...)` and pass the returned `copy_number` into the PDF, which renders the NF525 duplicate marking (`R12 §4.7`). Those rows surface in the compliance reprint log at `/settings/compliance/export`.

Required behaviour:
- **No print/PDF affordance anywhere on a list row** (NG-3, `OP-16`).
- On detail, both actions open a confirm dialog whose copy **names the consequence** — e.g. *"This produces a duplicate ticket. The duplicate is recorded in the fiscal reprint log with a copy number and is visible to your accountant."* Translated key, no hardcoded string (CLAUDE.md rule 11).
- The dialog is required on **every** invocation (no "don't ask again").
- The rendered page must not fetch either PDF URL as a side effect (no prefetch, no `<img>`/`<iframe>` preview) — a preview would silently write an audit row.

#### 3.b.2 Refund lineage — both directions (owner ruling)

- **Refund → original:** free today; `original_receipt_id` is already on the list row and the detail payload (`ReceiptController.php:115-116`) (`R14 §3.2`).
- **Original → its refund(s):** requires **S-5** — `show()` eager-loads `returnReceipts` and then `unset`s it at `:504` (`R12 §5-S-4`).
- Render as a small linked panel: refund receipt #, date, type badge (REFUND/VOID), **`refund_reason`** (the authoritative canonical value from **S-12** — *never* the legacy `return_reason` column, which the projector fills with the constant `"other"`, §4.5), `refund_destination`, amount magnitude. On a refund, the reciprocal panel links to the original (**S-5** must eager-load `originalReceipt`; r1 assumed it was already loaded — gate r1 finding 10).
- **Do not** compute or display a "net of refund" figure on the original — refunds are listed separately and never netted (owner ruling).

#### 3.b.3 Fiscal provenance panel

Collapsed by default; mirrors `ZReportDetailPage.tsx:346-364`. Shows `fiscal_hash`, `previous_hash`, `chain_sequence`, `receipt_year`, `fiscal_status`, `fiscal_event_id`, `synced_at`, `sync_error`.

**`canonical_bytes` must never reach the page.** `Receipt` declares no `$hidden` and `show()` returns `$receipt->toArray()`, so the current response includes the verbatim signed device encoding — several KB per row (`R12 §2.3`). **S-4** removes it server-side (a `ReceiptResource`); the FE must not render it even if present.

#### 3.b.4 Money & quantity presentation (CLAUDE.md rule 19 — binding)

**`unit_price` on a POS line is TAX-INCLUSIVE (TTC); the net is `line_subtotal`, persisted into the column named `line_total`** (`PosCoreReceiptProjection.php:1194-1195`; `R12 §4.2`; `docs/architecture/precision-contract.md:192-205`).

Consequences, all mandatory:
- Label the columns unambiguously: **"Unit price (incl. VAT)"** and **"Line net"** — translated keys, both locales.
- **Never** place `unit_price`, `quantity` and `line_total` adjacently in a way that implies a product. Prefer: `quantity` · `unit price (incl. VAT)` · `VAT rate` · **line net** with the VAT breakdown section carrying the reconciliation.
- **No line-arithmetic assertion anywhere** — not in the UI, not in a test, not in a tooltip. `line_subtotal == unit_price × qty − discount` is FALSE on every taxed line (`precision-contract.md:202`).
- Integrity messaging is **aggregate-level only**, and the equation is the **live DB constraint**, not r1's rounding-blind one (r2, gate r1 defect 5):

  ```
  total == subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)
  ```

  This is exactly `pos_receipts_totals` as enforced by PostgreSQL and asserted by the projector (`PosCoreReceiptProjection.php:417-430`; migration `2026_07_28_100200_add_cash_rounding_to_pos_receipts.php:158-170`). **r1's `subtotal + tax_amount == total + discount_amount` is WRONG** — it omits the signed `cash_rounding_adjustment` and false-fails every valid cash-rounded receipt. Any algebraically equivalent rearrangement is acceptable; dropping the rounding term is not.
  `cash_rounding_adjustment` is **NULL on legacy v1/v2 rows** (the column is written only for v3+, `PosCoreReceiptProjection.php:428-433`) — `COALESCE(...,0)` is mandatory, and both a rounded v3 fixture and a legacy NULL fixture are required (BT-14).
  The VAT-breakdown sums are unchanged and still binding, stated in the frozen wire names of §3.b.5(iv-b) (**r3, defect N-5** — r2 wrote them as `vatDetails.net`/`.vat`, which exist nowhere): `Σ vat_details[].net_amount == subtotal`; `Σ vat_details[].vat_amount == tax_amount` (`R12 §4.2`).
- **Do not** attempt the `unit_price_incl_tax` / `_excl_tax` rename — owner-flagged but deferred (`precision-contract.md:205`).
- Money: `formatCurrency` only; never `parseFloat`/`Number(...)` (rule 19). Two existing float round-trips (`PosAnalyticsService.php:85`, `ZReportDetailPage.tsx:222-224`) are **known violations owned elsewhere — do not copy either** (`R12 §4.6`).
- Quantities: `getQuantityDecimals` + `formatQuantity` at the **product unit's** precision. `GET /pos/receipts/{id}` does **not** emit `quantity_decimals` today — only `GET /pos/shifts/{id}/receipts` does (`ShiftController.php:305-330`). **S-4** adds it. Guarded by `no-literal-decimal-places` (ESLint) and `audit-quantity-display.mjs` (`R12 §4.3`).

#### 3.b.5 Wire schema — exhaustive allowlist (binding, r2 / gate r1 defect 6)

r1 said "use a `ReceiptResource`" and left the field set to the builder. r2 freezes it. **Every payload below is an allowlist: a field not named here is not emitted.** No `$model->toArray()`, no `$model->makeHidden(...)` subtraction pattern, no `...$rest` spreads — **construct** the array key by key.

**`canonical_bytes` is forbidden RECURSIVELY**, at every nesting depth, including the nested lineage receipts introduced by S-5 (r1's BT-6 only checked the root — gate r1 defect 6/finding 28). Enforced by BT-15 (recursive key search over the whole decoded JSON tree).

**(i) List row** — `GET /pos/receipts` `data.data[]`:

`id`, `receipt_number`, `posted_at`, `invoice_type_code`, `receipt_type`, `training_flag`, `is_voided`, `fiscal_status`, `location_id`, `location_name`, `terminal_id`, `terminal_code`, `cashier_id`, `cashier_name`, `total`, `currency`, `original_receipt_id`.

**2026-08-18 terminal-audit amendment:** `receipt_type` remains the explicitly labelled legacy axis, and `is_voided` is emitted so a migrated legacy-voided sale cannot be presented as indistinguishable from a live sale. Both fields are part of the exhaustive list-row allowlist and are locked by BT-5's row-key assertion.

Refund/void rows additionally carry the S-12 reporting fields: `original_receipt_number`, `refund_reason`, **`refund_reason_source`**, `refund_destination`, **`refund_policy_alerts`**.

**⚠️ r3 (gate r2 defect N-2):** r2's list row omitted `refund_reason_source` (required by S-12 §4.5 and asserted by **BT-17**) and `refund_policy_alerts` (the alert-indicator column the refunds register promises, §3.c.2) — an "exhaustive allowlist" that made two promised columns unrenderable. Both are added here. On SALE/TRAINING rows these five keys are **omitted entirely** (not `null` padding): the allowlist is per row *kind*, and FT-16/BT-2 already prove no refund row reaches screen (a).

**(ii) Detail root** — `GET /pos/receipts/{id}` `data`:

`id`, `receipt_number`, `posted_at`, `invoice_type_code`, `training_flag`, `receipt_type` (legacy axis, **labelled as legacy in the UI or not rendered**), `fiscal_status`, `location_id`, `location_name`, `terminal_id`, `terminal_code`, `cashier_id`, `cashier_name`, `currency`, `subtotal`, `tax_amount`, `discount_amount`, `cash_rounding_adjustment`, `cash_rounding_denomination`, `change_due`, `total`, `notes`, `is_voided`, `voided_at`, `fiscal_hash`, `previous_hash`, `chain_sequence`, `receipt_year`, `fiscal_event_id`, `synced_at`, `sync_error`, `refund_policy_alerts`, plus the four nested collections below and the lineage block (v).

**Never emitted:** `canonical_bytes`, `vat_breakdown_hash`, `payment_methods_hash`, `tenant_id`, raw `company` / `user` / `terminal` / `location` model objects, `created_at`/`updated_at`, and any `product` model object (the line's product identity is the **snapshot** pair in (iii); the current `product` relation is loaded only to derive `quantity_decimals` and is never serialised).

**(iii) Line** — `lines[]`: `id`, `line_number`, `product_id`, `product_name`, `product_code`, `quantity`, `quantity_decimals` (S-4), `unit_price` (**TTC**, §3.b.4), `discount_amount`, `vat_rate`, `vat_amount`, `line_total` (**net**), `returned_quantity`.

**(iv-a) Payment** — `payments[]`: `id`, `payment_method`, `amount`, `card_last_four`, `instrument_serial`, `transaction_reference`, `authorization_code`. **(iv-b) VAT** — `vat_details[]`: **`tax_rate`**, `net_amount`, `vat_amount`, `gross_amount`.

**⚠️ r3 (adjacent to gate r2 defect N-5 — source-verified here, the gate left it open):** the rate key is **`tax_rate`**, not r2's `vat_rate`. The column and model attribute are `tax_rate` [VERIFIED 2026-08-11 — `2026_01_08_190639_create_pos_receipt_vat_details_table.php:33`; `apps/api/app/Modules/POS/Domain/ReceiptVatDetail.php:41-47`], and the relation already serialises under the key `vat_details`, so `net_amount`/`vat_amount`/`gross_amount` are correct as written. A frozen allowlist may not name a field that does not exist on either side of the wire.

**Field-source mapping for (iii), binding — rewritten in r4 (gate r3 defect R3-3).** r3's version pointed `product_sku` and `unit_of_measure_code` at the **current, mutable** `product` / `product.unitOfMeasure` relations. That is wrong twice over: it renders present-day master data inside a historical fiscal register (a renamed or re-coded product would silently rewrite what an old receipt appears to say), and it breaks outright when `product_id` is `NULL` — which the fiscal projector **deliberately allows**, because the sealed snapshot, not the FK, is authoritative (`PosCoreReceiptProjection.php:1157-1176`). r4 re-sources both from the line's own snapshot columns.

| Wire key | Source | Rule |
|---|---|---|
| `product_code` | **`pos_receipt_lines.product_code`** — the immutable sale-time code [VERIFIED 2026-08-11 — column `string('product_code', 50)` with the PG comment *"Immutable snapshot: Product code at time of sale"*, `2026_01_08_190638_create_pos_receipt_lines_table.php:39,89`; `ReceiptLine.php:28` *"Product code at time of sale (immutable)"*]. The fiscal projector writes the **canonical** `line_items[].sku` into it (`PosCoreReceiptProjection.php:1189`); the legacy creation path snapshots the sellable code (`ReceiptCreationService.php:212,222,336`). **Replaces r3's `product_sku`** — the wire key is renamed to the column/snapshot name so no builder can mistake it for a live product lookup. Never `product->sku` |
| `product_name` | `pos_receipt_lines.product_name` — same immutable snapshot (`:40`) | Never `product->name` |
| `product_id` | `pos_receipt_lines.product_id` (**nullable** FK, `:33-36`) | Emitted as-is, **may be `null`**. It is a convenience link, never the source of any displayed value; the UI must render the line fully with `product_id === null` |
| `quantity_decimals` | **The one deliberate current-product enrichment** (S-4): `product.unitOfMeasure.decimal_places`, fallback **4** when the product, the relation, or the FK is absent — exactly the precedent at `ShiftController.php:305-330` (which comments it as *"Presentation-only enrichment"*) | It is **display precision**, not a fiscal value: it changes how `quantity` is rendered, never what it is. Keep the documented fallback |
| `vat_rate` / `vat_amount` | Presentation names for the line columns **`tax_rate` / `tax_amount`** [VERIFIED — `2026_01_08_190638_…:50-51`] | Rename only |
| `returned_quantity` | **Derived by the action**, not a column: `show()` computes it per line and injects it (`ReceiptController.php:490-501`), as a **magnitude** (`quantityMagnitude()`, `:511-532`) | Keep the magnitude semantics; do not re-derive it in the resource |
| everything else in (iii) | straight column read | — |

**`unit_of_measure_code` is DROPPED from the allowlist (r4, R3-3).** There is no truthful historical unit to emit:
- The canonical payload has **no unit field at all** — `line_items[]` is a fixed 13-property DTO (`+3` v2 variant keys) with no UOM among them [VERIFIED — `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/LineItemDTO.php:31-48`].
- `pos_receipt_lines.unit` **is** a snapshot column (`string('unit', 20)->default('unit')`, `2026_01_08_190638_…:45`), but the **fiscal projector writes the hardcoded literal `'pc'` into it for every v3+ receipt** (`PosCoreReceiptProjection.php:1193`), i.e. for every receipt this register will show at launch. Only the legacy `ReceiptCreationService` path snapshots a real symbol (`$sellableUnit = $product->unitOfMeasure->symbol ?? $product->unit ?? 'pc'`, `ReceiptCreationService.php:213,223,340`).
- So `unit` is the same trap as `pos_receipts.return_reason`: a real column filled with a constant. Emitting it would present `'pc'` as the sold unit for a receipt of 2.5 kg. **Rendering the current `product.unitOfMeasure` instead is forbidden** — that is exactly the mutable-master-data leak this note exists to stop.
- **Consequence, accepted:** detail lines render `quantity` at `quantity_decimals` precision **without a unit symbol**. If a unit label is later required, it needs a **new snapshot contract** — a unit field added to the canonical `line_items[]` payload (fiscal event-schema change, separate approval) **and** the projector writing it instead of `'pc'` — not a resource change. Filed owner-visible as **OI-17**.

BT-6's key-set equality assertion is against the **wire** names above, not the column names — and it must now fail on the presence of `product_sku` or `unit_of_measure_code`.

**(v) Lineage** — S-5, allowlisted at this depth too (this is where r1 would have leaked):
- On an original: `return_receipts[]` each = `{ id, receipt_number, posted_at, invoice_type_code, total, currency, refund_reason, refund_reason_source, refund_destination }`.
- On a refund/void: `original_receipt` = `{ id, receipt_number, posted_at, total, currency }` or `null`, plus the flat `original_receipt_id`.

**⚠️ r3 (gate r2 defect N-2):** `refund_reason_source` was missing from the lineage row while §4.5 and BT-17 require it everywhere `refund_reason` is emitted. Rule, binding: **`refund_reason` never travels without `refund_reason_source`** — at any depth, on any payload. A UI that renders a reason without knowing whether it is canonical free text or the projector's constant `"other"` is the exact defect S-12 exists to prevent.

**Money and quantity are emitted as decimal STRINGS** at the resolved scale (`CurrencyScale::bcformatStrict` with the receipt's own `currency`; `QuantityScale` for quantities) — never floats, never `number_format` (CLAUDE.md rule 19). The detail response is a **shape change** to `show()` and is declared as such in §4.1 (r1 wrongly filed it under "none changes an existing response shape" — gate r1 finding 9); the `index` envelope is **not** changed.

---

### (c) `/pos/receipts/refunds` — refunds & voids register

**Separate page, not a filter preset on (a).** Owner ruling: refunds are listed **separately** (`OWNER-DECISIONS…:59`). Rendered as a sibling route with a tab strip shared with (a) (`Receipts` | `Refunds & voids`) so the relationship is legible without merging the two lists.

**Gate:** `RequirePermission permission="pos.view_receipts"` (same data, same backend gate).

#### 3.c.1 Capability state is the content at launch (`R14 §3.1`)

At launch the register is **structurally empty by design**: `pos_terminals.v4_refund_authoring_enabled` ships `default(false)` (`2026_07_31_930000_…:36`) and on a v3-from-birth terminal the legacy `/return` path is structurally unavailable (`DisableV4RefundAuthoringCommand.php:95`); the standing E-7 interim NO-REFUNDS prohibition also applies (`R14 §0 fact 2`, `§1.3`).

So the page renders a **server-derived capability banner** with exactly three honest states, per terminal in scope:

| State | Condition | Copy intent |
|---|---|---|
| **Not enabled** | `v4_refund_authoring_enabled = false` | *"Refunds are not enabled on this terminal — the device cannot process one."* Source text: `DisableV4RefundAuthoringCommand.php:95`. **Not** "no refunds today". |
| **Enabled, awaiting device acknowledgement** | `enabled = true`, `acknowledged_at = null` | Names the two-phase handshake (`EnableV4RefundAuthoringCommand.php:48`; `POST /pos/terminals/{id}/acknowledge-v4-refund-authoring`, `routes.php:82-85`) |
| **Active since {date}** | `acknowledged_at != null` | Live register below |

**OP-20 RESOLVED [VERIFIED 2026-08-11]:** `TerminalResource` **does** emit both fields — `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:134-135` (`'v4_refund_authoring_enabled' => (bool) …`, `'v4_refund_authoring_acknowledged_at' => …?->toISOString()`). The `SUSPECTED not` marker in `R14 §3.1 A-5` / `OP-20` is **superseded**. However the resource is reachable only via `TerminalController`, whose **collection** endpoint `index()` requires `Gate::authorize('pos.manage_terminals')` (`TerminalController.php:45-65`) — which the proposed accountant grant does not include. (**r2 correction, gate r1 finding 13:** r1 said "every read action"; that is false — `show()` accepts manage **or** operate `:68-90`, and `available()`/`findByDevice()` use `pos.operate_terminal` `:308-310,496-498`. The collection gate alone carries the argument.) **Therefore the banner consumes S-7, not `/pos/terminals`** (§4.2 GATE-2).

**Do NOT build an "authorization-attempt" view** (`R14 §3.1`): on a v3-from-birth terminal the refusal is structural and fails closed with no fiscal event and no cash movement — there is nothing server-side to list, and instrumenting one would build for a temporary state.

#### 3.c.2 Live state (spec'd now, ships dark until refund-enable)

Columns: refund receipt # · date/time · type badge (**REFUND vs VOID separated** — only `invoice_type_code` separates them, `R12 §2.2`) · **`original_receipt_number`** (link) · **`refund_reason`** (rendered per its **`refund_reason_source`**, §4.5) · **`refund_destination`** · location/terminal/cashier · amount (per-row magnitude) · `refund_policy_alerts` indicator. All five refund fields are on the frozen list-row allowlist §3.b.5(i) (**r3, defect N-2** — r2's allowlist omitted two of them).

**Three of those columns do not exist on the wire today — they are S-12, not free (r2, gate r1 defect 2).** r1 promised them off the current index payload, which carries only `original_receipt_id` and the *legacy* `return_reason` enum:
- **`original_receipt_number`** — the index row has `original_receipt_id` (a UUID) only (`ReceiptController.php:113-119`); the number requires a join/eager-load back onto `pos_receipts`.
- **`refund_reason`** — the projector **deliberately writes the constant `ReturnReason::Other`** into the legacy `pos_receipts.return_reason` column, because that column is a strict enum while the canonical reason is free text; the authoritative human reason lives in `fiscal_events.payload.original_receipt_reference.refund_reason` (`PosCoreReceiptProjection.php:434-446`; `OriginalReceiptReferenceDTO.php:24-28`). **Rendering `return_reason` would show every v4 refund as "other".**
- **`refund_destination`** — has **no `pos_receipts` column at all**. It exists only in the canonical payload (`SaleReceiptPayload.php:107,225`; `FiscalPayloadConstraintValidator.php:393,2034`), where the launch domain is the single literal `'cash'` (`RefundReceiptV4Payload.ts:136,492`).

See **S-12** for the reporting projection that supplies all three (read via `CanonicalPayloadReader`, **never** by shipping `canonical_bytes` to the client), including its null/legacy semantics.
- `refund_policy_alerts` (JSONB, `2026_07_31_920000_…:13-20`) is written by the projector on every v4 refund and has **zero readers today** (`R14 §3.2`, gap `B-4`). **NULL means "no alerts", not "not evaluated"** — the UI must not render NULL as a warning. Ship as a small badge with a detail popover; wave 2.
- **Refunds are NEVER netted into any sales figure on any screen in this build** (owner ruling). If a refund-rate figure is shown in-window it must be presented as `refunds ÷ sales` with both raw numbers visible, and must carry the same convention label as §4.1 S-8.
- **Sign-era straddle:** v4 refunds store a POSITIVE `total` under `receipt_type='return'`; legacy returns stored NEGATIVE. Per-row magnitude, never a blanket flip (`R12 §2.2`). The safe pattern for any new aggregate is `ABS` **inside** the `SUM`, per row — `OwnerSalesSummaryService::aggregate()` (`:99-115`); an outer `ABS(SUM(...))` lets the eras cancel (`R14 §3.3`).
- **Refund destination is CASH only** at launch; a refund of a non-cash-tendered original is refused (`R14 §1.3`). Show the destination; do not offer a filter for destinations that cannot exist.

#### 3.c.3 Refund-enable dependencies to display, not to fix here

- **E1-1 / `OP-03`** — POS refunds are **not netted out of the TN VAT declaration** (`EloquentVatDataRepository.php:68-95` has no `receipt_type` discrimination, unlike the credit-note arm at `:51-52`). Overstates output VAT from the first refund onward (`R14 §3.4`). **Not fixed here**; the spec records it as a refund-enable (E1) blocker.
- **E1-3 / `OP-05`** — the refund-rounding delta (bounded by `D/2` = 0.025 TND) must be explicable on screen at refund-enable; *"the cashier has nothing on the avoir to point at"* (`cash-rounding-phase2-deploy-checklist.md:218-221`). Reserve a labelled slot in the refund detail panel; populate at enable-time.
- **C-16** — `GrandtotalService`'s `refunds_count` / `refunds_amount` actually count **VOIDS**, not returns, and are deliberately not renamed because the names live inside signed bytes (`GrandtotalService.php:138-141`). **Never surface those two fields to a user under those labels** (`R14 §4 C-16`).

---

### (d) Receipt-chain verification — panel, not a page

Wire `POST /pos/reports/receipts/verify-chain` (`ReportController.php:411`, `Gate::authorize('pos.view_reports')`), which is **implemented, gated and has zero web callers** (`R12 §2.6`, `R14 §C-7` — *"highest value-per-line in the lane"*).

**Placement:** a panel on `/pos/receipts` (terminal selector + **Verify chain** button + result), mirroring how `ZReportListPage.tsx:44-60` wires the Z-chain verify. Rendered only when the viewer holds `pos.view_reports` (the list itself only needs `pos.view_receipts`) — see §4.2 GATE-4. `R12 §9 Q8` left placement open; this spec rules **on the receipts list**, because the sibling Z-chain verify already lives on the Z list and the two then mirror each other.

**Honest-verification limitation — r1's assessment was WRONG; the gate's is adopted (r2, gate r1 defect 3 / finding 5).**

- `ES-07` (`docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md:68`) is scoped to the **CLI** `pos:verify-chains` (`VerifyPosChainCommand.php:257,293-330,341,372-399`), which excludes fiscal-era rows via `whereNull('fiscal_event_id')` and is a false green light. Its fix is Lane A0 of the fixes session (`HANDOVER-event-sourcing-remediation-2026-08-11.md:50,119`) — **not this lane** (NG-10).
- **The HTTP endpoint is a different code path, but its verdict is ALSO not trustworthy.** `is_valid` comes from `ReceiptHashService::verifyTerminalChain` (`ReceiptHashService.php:206-213`), whose fiscal arm selects **all** terminal fiscal events ordered only by `sequence_number`, with **no `company_id`, no event-type and no `chain_context` predicate** (`ReceiptHashService.php:234-250`). Fiscal chains are independently keyed by `(tenant, company, terminal, chain_context, sequence)` (`2026_05_14_100001_create_fiscal_events_table.php:37-42,91-95`) across **four legal contexts** (`FiscalEventEnvelope.php:61-67`), and the canonical ingestor resolves the prior head **per `chain_context`** (`OutboxIngestor.php:172-181`). Flattening four independently-sequenced chains into one walk produces **both false negatives and false positives**.
- It also omits the A0-required `pos_receipts.fiscal_hash ↔ fiscal_events.current_hash` **mirror check**, which the controlling addendum states is absent (`docs/handoff/ES-REGISTER-CORRECTIONS-2026-08-11.md:17-25`).
- The diagnostics are legacy-shaped on top of that: `chain_length` / `first_receipt` / `last_receipt` are counted over `pos_receipts` with `is_voided = false AND is_training = false`, and `broken_at_sequence` is located by re-walking `pos_receipts.previous_hash` + `calculateHash(...)` — from DB columns, not from `canonical_bytes` (`ReportController.php:441-489`).
- ~~r1 claimed "the boolean **is** fiscal-aware".~~ **Retracted.** It reads fiscal rows, which is not the same as being a correct fiscal verdict.

**Therefore, binding (r2):**
1. **Screen (d) is a HARD DEPENDENCY on Lane A0.** It is not built, not merged and not shipped until A0 has landed a verifier that (i) predicates on company + event type + `chain_context`, walking each context as its own chain, and (ii) performs the projection↔event mirror check.
2. **The "ship earlier with captions" option from r1 is DELETED.** A caption cannot convert a false negative or a false positive into an honest control; presenting this verdict to an accountant as a chain assurance is a compliance misstatement, not a UX shortfall.
3. After A0 lands, the builder **re-opens the endpoint and re-verifies its actual post-fix response shape** before freezing panel copy, i18n keys or tests. Nothing in this spec's current-source analysis of `ReportController::verifyChain` may be treated as the post-A0 contract (see §10 residual risk).
4. `chain_length` remains captioned as "receipts counted (excludes voided and training)" — it is a `pos_receipts` count, not a fiscal-event count — and `broken_at_sequence` is rendered only if A0 makes it fiscal-authoritative, otherwise omitted.
5. **Do not implement the verifier fix in this lane** (NG-10). If A0 slips, wave 2 ships **without** (d) and (d) becomes its own follow-up; that is the accepted trade.

---

## 4. Backend changes, permissions and deploy

### 4.1 Backend changes — additive **except S-4/S-5**, which intentionally reshape `show()`

**r1's heading was false (gate r1 finding 9) and is corrected here.** S-1/S-2/S-3/S-6/S-7/S-9/S-11/S-12/S-13 are additive. **S-4 and S-5 are a deliberate, breaking-by-design response-shape change** to `GET /pos/receipts/{id}`: they replace `$receipt->toArray()` with an allowlisted resource (§3.b.5) and **remove the currently-present `canonical_bytes` field** (`ReceiptController.php:494-508`; `Receipt.php:194-229` exposes it). That is the point of the change, not a side effect. Known consumers of `show()`: none in `apps/web` today (the page is being built by this spec) — the builder must re-confirm with a repo-wide search for `pos/receipts/` before landing, and the device (`apps/pos`) must be confirmed **not** to consume this endpoint (it authors receipts, it does not read them back through the web API).

The `GET /pos/receipts` **index** envelope is NOT changed (BT-5 guards it).

Enumerated from `R12 §6` (S-1…S-8 there) plus S-7 (spec authoring) and S-11…S-13 (gate r1).

| # | Change | Where | Wave | Effort |
|---|---|---|---|---|
| **S-1** | `location_ids[]` filter **and** `location_id` + `location_name` per row on `GET /pos/receipts` | `ReceiptController.php:69-105` (filters), `:110-133` (row map) | 1 | S |
| **S-2** | `invoice_type_codes[]` **array** filter (default `['SALE']`, `+TRAINING` iff opted in; the refunds register passes `['REFUND','VOID']` — §3.a "Disjoint registers", r2) + per-row `invoice_type_code`; `training_flag` filter with **default EXCLUDE** + per-row field (`include_training=true` opts in) | same | 1 | S — the fiscal-correctness fix of `R12 §4.1` / `R14 §C-3` |
| **S-3** | `fiscal_status` filter + per-row field | same | 1 | S (`R14 §A-6`, `§C-6`) |
| **S-4** | `quantity_decimals` on `GET /pos/receipts/{id}` lines **and recursive removal of `canonical_bytes`**. Form: an allowlisted `ReceiptResource` (+ line/payment/VAT/lineage sub-resources) replacing raw `toArray()`, **exactly per §3.b.5**; copy the quantity enrichment from `ShiftController.php:305-330` — which requires **widening the eager-load from `lines.product` to `lines.product.unitOfMeasure`** [VERIFIED 2026-08-11 — `show()` loads only `lines.product` today, `ReceiptController.php:475-486`] and then unsetting the relation before serialisation, per the same precedent. **Every other line value comes from the line's own snapshot columns (r4, §3.b.5 field-source map).** **Response-shape change — see §4.1 preamble** | `ReceiptController::show` `:469-509` | 2 | S–M |
| **S-5** | Surface `returnReceipts` on `show()` (currently eager-loaded then `unset` at `:504`) **and eager-load `originalReceipt`** (not loaded today, `:475-486`) → both lineage directions, allowlisted per §3.b.5(v), enriched with the S-12 refund reporting fields (**r3:** `refund_reason` + `refund_reason_source` + `refund_destination` — r2 called this a "triple", which is now four fields on the row and five on the list row) | `ReceiptController.php:483-505` | 2 | S |
| **S-6** | Index migration — **exact DDL below**, no invention | `apps/api/database/migrations/tenant/` | 1 | S |
| **S-7** | **NEW (this spec).** `GET /pos/receipts/filter-options` — **full contract frozen below**, no invention | new action on `ReceiptController` (or a thin `ReceiptFilterOptionsController`) | 1 | S–M |
| **S-8** | Totals strip endpoint — same filter contract as `index`, returning `receipt_count, gross, refunds, average_ticket`. `ABS` **inside** the `SUM` per row (`OwnerSalesSummaryService::aggregate()` `:99-115`); `training_flag = false`; `CurrencyScale::bcformatStrict` at the boundary; response **declares the returns convention** | new action / `ReceiptSummaryService` | **3 — BLOCKED on OI-3** | M |
| **S-9** | Seeder: grant the accountant role the A-2 permission set (§4.2) **AND regenerate the CI-enforced frontend permission map** — `php artisan permissions:export-frontend-map`, committing `apps/web/src/hooks/permissionsMap.generated.ts` (`ExportFrontendPermissionsMap.php:12-40`; drift is a hard preflight failure, `scripts/preflight.sh:137-161`). r1 omitted this and would have red-lit CI (gate r1 defect 7) | `RolesAndPermissionsSeeder.php` accountant block + generated map | 1 | S + deploy steps |
| ~~S-10~~ | `sort`/`direction` params (today fixed `posted_at DESC`, `:107`) | — | **deferred** | — |
| **S-11** | **NEW (r2, gate r1 defect 4).** Membership **allowed-location authorization** on the entire receipt read surface — index, show, both PDF actions, S-7, and the (d) terminal selector. Full contract in **§4.4** | `ReceiptController` (`:66-69,473-488,594-599,627-632`), S-7 action, `ReportController::verifyChain` | 1 (index/S-7) + 2 (show/PDF/(d)) | M |
| **S-12** | **NEW (r2, gate r1 defect 2).** Refund **reporting projection**: supply `original_receipt_number`, authoritative free-text `refund_reason`, and `refund_destination` on refund/void rows (list + lineage). Full contract in **§4.5** | `ReceiptController` index/show + a `RefundReportingEnricher` reading via `CanonicalPayloadReader` | 2 | M |
| **S-13** | **NEW (r2, gate r1 defect 10).** **FormRequest validation + calendar-date→timestamp contract** for every receipt filter: UUID arrays, enum filters, booleans, date ordering, bounded pagination, and company-timezone date boundaries. Full contract in **§4.6** | new `IndexReceiptsRequest` / `ReceiptFilterOptionsRequest` | 1 | S–M |

**In-scope backend changes: 12 (S-1…S-9, S-11…S-13), of which S-8 is wave-3 and owner-blocked.** Wave 1 backend = S-1, S-2, S-3, S-6, S-7, S-9, S-13 + the index/S-7 half of S-11 (8). Wave 2 backend = S-4, S-5, S-12 + the show/PDF/(d) half of S-11 (4).

#### S-6 — exact index DDL (binding; r2, gate r1 defect 14)

r1 said "`(company_id, location_id, posted_at)` + a partial index `WHERE training_flag = false`" and left the second index's key columns, names and concurrency to invention. Frozen:

```sql
-- 1. canonical back-office scan: company + location scope, newest first
CREATE INDEX IF NOT EXISTS pos_receipts_company_location_posted_at_idx
    ON pos_receipts (company_id, location_id, posted_at DESC);

-- 2. production-only scan (the default register: training excluded)
CREATE INDEX IF NOT EXISTS pos_receipts_company_posted_at_production_idx
    ON pos_receipts (company_id, posted_at DESC)
    WHERE training_flag = false;
```

- Both names are **explicit** (Laravel's auto-generated names are not acceptable here — the `down()` must drop by name).
- `location_id` is `NOT NULL` (`create_pos_receipts_table.php:29-31`), so index 1 needs no `NULLS` handling.
- `down()`: `DROP INDEX IF EXISTS` for each, by the exact names above. Idempotent both directions.
- **`CONCURRENTLY` is NOT mandatory** at first-tenant volume (`R12 §7`). If the implementer chooses it, the migration **must** set `public $withinTransaction = false;` — `CREATE INDEX CONCURRENTLY` cannot run inside Laravel's wrapping transaction — and must then use raw `DB::statement`, since a failed concurrent build leaves an INVALID index that the re-run must drop first. Default choice: **plain `CREATE INDEX IF NOT EXISTS`**, which is self-guarding and safe for the auto-`tenants:migrate` deploy (§4.3 note 1).
- Verification is **structural** (BT-16: the two named indexes exist with the stated columns/predicate after migrate). BT-10's `EXPLAIN` assertion stays **advisory** — planner choice on a small fixture table is not a contract.

#### S-7 — frozen endpoint contract (binding; r2, gate r1 defect 9)

| Aspect | Ruling |
|---|---|
| Route | `Route::get('/receipts/filter-options', …)` registered **BEFORE** `/receipts/{id}` in `apps/api/app/Modules/POS/routes.php` (the file already orders collection routes before `/{id}`, `:143-172`). A literal segment registered after the wildcard is captured by it. |
| Gate | `Gate::authorize('pos.view_receipts')` — the same gate as index, deliberately **not** `pos.manage_terminals` (GATE-2). |
| Request validation | `ReceiptFilterOptionsRequest`: `location_ids` `array` / `location_ids.*` `uuid`; `from_date`/`to_date` `date` + `to_date >= from_date` (same rules and timezone conversion as §4.6). No other params. |
| "In scope" (was undefined in r1) | = current company **∩** the caller's allowed locations (**S-11** / §4.4) **∩** the validated `location_ids[]` when supplied. An empty intersection returns `200` with empty arrays — **never 403, never all-locations**. |
| Terminal lifecycle | Return **all** terminals in scope regardless of `is_active`/archived state, each with an `is_active` boolean, so a filter over historical receipts can still name the terminal that produced them. Filtering to active-only is a **client** concern. |
| Terminal shape | `{ id, code, name, is_active, v4_refund_authoring_enabled, v4_refund_authoring_acknowledged_at }` (the last two per `TerminalResource.php:124-136`). |
| Cashier source | **Snapshot identity from the receipts themselves**, not the current user directory: `SELECT DISTINCT cashier_id, cashier_name FROM pos_receipts` within scope. A renamed or deactivated user still appears exactly as the receipts record them. Shape `{ id, name }`. |
| Cashier dedup | By `cashier_id`; when one `cashier_id` has several historical `cashier_name` snapshots, take the name from the **most recent `posted_at`** in scope. |
| Ordering | Terminals by `code ASC`; cashiers by `name ASC`, then `id ASC` as the tiebreak. **Deterministic, no DB-default ordering.** |
| Response envelope | `{"data": {"terminals": [...], "cashiers": [...]}}` — single-nested (`apiGet` unwraps once). It does **not** copy index's double nesting; it is a new endpoint, so it follows the house convention. |
| Training | The option lists are **not** filtered by `training_flag` — a terminal/cashier that only produced training receipts still appears. |

**Do not change** (`R12 §4.8`, NG-4): the double-nested `{data:{data,meta}}` envelope of `index`; the three 410 tombstones; the device-only `/return` route.

### 4.2 Permissions and gating

| ID | Item | Decision |
|---|---|---|
| **GATE-1** | `/pos/receipts`, `/pos/receipts/:id`, `/pos/receipts/refunds` | `RequirePermission permission="pos.view_receipts"` — matches the backend `Gate::authorize('pos.view_receipts')` at `ReceiptController.php:64`. **Never `moduleKey="pos"`** (`R14 §5.3 item 2`). |
| **GATE-2** | Terminal/cashier filter options + refunds capability banner | Consume **S-7** (`pos.view_receipts`), **not** `GET /pos/terminals`. **r1's justification was overbroad and is corrected (gate r1 finding 13):** it is **not** true that "every `TerminalController` read action" requires `pos.manage_terminals` — `show()` accepts manage **or** operate (`TerminalController.php:68-90`), and `available()`/`findByDevice()` use `pos.operate_terminal` (`:308-310,496-498`); r1's `:100` citation is a **write** action. What is true, and sufficient: the **collection** endpoint `index()` requires `pos.manage_terminals` (`TerminalController.php:45-65`), which the A-2 accountant grant deliberately excludes — so `/pos/terminals` would 403 for that persona (`ZReportListPage.tsx:32-35` fetches it directly and is the precedent). S-7 stands as the least-privilege collection endpoint. |
| **GATE-3** | Sidebar entry | ⚠️ **[VERIFIED 2026-08-11] a sidebar `permission:` value that is not a key of `MODULE_PERMISSIONS` fails OPEN**: `canAccessModule` returns `true` when the key is absent (`apps/web/src/hooks/usePermissions.ts:129-134`), and `Sidebar.tsx:432` gates purely through `canAccessModule`. Precedent bug: `permission: 'goods-receipt.create-standalone'` (`Sidebar.tsx:182`) is absent from the map and therefore ungated. **Required:** add identity entries `'pos.view_receipts': ['pos.view_receipts']` and `'pos.view_reports': ['pos.view_reports']` to `MODULE_PERMISSIONS` (the file already uses that idiom — `'reports.financial': ['reports.financial']`, `'ledger.view': ['ledger.view']`, `usePermissions.ts:46-49`) and reference `'pos.view_receipts'` from the new sidebar item. Reusing the existing `permission: 'pos'` alias would show the entry to anyone holding `pos.operate_terminal` **or** `pos.manage_terminals` — `canAccessModule` is `hasAnyPermission` (OR), `usePermissions.ts:131-134`. |
| **GATE-5** | **NEW (r2, gate r1 defect 7).** POS sidebar group least-privilege audit | Granting the accountant `pos.view_receipts` makes the **broad `pos` alias pass** — `MODULE_PERMISSIONS.pos = ['pos.operate_terminal','pos.manage_terminals','pos.view_receipts']` with OR semantics (`usePermissions.ts:56,131-134`). The POS **parent** and **every existing child** are keyed `permission: 'pos'` (`Sidebar.tsx:227-243`), while their routes require narrower permissions: `/pos/terminals` → `pos.manage_terminals`, `/pos/shift-history` → `pos.manage_shifts`, `/pos/z-reports`, `/pos/analytics` → `pos.view_reports`, `/pos/orders` → `pos.operate_terminal` (`routes/index.tsx:2870-2939`). **Net effect if unfixed: the accountant sees a POS menu of ~7 links, most of which bounce.** **Required:** re-key each POS child to its **exact route permission**, per the frozen child→route table in **§4.2.1** (**r3, gate r2 defect N-3** — r2 keyed Tables to `pos.operate_terminal`, but its route requires `pos.manage_tables`; that mismatch would have preserved the exact visible-link/denied-route bug GATE-5 exists to remove). Add the identity entries listed there to `MODULE_PERMISSIONS`, and leave the **parent** on the `pos` alias (a group header with zero visible children must not render — assert this). Voucher **routes** keep `moduleKey="pos"`; leave them as-is but cover them in the parity test. Locked by FT-14/FT-15 (sidebar↔route parity for accountant and cashier). ⚠️ This changes what **existing** roles see in the POS menu — flagged owner-visible as **OI-14**. |
| **GATE-4** | Chain-verify panel (d) | Rendered only under `pos.view_reports` (backend gate at `ReportController.php:413`). The list must degrade cleanly without it. |
| **A-1** | **`/settings/compliance/export` re-gate — APPROVED, launch-required** (`OWNER-DECISIONS…:63`; `OP-02`; `R14 §C-5`) | [VERIFIED 2026-08-11 — `apps/web/src/routes/index.tsx` `path="compliance/export"` is `<RequirePermission moduleKey="pos">`]. `MODULE_PERMISSIONS.pos = ['pos.operate_terminal','pos.manage_terminals','pos.view_receipts']` (`usePermissions.ts:56`), none of which the accountant holds — so the accountant is bounced from the page hosting their **own** `compliance.*` grants. **r2 correction (gate r1 defect 8): a single `compliance.view_reprint_log` gate is too narrow.** The page renders **three** panels unconditionally (`ComplianceExportPage.tsx:8-20`) backed by **three separately gated** endpoints — `can:compliance.export_jet`, `can:compliance.verify_chains`, `can:compliance.view_reprint_log` (`Compliance/Presentation/routes.php:59-71`). **Ruling (a) of the gate's two options is adopted:**
1. Route gate = **any-of** the three: `<RequirePermission permissions={['compliance.export_jet','compliance.verify_chains','compliance.view_reprint_log']}>`. If the component's current props do not support an any-of list, extending it is in scope for this lane (and must be unit-tested); a single-permission fallback of `compliance.view_reprint_log` is **not** acceptable, because a role holding only `export_jet` would be bounced from its own capability.
2. **Each of the three panels is individually gated** by its own permission, so a partial holder sees only what they can actually call and never a panel that 403s.
3. **Navigation entry (r1 omitted it entirely; direct-URL acceptance does not satisfy U-6). ⚠️ Frozen in r3 — gate r2 defect N-4.** r2 asked for "the same any-of gate" on a **"Settings → Compliance" sidebar group**. Neither part was implementable as written, and both are now pinned against source:

   - **There is no Settings → Compliance group.** The Settings entry is a single bottom-section **leaf** — `{ key: 'settings', href: '/settings', icon: Settings, permission: 'settings', section: 'bottom' }` with **no `children`** [VERIFIED 2026-08-11 — `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:361-367`].
   - **Nesting under it would not work anyway.** `MODULE_PERMISSIONS.settings = ['settings.view']` (`usePermissions.ts:53`) and `settings.view` is seeded to **admin/manager/viewer only — not accountant** (`permissionsMap.generated.ts:249`). The parent is filtered **before** its children (`Sidebar.tsx:445-455` filters modules first, then maps over children), so an accountant-eligible child hung under that parent would never render.
   - **A sidebar item cannot express any-of directly** — `NavItem`/`NavChild` carry a single `permission?: string` (`Sidebar.tsx:110-122`) resolved through `canAccessModule` (`Sidebar.tsx:425-437`). **But it does not need to:** `canAccessModule` is `hasAnyPermission` over the mapped list (`usePermissions.ts:127-134`), so **one composite `MODULE_PERMISSIONS` key mapping to all three permissions is exactly the any-of gate**, with no component change.

   **Binding resolution — option (i), composite key + top-level sibling entry:**
   - Add `'compliance': ['compliance.export_jet','compliance.verify_chains','compliance.view_reprint_log']` to `MODULE_PERMISSIONS` (all three exist in `permissionsMap.generated.ts:35-37`; there is no `'compliance'` key today, so nothing is shadowed). This key mirrors the route's any-of gate of item 1 **exactly** — one source of truth, two consumers.
   - Add a **top-level bottom-section leaf**, a sibling of `settings` (immediately above it), **not a child of it**:
     `{ key: 'complianceExport', labelKey: 'common:navigation.complianceExport', href: '/settings/compliance/export', icon: FileCheck, permission: 'compliance', section: 'bottom' }` — the `labelKey` idiom and `section: 'bottom'` both already exist on the `supportAccess` entry (`Sidebar.tsx:353-360`), and `FileCheck` is already imported (used by `zReports`).
   - Label key `common:navigation.complianceExport` (EN "Compliance export" / FR "Export de conformité") — unchanged from r2.
   - Extending `NavItem` to accept a `permissions[]` array is **explicitly NOT in scope**: it would touch every consumer of the nav type for no behavioural gain over the composite key.
   - The IA consequence (a new top-level bottom-section entry rather than a Settings sub-item) is **owner-visible — OI-16**.
4. `RequirePermission permission=` takes a **raw** permission and does **not** consult `MODULE_PERMISSIONS` — **verified, no longer an open question** (`RequirePermission.tsx:37-63`; only `moduleKey` calls `canAccessModule`). r1's "the implementer must confirm which prop resolves through" is deleted; OI-5 is CLOSED. A `MODULE_PERMISSIONS` entry is therefore needed **only** for the sidebar key, which item 3 uses — **r3 (defect N-4): that entry is the single composite `'compliance'` key defined in item 3, NOT the three separate identity keys r2 asked for.** Three identity keys cannot express any-of on an item that carries one `permission:` string; the composite key can, and does. A sidebar `permission:` absent from the map **fails open** (GATE-3), so the composite key must be added in the same change as the nav item.
While here, note but **do not fix** the sibling `compliance/fraud-alerts` gated `moduleKey="settings"` (`OP` / `R14 §C-11`, owned by the OQ-10 Compliance-section work). |
| **A-2** | **Accountant POS permission scope — OPEN, owner confirms at read-through** (`OP-09`, `R14 §7 Q1`) | **Spec proposes: grant `accountant` exactly `pos.view_receipts` + `pos.view_reports`; do NOT grant `dashboard.owner`.** Rationale: `pos.view_receipts` unlocks the register + detail + refunds register (the VAT tie-out and the monthly refund review); `pos.view_reports` unlocks the chain-verify panel, matching the `compliance.verify_chains` grant the role already holds; `dashboard.owner` carries far more than POS (the entire `/reports` owner suite) and is a broader decision than this lane (`R14 §7 Q1` recommendation, adopted). Explicitly **not** granted: `pos.manage_terminals`, `pos.operate_terminal`, `pos.process_returns`, `pos.void_receipts`. ⚠️ **owner confirms at read-through.** |

#### 4.2.1 GATE-5 — frozen POS sidebar child → route permission map (binding; r3, gate r2 defect N-3)

Every row verified 2026-08-11 against `apps/web/src/routes/index.tsx`. A sidebar key that is **not** the route's own permission is the defect, in either direction.

| Sidebar child | Route gate (verified) | `permission:` key | `module:` |
|---|---|---|---|
| `posOrders` | `pos.operate_terminal` (`:2930-2938`) | `pos.operate_terminal` | — |
| `tables` | **`pos.manage_tables`** inside `ModuleGuard module="Tables"` (`:2940-2951`) | **`pos.manage_tables`** | `Tables` (already declared — keep) |
| `kitchen` | `pos.operate_terminal` inside `ModuleGuard module="Menu"` (`:3193-3205`) | `pos.operate_terminal` | `Menu` (already declared — keep) |
| `terminals` | `pos.manage_terminals` (`:2872-2878`) | `pos.manage_terminals` | — |
| `shiftHistory` | `pos.manage_shifts` (`:2883-2889`) | `pos.manage_shifts` | — |
| `zReports` | `pos.view_reports` (`:2894-2900`) | `pos.view_reports` | — |
| `analytics` | `pos.view_reports` (`:2915-2921`) | `pos.view_reports` | — |
| `vouchers` | `RequirePermission moduleKey="pos"` (`:3113-3132`) — any-of `MODULE_PERMISSIONS.pos` | **keep `pos`** — the alias **is** exact parity here | — |
| `receipts` (new) | `pos.view_receipts` (§3.a) | `pos.view_receipts` | — |

**Identity entries to add to `MODULE_PERMISSIONS`:** `'pos.view_receipts'`, `'pos.view_reports'`, `'pos.operate_terminal'`, `'pos.manage_terminals'`, `'pos.manage_shifts'`, **`'pos.manage_tables'`** — each mapping to the single like-named permission. All six exist in `permissionsMap.generated.ts:170-189`; a sidebar `permission:` missing from the map **fails open** (GATE-3), so the entries and the re-key must land in the same change.

**Anti-pattern to state in the build brief (`R14 §5.2`):** `<RequirePermission moduleKey="…">` is **not** a module gate — `canAccessModule` runs a *permission* test against `MODULE_PERMISSIONS`. Only `<ModuleGuard module="…">` / `hasModule()` consult `all_enabled_modules`. `docs/architecture/vertical-module-gating.md:146-155` glosses over this and will mislead an implementer. This confusion is the direct cause of the A-1 accountant lockout.

**Future module gating (NG-5):** when POS becomes a real module (`R14 §5.3 item 4`), these three routes inherit `ModuleGuard module="POS"` on the FE and `module:POS` on the POS route files. Leave a one-line comment on each route saying so; do **not** build it now.

### 4.3 Deploy notes

1. **Migration (S-6)** runs automatically: pushing to `origin/dev` auto-deploys and runs `tenants:migrate` on staging (CLAUDE.md rule 21 context / memory rule). The index migration must be **self-guarding** (`CREATE INDEX IF NOT EXISTS`, and idempotent on re-run) — no manual prerequisite.
2. **Per-tenant `RolesAndPermissionsSeeder` reseed** after S-9, then **`php artisan permission:cache-reset`** — the Spatie permission cache is **tenant-blind** (`R12 §6` deploy note; `R14 §5.3 item 3`; memory `project_spatie_permission_cache_tenant_blind.md`).
2b. **Frontend permission map regeneration is part of the COMMIT, not the deploy (r2, gate r1 defect 7).** Any change to `RolesAndPermissionsSeeder` must be followed by `(cd apps/api && php artisan permissions:export-frontend-map)` and the regenerated `apps/web/src/hooks/permissionsMap.generated.ts` committed in the same change. Preflight and CI fail the build on drift (`scripts/preflight.sh:137-161`), and the committed map currently excludes accountant from `pos.view_receipts`/`pos.view_reports` (`permissionsMap.generated.ts:188-189`) — a stale map means the FE fallback keeps denying the accountant even after the seeder runs.
3. ⚠️ **`syncPermissions` clobbers custom grants** — standing warning from `docs/superpowers/tickets/2026-08-07-reports-view-deprecation-sweep.md` (`R14 §5.3 item 3`). Any manually-granted permission on the target tenants must be re-checked after the reseed.
4. Verify the deploy by loading `/pos/receipts` **as the accountant user**, and `/settings/compliance/export` as the same user (A-1 acceptance).
5. **Index build cost:** `pos_receipts` on a live tenant — see the S-6 DDL block for the `CONCURRENTLY` ruling; at first-tenant volume this is not expected to bite (`R12 §7`).

### 4.4 S-11 — authorization model: company scope is NOT enough (binding; r2, gate r1 defect 4)

**The defect r1 shipped:** `ReceiptController` scopes receipts by **company only** — index (`:66-69`), show (`:473-488`), `downloadPdf` (`:594-599`), `streamPdf` (`:627-632`). But a `UserCompanyMembership` can carry a restricted `allowed_location_ids`, and the canonical helper `LocationContext::getAllowedLocationIds()` **fails closed** (empty array when no active membership; `null` = unrestricted) (`LocationContext.php:181-207`). A user restricted to location A can today read, and **PDF-reprint**, every receipt of location B in the same company by direct ID. Passing `useViewScope` IDs from the browser is a **view preference, not authorization**.

**Binding rules:**

1. **One resolver, injected** — `LocationContext` via constructor injection (never `app()`, CLAUDE.md rule 13). Semantics: `null` ⇒ unrestricted (no location predicate); `[]` ⇒ **no access** (deny/empty, never "all"); non-empty ⇒ `whereIn('location_id', $allowed)`.
2. **Effective scope = company ∩ allowed ∩ requested.** The client-supplied `location_ids[]` may only ever **narrow**. A requested location outside `allowed` is dropped silently for collections; it is never an error and never widens.
3. **Applied to all six paths**: `index`, `show`, `downloadPdf`, `streamPdf`, S-7 `filter-options`, and the (d) chain-verify **terminal selector** (a terminal whose receipts are outside the caller's locations must not be verifiable or even listed).
4. **Denial semantics — pinned:** missing **permission** ⇒ **403**. A resource that exists but is outside the caller's location scope ⇒ **404, non-enumerating** (identical body to a genuinely absent receipt), for `show`, `downloadPdf` and `streamPdf`. Rationale: a 403 on a same-company receipt confirms that receipt number exists to a user who may not know it. Collections simply omit the rows. **This is a spec ruling, not an owner decision** — it is pinned so no builder has to choose.
5. **PDF denial writes no audit row.** The location check must run **before** `receiptPrintAuditService->recordPrint(...)` (`ReceiptController.php:604-612,637-645`) — a denied request must not create a fiscal duplicate record (BT-13).
6. Tests: BT-12 (deny matrix) — same-company receipt outside scope returns 404 on `show`, `downloadPdf`, `streamPdf`; is absent from `index`; its terminal is absent from `filter-options` and rejected by verify-chain. Plus the unrestricted (`null`) and the no-membership (`[]`) cases.

### 4.5 S-12 — refund reporting fields (binding; r2, gate r1 defect 2)

Supplies the three refund columns §3.c.2 requires and the lineage block in §3.b.5(v). **Additive** — no existing field is removed or repurposed.

| Wire field | Source | Null / legacy behaviour |
|---|---|---|
| `original_receipt_number` | Join/eager-load `originalReceipt` on `pos_receipts.original_receipt_id` → `receipt_number` | `null` when `original_receipt_id` is null, or when the original is outside the caller's location scope (§4.4) — render "—", never a broken link |
| `refund_reason` | `fiscal_events.payload.original_receipt_reference.refund_reason` (free text), read through `CanonicalPayloadReader::forSaleReceipt()` → `OriginalReceiptReferenceDTO::refundReason` (`OriginalReceiptReferenceDTO.php:24-28`) | **Legacy (pre-v4) returns:** no fiscal event / no `original_receipt_reference` ⇒ fall back to the legacy `pos_receipts.return_reason` **enum label**, and mark the field `refund_reason_source: 'legacy_enum' \| 'canonical'` so the UI can avoid presenting "other" as a human reason. **Never** display the projector's constant `ReturnReason::Other` as if it were authored text (`PosCoreReceiptProjection.php:434-446`) |
| `refund_destination` | `fiscal_events.payload.refund_destination` (`SaleReceiptPayload.php:107,225`) — launch domain is the single literal `'cash'` (`FiscalPayloadConstraintValidator.php:393,415,2034`) | `null` for legacy returns, voids, and any row without a v4 payload. Render "—". **No destination filter** is offered (§3.c.2) |
| `refund_reason_source` | Derived by the enricher, not stored: `'canonical'` when the reason came from `OriginalReceiptReferenceDTO::refundReason`, `'legacy_enum'` when it came from the `pos_receipts.return_reason` fallback | **Always emitted wherever `refund_reason` is** (list row §3.b.5(i) and lineage §3.b.5(v)) — never optional, never inferred client-side. `'legacy_enum'` is the UI's signal not to present the value as authored text |

Mechanics, binding (**⚠️ rewritten in r3 — gate r2 defect N-2**; r2 simultaneously forbade a per-row `CanonicalPayloadReader` call *and* required per-row exception degradation, which is not implementable):

- Read the payload **server-side only**. `canonical_bytes` is never sent to the client (§3.b.5), and the enricher must not re-hydrate it into the response under any other key.
- **The forbidden thing is a per-row QUERY, not a per-row CALL.** `CanonicalPayloadReader::forSaleReceipt(FiscalEvent $event)` takes an **already-hydrated model** and issues no database work — it is pure CPU DTO construction [VERIFIED 2026-08-11 — `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:64-125`; the method body only reads `$event->event_type` / `$event->payload` and builds DTOs]. So the mechanic is: **one** `whereIn('id', $fiscalEventIds)` load of the page's events (the ids come from the `pos_receipts.fiscal_event_id` column already selected), keyed into a map, then **`forSaleReceipt()` is called per row against the in-memory model**. No bulk reader API needs to be invented, and none exists.
- **No N+1** is therefore assertable as a query count (BT-17): the enricher adds **one** query per page for events plus the S-5/`originalReceipt` eager-load, regardless of page size.
- Rows whose fiscal event is missing (no `fiscal_event_id`, or the id resolves to nothing in the bulk map), quarantined, has a `NULL` payload, **or fails typed-DTO construction anywhere inside the view** must **degrade to the legacy fallback**, never 500 the register.

  **⚠️ r4 (gate r3 defect R3-2) — the exception surface, restated exactly per source.** r3 wrote that `forSaleReceipt()` throws "in exactly two cases". **That is false**, and the reader's own docblock says so: it declares `@throws InvalidArgumentException when the event is not SALE_RECEIPT, the payload is null, **or sub-objects fail the typed-DTO constructor**` [VERIFIED 2026-08-11 — `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:55-62`]. What is actually reachable from a single `forSaleReceipt()` call:
  1. **The two early guards** — `event_type !== SALE_RECEIPT` and `payload === null` (`:65-77`). These are the *documented* cases, not the *exhaustive* ones.
  2. **`SaleReceiptPayload::fromArray()`** (`:79`) routes every key through `FiscalPayloadArrayGuards`, which throws `InvalidArgumentException` on a missing key or a wrong PHP type (`apps/api/app/Modules/Fiscal/Domain/DTOs/FiscalPayloadArrayGuards.php:38-51` and its six sibling guards — every `throw` in that class is an `InvalidArgumentException`, `:44,64,83,103,123,148,164`).
  3. **Every sub-DTO built in the same call** — `SellerDTO`, `BuyerDTO`, `OriginalReceiptReferenceDTO`, and one `LineItemDTO`/`PaymentDTO`/`VatBreakdownDTO`/`VoucherRedemptionDTO`/`OriginalLineReferenceDTO` per element (`:81-117`) — uses the same guards, plus its own shape checks (`SellerDTO.php:41`, `BuyerDTO.php:43,47`), all `InvalidArgumentException`.
  4. **One non-`InvalidArgumentException` path exists:** the list containers are only guaranteed to be *arrays* by `requireArray`; a **non-array element** inside `line_items` / `payments` / `vat_breakdown` / `vouchers_redeemed` / `original_line_references` fails the sub-DTO's `fromArray(array $data)` **signature**, raising a PHP `TypeError`. The reader acknowledges exactly this gap in its own `@phpstan-ignore` comment (`:114`, *"validated as list<object> by FiscalPayloadConstraintValidator"* — i.e. the reader trusts an upstream invariant it does not re-check).
  5. No other throw class is reachable from the reader's own code path [VERIFIED — the only `throw` statements under `app/Modules/Fiscal/Domain/DTOs/Canonical/` are in `SellerDTO.php`, `BuyerDTO.php` and `AccountChargeTermsDTO.php` (not on this path), and `FiscalPayloadArrayGuards` throws only `InvalidArgumentException`].

  **Binding consequence:** the enricher wraps the **entire** per-row `forSaleReceipt()` call — not a narrow guard around the two early checks — in `try { … } catch (\InvalidArgumentException|\TypeError $e) { log warning with fiscal_event_id + receipt_id; fall back to the legacy path of the §4.5 table }`. A malformed **line item** on an otherwise readable refund therefore degrades that one row's reason/destination instead of 500-ing the whole register: the reader constructs the *whole* view eagerly, so a fault anywhere in the payload reaches the caller even though the enricher only needs `original_receipt_reference` and `refund_destination`. **Do not** catch bare `\Throwable` — a DB/connection failure must still surface.
- Tests: BT-17 covers a v4 refund (canonical reason + `'cash'` destination + original number), a **legacy** return (enum fallback, null destination, `refund_reason_source='legacy_enum'`), a VOID, a refund whose fiscal event payload is NULL (degrades, no 500), and — **r4, R3-2** — a refund whose payload is present but has a **malformed typed sub-object** (e.g. a `line_items[0]` missing a required string key ⇒ `InvalidArgumentException`, and a `line_items[0]` that is a scalar instead of an object ⇒ `TypeError`): both degrade to the fallback with **HTTP 200** and a logged warning.

### 4.6 S-13 — filter validation and the calendar-date contract (binding; r2, gate r1 defect 10)

**The defect r1 shipped:** `ReceiptController::index` compares **raw request strings** and accepts an **unbounded `per_page`** (`:71-109`); `posted_at` is a **timezone-less** `timestamp` (`create_pos_receipts_table.php:47-53`) written from device event time (`PosCoreReceiptProjection.php:365-380`), while the FE "today" default comes from **browser local time** (`OwnerDashboardPage.tsx:30-45`). Different browser timezone ⇒ different "today" ⇒ a receipt near midnight silently in or out.

**Validation** — a real FormRequest (`IndexReceiptsRequest`), not inline string handling:

| Param | Rules |
|---|---|
| `location_ids` / `location_ids.*` | `array` / `uuid` (`Str::isUuid` before any `where` — a malformed UUID 500s on PG) |
| `terminal_id`, `cashier_id` | `uuid`, `nullable` |
| `invoice_type_codes` / `.*` | `array`, **`min:1`** / `in:SALE,TRAINING,REFUND,VOID`; default per §3.a |
| `fiscal_status` | `in:pending_seal,fiscalized,voided,pending_sync,synced,sync_failed` (`FiscalStatus.php:7-14`) |
| `include_training` | `boolean` (accept `true/false/1/0`), default **false** |

**Cross-field rule — the training axis (binding; r4, gate r3 defect R3-1).** `IndexReceiptsRequest` adds one cross-field check on top of the per-param table: **`TRAINING ∈ invoice_type_codes[]` requires `include_training=true`**, otherwise **422** on `invoice_type_codes` with the message key *"`invoice_type_codes` may include TRAINING only when `include_training=true`"*. Implement it in `withValidator()`/`after()` — no per-param rule can express it. Rationale and the **full combination table** (which pairs are 200 and which are 422, for every subset of the four codes × both toggle states) are frozen in §3.a "Training axis — precedence, exhaustive"; that table is the contract, this row is its validation half. Asserted by **BT-1**.
| `receipt_number` | `string`, `max:50`; escape `%`/`_` before the `LIKE` |
| `from_date` / `to_date` | `date_format:Y-m-d`; `to_date` `after_or_equal:from_date` |
| `per_page` | `integer`, `min:1`, **`max:100`**, default `25` |
| `page` | `integer`, `min:1` |

**Calendar date → timestamp bounds — pinned:**
- The conversion timezone is **`companies.timezone`** of the current company (`companies` migration `2025_11_30_104000_create_companies_table.php:61` — `string(50)`, **NOT NULL**; TN tenants seed `Africa/Tunis`, `2025_11_30_133000_migrate_tenant_data_to_companies.php:68`). **Never** the browser timezone, never `config('app.timezone')`, never the DB session timezone.
- Bounds are **half-open**: `posted_at >= from_date 00:00:00 (company tz → UTC)` and `posted_at < (to_date + 1 day) 00:00:00 (company tz → UTC)`. Half-open removes the `23:59:59` sub-second hole.
- The **client sends calendar dates** (`Y-m-d`), never instants; the server owns the conversion. The FE "today" default is computed from the company timezone exposed to the app, not `new Date()` local.
- The response echoes the resolved window (`meta.from`, `meta.to` as ISO instants) so the UI can caption the exact range it is showing.
- Tests: BT-18 — a receipt at `23:30` company-local on `to_date` is **included**; a receipt at `00:15` company-local on `to_date + 1` is **excluded**; both hold with the app timezone set to something different from the company timezone; plus a DST-transition day for any configured non-UTC-offset tenant timezone (`Africa/Tunis` has no DST — use `Europe/Paris` as the DST fixture, which is a configured-country timezone for FR tenants).

---

## 5. i18n

Rules: every user-facing string via `t()` (CLAUDE.md rule 11); EN + FR in this build; **AR runs as a parallel own-pace Codex lane** (`OWNER-DECISIONS…` OQ-3/OQ-A1: MSA, launch-critical namespaces first — `pos` is one).

**Reuse — `pos:receiptSearch.*` survived the deletion intact.** [VERIFIED 2026-08-11] EN and FR each carry **21 keys** with identical key sets: `title, description, receiptNumber, terminal, cashier, date, total, status, active, voided, searchPlaceholder, noReceipts, noReceiptsDescription, void, voidReceipt, voidConfirm, voidReason, voidReasonPlaceholder, voidSuccess, voidError, filters` (`apps/web/src/locales/{en,fr}/pos.json`). **AR has no `receiptSearch` block** [VERIFIED — absent from `locales/ar/pos.json`].

Actions:
1. **Rename the block `pos:receiptSearch` → `pos:receipts`** (`R12 §5(a)`, `§9 Q9` — ruled: do it now, while the only live consumer is being deleted anyway; see §6).
2. **Delete the 7 dead `void*` keys** (`void, voidReceipt, voidConfirm, voidReason, voidReasonPlaceholder, voidSuccess, voidError`) — the void route is a 410 tombstone (`R12 §2.1`). **14 keys carry over.**
3. **`pos:receiptSearch.voided`** is the one key with a live consumer, inside `ShiftReceiptsList.tsx:81` — migrate it into the new block **before** deleting that component (`R12 §5(d)`).
4. **`common:navigation.receipts` already exists** (EN `:253` "Receipts", FR `:194` "Tickets") but is the wrong label here — add a distinct nav key rendering **"POS Receipts" / "Tickets de caisse"** (§3.0, `R12 §3.1`).
5. **New keys needed** (EN + FR), grouped: type badges (`SALE/REFUND/VOID/TRAINING`); `fiscal_status` labels (6 values, `FiscalStatus.php:7-14`); training include-toggle + row badge; the three empty states; the reprint confirm dialog title/body/confirm/cancel (§3.b.1); line-table headers incl. **"Unit price (incl. VAT)"** / **"Line net"** (§3.b.4); VAT-breakdown, payments, refund-lineage and fiscal-provenance section headers; the three refund capability-banner states (§3.c.1); the refunds-register column headers incl. **original receipt #**, **refund reason**, **refund destination** (§3.c.2 / S-12) and the "—" placeholders for their legacy/null cases; the **register tab strip** labels ("Receipts" | "Refunds & voids"); the **top-level Compliance-export** nav key `common:navigation.complianceExport` (A-1 item 3 — **r3, defect N-4:** it is a bottom-section sibling of Settings, not a "Settings → Compliance" child, because no such group exists); the date-range caption echoing the resolved company-timezone window (§4.6). **No type-tab labels** are needed on (a) any more (r2 removed the tabs). Chain-verify panel labels are deferred with the panel to **wave 2b** — do not author them before the post-A0 contract is known (§3.d).
6. AR lane receives the same key list as a handover note; this build does not block on it.
7. **`POSTransactions.tsx:43-47`** copy — see §6.

---

## 6. Cleanup (in scope)

| # | Item | Action |
|---|---|---|
| CL-1 | `features/pos/components/ShiftReceiptsList.tsx` (120 lines) + its export at `components/index.ts:4` [VERIFIED — file and export line present] | **DELETE.** Zero page imports; legacy `DataTable` markup-passthrough form; `new Date(iso).toLocaleString()`; raw `{total} {currency}` concat instead of `formatCurrency`; prop-driven with no pagination; and a **row-level reprint button** (`:96-114`) that is a fiscal hazard (`OP-16`). Migrate `pos:receiptSearch.voided` first (§5.3). (`R12 §3.3`, `§5(d)`) |
| CL-2 | `getShiftReceipts` (`api/shiftApi.ts:152-156`) | **KEEP, unused.** It is the only accessor for the shift↔receipt heuristic and the natural backend for a future shift-detail tab (`R12 §5(d)`, gap `B-3`). Add a `@see` pointer to the new receipts list. |
| CL-3 | `LedgerHistoryTable.tsx:108` and `ProvenanceSection.tsx:28` | **Self-heal** once `/pos/receipts/:id` is registered — no code change. **Must be proven by test** (§7.3), not assumed. |
| CL-4 | `ProvenanceSection.test.tsx:32` | Today it asserts an href that resolves to `/dashboard` — the test passes on a dead link (`R12 §3.2`). **Strengthen** to assert the rendered destination/navigation, not just the href string. |
| CL-5 | `POSTransactions.tsx:43-47` — *"From the web you can browse receipts […] from the shop-management screens"* | The sentence has been **false since `973834a13`** (`R12 §1`). Update the copy to name and link `/pos/receipts` explicitly (translated). Do **not** delete or redirect `/pos/transactions` — that is `OQ`/`10-verification…` territory (`R12 §9 Q10`). |
| CL-6 | `routes/index.tsx:2925-2929` quarantine comment | Replace with the route + a one-line note that the **write** surface stays retired (NG-4). |
| CL-7 | `docs/superpowers/tickets/2026-08-01-positive-refund-total-consumers.md` | 🎫 **Close it** — remediated by `032e89dea` + `253e564b7` (`R12 §4.4`, `R14 §3.3`, `OP-01`). Still cited as an open pre-enable gate in the launch ledger. Housekeeping; verify against current `dev` before closing. |
| CL-8 | `pos.void_receipts` still seeded to `manager` though the void route is a 410 tombstone | 🎫 **Out of scope — flag only** (`OP-14`, `R12 §5(d)`, `R14 §C-h`). Do not touch the seeder beyond S-9. |

**Wave ownership of every cleanup item (binding; r2, gate r1 defect 11).** r1 assigned no wave to CL-2 and CL-4, and put CL-3 ("both voucher links healed") in wave 1 while the detail route it depends on was in wave 2 — impossible as written.

| Item | Wave | Why |
|---|---|---|
| CL-1 (delete `ShiftReceiptsList`) | **1** | Depends only on the i18n key migration |
| CL-2 (`getShiftReceipts` KEEP + `@see`) | **1** | Doc-comment only; no dependency |
| **CL-3** (voucher links self-heal) | **2** | **Moved from wave 1.** The links target `/pos/receipts/:id`, which does not exist until the **detail route** ships in wave 2 |
| **CL-4** (`ProvenanceSection.test.tsx` strengthened) | **2** | **Assigned.** It must travel with the route regression (FT-12/FT-13) — a strengthened test would fail in wave 1 by design |
| CL-5 (`POSTransactions.tsx` copy) | **1** | Links `/pos/receipts` (the list), which ships in wave 1 |
| CL-6 (quarantine comment → route) | **1** | Part of registering the list route |
| CL-7 (close the positive-refund ticket) | **1** | Housekeeping, no code dependency |
| CL-8 (`pos.void_receipts`) | — | Flag only, never built here |

Consequently the **wave-1 exit criterion "both voucher links healed" moves to wave 2** (§7.5). Wave 1 is a list-only wave. (The gate's alternative — pulling a minimal detail route plus S-4/S-5 forward into wave 1 — was considered and rejected: S-4's allowlisted resource and S-12's lineage enrichment are the bulk of the backend risk, and shipping a stub detail page purely to satisfy a link would ship the `canonical_bytes` leak §3.b.5 exists to prevent.)

---

## 7. Test plan (TDD — red first, per CLAUDE.md rule 2)

Run backend tests **by path** (never the full suite — memory rule `feedback_no_full_test_suite`). PG-backed with `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`; zero fake payloads (CLAUDE.md testing conventions).

### 7.1 Backend — `apps/api/tests/Feature/POS/`

| # | Test | Asserts |
|---|---|---|
| BT-1 | `ReceiptIndexTrainingExclusionTest` | Default request **omits** `training_flag = true` rows; `include_training=true` returns them; the returned row carries `training_flag` and `invoice_type_code`. Fixture must contain a TRAINING receipt (which projects as `receipt_type='sale'`, `R12 §2.2`) — the regression this guards. **⚠️ r4 (gate r3 defect R3-1) — this class also owns the training-axis precedence matrix of §3.a.** Assert, as a data provider over the frozen table: **422** for `codes=['TRAINING']`, `['SALE','TRAINING']` and any TRAINING-containing set with `include_training` absent/false, and for `invoice_type_codes[]=[]`; **200** for `['SALE']+include_training=true` (SALE rows only — the toggle alone widens nothing), `['TRAINING']+true` (TRAINING only), `['SALE','TRAINING']+true` (the union), and `['REFUND','VOID']+true` (identical result to the same request without the toggle — the documented no-op). The 422 body must name `invoice_type_codes`. **⚠️ r5 (gate r4 defect R4-1) — the provider must also cover the six legal mixed non-TRAINING cases** that r4's matrix omitted: `['SALE','REFUND']`, `['SALE','VOID']` and `['SALE','REFUND','VOID']`, each **twice** — once with `include_training` absent/false and once with `include_training=true`. Each of the six asserts **200** with exactly the requested code set returned (no widening to TRAINING, no narrowing), and each `true` case asserts a **byte-identical response body** to its absent/false twin, proving the toggle is the documented no-op on this axis. With these rows the provider mirrors the frozen §3.a table one-for-one. |
| BT-2 | `ReceiptIndexTypeFilterTest` | **(r2)** Default request (no type param) returns **SALE only** — REFUND and VOID rows are absent; `invoice_type_codes[]=REFUND` and `[]=VOID` return **disjoint** sets; `invoice_type_codes[]=REFUND&[]=VOID` returns exactly their union; the legacy `receipt_type=return` axis returns that same union (proving it is lossy and the new filter is authoritative). **(r4)** The training axis is **not** re-tested here — BT-1 owns the full 200/422 matrix; BT-2 asserts only that the no-param default is SALE-only. |
| BT-2b | `RefundRegisterPaginationTest` | **(r2, gate r1 defect 1)** With 30 interleaved REFUND and VOID receipts and `per_page=10`, the mixed register is **server-paginated in one query**: `meta.total = 30`, `meta.last_page = 3`, page 2 contains **both** REFUND and VOID rows in `posted_at DESC` order, and no row appears on two pages. A client-side merge of two calls cannot satisfy this. |
| BT-3 | `ReceiptIndexLocationScopeTest` | `location_ids[]` filters correctly; rows carry `location_id`/`location_name`; a location outside the caller's company is never returned. |
| BT-4 | `ReceiptIndexFiscalStatusFilterTest` | `fiscal_status=pending_seal` / `sync_failed` select the right rows; value set matches `FiscalStatus.php:7-14`. |
| BT-5 | `ReceiptIndexEnvelopeTest` | Response is exactly `{data:{data:[…],meta:{current_page,last_page,per_page,total,from,to}}}` — a **regression guard against "fixing" the envelope** (`R12 §4.8`). **⚠️ r3 (gate r2 defect N-5):** r2 asserted the meta key set was *exactly* the four paginator keys while §4.6 (S-13) simultaneously required `meta.from`/`meta.to`; the two froze incompatible shapes. `from`/`to` are added to the asserted set. They are the **resolved company-timezone window as ISO instants** (§4.6), **not** Laravel's row-index `from`/`to`: this meta block is hand-constructed key-by-key, not `$paginator->toArray()` [VERIFIED 2026-08-11 — `ReceiptController.php:134-143`], so there is no collision to resolve — but the test must assert the values are ISO instants, precisely so a later refactor to `toArray()` (which would emit row indices under those names) fails loudly. |
| BT-6 | `ReceiptShowResourceTest` | `canonical_bytes` is **absent at the ROOT**; every line carries `quantity_decimals` matching `product.unitOfMeasure.decimal_places`; `returned_quantity` still present; the emitted key set equals the §3.b.5 allowlist **exactly** (assert equality of key sets, not just presence — an extra key is a failure). **⚠️ r4 (gate r3 defect R3-3):** lines carry **`product_code`** and **must not** carry `product_sku` or `unit_of_measure_code` (both dropped — the key-set equality assertion fails on either). Add a fixture line with **`product_id = NULL`** (the projector's documented case, `PosCoreReceiptProjection.php:1157-1176`): it must still emit the full line — `product_code`/`product_name` from the snapshot columns and `quantity_decimals = 4` via the documented fallback — and must **not** 500 or omit the row. Assert `product_code` equals the **snapshot column**, not the current `products.sku`, by mutating the product's SKU after the receipt exists and re-reading the endpoint. |
| BT-7 | `ReceiptShowRefundLineageTest` | On an original: `return_receipts[]` lists its refunds. On a refund: `original_receipt_id` resolves. Both directions in one test class. |
| BT-8 | `ReceiptFilterOptionsTest` (S-7) | 200 for a user holding **only** `pos.view_receipts`; terminals carry `v4_refund_authoring_enabled` + `_acknowledged_at`; cashiers restricted to those with receipts in scope; cross-company terminals absent. |
| BT-9 | `ReceiptAuthorizationTest` | 403 without `pos.view_receipts` on index/show/filter-options; 403 on verify-chain without `pos.view_reports`; **accountant role (post-S-9) gets 200 on index/show/filter-options and on verify-chain, and 403 on `/pos/terminals`** — locks GATE-2 in. |
| BT-10 | `ReceiptIndexIndexUsageTest` (optional, S-6) | `EXPLAIN` on the canonical company+location+date+non-training query shows an index scan, not a seq scan. Mark as advisory if the harness makes plan assertions brittle. |
| BT-11 | Seeder test | `accountant` holds exactly `pos.view_receipts` + `pos.view_reports` and **not** `dashboard.owner` / `pos.manage_terminals` / `pos.process_returns` (locks A-2 as ruled). **Plus:** the committed `permissionsMap.generated.ts` matches the seeder (the preflight drift guard is the real enforcement, `scripts/preflight.sh:137-161`). |
| **BT-12** | `ReceiptLocationScopeAuthorizationTest` (**r2, defect 4 / §4.4**) | Membership restricted to location A: a **same-company** receipt at location B is (i) absent from `index`, (ii) **404** on `show`, (iii) **404** on `downloadPdf`, (iv) **404** on `streamPdf`, (v) its terminal absent from `filter-options`, (vi) rejected by `verify-chain`. Unrestricted membership (`allowed_location_ids = null`) sees all; no active membership (`[]`) sees none. Client-supplied `location_ids[]` naming location B does **not** widen. |
| **BT-13** | `ReceiptPdfPrintAuditTest` (**r2, defect 13**; corrected in **r3, gate r2 defect N-6**) | For **both** PDF endpoints: an authorized fetch creates **exactly one** row in **`pos_receipt_prints`** with the correct `receipt_id`, `terminal_id`, `user_id`, `print_method = PrintMethod::Pdf`, and a `copy_number` incremented from the previous one (second fetch ⇒ `copy_number = 2`). **No row** is written on 403 (missing permission), 404 (absent receipt) or the §4.4 location denial — the scope check runs **before** `recordPrint()`. **r2 named a nonexistent `receipt_print_audits` table and asked for a "download vs stream" method distinction that does not exist**: the model's table is `pos_receipt_prints` [VERIFIED 2026-08-11 — `apps/api/app/Modules/POS/Domain/ReceiptPrint.php:44`], `PrintMethod` has exactly `Pdf`/`Thermal`/`EscPos` [VERIFIED — `Domain/Enums/PrintMethod.php:7-11`], and **both** live actions pass `PrintMethod::Pdf` [VERIFIED — `ReceiptController.php:604-609,637-642`]. So the two endpoints are distinguished **only** by the row count and copy sequence they produce, never by `print_method`. Adding a download/stream distinction would be an **audit-schema change requiring separate approval** — it is explicitly **not** specified here and must not be inferred. |
| **BT-14** | `ReceiptAggregateIntegrityTest` (**r2, defect 5**) | The asserted identity is `total == subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment,0)`. Fixtures: a **cash-rounded v3** receipt (non-zero adjustment, both signs) and a **legacy v1/v2** receipt with `cash_rounding_adjustment IS NULL`. Both pass. Also asserts `Σ vat_details[].net_amount == subtotal`, `Σ vat_details[].vat_amount == tax_amount` — **the frozen wire names of §3.b.5(iv-b)** (**r3, gate r2 defect N-5**: r2's `vat_details.net` / `.vat` matched neither the allowlist nor the source columns `pos_receipt_vat_details.net_amount` / `.vat_amount`, `2026_01_08_190639_create_pos_receipt_vat_details_table.php:36-38`). **No line arithmetic anywhere.** |
| **BT-15** | `ReceiptResourceNoCanonicalBytesRecursiveTest` (**r2, defect 6**) | Decode the **entire** `show()` JSON tree and assert the key `canonical_bytes` appears **zero times at any depth** — on a receipt that has both `return_receipts[]` and an `original_receipt` (the nesting r1's root-only BT-6 missed). Same recursive search for `vat_breakdown_hash`, `payment_methods_hash`, `tenant_id`. |
| **BT-16** | `PosReceiptsIndexMigrationStructureTest` (**r2, defect 14**) | After migrate, `pg_indexes` contains `pos_receipts_company_location_posted_at_idx` and `pos_receipts_company_posted_at_production_idx` with the stated columns and the `WHERE training_flag = false` predicate; re-running the migration is a no-op; `down()` drops both. Planner-independent. |
| **BT-17** | `RefundReportingFieldsTest` (**r2, defect 2 / §4.5**) | v4 refund ⇒ `original_receipt_number` present, `refund_reason` = the **canonical free text** (not `"other"`), `refund_destination = 'cash'`, `refund_reason_source = 'canonical'`. Legacy return ⇒ enum fallback with `refund_reason_source = 'legacy_enum'`, `refund_destination = null`. VOID ⇒ correct shape. Fiscal event with NULL payload ⇒ degrades to fallback, HTTP 200, no 500. One bulk event load per page (no N+1 — assert query count). **⚠️ r4 (gate r3 defect R3-2):** add two malformed-payload fixtures whose faults sit **outside** the two early guards — a `line_items[0]` missing a required string key (⇒ `InvalidArgumentException` from `FiscalPayloadArrayGuards`) and a `line_items[0]` that is a scalar instead of an object (⇒ PHP `TypeError` from `LineItemDTO::fromArray(array $data)`). Both must degrade that single row to the legacy fallback with **HTTP 200** and a logged warning, proving the `catch` wraps the **whole** `forSaleReceipt()` call and not just its two documented cases (§4.5). |
| **BT-18** | `ReceiptDateBoundaryTest` (**r2, defect 10 / §4.6**) | With `companies.timezone = 'Africa/Tunis'` and `config('app.timezone')` deliberately different: a receipt at `23:30` company-local on `to_date` is **included**; one at `00:15` company-local on `to_date + 1` is **excluded**. Repeat with `Europe/Paris` across a **DST transition** day. Also: `per_page=10000` is rejected (max 100); a malformed `location_ids[]` UUID is a **422, not a 500**; `to_date < from_date` is 422. |

**Aggregate-integrity rule for POS lines — binding on every test in this plan (`R12 §4.2`, precision-contract `:202`):** no test may assert `line_subtotal == unit_price × qty − discount`, or any variant, on a POS canonical line. Integrity is asserted only at aggregate level, using the **rounding-aware** identity of §3.b.4 — `total == subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment,0)`, `Σ vat_details[].net_amount == subtotal`, `Σ vat_details[].vat_amount == tax_amount`. (**r2:** r1 printed the rounding-blind form here too; it is corrected in both places. **r3, defect N-5:** the VAT sums now use the frozen wire names §3.b.5(iv-b).) A reviewer must reject any line-arithmetic assertion introduced by the build.

**Money/quantity in fixtures:** decimal strings only; `CurrencyScale::bcformatStrict` with the entity currency; `QuantityScale` for quantities. Queued/console contexts pass the currency explicitly (CLAUDE.md rule 19).

### 7.2 Frontend — vitest, `apps/web/src/features/pos/`

| # | Test | Asserts |
|---|---|---|
| FT-1 | `ReceiptListPage` renders rows | Canonical shell used; a TRAINING row (when opted in) carries its badge; a plain SALE row carries **no** type badge. |
| FT-2 | Training toggle | Default request payload has no `include_training`; toggling sends `include_training=true` **and adds `'TRAINING'` to `invoice_type_codes[]`** (r4, gate r3 defect R3-1 — the two always travel together, so the FE can never emit the 422 shape of §3.a); the header shows the "training included" state. |
| FT-3 | Query key | Key equals `locationScopedKey(['pos','receipts',filters], scope)` — the `audit-tanstack-keys.mjs` gate also enforces this at lint/preflight/CI (CLAUDE.md rule 14). |
| FT-4 | **No PDF fetch on render** | Mount list and detail; assert **zero** requests to `/pdf` or `/pdf/download` occur without an explicit confirmed action (`OP-16` guard). |
| FT-5 | Reprint confirm | Clicking Print opens the dialog; cancelling issues no request; confirming issues exactly one; the dialog body renders the audit-consequence key. |
| FT-6 | Detail line labelling | The unit-price header renders the **incl. VAT** key and the net column renders the **line net** key; quantity rendered via `formatQuantity` at `quantity_decimals` (no literal `.toFixed(2)`). **(r4, R3-3)** The line renders its **`product_code`** snapshot and **no unit symbol** (no `unit_of_measure_code` on the wire); a fixture line with `product_id === null` renders completely rather than blanking or crashing. |
| FT-7 | Refund lineage both directions | Original renders links to its refunds; refund renders a link to its original. |
| FT-8 | Refunds register capability states | Three banner states render from the three `(enabled, acknowledged_at)` combinations; the "not enabled" copy states the device cannot process one, and no empty-table language appears. |
| FT-9 | Chain-verify panel | **Only written once Lane A0 has landed and the post-A0 endpoint shape has been re-verified (§3.d, r2).** Hidden without `pos.view_reports`; success/failure toasts; `chain_length` always captioned as a `pos_receipts` count. |
| FT-10 | Empty states | The three states of §3.a render distinctly; the filtered state exposes **Clear filters**. |
| FT-11 | i18n | No hardcoded user-facing string in the new components (ESLint + a render assertion on a non-EN locale for at least the list page). |
| **FT-14** | Sidebar↔route parity — **accountant** (**r2, defect 7 / GATE-5**; row corrected in **r3, defect N-3**) | With exactly the A-2 grant (`pos.view_receipts` + `pos.view_reports`), every POS sidebar entry the accountant can **see** resolves to a route they can **enter**: **visible** = POS Receipts, Z-Reports, Analytics, **and Vouchers** (its route is `moduleKey="pos"`, which `pos.view_receipts` satisfies — so it is parity-correct, not a leak); **not rendered** = Terminals, Shift History, Orders, Tables, Kitchen. Also asserts the Compliance-export nav entry (A-1 item 3) renders for this role. |
| **FT-15** | Sidebar↔route parity — **cashier** (**r2, defect 7**; expectations pinned in **r3, defect N-3**) | The re-key must not hide anything the cashier can actually enter, and must hide everything it cannot. With the seeded cashier grants [VERIFIED 2026-08-11 — `permissionsMap.generated.ts:170-189`: cashier holds `pos.operate_terminal`, `pos.manage_shifts`, `pos.view_receipts`; it does **not** hold `pos.manage_tables`, `pos.manage_terminals`, `pos.view_reports`]: **visible** = Orders, Kitchen (when `Menu` is enabled), Shift History, POS Receipts, Vouchers; **now hidden** = **Tables** (needs `pos.manage_tables`), Terminals, Z-Reports, Analytics. **Tables disappearing from the cashier menu is the intended outcome, not a regression** — that link 403s on entry today. Assert both directions: nothing visible 403s, and each hidden entry's route denies this role. |
| **FT-16** | Disjoint registers (**r2, defect 1**) | `/pos/receipts` renders **no type tabs**; its request payload never contains `REFUND`/`VOID`; `/pos/receipts/refunds` issues **exactly one** list request carrying both codes **and no `include_training` param at all** (r4, R3-1 — the toggle is a no-op on (c) and must not be sent); assert the request, proving no client-side merge; a REFUND fixture row never appears on (a). |

### 7.3 Link-healing regression (CL-3/CL-4)

| # | Test |
|---|---|
| FT-12 | `LedgerHistoryTable` — clicking the receipt link **navigates to the receipt detail route**, asserted through a real router with the new route registered (not an href string equality). |
| FT-13 | `ProvenanceSection.test.tsx` — upgraded per CL-4; must fail against the pre-build routing table and pass after. |

### 7.4 Gates

**r1 named a bare `./scripts/preflight.sh` while also forbidding the full suite — those are contradictory, and the bare form SILENTLY SKIPS PHPUnit** (default `PREFLIGHT_SCOPE=paths` + no `PREFLIGHT_TEST_PATHS` ⇒ `PHPUNIT_SKIPPED=1`, `scripts/preflight.sh:71-90`). Corrected (r2, gate r1 defect 15) — **this exact invocation, from the repo root**:

```bash
PREFLIGHT_TEST_PATHS='tests/Feature/POS tests/Feature/Compliance tests/Unit/POS' \
PREFLIGHT_VITEST_PATHS='src/features/pos src/features/vouchers/components/__tests__ src/components/organisms/Sidebar' \
  ./scripts/preflight.sh
```

**`PREFLIGHT_VITEST_PATHS` is part of the invocation, not optional (r3, gate r2 defect N-7).** r2 set only `PREFLIGHT_TEST_PATHS`; with the Vitest variable unset, preflight falls through to `pnpm test` — **the entire web unit suite** [VERIFIED 2026-08-11 — `scripts/preflight.sh:197-203`] — which contradicts the scoped-laptop contract this section exists to state, and runs the whole suite *before* the separately scoped Vitest command below ever executes. The two path sets are deliberately identical to the standalone commands in this section.

That path set must contain **BT-1…BT-18** (all new classes live under `apps/api/tests/Feature/POS/`, except the A-1 route/permission coverage under `tests/Feature/Compliance/`). **Never** run `PREFLIGHT_SCOPE=full` on the laptop (memory `feedback_no_full_test_suite`) — full-suite runs are VPS/CI only. Preflight also runs PHPStan level 8, Pint, `typescript:transform` drift, **`permissions:export-frontend-map` drift** (S-9, §4.3 note 2b), tsc and ESLint. PHPStan needs a live-DB env in a worktree (memory `project_izipos_worktree_backend_env_gotchas.md`).

**Frontend unit/component gate** (run from `apps/web`, scoped — not the whole suite):

```bash
pnpm vitest run src/features/pos src/features/vouchers/components/__tests__ src/components/organisms/Sidebar
pnpm lint && pnpm typecheck
```

covering FT-1…FT-16. Frontend design-token ESLint rule applies to every new `.tsx` (CLAUDE.md rule 18) — new feature directories must use `tokens`/`textColors`/`borderColors` exclusively. `audit-tanstack-keys.mjs` and `audit-quantity-display.mjs` run inside `pnpm lint`.

**Playwright flows (required, not optional) — spec paths and invocation frozen in r3 (gate r2 defect N-7).** r2 described four flows but named no spec file and no command, so "exact test commands" was not satisfiable. Pinned:

| # | Flow | Spec file | Wave |
|---|---|---|---|
| 1 | **Route + permission:** accountant signs in → sidebar shows POS Receipts → `/pos/receipts` loads → `/settings/compliance/export` loads (via the **nav entry**, A-1 item 3) → `/pos/terminals` is **not** in the nav and direct navigation is denied | `apps/web/e2e/pos/receipts-permissions.spec.ts` | 1 |
| 2 | **Voucher link healing:** voucher provenance → click the receipt link → lands on `/pos/receipts/:id` with the receipt number rendered (the CL-3/CL-4 regression, end-to-end) | `apps/web/e2e/pos/receipts-voucher-link-healing.spec.ts` | 2 |
| 3 | **Reprint consequence:** open a receipt, observe **zero** PDF network calls on render, click Print → confirm dialog → cancel (**zero** calls) → click Print → confirm (**exactly one** call), then the compliance reprint log shows the new row with its copy number | `apps/web/e2e/pos/receipts-reprint-consequence.spec.ts` | 2 |
| 4 | **Disjoint registers:** a REFUND receipt is absent from `/pos/receipts` and present on `/pos/receipts/refunds` | `apps/web/e2e/pos/receipts-disjoint-registers.spec.ts` | 2 |

Exact invocation (wave 1 runs flow 1 only; wave 2 runs all four):

```bash
cd apps/web
pnpm exec playwright test --project=chromium \
  e2e/pos/receipts-permissions.spec.ts \
  e2e/pos/receipts-disjoint-registers.spec.ts \
  e2e/pos/receipts-voucher-link-healing.spec.ts \
  e2e/pos/receipts-reprint-consequence.spec.ts
```

- `playwright.config.ts` sets `testDir: './e2e'`, `baseURL: http://localhost:5173`, one `chromium` project, and auto-starts `pnpm dev` via `webServer` [VERIFIED 2026-08-11 — `apps/web/playwright.config.ts`]. `pnpm test:e2e` is the unscoped alias (`package.json:20`) — **do not** use it here; these flows are scoped by path.
- These are **live-stack** flows, not mocked: reuse `e2e/money-campaign/helpers.ts` (`loginAsRole(page, 'accountant' | 'cashier')`, `ROLE_CREDENTIALS`, `dismissCookieConsent`) against the local API and the seeded demo tenant — **not** `e2e/fixtures.ts`, whose `authenticatedPage` mocks `/auth/me` with a hardcoded `admin` role and therefore cannot exercise a permission gate at all.

**Evidence to record with the handback** (repo convention for new UI): screenshots of the list (empty + populated + training-included), the detail page (all six sections), the refunds register in each of its three capability-banner states, and the reprint confirm dialog — plus the terminal output of the preflight and vitest invocations above.

### 7.5 Wave sequencing

Corrected in r2 (gate r1 defect 11): wave 1 no longer claims to heal links whose target route ships in wave 2, and every cleanup item has an owner.

| Wave | Contents | Exit |
|---|---|---|
| **1** | S-1, S-2, S-3, S-6, S-7, S-9 (**incl. the generated permission map**), S-13, S-11 (index + `filter-options` half) · screen (a) · A-1 re-gate **+ its nav entry** · GATE-3 map entries · GATE-5 POS sidebar re-key · cleanup CL-1/CL-2/CL-5/CL-6/CL-7 · i18n rename · BT-1…BT-5, BT-8…BT-11, BT-12(index/options), BT-16, BT-18 · FT-1…FT-3, FT-10, FT-11, FT-14…FT-16 | Disjoint, **location-authorized** SALE register live with stable filter options; accountant reaches `/pos/receipts` **and** `/settings/compliance/export` from the nav; permission map in sync. **Voucher links are still dead — that is expected in wave 1.** |
| **2** | S-4, S-5, S-12, S-11 (show/PDF/(d) half) · screen (b) · screen (c) · **cleanup CL-3 + CL-4** · BT-6, BT-7, BT-12(show/PDF), BT-13, BT-14, BT-15, BT-17 · FT-4…FT-8, FT-12, FT-13 | Allowlisted detail (no `canonical_bytes` at any depth) + two-way refund lineage + separately paginated refunds/voids register + **both voucher links healed and test-proven** + deliberate reprint workflow |
| **2b** | screen (d) chain-verify panel · FT-9 | **HARD-BLOCKED on Lane A0** (§3.d). Not shipped with captions, not shipped early. If A0 has not landed when wave 2 closes, wave 2 ships without (d) and 2b becomes a follow-up. |
| **3** | S-8 + totals strip — **blocked on OI-3** | Only after the totals-convention ruling |

---

## 8. Open items

| ID | Item | Status | Owner / resolution path |
|---|---|---|---|
| **OI-1** | **A-2 accountant POS scope.** Spec proposes `pos.view_receipts` + `pos.view_reports`, **no `dashboard.owner`** (§4.2 A-2; `R14 §7 Q1`; `OP-09`). | **OPEN — owner confirms at read-through** | Owner ruling → S-9 seeder + reseed + `permission:cache-reset` |
| **OI-2** | **Returns convention.** **RULED — refunds are listed SEPARATELY** (gross + a separate refunds register; never netted); receipt detail cross-links to its refund(s) and vice versa; POS scope only, B2B credit notes out (`OWNER-DECISIONS…:59`). Restated here because `R12 §9 Q1` and `R14 §7 Q3` still record it as open — **they are superseded for this lane.** | **CLOSED (ruled)** | — |
| **OI-3** | **Totals convention (B-9)** for the S-8 strip: owner convention (returns excluded and reported separately, training filtered, `change_due` netted) vs POS-analytics convention (returns netted in, training unfiltered). The two disagree by exactly the change handed back (`R12 §4.5`, `R14 §6(b) B-9`). Recommendation: owner convention. | **OPEN — blocks wave 3 only** | Owner ruling; wave 1–2 ship without a totals strip |
| **OI-4** | ~~"Do training receipts emit fiscal events?"~~ **NOT an open item for this lane (r2, gate r1 defect 12).** It is an already-tracked defect, not an unknown: the device receipt service promises a training fiscal event (`apps/pos/src/lib/offline/receiptService.ts:256-267`) and builds `invoice_type_code=TRAINING`/`training_flag=true` (`SaleReceiptPayload.ts:149-177`), but its `engine.append()` **omits `chain_context`** (`receiptService.ts:489-505`); the engine defaults that to `operational` and **rejects `training_flag=true`** without a training context (`FiscalEventEngine.ts:539-574,798-824`). Ownership: **ES-19 / OP-18** (`FINDINGS-other-problems-2026-08-11.md:54-62`), remediation lane A1. **This lane does not fix it and does not investigate it.** After A0/A1 land, re-evaluate what the (d) panel counts and how `chain_length` is captioned. | **CLOSED here — cross-referenced to ES-19 / A1** | Fixes session lane A1; (d) re-evaluation at wave 2b |
| **OI-5** | ~~`RequirePermission` prop resolution~~ **CLOSED — VERIFIED (r2, gate r1 defect 12).** `RequirePermission` calls `hasPermission(permission)` **directly**; only `moduleKey` goes through `canAccessModule`/`MODULE_PERMISSIONS` (`apps/web/src/features/auth/components/RequirePermission.tsx:37-63`). So GATE-1 is airtight as written, and A-1 needs a `MODULE_PERMISSIONS` entry **only** for its sidebar key (A-1 item 4). r1 recorded this as open while its own body (§4.2 A-1) already stated it correctly — the contradiction is removed. | **CLOSED (verified)** | — |
| **OI-6** | **OP-20 — `TerminalResource` capability fields: RESOLVED.** [VERIFIED 2026-08-11] `TerminalResource.php:134-135` emits both `v4_refund_authoring_enabled` and `v4_refund_authoring_acknowledged_at`. The `SUSPECTED not` marker in `R14 §3.1 A-5` and `OP-20` is superseded. **Residual:** the resource is only reachable behind `pos.manage_terminals` (`TerminalController.php:52`), hence S-7. | **CLOSED (verified) — residual handled by S-7** | — |
| **OI-7** | **ES-07 / chain-panel sequencing — RESOLVED AS A HARD BLOCK (r2, gate r1 defect 3).** r1 called the HTTP verdict "fiscal-aware" and allowed an early ship with captions. Both are retracted: the verifier flattens four independently-sequenced `chain_context` chains and omits the projection↔event mirror check (`ReceiptHashService.php:234-250`; `ES-REGISTER-CORRECTIONS-2026-08-11.md:17-25`). Screen (d) is now **wave 2b, hard-dependent on Lane A0**, with mandatory re-verification of the post-A0 endpoint shape before UI copy/tests are frozen. | **CLOSED (ruled) — no longer a scheduling choice** | Lane A0 lands first; then wave 2b |
| **OI-8** | **Filter state in URL** — this spec rules `useTableState({syncToURL:true})` (§3.a), deviating from the local-`useState` canon exemplar (`R12 §9 Q4`). Flagged for the gate to accept or reject. | **RULED by spec — gate may overturn** | Adversarial gate |
| **OI-9** | **`pos:receiptSearch` → `pos:receipts` rename** — this spec rules **do it now** (§5.1; `R12 §9 Q9`), since the only live consumer (`ShiftReceiptsList`) is deleted in the same wave. | **RULED by spec** | — |
| **OI-10** | **Chain-verify placement** — this spec rules **on the receipts list**, mirroring the Z-chain verify on the Z list (`R12 §9 Q8`). | **RULED by spec** | — |
| **OI-11** | Refund-enable (E1) dependencies **recorded, not fixed**: `OP-03`/E1-1 (POS refunds not netted from the TN VAT declaration), `OP-04`/E1-2 (second terminal does not auto-disable capability), `OP-05`/E1-3 (rounding delta explicability), E1-4 (E-7 Status cell still blank). | **OUT OF SCOPE — tracked** | Launch program / E1 checklist |
| **OI-12** | Cross-lane, **not owned here**: `OP-15` `/pos/shift-history` fixes; `R14 B-3` shift-detail page; `R14 B-8` bulk/CSV export; `R14 C-9` override/manager-PIN audit surface; `OP-14` `pos.void_receipts` seeding; `R14 C-13` ANNULATION→RETOUR ratification. | **Cross-reference only** | Respective lanes |
| **OI-13** | ⚠️ **NEW, OWNER-OWED (r2, gate r1 defect 1).** **Does "listed separately" permit duplication?** r2 makes the registers **disjoint**: `/pos/receipts` is SALE-only with **no type tabs**, and refunds/voids live **only** on `/pos/receipts/refunds` (§3.a "Disjoint registers"). r1 instead defaulted the primary list to "All" and *also* duplicated the rows on the refunds page. If the owner intends refunds to remain visible **inside** the main register as well (a "Refunds" tab alongside a gross "All" view), that is a different reading of `OWNER-DECISIONS…:54-60` and the tabs come back. **The spec does NOT decide this — it defaults to the strict reading (disjoint) because that is the conservative fiscal-presentation choice.** | **OPEN — owner confirms at read-through** | Owner ruling → keep disjoint (no change) or re-add type tabs to (a) + BT-2/FT-16 relaxed |
| **OI-14** | ⚠️ **NEW, OWNER-VISIBLE (r2, gate r1 defect 7 / GATE-5).** The POS sidebar re-key to exact least-privilege permission keys **changes what existing roles see**: today every POS child is keyed on the broad `pos` alias, so e.g. a `pos.operate_terminal`-only user is shown Terminals/Shift History/Z-Reports links whose routes then deny them. r2 rules the re-key **in scope** (it is the direct precondition for granting the accountant `pos.view_receipts` without producing a menu of bouncing links). Owner-visible because some users will see **fewer** POS menu entries after deploy — all of them entries they could not enter anyway. | **RULED by spec — owner may overturn at read-through** | Owner read-through; FT-14/FT-15 lock the outcome either way |
| **OI-16** | ⚠️ **NEW, OWNER-VISIBLE (r3, gate r2 defect N-4).** **Where the Compliance-export nav entry lives.** r2 asked for it inside a "Settings → Compliance" group. No such group exists, and it cannot be created as specified: the Settings entry is a leaf gated by `settings.view`, which the accountant does **not** hold, and a parent is filtered before its children — so any child hung under Settings is invisible to exactly the persona A-1 exists to serve (§4.2 A-1 item 3, all citations verified). r3 therefore rules a **top-level bottom-section entry** immediately above Settings, gated any-of the three `compliance.*` permissions. Owner-visible because admins and managers gain a **new** always-visible bottom-nav item, and because an owner who wants compliance pages grouped under Settings is asking for a Settings-section IA change (re-keying the Settings parent) that is **not** in this lane's scope. **The spec does NOT decide the IA question — it takes the only placement that is implementable today and reaches the accountant.** | **RULED by spec — owner may overturn at read-through** | Owner ruling → keep the top-level entry, or open a separate Settings-IA lane (OQ-10 Compliance-section work is the natural home) |
| **OI-17** | ⚠️ **NEW, OWNER-VISIBLE (r4, gate r3 defect R3-3).** **Receipt detail lines show no unit of measure.** The line allowlist drops `unit_of_measure_code` because there is no truthful historical unit to emit: the canonical `line_items[]` payload carries **no unit field** (`LineItemDTO.php:31-48`), and the snapshot column `pos_receipt_lines.unit` is written as the **literal `'pc'`** by the fiscal projector for every v3+ receipt (`PosCoreReceiptProjection.php:1193`) — so it would print "pc" for a receipt of 2.5 kg. Reading the **current** `product.unitOfMeasure` instead is refused on principle: a historical fiscal register must not render present-day master data (§3.b.5(iii)). Quantities are still rendered at the product unit's **precision** via `quantity_decimals` (CLAUDE.md rule 19) — only the human-readable **symbol** is absent. Restoring it is **not** a resource change: it requires a unit field in the canonical payload (fiscal event-schema change, separate approval) **and** the projector writing it instead of `'pc'`. **The spec does NOT decide that** — it takes the only option that cannot misstate a receipt. | **RULED by spec — owner may overturn at read-through** | Owner ruling → accept the unit-less line, or open a canonical-payload/projector lane (fiscal event-schema change; would also need a backfill answer for already-sealed receipts, which cannot be rewritten) |
| **OI-15** | **Post-A0 endpoint contract for screen (d)** — the exact response shape of `POST /pos/reports/receipts/verify-chain` after Lane A0 is **unknowable from today's source** and must be re-read before wave 2b's copy, i18n keys and tests are written (§3.d rule 3, §10). Not an owner item; a builder obligation carried forward. | **OPEN — resolved by re-verification at wave 2b** | Wave-2b builder |

---

## 9. Revision log

### 9.1 — r1 → r2 (gate round 1)

Source: `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r1.md` (verdict **REVISE**, 15 required fixes + 30 claim verdicts). **All 15 were edited into r2.** Where the gate REFUTED an r1 claim, the gate's version is adopted verbatim in substance — it was verified against source, r1's was not.

**Gate r2's audit of this table (recorded here so the log stays honest):** gate r2 re-verified all 15 against source and confirmed **7 as fully applied — defects 3, 4, 5, 9, 11, 12, 14 (PASS)**. It marked **1, 2, 6, 7 FAIL** and **8, 10, 13, 15 PARTIAL** — in every case because the r2 edit *was* made but left an internal contradiction, a missing field, or a wrong permission/table name behind it. So the "where r2 fixes it" column below remains accurate about **what was edited**; it was **not** accurate as a completeness claim. Those eight are closed by §9.2 (N-1…N-7), and the r2 rows are left unedited so the two rounds can be read against each other.

| Gate defect | What r1 got wrong | Where r2 fixes it |
|---|---|---|
| **1** — primary list violates the separate-refunds ruling; no valid mixed-register query | Defaulted (a) to type **All** with Refunds/Voids tabs *and* duplicated them on (c); only a **singular** `invoice_type_code` equality filter existed, which cannot express REFUND∪VOID | §3.a **"Disjoint registers"** (new); Type filter row **removed**; Columns "Type" row rewritten; S-2 rewritten to `invoice_type_codes[]`; **BT-2 rewritten + BT-2b** (server pagination proof); **FT-16**; owner escape hatch = **OI-13** |
| **2** — three refund columns cannot be rendered from live data | Promised `original receipt #`, `return_reason`, destination off the current payload; in fact only `original_receipt_id` + a projector-constant `"other"` enum exist, and `refund_destination` has **no column at all** | §3.c.2 rewritten with the three source facts; new **§4.5 (S-12)** reporting projection via `CanonicalPayloadReader`, incl. `refund_reason_source` and legacy/void/NULL-payload degradation; **BT-17**; lineage shape in §3.b.5(v) |
| **3** — HTTP verifier not safe to present as a chain verdict | Claimed "the boolean **is** fiscal-aware"; it flattens four `chain_context` chains, has no company/event-type predicate, and omits the projection↔event mirror check | §3.d **rewritten**: r1's claim explicitly **retracted**; (d) becomes a **hard A0 dependency**; the "ship with captions" option **deleted**; post-A0 re-verification mandated; **OI-7 closed as ruled**, **OI-15** added; wave **2b** created |
| **4** — membership location authorization absent from the entire read surface | Company-only scoping on index/show/both PDFs; `useViewScope` IDs treated as authorization | New **§4.4 (S-11)** — `LocationContext` intersection on six paths, `null`/`[]` semantics, **403 vs non-enumerating 404 pinned**, scope check **before** `recordPrint()`; **BT-12**; S-7 "in scope" defined |
| **5** — aggregate integrity equation wrong for rounded receipts | `subtotal + tax_amount == total + discount_amount` omits signed `cash_rounding_adjustment` and false-fails valid v3 rows | §3.b.4 equation replaced with the live `pos_receipts_totals` form incl. `COALESCE(...,0)`; the duplicate statement in §7.1 corrected too; **BT-14** with rounded + legacy-NULL fixtures |
| **6** — S-4/S-5 had no exact safe response schema | "Use a `ReceiptResource`" left every field to the builder; nested lineage receipts could reintroduce `canonical_bytes` even with a stripped root | New **§3.b.5** — exhaustive allowlists for list row / detail root / line / payment / VAT / lineage, never-emitted list, decimal-string rule, **recursive** `canonical_bytes` ban; **BT-6 strengthened to key-set equality**; **BT-15** recursive tree search |
| **7** — S-9 omitted CI-enforced generated map; POS nav would mislead | No `permissions:export-frontend-map`; broad `pos` alias on every POS sidebar child would show the accountant ~7 bouncing links | S-9 row rewritten; **§4.3 note 2b** (map is a commit artifact, not a deploy step); new **GATE-5** least-privilege re-key with exact route permissions; **FT-14/FT-15**; owner-visible flag **OI-14** |
| **8** — A-1 role-correct but permission-model-incomplete; no nav entry | Single `compliance.view_reprint_log` route gate for a page hosting three separately gated capabilities; discoverability unaddressed | §4.2 A-1 rewritten: **option (a) adopted** — any-of route gate over the three permissions + **per-panel gating** + a **Settings → Compliance nav entry**; OI-5 resolution folded in |
| **9** — S-7 underspecified | No route placement, validation, scope semantics, lifecycle policy, ordering, cashier source | New **"S-7 — frozen endpoint contract"** table: route **before `/{id}`**, request rules, scope = company ∩ allowed ∩ requested (empty ⇒ 200 empty), inactive terminals included with `is_active`, **snapshot** cashier identity + dedup by latest `posted_at`, deterministic ordering, single-nested envelope; BT-8 extended by BT-12 |
| **10** — no validation / time-boundary contract | Raw string comparisons, unbounded `per_page`, browser-local "today" against a timezone-less `posted_at` | New **§4.6 (S-13)** FormRequest rule table + **company-timezone**, **half-open** date bounds, `meta.from`/`meta.to` echo; **BT-18** (midnight, DST, 422s) |
| **11** — wave sequencing impossible | Wave 1 claimed both voucher links healed while the detail route was wave 2; CL-2/CL-4 unassigned | §6 **"Wave ownership"** table (every CL item assigned; CL-3/CL-4 → wave 2); §7.5 rewritten; wave-1 exit criterion corrected to "links still dead, expected" |
| **12** — OI-4 and OI-5 are not valid open items | OI-4 was a tracked ES-19/OP-18 chain-context defect; OI-5 was already resolved by source and contradicted §4.2's own text | **OI-4 closed** with the ES-19/A1 cross-reference and the exact device/engine citations; **OI-5 closed as verified** (`RequirePermission.tsx:37-63`) |
| **13** — reprint tests stopped at FE request counting | No backend assertion on the fiscal consequence (audit row, copy number) | **BT-13** — one audit row per authorized fetch on both endpoints, incremented `copy_number`, correct method/user/receipt, **no row** on 403/404/location-denial; FT-4/FT-5 retained |
| **14** — S-6 did not name the index it asked for | "Composite + a partial index" left key columns, names and concurrency to invention | **"S-6 — exact index DDL"** block: two explicitly named indexes with full DDL, `down()` behaviour, `CONCURRENTLY` ruling (`$withinTransaction = false` if chosen; default plain), **BT-16** structural assertion, BT-10 kept advisory |
| **15** — verification command internally ambiguous | Bare `./scripts/preflight.sh` silently skips PHPUnit in default `paths` mode | §7.4 rewritten with the exact `PREFLIGHT_TEST_PATHS='…' ./scripts/preflight.sh` invocation, the scoped `pnpm vitest` command, **four required Playwright flows**, and the screenshot/evidence list |

**Also corrected from the gate's claim table (not separately numbered as defects):**
- *Finding 9* — the r1 heading "all additive, none changes an existing response shape" was false; §4.1 now declares S-4/S-5 an intentional `show()` shape change and states the consumer check.
- *Finding 13* — r1's "every `TerminalController` read action requires `pos.manage_terminals`" was false (`show()` is manage-or-operate; `available()`/`findByDevice()` are operate; `:100` is a write action). Corrected in **both** places it appeared (§3.c.1 and GATE-2); the narrower **collection-endpoint** argument, which is what S-7 actually rests on, is retained.
- *Finding 10* — the "S-5 alone yields complete lineage" overstatement is corrected: S-5 now also eager-loads `originalReceipt`, and the original **receipt number** comes from S-12.
- *Finding 26* — A-1's completeness caveat is addressed by the any-of gate + per-panel gating + nav entry.

**Nothing from gate r1 was skipped at edit level.** Where the gate offered a choice (defect 8 a/b, defect 11 either/or), r2 states which branch it took and why. (**r3 correction:** r2 additionally asserted that all 15 were *complete*. Gate r2 refuted that for eight of them — see the audit note above and §9.2.)

### 9.2 — r2 → r3 (gate round 2)

Source: `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r2.md` (verdict **FAIL**; 15/15 r1 defects addressed, **7 new internal-contract defects**). Where gate r2 verified a name against source, its correction is adopted verbatim; where it left a name or mechanism open, r3 verified it and the `file:line` is inline in the section.

**⚠️ r4 correction — what this table does and does not claim.** r3 wrote "All seven are applied", "Nothing from gate r2 was skipped" and that both extra N-5 corrections were source-verified. **Gate r3 refuted the completeness half of that** (`docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r3.md:5,33`): it re-verified all seven against source and confirmed **four as fully closed — N-3, N-4, N-6, N-7 (PASS)** — while marking **N-1, N-2 and N-5 PARTIAL**:

| r2 defect | Gate r3 verdict | Why it was not closed by r3 | Closed by |
|---|---|---|---|
| **N-1** | **PARTIAL** | The REFUND/VOID contradiction was removed, but `invoice_type_codes[]=TRAINING` with `include_training` absent/false still had two incompatible answers ("honours exactly what validation admits" vs. the independent default training exclusion) | **R3-1** — §9.3, **partially at r4** (gate r4 found its "exhaustive" table three code subsets short); **completed at r5** — §9.4 / **R4-1** |
| **N-2** | **PARTIAL** | The allowlist and bulk-load mechanic are correct, but "throws `InvalidArgumentException` in exactly two cases" is **source-false**: the reader's own docblock and its sub-DTO calls throw on malformed sub-objects too | **R3-2** — §9.3 |
| **N-5** | **PARTIAL** | BT-5, the VAT sums and the `tax_rate` key are correct, but the **extra** line-field source map r3 added on its own initiative pointed `product_sku` / `unit_of_measure_code` at the **current, mutable** product relations instead of the receipt-line snapshots | **R3-3** — §9.3 |
| N-3, N-4, N-6, N-7 | **PASS** | — | closed at r3; untouched by r4 |

The rows below therefore remain accurate about **what r3 edited**; they were **not** accurate as a completeness claim for N-1/N-2/N-5. They are left unedited so the rounds can be read against each other.

| Gate r2 defect | Severity | What r2 got wrong | Where r3 fixes it |
|---|---|---|---|
| **N-1** — binding API contract self-contradictory | BLOCKER | §3.a said `GET /pos/receipts` must never return REFUND/VOID *"whatever the client sends"*, then said the refunds register calls that same action with both codes. Both cannot hold | §3.a "Disjoint registers" **rewritten**: the API **honours the validated codes**; disjointness is a **screen** contract (no type tabs, no control that can emit those codes), not a server ban. "Whatever the client sends" **deleted**; the register table now states what each screen *sends*; new "Where disjointness is enforced" list points at FT-16 (request payload) + BT-2 (server default) + BT-2b (one-query mixed pagination). Columns "Type" row reworded from "structurally excluded". **OI-13 owner escape hatch unchanged**. ⚠️ **Gate r3: PARTIAL** — the training half of the type axis was still contradictory; closed by **R3-1**, §9.3 |
| **N-2** — allowlists omit fields the spec requires elsewhere; reader mechanic impossible | BLOCKER | List row omitted `refund_reason_source` **and** `refund_policy_alerts`; lineage omitted `refund_reason_source`; §4.5 forbade a per-row `CanonicalPayloadReader` call while requiring per-row exception degradation | §3.b.5(i) + (v) **extended** (five refund fields on the list row, `refund_reason` may never travel without `refund_reason_source`); §4.5 gains a `refund_reason_source` wire row and **rewritten mechanics**: `forSaleReceipt(FiscalEvent)` takes a hydrated model and issues **no query** (`CanonicalPayloadReader.php:64-125`), so the ban is on a per-row **query**, not a per-row **call** — bulk-load the page's events in one `whereIn`, call the reader per row in memory, catch its two documented `InvalidArgumentException` cases (`:66-79`) per row. No bulk reader API is invented, because none exists. §3.c.2 and the S-5 row ("triple") reconciled. ⚠️ **Gate r3: PARTIAL** — the allowlist and bulk-load mechanic hold, but "its two documented `InvalidArgumentException` cases" understated the reader's throw surface; corrected by **R3-2**, §9.3 |
| **N-3** — GATE-5 re-keys Tables to the wrong permission | BLOCKER | Tables mapped to `pos.operate_terminal`; the live route requires `pos.manage_tables` (plus `ModuleGuard module="Tables"`) | GATE-5 now carries a **frozen child→route table** with a verified `file:line` per row: `tables` → **`pos.manage_tables`**, kitchen → `pos.operate_terminal` + `ModuleGuard Menu`, vouchers → **keep the `pos` alias** (its route is `moduleKey="pos"`, so the alias *is* exact parity). Identity-entry list extended with `'pos.manage_tables'`. **FT-15 rewritten** with the seeded cashier grants: Tables becomes **hidden** for cashier (it lacks `pos.manage_tables`) and that is the intended outcome, since the link 403s today; **FT-14** corrected to expect Vouchers **visible** for the accountant |
| **N-4** — A-1 sidebar any-of not expressible; cited Settings group does not exist | BLOCKER | Three separate identity keys cannot express any-of on an item that carries one `permission:` string; "Settings → Compliance" group does not exist and the Settings leaf is gated by `settings.view`, which the accountant lacks | §4.2 A-1 item 3 **rewritten and frozen**: one **composite** `MODULE_PERMISSIONS` key `'compliance' → [export_jet, verify_chains, view_reprint_log]` — `canAccessModule` is `hasAnyPermission`, so this **is** the any-of gate with **no component change**; plus an exact nav item (**top-level bottom-section leaf, sibling of Settings, not a child**) with `labelKey`, icon and `permission: 'compliance'`. Parent-before-children filtering and the accountant's missing `settings.view` are cited as the reason nesting is impossible. Item 4's three-identity-key instruction superseded. Extending `NavItem` to `permissions[]` explicitly rejected. IA consequence → new **OI-16** |
| **N-5** — tests contradict frozen schemas | MAJOR | BT-5 froze `meta` as exactly the four paginator keys while S-13 requires `meta.from`/`meta.to`; BT-14 asserted `vat_details.net`/`.vat` against an allowlist naming `net_amount` | BT-5 asserts `{current_page,last_page,per_page,total,from,to}` and pins `from`/`to` as **ISO instants** (the meta block is hand-built key-by-key, `ReceiptController.php:134-143`, so there is no clash with Laravel's row-index `from`/`to` — and the test must fail loudly if someone refactors to `toArray()`). BT-14, §3.b.4 and the §7.1 binding rule all restated as `Σ vat_details[].net_amount` / `Σ vat_details[].vat_amount`. **Verified beyond the gate:** the rate key is **`tax_rate`**, not `vat_rate` (`create_pos_receipt_vat_details_table.php:33`; `ReceiptVatDetail.php:41-47`) — (iv-b) corrected, and a **field-source mapping note** added for (iii), whose `product_sku` / `unit_of_measure_code` / `vat_rate` / `vat_amount` are derived or renamed and have no such columns (`create_pos_receipt_lines_table.php:50-51`). ⚠️ **Gate r3: PARTIAL** — BT-5, the VAT sums and `tax_rate` PASS, but this extra field-source map sourced two historical line fields from the **current** product relations; **withdrawn and re-sourced by R3-3**, §9.3 |
| **N-6** — BT-13 targets a nonexistent table and an invented method distinction | MAJOR | Asserted `receipt_print_audits` and a "download vs stream" `print_method` | BT-13 asserts **`pos_receipt_prints`** (`ReceiptPrint.php:44`) and **`PrintMethod::Pdf` on both endpoints** (`ReceiptController.php:604-609,637-642`; enum has only `Pdf`/`Thermal`/`EscPos`, `PrintMethod.php:7-11`). The endpoints differ only by row count and copy sequence; a download/stream distinction is stated to be an **audit-schema change requiring separate approval** and explicitly not specified |
| **N-7** — §7.4 presented as exact but is not | MAJOR | No Playwright spec paths or command; the preflight invocation left `PREFLIGHT_VITEST_PATHS` unset, so preflight ran the **whole** web suite (`scripts/preflight.sh:197-203`) before the scoped Vitest command | §7.4 preflight invocation now sets **both** scope variables (path sets identical to the standalone commands); the four flows get a **table of frozen spec paths** under `apps/web/e2e/pos/` with waves, an **exact `pnpm exec playwright test --project=chromium …` invocation**, the config facts (`testDir`, `baseURL`, auto `webServer`), and a binding note to drive them through the **live-stack** `e2e/money-campaign/helpers.ts` (`loginAsRole`) rather than `e2e/fixtures.ts`, whose mocked `admin` `/auth/me` cannot exercise a permission gate |

**Every one of gate r2's seven defects was edited into r3 at text level, and four of them (N-3, N-4, N-6, N-7) were verified closed by gate r3.** Two corrections beyond the seven defects were made where r3's own source check found a frozen name that does not exist (`vat_details.tax_rate`; the derived line-field mapping) — both are recorded under N-5 rather than smuggled in silently. **The second of those two — the line-field mapping — was itself wrong** (it read current product data into a historical register) and is corrected in §9.3/R3-3; r3's claim that both extras were source-verified is withdrawn. No owner decision was taken by r3: the one new judgement call (nav placement) is filed as **OI-16** for the read-through.

### 9.3 — r3 → r4 (gate round 3)

Source: `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r3.md` (verdict **FAIL**; N-3/N-4/N-6/N-7 **PASS**, N-1/N-2/N-5 **PARTIAL**, three new defects **R3-1…R3-3**, plus a **FAIL on §9.2 as a completion claim**). All three defects and the §9.2 honesty finding are applied below. **N-3, N-4, N-6 and N-7 are untouched by r4** — the gate verified them against source and there was nothing to change.

| Gate r3 defect | Severity | What r3 got wrong | Where r4 fixes it |
|---|---|---|---|
| **R3-1** | **MAJOR** | §3.a promised the API "honours exactly what validation admits" while validation admitted `TRAINING` in `invoice_type_codes[]` independently of `include_training`, and the server default-excluded training regardless. `invoice_type_codes[]=TRAINING` + `include_training` absent/false had **two incompatible outcomes** | New **§3.a "Training axis — precedence, exhaustive"**: the gate's first recommended resolution is adopted — `include_training` is the **only** training switch, and `TRAINING ∈ codes` **without** `include_training=true` is a **422** (cross-field rule added to §4.6, `IndexReceiptsRequest::withValidator`). Because validation no longer admits the contradiction, "honours exactly what validation admits" is now **true as written** and is kept. A combination table states the outcome for the subsets of the four codes × both toggle states — including the two legal no-ops (`['SALE']`+`true` ⇒ SALE only; `['REFUND','VOID']`+`true` ⇒ unchanged) and the empty-array 422. Rests on the verified invariant `(invoice_type_code === 'TRAINING') ⟺ training_flag` (N-03, `FiscalPayloadConstraintValidator.php:901-914`; migration `2026_05_20_120000_…:15-32,44-45`), which is *why* the rejected pair requests a provably empty set. **BT-1** now owns the 200/422 matrix; **BT-2** explicitly defers the training axis to it; **FT-2** asserts the toggle always sends the code alongside the flag; **FT-16** asserts (c) sends no `include_training`. ⚠️ **Gate r4: PARTIAL** — the contradiction is genuinely resolved, but the table (and BT-1's matrix) called itself exhaustive while omitting `['SALE','REFUND']`, `['SALE','VOID']` and `['SALE','REFUND','VOID']` under **both** toggle states; the six rows are added and BT-1 extended at **r5** — see §9.4 / **R4-1** |
| **R3-2** | **MINOR** | §4.5 (and the §9.2/appendix restatements) said `CanonicalPayloadReader::forSaleReceipt()` throws `InvalidArgumentException` "in exactly two cases". Its own docblock and its sub-DTO calls contradict that | §4.5's degradation bullet **rewritten against source**: the two early guards (`CanonicalPayloadReader.php:65-77`) are stated as the *documented*, **not exhaustive**, cases; the docblock's third cause is quoted (`:55-62`); `SaleReceiptPayload::fromArray()` + every sub-DTO throw the same class through `FiscalPayloadArrayGuards` (`:38-51,44,64,83,103,123,148,164`) and `SellerDTO.php:41` / `BuyerDTO.php:43,47`; and the one **non-**`InvalidArgumentException` path is named — a non-array element in a list container raises a PHP `TypeError` from `fromArray(array $data)`, the gap the reader's own `@phpstan-ignore` at `:114` admits. Binding: wrap the **entire** per-row call in `catch (\InvalidArgumentException\|\TypeError)`, log + fall back, never `\Throwable`. **BT-17** gains a malformed-sub-object fixture for each of the two classes |
| **R3-3** | **MAJOR** | The extra N-5 "correction" mapped `product_sku` and `unit_of_measure_code` to the **current** eager-loaded `product` / `product.unitOfMeasure`, discarding the immutable snapshots and breaking when `product_id` is null | §3.b.5(iii) **re-sourced from snapshot columns only**. `product_sku` → **`product_code`**, read from `pos_receipt_lines.product_code`, the column PG itself comments as *"Immutable snapshot: Product code at time of sale"* (`2026_01_08_190638_…:39,89`; `ReceiptLine.php:28`; projector writes canonical `sku` there, `PosCoreReceiptProjection.php:1189`; legacy path `ReceiptCreationService.php:212,222,336`). **`unit_of_measure_code` is DROPPED**: the canonical `line_items[]` DTO has **no unit field at all** (`LineItemDTO.php:31-48`), and although `pos_receipt_lines.unit` exists as a snapshot column (`…:45`), the fiscal projector writes the **constant `'pc'`** into it for every v3+ receipt (`PosCoreReceiptProjection.php:1193`) — the same "real column filled with a constant" trap as `return_reason`. Restoring a unit label requires a **new snapshot contract** (canonical payload field + projector write), filed as **OI-17**. `product_id` is documented as nullable-by-design (`PosCoreReceiptProjection.php:1157-1176`); `returned_quantity` is documented as action-derived (`ReceiptController.php:490-501,511-532`); current-product enrichment survives **only** for `quantity_decimals` with its fallback-4 precedent. **BT-6** asserts `product_code`, the absence of both dropped keys, a null-`product_id` line, and snapshot-not-live sourcing; **FT-6** asserts no unit symbol renders; **S-4** gains the `lines.product.unitOfMeasure` eager-load widening the enrichment actually needs |
| **§9.2 completion claims** | — | "All seven are applied", "Nothing from gate r2 was skipped", and "both extra N-5 corrections source-verified" were completion claims the gate refuted | §9.2's preamble now carries the **per-defect gate r3 verdict table** (4 PASS / 3 PARTIAL) and its closing paragraph is restated: the seven were applied **at text level**, four are **verified closed**, and the second extra N-5 correction is **withdrawn as wrong**. The r3 rows are left unedited as edit history |

**Scope of r4, stated honestly.** r4 changes exactly what gate r3 required: §3.a (new training-axis block + one sentence), §4.6 (one cross-field rule + `min:1`), §4.5 (the degradation bullet + BT-17 fixture), §3.b.5(ii)/(iii) (the line allowlist and its field-source map), S-4's eager-load clause, the **six** affected test rows (BT-1, BT-2, BT-6, FT-2, FT-6, FT-16 — **r5 correction, gate r4 defect R4-2: r4 wrote "five" while enumerating six**), §9.2's honesty, this log, **OI-17**, and the appendix. **No section covered by N-3/N-4/N-6/N-7 was edited.** One judgement call was made and is **not** taken as an owner decision: dropping the line unit rather than inventing a snapshot for it — filed as **OI-17** for the read-through. **r5 update on the last sentence:** r4 stated it had not been re-gated. It since was — gate r4 verified **R3-2 and R3-3 PASS** and **OI-17 as holding**, and marked **R3-1 PARTIAL** (completed at r5) alongside the count defect above. §9.4 records that round; nothing in §9.4 has itself been gated.

### 9.4 — r4 → r5 (gate round 4)

Source: `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r4.md` (verdict **FAIL**). The gate verified **R3-2 (PASS)**, **R3-3 (PASS)** and **OI-17 as holding**, and confirmed §9.2's r4 correction as honest. It marked **R3-1 PARTIAL** and the **revision-history honesty PARTIAL**, raising two new defects. It re-litigated **no** round-1 defect and **no** round-3 PASS item.

| Gate r4 defect | Severity | What r4 got wrong | Where r5 fixes it |
|---|---|---|---|
| **R4-1** | **MAJOR** | §3.a's "Every combination, exhaustively" table, the §4.6 and §9.3 completeness language, and BT-1's "full matrix" all omitted the three **legal, non-TRAINING** mixed subsets — `['SALE','REFUND']`, `['SALE','VOID']`, `['SALE','REFUND','VOID']` — under **both** `include_training` states: six cases in total. The established rules imply their outcome, but the promised exhaustive matrix did not state it | §3.a's table gains the **six rows**, derived from the already-frozen rules and from nothing new: validation admits every one of them (no `TRAINING` member ⇒ rule 2 never fires; every member is inside the `in:` domain; `min:1` is satisfied), so by rule 3 the API **returns exactly the requested code set**. With `include_training` absent/false the `training_flag = false` predicate is applied and is **vacuous** — by the N-03 invariant (`FiscalPayloadConstraintValidator.php:901-914`) no `SALE`/`REFUND`/`VOID` row can carry `training_flag=true`; with `include_training=true` the predicate is merely dropped and the explicit array still bounds the set, so the response is **byte-identical** — the same documented no-op already recorded for the `REFUND`/`VOID` rows. **BT-1** is extended with all six as data-provider cases (200, exact code set, and a byte-identical body across the toggle pair). The table's own header now states the coverage it achieves — all 15 non-empty subsets × both toggle states, plus absent, empty and out-of-domain — so the "exhaustively"/"full matrix" language in §3.a, §4.6 and BT-1 is retained **because it is now true**, not asserted ahead of the content. §9.3's "complete combination table" wording is withdrawn and its R3-1 row carries the **PARTIAL at r4 / completed at r5** marker; §9.2's N-1 "Closed by" cell says the same |
| **R4-2** | **MINOR** | §9.3's scope statement said "the five affected test rows" while enumerating **six** (BT-1, BT-2, BT-6, FT-2, FT-6, FT-16) | The word is corrected to **six** in place, with the miscount named rather than silently overwritten |

**Scope of r5, stated honestly.** r5 changes exactly four things: (1) §3.a's combination table — six added rows plus a header sentence stating the coverage; (2) §3.a's "Enforced by" line and the **BT-1** row, extending the matrix to those six cases; (3) §9.3's five→six count and its withdrawn "complete" wording, plus the R3-1 PARTIAL/completed markers in §9.2 and §9.3; (4) this §9.4, the revision header/status, and the gate-r4 pointer in the appendix. **Nothing else was touched** — no source verification was redone, no new rule was invented (every added row is derived from rules 2–4 and the N-03 invariant already frozen at r4), and **no section covered by R3-2, R3-3, OI-17, or any r3-PASS item (N-3/N-4/N-6/N-7) was edited**. No owner decision was taken at r5; the open items register is unchanged. **This spec has not been re-gated since these edits.**

### 9.5 — r5 → r5.1 (implementation terminal audit)

Source: parent terminal audit and the accepted narrow re-audit at `docs/handoff/reviews/receipts-build/M2-terminal-round2.md`. The parent required the dormant `is_voided` filter axis to become visible rather than leaving migrated legacy-voided sales indistinguishable from live sales. The list DTO therefore emits `is_voided`; §3.b.5(i) now names it and the already-emitted legacy `receipt_type` field in the exhaustive allowlist; BT-5 locks the complete base row key set. No other frozen wire shape or behavior changed in r5.1.

## 10. Residual risk (carried from gate r1 §5)

Fiscal-chain presentation stays release-sensitive to whichever A0/A1 code lands first. Screen (d) is now blocked on A0 rather than captioned around it, which removes the *dishonest-control* risk, but it does **not** remove the *contract-drift* risk: the wave-2b builder **must re-open `ReportController::verifyChain` and `ReceiptHashService` and re-verify the post-remediation response** rather than trusting §3.d's current-source analysis. Tracked as **OI-15**.

---

## Appendix — primary sources

**Research consolidated:** `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/12-research-receipts-web-view.md` · `…/14-research-pos-module-first-tenant-reporting.md` · `docs/handoff/FINDINGS-other-problems-2026-08-11.md` · `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md`

**Verified in code during spec authoring (2026-08-11):** `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:134-135` · `Presentation/Controllers/TerminalController.php:52,100` · `Presentation/Controllers/ReportController.php:411-489` · `Domain/Services/ReceiptHashService.php:206-213,234-239` · `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (accountant block) · `apps/web/src/hooks/usePermissions.ts:44-56,129-134` · `apps/web/src/routes/index.tsx` (`compliance/export` gate; quarantine comment `:2925-2929`) · `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:182,226-244,432` · `apps/web/src/hooks/useTableState.ts:4-44` · `apps/web/src/lib/locationScopedKey.ts:11-17` · `apps/web/src/features/pos/pages/ZReportListPage/ZReportListPage.tsx:27-42` · `apps/web/src/features/pos/components/ShiftReceiptsList.tsx` + `components/index.ts:4` · `apps/web/src/locales/{en,fr,ar}/pos.json`

**Gate r1 (r2 is the response to it):** `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r1.md`
**Gate r2 (r3 is the response to it):** `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r2.md`
**Gate r3 (r4 is the response to it):** `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r3.md`
**Gate r4 (r5 is the response to it):** `docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r4.md`

**Verified in code during r4 (2026-08-11) — the R3-1…R3-3 fixes:**
- **R3-1 (training axis):** `apps/api/database/migrations/tenant/2026_05_20_120000_add_invoice_type_code_and_training_flag_to_pos_receipts.php:15-32,34-37,44-45` (`invoice_type_code` default `'SALE'`, `training_flag` default `false`, documented as denormalized from `invoice_type_code == 'TRAINING'`; legacy rows take the defaults) · `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:57,187,901-914` (invoice-type enum domain; **N-03 invariant `(invoice_type_code === 'TRAINING') ⟺ training_flag`** enforced pre-signature, `payload_training_flag_mismatch`) · `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:395,405-406` (both columns written from the signed payload).
- **R3-2 (reader exception surface):** `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:55-62` (docblock: **also** throws when sub-objects fail the typed-DTO constructor), `:65-77` (the two early guards), `:79-117` (payload + sub-DTO construction, incl. the `@phpstan-ignore` at `:114` admitting the unchecked list-element shape) · `apps/api/app/Modules/Fiscal/Domain/DTOs/FiscalPayloadArrayGuards.php:9-33,38-51,44,64,83,103,123,148,164` (every throw is `InvalidArgumentException`) · `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:113-153` (all keys routed through the guards) · `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SellerDTO.php:41`, `BuyerDTO.php:43,47` (own shape guards, same class).
- **R3-3 (line snapshot sourcing):** `apps/api/database/migrations/tenant/2026_01_08_190638_create_pos_receipt_lines_table.php:33-36` (nullable `product_id`), `:39-41` (`product_code`/`product_name`/`product_description` snapshot), `:45` (`unit` snapshot, default `'unit'`), `:89` (PG comment *"Immutable snapshot: Product code at time of sale"*) · `apps/api/app/Modules/POS/Domain/ReceiptLine.php:17-40,69-104` (immutability docblock; `product_code`/`unit` fillable) · `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1157-1176` (null `product_id` is deliberate — the sealed snapshot is authoritative), `:1189` (canonical `sku` → `product_code`), `:1193` (**`'unit' => 'pc'` hardcoded**) · `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/LineItemDTO.php:31-48` (13+3 properties, **no unit field**) · `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:212,213,222,223,336,340` (legacy path snapshots sellable code and unit) · `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:475-486` (`show()` eager-loads `lines.product` **without** `unitOfMeasure`), `:490-501` (`returned_quantity` injected by the action), `:511-532` (magnitude semantics) · `apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:305-330` (the fallback-4 enrichment precedent, commented *"Presentation-only enrichment"*).

**Verified in code during r3 (2026-08-11) — the N-1…N-7 fixes:** `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:64-125` (takes a hydrated `FiscalEvent`, issues no query — **the "throws only on non-`SALE_RECEIPT` and NULL payload" half of this r3 entry is WITHDRAWN as source-false; see the r4 block below and §4.5**) · `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:134-143` (hand-built 4-key `meta`, not `$paginator->toArray()`), `:604-609,637-642` (both PDF actions record `PrintMethod::Pdf`) · `apps/api/app/Modules/POS/Domain/ReceiptPrint.php:44` (`$table = 'pos_receipt_prints'`) · `apps/api/app/Modules/POS/Domain/Enums/PrintMethod.php:7-11` (`Pdf`/`Thermal`/`EscPos` only) · `apps/api/app/Modules/POS/Domain/ReceiptVatDetail.php:41-47` + `database/migrations/tenant/2026_01_08_190639_create_pos_receipt_vat_details_table.php:33-38` (`tax_rate`, `net_amount`, `vat_amount`, `gross_amount`) · `database/migrations/tenant/2026_01_08_190638_create_pos_receipt_lines_table.php:39-51` (no `product_sku`/`unit_of_measure_code`/`vat_rate`/`vat_amount` columns — **but `product_code`, `product_name` and `unit` DO exist as snapshot columns; r3's inference that the missing names justified current-product enrichment is corrected by R3-3**) · `apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:300-330` (line enrichment precedent) · `apps/web/src/routes/index.tsx:2872-2951,2894-2921,3113-3132,3193-3205` (POS route gates incl. `tables` → `pos.manage_tables` + `ModuleGuard Tables`) · `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:110-122` (single `permission?: string` per nav item), `:226-244` (POS group), `:353-367` (`supportAccess` `labelKey` idiom; `settings` leaf), `:425-437,445-455` (visibility gate; parent filtered before children) · `apps/web/src/hooks/usePermissions.ts:53,56,127-134` (`settings`→`settings.view`; `pos` alias; `canAccessModule` = any-of) · `apps/web/src/hooks/permissionsMap.generated.ts:35-37,170-189,249` (compliance trio; POS grants per role; `settings.view` excludes accountant) · `apps/web/playwright.config.ts` · `apps/web/package.json:20` · `apps/web/e2e/money-campaign/helpers.ts:10-48` (`loginAsRole`, real-role credentials) · `apps/web/e2e/fixtures.ts:1-40` (mocked `admin` `/auth/me` — unusable for permission gates) · `scripts/preflight.sh:34-41,197-203` (`PREFLIGHT_VITEST_PATHS`; unset ⇒ full `pnpm test`)

**Verified by gate r1 and adopted in r2 (2026-08-11):** `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:234-250` (context-flattening walk — **retracts r1's "fiscal-aware" reading of `:206-213,234-239`**) · `apps/api/database/migrations/tenant/2026_05_14_100001_create_fiscal_events_table.php:37-42,91-95` · `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php:61-67` · `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:172-181` · `docs/handoff/ES-REGISTER-CORRECTIONS-2026-08-11.md:17-25` · `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:417-430,434-446` · `apps/api/database/migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php:158-170` · `apps/api/app/Modules/Company/Services/LocationContext.php:181-238` · `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:45-65,68-90,308-310,496-498` (**corrects r1's `:52,:100`**) · `apps/web/src/features/auth/components/RequirePermission.tsx:37-63` · `apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:12-40` · `scripts/preflight.sh:71-90,136-161` · `apps/web/src/hooks/permissionsMap.generated.ts:188-189` · `apps/api/app/Modules/Compliance/Presentation/routes.php:59-71` · `apps/web/src/features/compliance/pages/ComplianceExportPage.tsx:8-20` · `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/OriginalReceiptReferenceDTO.php:24-28` · `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:107,225` · `apps/api/database/migrations/tenant/2026_01_08_190637_create_pos_receipts_table.php:29-31,47-53,87-97` · `apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:61` · `apps/pos/src/lib/offline/receiptService.ts:256-267,489-505` · `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:539-574,798-824`

**Cross-lane:** `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/00-CONSOLIDATED-REGISTER.md:68,191` · `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md:50,104,119` · `docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md` · `docs/architecture/precision-contract.md:192-205` · `docs/architecture/vertical-module-gating.md:146-155` · `docs/handoff/cash-rounding-phase2-deploy-checklist.md:218-240` · `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md`
