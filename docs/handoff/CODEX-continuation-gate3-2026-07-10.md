# Gate 3 Result + Continuation Brief — design-system unification

> Gate 3 review of ee90d3690 (gate-2 fixes) + b4c9cff00 (documents/admin sweep), 2026-07-10. Three independent lanes.
> **Verdict: APPROVE-WITH-FIXES.** Gate-2 fix commit: clean APPROVE (all 5 items genuinely fixed, 63/63 tests reproduce). Sweep commit: behavior and pixels conserved across ALL 65 files (mechanically verified — every file normalized and diffed, not spot-checked): no dropped columns, links, actions, statuses, or states anywhere. Baseline replay round 3: HONEST (53 removals = 31 admin + 22 documents, zero additions, 0 stale). All evidence claims reproduced exactly, including the 7,651-warning count.
> **The three majors are all RATCHET-INTEGRITY issues, not regressions.** Fix them FIRST — they determine whether the remaining Wave 5 directories are actually locked when swept.

## Step 1 — Gate-3 fixes (before sweeping any new directory)

**G3-1 (major) — the new ESLint color guard doesn't match template literals.** `eslint.config.js:279-292` uses `no-restricted-syntax` selector `Literal[value=/.../]`; template-literal quasis are `TemplateElement` nodes, so `` className={`bg-red-500 ...`} `` — the DOMINANT className form in this codebase — does NOT error in the promoted dirs. The ratchet is currently a sieve.
Fix: add a second selector `TemplateElement[value.raw=/...same regex.../]` to both the full-palette ERROR block and the global WARN rule. Verify with a deliberate (uncommitted) template-literal violation in `src/features/documents/` that lint now errors, then remove it.

**G3-2 (major) — `colorClasses` neutralizes the ratchet instead of progressing it.** `lib/designTokens.ts:127-257` adds 129 pure 1:1 aliases (`bgBlue600: 'bg-blue-600'`) with zero semantic indirection; the ESLint message even sanctions `colorClasses.*` as an approved destination. This makes C7=0 true while actual palette usage is unchanged, and invites every future feature to launder through it.
Fix: (a) mark the `colorClasses` export `@deprecated — pixel-preservation quarantine for the Wave 5 sweep; do not add entries or import outside features/documents|admin`; (b) enforce that with `no-restricted-imports` (or a no-restricted-syntax selector) for all other paths; (c) remove `colorClasses` from the sanctioned-destinations wording in the ESLint messages; (d) add a burn-down entry to the progress doc (map aliases → real semantic tokens as a post-sweep task).

**G3-3 (major) — C1 audit dodge must become visible debt.** ~14 complex headers (admin pages, DocumentHeader, ReturnNoteListPage, CN/DN/RN detail pages) were "cleaned" by rewriting `text-3xl`→`text-[1.875rem] leading-9` and `text-2xl`→`text-[1.5rem] leading-8` — metrically identical (verified), but it games the C1 scanner instead of adopting PageHeader.
Fix: teach `audit-design-system.mjs` C1 (and the manifest command) to also match `text-\[1\.(5|875)rem\]` bespoke `<h1>`s; re-run and add the resulting entries to the baseline as ACKNOWLEDGED debt (honest count > pretty zero). PageHeader adoption for these headers is DEFERRED to a dedicated pass needing the owner's visual sign-off — log it as an explicit deferral with the file list.

**Minors (one cleanup commit):**
- **G3-4** MonitoringPage badges lost their per-status icons (unlisted visual change) — restore by passing the icon as StatusBadge children (`<StatusBadge tone={...}><Icon className="h-3 w-3"/>{label}</StatusBadge>`).
- **G3-5** DataTable "children mode" (`DataTable.tsx:78-131`) — add a docblock stating it's a migration shim (bare table passthrough, not canonical semantics) + a direct unit test for the mode.
- **G3-6** MonitoringPage composite React keys can collide (`:578`, `:605`) — append the index.
- **G3-7** VerticalConfigModal close-button restyle — add to the progress doc's visual-change list.
- **G3-8** LineItemEntryBar overlay lingers after keyboard tab-away (only Escape/pointerdown/commit close it; stale `aria-expanded`) — close on `focusout` when `relatedTarget` is outside `containerRef`, mirroring the pointerdown handler; add a test.
- **G3-9** Use `REQUIRED_VALIDATION_KEY` at the schema emit sites in SupplierInvoiceCreatePage (`:91`, `:103`) instead of duplicate literals.
- **G3-10** Add the dead `default_tax_configuration_id` reads on service DTOs (`features/services/types.ts:28,:66`, `ServiceDetailPage.tsx:72`, `ServiceForm.tsx:103`) to the Wave 6 cleanup list in the progress doc.
- **G3-11** Commit `docs/handoff/CODEX-continuation-gate2-2026-07-10.md` and this file.

## Step 2 — Wave 5 continuation (after Step 1)

Same per-directory protocol (sweep → manifest commands zero → baseline shrink → full-palette ESLint ERROR promotion — now with the G3-1-fixed selector — → progress-doc entry). Directory order for THIS leg:
1. `import/` (~394 color occurrences), `inventory-counting/` (~380), `opening-balances/` (~327)
2. `partners/` (~327), `compliance/` (~279), `loyalty/` (~212), `parts-catalog/` (~370)

Rules unchanged: pixel-conservative; `colorClasses` quarantine means NEW dirs must map to real tokens (`tokens/textColors/borderColors`) — the alias table is closed; modal/drawer forms get RHF but not StickyFormFooter; deferrals logged.

**STOP after these 7 directories and report for Gate 4** with the standard evidence set (design audit totals, tanstack, typecheck, lint errors=0 + warning count, per-directory manifest counts, targeted suite counts). Gate 5 will be the remaining tail (services, batches, parapharmacy, pricing, crm, vat-reporting, vouchers, categories, products, dashboard, reports, uom, pos, auth*, expenses, coupons, promotions, stock-transfers, settings, catalog, vehicles, customer-history-audit, locations, company, inventory) + Wave 6 dedup/orphans.

## Standing rules (unchanged, all held at gate 3)

Never launder the baseline (entry replay re-runs every gate); every deferral logged; targeted tests only; no push/merge; pixel-conservative with visual changes listed.
