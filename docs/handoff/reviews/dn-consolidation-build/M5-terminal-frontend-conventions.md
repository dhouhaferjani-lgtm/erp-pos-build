# Terminal whole-branch gate — M5, lens: frontend-conventions (+ general)

**Wave:** `dn-consolidation-build` · **Branch:** `codex/dn-consolidation-2026-08-12`
**Range reviewed:** `60df88a01..8faec0952` (whole branch, re-pin base to tip)
**Tip at review:** `8faec0952` — *"Phase 2.5.1: VP-1 — en unbilledLine count strings mirror fr"*
(the parent's own string fix from its live visual pass; reviewed here like any other commit)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation` (read-only; working tree clean)
**Inputs consulted:** `M5-evidence.md`, `M1-round1..M4-round3`, `SPEC-dn-consolidation-billing-2026-08-11.md`
§3 / §4 / §6.3, `CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md` M5 row,
`docs/handoff/progress/dn-consolidation-build.progress.yaml`.

**Everything below was re-run or re-derived at this tree.** No count, gate result or citation from
`M5-evidence.md` or any earlier register was accepted on trust.

---

## A. Gates I ran myself

| Gate | Command | Result |
|---|---|---|
| Focused FE suite (parent-named) | `npx vitest run --maxWorkers=1 src/features/documents/to-bill src/features/documents/delivery-notes src/features/partners/PartnerDetailPage.deliveryNotes.gates.test.tsx src/routes` | **12 files / 75 tests passed**, exit 0 |
| §6.3 registry sweep | `npx vitest run --maxWorkers=1 src/features/documents src/features/partners src/features/finance src/routes src/components/organisms/Sidebar` | **Test Files 2 failed \| 91 passed (93) · Tests 2 failed \| 725 passed (727)** |
| TanStack key audit | `node tools/audit-tanstack-keys.mjs` | Gate C **0**; baseline **0 acknowledged, 0 new, 0 stale** |
| Design-system audit | `node tools/audit-design-system.mjs` | **728 acknowledged, 0 new, 0 stale** |
| TypeScript | `npx tsc --noEmit` | clean, exit 0 |
| en/fr key parity, all 2 touched namespaces | own script over `src/locales/{en,fr}/*.json` | `sales` and `finance` **0 EN-only, 0 FR-only** |
| en/fr interpolation-variable parity | own script | **0 mismatches** across `sales` + `finance` |

**§6.3 claim adjudicated: the M5 evidence is exactly right.** I reproduce **725/727**, and the two
reds are byte-for-byte the two pinned `ui-wave0 M0b`-owned finance tests —
`src/features/finance/api.test.ts` (*"fetches upcoming payments from the C3 endpoint without
double-unwrapping"*) and `src/features/finance/hooks/__tests__/tenantScope.test.tsx`. Confirmed
inherited by construction: `git diff --name-only 60df88a01..8faec0952 -- apps/web/src/features/finance/`
is **empty** — this branch touched no file in that directory (only `locales/{en,fr}/finance.json`,
one key each). `PartnerForm.test.tsx:356` did not flake for me either, matching the M4-round2 N4 record.

**Pre-existing locale parity gaps are unchanged, not introduced.** `common.json` (15 FR-only) and
`treasury.json` (1 FR-only) show the same counts at `60df88a01` as at the tip. Neither namespace was
touched by this wave.

---

## B. Baseline honesty (protocol step 2) — PASS

`apps/web/tools/audit-design-system-baseline.json`: **6 removals, 0 additions.** Replayed entry-level:

| Removed entry | Justification at tip |
|---|---|
| 2× `C2 … DeliveryNoteConsolidation.tsx` (raw select-all + row checkboxes) | file **deleted** by M4 (`38fdd18f9`) |
| 3× `C3 … DeliveryNoteConsolidation.tsx` (three raw buttons) | same file, deleted |
| 1× `C2 … B2BFieldsSection.tsx` `<select id="consolidation_frequency">` | selector **deleted** (`B2BFieldsSection.tsx` diff removes the whole `{invoiceConsolidation && …}` block, and `PartnerForm.tsx` drops the field from the interface, defaults, hydration and payload) |

Every removal maps to genuinely deleted code. **Zero additions** — i.e. the three new FE surfaces
(`to-bill/ToBillPage.tsx`, `delivery-notes/PartnerDeliveryNotesTab.tsx`,
`delivery-notes/DeliveryNoteBillingStatus.tsx`) introduced **no** new C1–C6 debt. That zero is
honest and not an artefact of scope: `tools/audit-design-system.mjs:114` treats anything under
`src/features/` as in-scope, so `to-bill/` and `delivery-notes/` are scanned, and the new code
genuinely uses the atoms (`Input`, `Button`, `StatusBadge`, `PageHeader`, `DataTable`,
`OffsetPagination`, `EmptyState`, `QueryError`, `ConfirmDialog`) rather than raw controls or a
parallel picker.

## C. Mechanism audit (protocol step 3) — PASS, no evasion

- **No alias table.** The new files import `semanticColorTokens as colorTokens` — the established
  in-repo rename (already used by `PartnerDetailPage.tsx` at the base), not a re-export layer.
  `src/lib/designTokens.ts` is **not in the diff at all**: no token was added, renamed or
  shade-substituted, so the "extend, never substitute" rule is trivially satisfied.
- **No suppressions.** `git diff` added lines contain no `eslint-disable`, `ts-ignore`,
  `ts-expect-error` or `.skip(` on any FE path.
- **No dead-CSS interpolation** (protocol step 4, the invisible class of bug):
  `grep -rnE '(hover|focus|active|group-hover|disabled|dark|sm|md|lg|xl):\$\{|\}/[0-9]'` over
  `to-bill/`, `delivery-notes/`, `deliveryNoteBillingRefusal.ts`, `hooks/useDeliveryNotes.ts`
  returns **zero hits**. Every variant/opacity class ships whole inside `designTokens.ts`
  (`intent.primary.bgSubtleAlphaLight = 'bg-blue-50/30'`, `intent.neutral.bgHover = 'hover:bg-gray-50'`,
  `intent.primary.textHoverStrongest`), which Tailwind scans.
- **No `parseFloat` / `Number()` / `toFixed` on money** anywhere in the new FE code.
  `formatAmount(value, currency)` and `useCurrency().format` are used throughout; payloads carry
  decimal strings. The three `parseFloat` calls M4 removed went out with the retired page.
- **Route manifest is honestly updated, not bypassed:** `scripts/factory/manifests/routes-web.yaml`
  *adds* `/sales/to-bill` (`module_gate: Sales`, `permission: deliveries.view`) and *removes*
  `/inventory/delivery-notes/consolidate`. The lane recorded its own routes rather than absorbing
  drift.

---

## D. The charge, item by item

### D1. OI-8 conditions 1–4 on the surviving surfaces — **HOLD** (one asymmetry, §E1)

Consolidation was deleted, so three surfaces carry the ratified behaviour: the **partner tab**
(`PartnerDeliveryNotesTab.tsx`), **View B** (`ToBillPage.tsx`, via the `ToBillRefusalAlert`
component), and the **SO lane** (`SalesOrderDetailPage.tsx`).

| # | Condition | Partner tab | View B | SO lane |
|---|---|---|---|---|
| 1 | Persistent inline, never a toast | `:39` state → `:207-213` `role="alert"` | `:311` state → `:67` `role="alert"`, mounted `:503-509` | `:434-437` `role="alert"` + `aria-labelledby` |
| 2 | Every lost DN named with its taker | `:219-258` — number, date, lane label, invoice `<Link>` | `:81-128` — same, `entityRoutes.document(…,{documentType:'invoice'})` at `:108` | `:450-473` — same shape, but see §E1/§E3 |
| 3 | Guarantee in words, en + fr, via `t()` | `:216` | `:78` | `:447` |
| 4 | No bare retry | `:265-277` remainder-only, gated `selectedIds.size > 0` | `:130-142` gated on `remainingCount > 0` computed `:63`; resubmits only un-refused ids `:372-384` | `openInvoice` / `invoiceRemaining` / `noRemaining` / `remainingUnavailable`, `:475-491` |

Toast suppression for the attributed 422 is real and shared:
`useDeliveryNotes.ts:208-212` fires `toast.error` **only** when
`parseDeliveryNoteBillingRefusal(error) === null`, so the 422 never double-surfaces as a toast on
either consolidation-lane surface. Generic failures still toast. Verified by the green tests
`PartnerDeliveryNotesTab.test.tsx:136,174,227,261` and `ToBillPage.test.tsx:251`.

### D2. Badge + invoice link + `legacy_unknown` neutrality — **HOLDS**

`DeliveryNoteBillingStatus.tsx:9-14` maps `legacy_unknown → 'neutral'`, and `neutral` resolves to
`tokens.badge.gray` (`StatusBadge.tsx:37`) — a genuinely neutral gray pill, not an alert colour.
The `null`/unrecognised lane also falls to `'neutral'` (`:44`). `en`/`fr` both carry all five labels
(`invoicedVia.legacyUnknown` = *"Invoiced (source not recorded)"* / *"Facturé (origine non
enregistrée)"*). Un-invoiced rows use `tone="pending"`, which is **also** `tokens.badge.gray` — so
the billing-state column adds no colour to the default filter. The badge is paired with a link to the
taking invoice through `entityRoutes.document(id, { documentType: 'invoice' })` on both
consolidation-lane surfaces — **the correct entity route with the correct id**; the
delivery-note-id-in-an-invoice-URL class of defect does not occur anywhere in this diff.
Asserted by `PartnerDeliveryNotesTab.test.tsx:412` (en **and** fr) and
`DeliveryNoteDetailPage.billingStatus.test.tsx:89,105`.

### D3. The A2 line with the VP-1 string — **en and fr both render sane shapes**

Ran the two focused files; both green. Strings at tip:

```
en  count_one   "({{count}} delivery note in {{currency}})"
en  count_other "({{count}} delivery notes in {{currency}})"
fr  count_one   "({{count}} bon de livraison en {{currency}})"
fr  count_other "({{count}} bons de livraison en {{currency}})"
```

Rendered: *"Delivered, not yet invoiced (3 delivery notes in TND) — 12 430,750 TND"* /
*"Livré, pas encore facturé (3 bons de livraison en TND)"*. The pre-VP-1 en form
(`"({{count}} {{currency}} delivery note)"` → *"3 TND delivery notes"*) is gone; en now mirrors fr's
preposition placement. Both plural forms exist in both locales, `count` + `currency` interpolations
match across locales (verified by script, §A). The commit is a pure two-string + two-assertion change
(`git show 8faec0952 --stat`: 2 files, 4+/4-) — no code, no scope creep.

The A2 line and the tab summary are **one query, not two**: `PartnerUnbilledBalanceLine` calls
`usePartnerDeliveryNotes({filter:'uninvoiced', page:1, perPage:10})`, byte-identical to the tab's
initial state, so they share a cache entry and cannot disagree — spec §3.1's *"the tab and the
balance line must show the same number for the same filter"* is satisfied structurally, and pinned by
`PartnerDeliveryNotesTab.test.tsx:389`.

Money on that line goes through `useCurrency().format` on the decimal string. The aggregate is
company-currency by backend construction (`UninvoicedDeliveryNoteService.php:310`
`->where('documents.currency', $company->currency)`), so formatting with the company formatter is
correct, not a currency mismatch.

### D4. C9 invalidations are `tenantScopedKey`-built — **HOLDS**

`useDeliveryNotes.ts:183-205`: seven `invalidateQueries` under one `Promise.all`, each using
`scopedNamespacePredicate(namespace, tenantId, companyId)` (`:26-40`), which requires
`k[k.length-2] === tenantId && k[k.length-1] === companyId`. Namespaces covered: `delivery-notes`,
`documents`, `invoices`, `delivery-note`, `document`, `partner-account-balance`,
`delivery-notes-to-bill` — the spec's four (`SPEC:718`) plus three.

**The seam that could have silently broken this, checked:** the queue's keys are built by
`locationScopedKey`, not `tenantScopedKey` directly. `locationScopedKey.ts:16` inserts
`{ locScope }` **before** delegating to `tenantScopedKey`, so tenant/company remain the last two
segments and the `k.length-2` predicate still matches `['delivery-notes-to-bill', …, {locScope}, tenant, company]`.
Had the location segment been appended last, the queue invalidation would have been a silent no-op.
It is not. `audit:keys` Gate C is **0** with a 0/0/0 baseline.

### D5. Module gating per rule 12, on every new FE surface — **HOLDS on both layers**

| Surface | Backend | Frontend |
|---|---|---|
| `/sales/to-bill` | `GET /delivery-notes/uninvoiced` — `['module:Sales','can:deliveries.view']` (`Document/Presentation/routes.php:275-277`) | `routes/index.tsx:583-595` — `ModuleGuard module="Sales"` at `:586` wrapping `RequirePermission permission="deliveries.view"` at `:587` |
| group expansion | `GET /delivery-notes/uninvoiced/{partner}` — `->whereUuid('partner')` + `['module:Sales','can:deliveries.view']` (`:279-282`) | same route gate |
| per-group / per-selection action | `POST /delivery-notes/consolidate-to-invoice` — `['module:Sales','can:invoices.create']` (`:298-300`) | `ToBillPage.tsx:314` `hasPermission('invoices.create')`; `PartnerDetailPage.tsx:909` passes `hasSalesModule && canCreateDeliveryNoteInvoice` |
| partner "Delivery notes" tab | (list route unchanged — recorded residual, §F) | `PartnerDetailPage.tsx:219-225` — `isCustomerContext && hasModule('Sales') && hasPermission('deliveries.view') && type∈{customer,both}` |

Route ordering is correct: the static `uninvoiced` routes are declared **before**
`GET /delivery-notes/{deliveryNote}`, and the parameter route additionally carries `->whereUuid('deliveryNote')`
(`:285`) — belt and braces, as §3.2 required.

Gates are asserted **independently**, not in one combined test:
`ToBillRoute.gates.test.tsx:30` (admin + `deliveries.view`, Sales **off** → dashboard fallback),
`:39` (Sales on, no permission → fallback), `:47` (both → renders);
`PartnerDetailPage.deliveryNotes.gates.test.tsx:140,150,158,168,180`.
The **module gate fails closed**: `Sidebar.tsx:436-441` requires the module to be present in
`all_enabled_modules` with no fallback-to-visible, and the `sales` group itself carries
`module: 'Sales'` (`:162`), so the new `toBill` child is hidden with its group on a non-Sales tenant.
No `canAccessModule`-with-an-unknown-key and no role-name heuristic anywhere in the new code —
notably, the retired route's `permission="sales.create"` **role alias** went out with it and the
replacement uses the real `deliveries.view`.

**No orphaned route.** `/sales/to-bill` is wired into the sidebar (`Sidebar.tsx:171`) and into the
factory manifest. `/finance/lane-separation` gains its nav entry with `permission: 'reports.financial'`
(`Sidebar.tsx:301`), 1:1 aligned to the route guard at `routes/index.tsx:2049`, honouring the I-2/I-3/I-5
alignment invariant. The retired route is proven gone by `DeliveryNoteConsolidationRoute.retired.test.tsx:53`
and by grep: the only two remaining mentions of the string in `src/` are inside that regression test.

### D6. Tokens / i18n / en–fr parity across ALL new keys of the whole wave — **HOLDS**

`sales` and `finance` are at full en/fr key parity and full interpolation-variable parity (§A).
No raw i18n key strings are rendered on the two consolidation-lane surfaces: the only dynamic key
(`billedBy.${invoiced_via}`) carries an explicit `defaultValue` on both
(`ToBillPage.tsx:84-86`, `PartnerDeliveryNotesTab.tsx:223-226`) — **except on the SO lane, §E1**.
No user-facing literal escapes `t()` in the new files. No brand string leaked: grep for
`AutoERP|Syneriva|Otospex|IziPOS` over the wave's added `apps/web` lines returns **zero**.
Retirement was clean at the i18n layer too — `deliveryNotes.consolidation.*` now contains **only**
`billingRefusal.*`; every key belonging to the deleted page is gone, and `consolidationFrequency` /
`selectFrequency` / `partners.consolidation.weekly|monthly` are absent from both locales. No dead
keys, no orphan keys.

### D7. Owner-ruled UI principles — no violations found

- **One main element per screen.** The tab's emphasis is un-billed goods; invoiced rows are
  `opacity-60` + `surface.muted`, and un-invoiced rows carry a **gray** pending pill. The A2 line's
  tint is `bg-blue-50/30`. View B's single accent is the "Total left to bill" tile
  (`intent.primary.bgSubtleAlphaLight`) against four `surface.muted` bucket tiles. Nothing added
  competes with the primary element. (One small colour-vocabulary nit: §E4.)
- **Enrichment/hero surfaces blend in (OQ-5).** No new high-contrast decorative band exists in the diff.
- **Dead controls hidden until real (OQ-11).** No `disabled={true}`, no coming-soon toast, no
  placeholder modal, no empty ternary branch shipped. Absent capability is *hidden*, not disabled:
  the create-invoice button is `{canCreateInvoice ? … : null}` on both surfaces; the all-locations
  toggle renders only when the **server** reports `scope.can_view_all_locations`. (One inert-but-visible
  control survives: §E2, already disclosed by the handback.)
- **Brand/app name from config (OQ-1).** No literal brand string added.
- **Refunds separate from sales / OQ-12 customer vs supplier separation.** The tab renders only in
  `isCustomerContext`; the queue is customer-partner-scoped in the shared backend scope. No return
  note of either direction surfaces in any view this wave adds. The wording throughout is
  *"delivery notes"* / *"bons de livraison"* — sales-side supplier-return confusion is not possible here.
- **Blind counting (A-9).** Not applicable to this surface set.
- **UI must not overstate system guarantees.** The strongest claim shipped is
  `billingRefusal.guarantee` = *"No invoice was created. No invoice number was used."* This is
  **backed at the tip**, not aspirational: `claim()` is the only public entry point, invoice creation
  is passed to it as a closure so it cannot run before the reservation returns, and `L5`
  (`document_sequences[invoice]`) is only ever taken *inside* that closure — i.e. a losing claim
  consumes no invoice number. Likewise the four-line `coexistence` copy (*"A delivery note is billed
  once, by whichever route reaches it first"*) describes an invariant this branch actually enforces
  (conditional `UPDATE … WHERE payload->>'invoiced_at' IS NULL` + `delivery_note_billing_marks`
  primary key). Neither is an overstatement.

### D8. Cross-milestone seams the per-milestone (delta-scoped) reviews could not see

I diffed the milestones against each other rather than against their own bases:

| Seam | Verdict |
|---|---|
| M2 tab ↔ M3 queue share `useConsolidateDeliveryNotes` | Sound. One mutation, one invalidation set, one refusal parser (`deliveryNoteBillingRefusal.ts`) consumed by both. The hook's success toast (`deliveryNotes.partnerTab.created` = *"Invoice created from delivery notes"*) reads correctly on both surfaces. |
| M2 tab ↔ M3 queue share query-key namespaces | Sound, and the `locationScopedKey` suffix-order hazard is not present (§D4). |
| M2 tab ↔ M3 queue share i18n | Works; `deliveryNotes.partnerTab.coexistence` is reused verbatim on the **global** queue (`ToBillPage.tsx:500`). Content is correct globally; the key **name** is now misleading (§E5). |
| M4 deletions ↔ M2/M3 consumers | Clean. No import, barrel export, route, manifest entry, i18n key or baseline entry survives the deleted page. |
| M4 `consolidation_frequency` retirement ↔ partner form/type | Clean: interface, defaults, hydration and payload all dropped; only inert test fixtures still carry the field, and typecheck is green. |
| **M1 SO refusal surface ↔ M2/M3 refusal surfaces** | **The one real seam defect — §E1 and §E3.** M1 shipped before M2/M3 established the `defaultValue` fallback and the `entityRoutes` centralization (`483457a41`), and no later milestone's delta ever compared the three surfaces side by side. |

---

## E. Findings

### E1 — MAJOR — `apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:460` — the SO refusal surface renders a dynamic i18n key with **no** `defaultValue`, unlike its two siblings; and the M5 evidence overstates the three surfaces as identical

```tsx
{document.invoiced_via === null
  ? t('orders.billingRefusal.billedBy.unknown')
  : t(`orders.billingRefusal.billedBy.${document.invoiced_via}`)}   // ← no defaultValue
```

The parser types `invoiced_via` as an unconstrained `string | null`
(`deliveryNoteBillingRefusal.ts:7`, populated by `nullableString(candidate['invoiced_via'])` at `:46`)
— it does **not** narrow to the four enum cases. Any `invoiced_via` value the server emits that has
no locale key therefore renders the literal string `orders.billingRefusal.billedBy.<value>` into the
refusal region, in en **and** fr. That is precisely the OP-13 class spec §6.3's *Conventions* bullet
forbids (*"no raw i18n key strings rendered in en or fr"*). Both sibling surfaces are already
defensive — `ToBillPage.tsx:84-86` and `PartnerDeliveryNotesTab.tsx:223-226` both pass
`defaultValue: t('…billedBy.unknown')`.

Not reachable today (all four `DeliveryNoteBillingLane` cases have en+fr keys, and no test pins that
coupling), so this is latent rather than live. What raises it to MAJOR is the second half:
`M5-evidence.md` §4.1, condition 2 asserts *"Same shape at `PartnerDeliveryNotesTab.tsx:219-258`
**and `SalesOrderDetailPage.tsx:450-470`**"*. The shape is **not** the same — the fallback is
missing on exactly the surface named. Under the no-overstated-claims charter the evidence sentence
must be corrected even if the code fix is deferred.

**Fix:** add `, { defaultValue: t('orders.billingRefusal.billedBy.unknown') }` at
`SalesOrderDetailPage.tsx:460`, and amend `M5-evidence.md` §4.1 condition 2 to state the SO lane's
fallback status accurately.

### E2 — MAJOR (recorded residual, non-blocking) — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:344-346` — the A2 balance line returns `null` for loading, error **and** absent data alike

```tsx
const aggregates = query.data?.aggregates
if (aggregates === undefined) return null
```

An aggregate transport failure is visually indistinguishable from "zero un-billed exposure": the
balance card silently reverts to showing `totalReceivable` alone — which is the *systematically
understated picture of exposure* (spec §3.1 / R13 §3 View 4) that View A2 exists to prevent. The
un-billed number simply vanishes with no error affordance, unlike the tab itself, which renders
`QueryError` with a retry (`:281-283`).

Already disclosed verbatim in `HANDBACK-…:882-884` under *Discovered findings not in scope*, so this
is an honestly-recorded residual, not a hidden defect, and the spec does not mandate an error state.
Recording it here so it is carried as a follow-on rather than absorbed.

**Fix (follow-on):** distinguish `query.isError` from `aggregates === undefined` and render a compact
error affordance (or the label with an explicit "unavailable" marker) instead of nothing.

### E3 — MINOR — `apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:465` — the taking-invoice link is a hand-built path, bypassing the M2 centralization

```tsx
to={`/sales/invoices/${document.invoice_id}`}
```

Functionally **correct** — `entityRoutes.document(id, { documentType: 'invoice' })` resolves to
exactly `/sales/invoices/${id}` (`entityRoutes.ts:85-87`), and the id is the invoice id, not a
delivery-note id, so the mislinked-entity class does not occur. But M2's `483457a41`
("Centralize delivery-note billing links") only reached `PartnerDeliveryNotesTab.tsx`; the M1 surface
was never swept, and a future change to the invoice route shape would break this one call site.

**Fix:** route it through `entityRoutes.document(document.invoice_id, { documentType: 'invoice' })`.

### E4 — MINOR — `apps/web/src/features/documents/delivery-notes/DeliveryNoteBillingStatus.tsx:9-14` — the lane tone map encodes a value judgement on a purely informational axis

```ts
const laneTones: Record<string, StatusTone> = {
  consolidation: 'info',            // blue
  order_conversion: 'success',      // GREEN
  pre_post_delivery: 'info',        // blue
  legacy_unknown: 'neutral',        // gray
}
```

`order_conversion` renders **green** (`success: tokens.alert.success`) while the two other live lanes
render blue. Which lane billed a delivery note is attribution, not a success/failure axis, and all
three are equally valid routes per the coexistence copy. The green also lands inside the refusal
region — a red `role="alert"` block whose per-row attribution can carry a green "success" pill
(`PartnerDeliveryNotesTab.tsx:106-116`). Impact is muted in the table (invoiced rows are
`opacity-60`), which is why this is MINOR rather than higher, but it is exactly the class of
competing accent the 2026-08-10 ruling targets.

**Fix:** map all four lanes to `'neutral'` (the text already carries the distinction), or at minimum
demote `order_conversion` from `'success'` to `'info'` so no lane reads as "the good one".

### E5 — MINOR — `apps/web/src/features/documents/to-bill/ToBillPage.tsx:500` — the global queue renders an i18n key namespaced to the partner tab

`t('deliveryNotes.partnerTab.coexistence')` on `/sales/to-bill`. The **content** is correct and
global (all four lines apply to the queue), and both locales carry the full four-line block — this is
purely a key-location smell created by the M2→M3 seam (M3 reused M2's key rather than promoting it).
A later edit scoped "to the partner tab" would silently change the global queue's copy.

**Fix:** promote the key to a shared location (e.g. `deliveryNotes.coexistence`) and point both
surfaces at it, or leave it and add a comment recording the shared consumer.

### E6 — MINOR — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:300-313` — selection UI is inert for a `deliveries.view`-only user

The `selection={{…}}` prop is passed to `DataTable` unconditionally, while the action button is
`{canCreateInvoice ? … : null}`. A `viewer`/`accountant` with `deliveries.view` but not
`invoices.create` therefore gets working checkboxes that lead nowhere. The gating itself is *correct*
per spec §3.1 (*"a `viewer` sees the list and no button"*), and this is already disclosed at
`HANDBACK-…:887-889`. It is adjacent to OQ-11 (a control that does nothing) without being the
backend-doesn't-exist case OQ-11 names.

**Fix:** pass `selection` only when `canCreateInvoice` is true.

### E7 — MINOR (record) — `docs/handoff/HANDBACK-dn-consolidation-build-2026-08-12.md:12-13` is stale at the tip

> `- Current M4 implementation SHA: 4d747ce41 (awaiting bridge review).`
> `- Milestone being handed back: M4 implementation and amended preflight complete; bridge review is next.`

At `8faec0952`, M4 has been through **three** bridge rounds and `M4-round3.md` returned ACCEPT; the
progress YAML correctly records `M4 status: passed` with `verdict: …/M4-round3.md` and M5 complete.
The handback header — the wave's front page — contradicts the YAML and would tell a reader that M4 is
unreviewed. This is an *under*statement, not an overstatement, and no §6 evidence depends on it, but
it is an inaccurate record at a terminal gate.

**Fix:** update the header block to the tip state (M4 passed via `M4-round3.md`; M5 evidence at
`b9418ab73`; VP-1 at `8faec0952`), or add a one-line pointer to `M5-evidence.md` as the current record.

### E8 — MINOR (informational, inherited) — `/finance/lane-separation` is newly linked but absent from the route manifest

The route existed at the base and was already missing from `scripts/factory/manifests/routes-web.yaml`
(the manifest generator does not resolve it, plausibly because `RequirePermission` is nested *inside*
`SuspenseWrapper` at `routes/index.tsx:2047-2053`, inverted relative to every other entry). This wave
did not create the route, but it did make it **user-reachable for the first time** via the new
sidebar entry, while correctly manifesting its own `/sales/to-bill`. The M5 evidence discloses the
drift as inherited and lane-clean (§5 R5), which is accurate as far as it goes.

**Fix (optional, 4 lines):** add the `/finance/lane-separation` manifest entry
(`module_gate: null`, `permission: reports.financial`), or record it explicitly as an owner-owned
manifest-generator gap now that the route is linked.

---

## F. Recorded, verified, and NOT findings

- **F-3 / OI-1a residual stands as instructed:** the existing `/inventory/delivery-notes` list and
  detail routes keep `moduleKey="inventory"` (`routes/index.tsx:1241` list, `:1261` detail). Unchanged, correctly
  recorded as an owner question.
- **The base `GET /delivery-notes` route** used by the partner tab still has `can:deliveries.view`
  with no `module:Sales`. The tab and its action are independently gated on the FE, and the handback
  correctly returns the backend revocation to the owner rather than widening it silently. Not a
  finding for this lane.
- **OI-13** (`module:Sales` on the pre-existing consolidation POST) is a live **revocation** and is
  built; the tenant module-assignment verification is a promotion gate. Correctly recorded, and the
  E2E run produced neither evidence for nor against it (no 403 was observed because the specs died
  earlier) — the M5 evidence says exactly that rather than claiming coverage.
- **E2E** is disclosed as *run and red* (210 failed / 40 passed / 211 not run) with six named
  environment blockers, none attributable to this lane, and with the explicit statement that **no
  e2e spec exercises this lane's new UI at all**. That is the opposite of an overstated claim.
- **Proposals OI-8 5–7** are returned unratified with an explicit statement of what was and was not
  built. Proposal 6 is marked *"NOT designed and NOT built"* rather than dressed up as partially met.
- **Pin entries 1–2** (the C-3 `precision.hardcodedBcmathScale` residuals): the M5 re-derivation
  correctly refutes M4-round1 finding 7 — `CopiesDocumentData.php` is a **trait**, and PHPStan only
  applies the rule in the context of a consuming class, which single-file scope never supplies. The
  pin must be kept. Outside my lens; recorded because it is the one place the branch corrects an
  earlier register rather than inheriting it.
- **`FilterTabs` gained `aria-pressed`** (a shared molecule). Additive, backward-compatible, correct
  for role-less toggle buttons, and disclosed as a deviation. Not a finding.

---

## G. Verdict rationale

Every accumulated FE claim I was asked to re-verify holds at `8faec0952`, and every number in the
M5 evidence that falls in my lens reproduces **exactly** when I run it myself — including the
725/727 figure and the identity of the two reds. The baseline moved only by removals that map to
deleted code, with zero additions from three new surfaces; the improvement was achieved by deletion
and by using the atoms, not by indirection, aliasing, suppression or renamed literals. Module gating
is real and fails closed on both layers, with independent per-layer tests. Query keys, money
handling, tokens and en/fr parity are clean. The strongest guarantee the UI states is backed by the
mechanism the branch actually built.

The findings are one latent i18n fallback asymmetry with an accompanying evidence overstatement (E1),
one already-disclosed error-state residual (E2), and five minor consistency/record items. None
compromises correctness, tenancy scoping, fiscal integrity or the ratified OI-8 behaviour on any
shipped surface. E1's record half should be corrected at merge; E1's code half and E3–E6 are
one-to-four-line follow-ons that do not warrant blocking a five-milestone branch that has been
reviewed this thoroughly.

**Disposition:** ACCEPT for merge. Close **E1** (both halves) and **E7** before promotion —
they are record-truthfulness items and cost minutes. Carry **E2–E6** and **E8** as recorded follow-ons.

---

VERDICT: ACCEPT
