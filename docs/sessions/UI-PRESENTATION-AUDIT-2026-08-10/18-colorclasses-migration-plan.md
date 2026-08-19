# 18 — `colorClasses` migration / baseline plan (UI-40, part b)

**Status: PLAN ONLY. Nothing in this document is scheduled, funded or authorised to execute.**
**The migration remains unscheduled and must be assigned to a later wave and budgeted** (`00-EXECUTIVE-REPORT.md §5:246`).

Produced by UI Audit **Wave 0, task T7(b)** (`docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md`).
Wave 0's mandate on `UI-40` is exactly two things: **fix the C6 detector** (part a, landed in the same
commit as this document) and **write this plan** (part b). Wave 0 does **not** remove the ESLint
carve-outs and does **not** migrate any importer. Doing either without the migration or a recorded
baseline turns 52 quarantined importers into hard lint failures on the next run.

Audit of record: [`00-EXECUTIVE-REPORT.md`](00-EXECUTIVE-REPORT.md) — finding `UI-40` (`§2:128`),
carve-out note (`§5:246`), and `02-design-system-consistency.md:22` F-8.

---

## 1. What is quarantined, and where

`apps/web/src/lib/designTokens.ts:568` exports `colorClasses`, a flat alias table of raw Tailwind
palette utilities. Its own docblock (`:563-567`) marks it `@deprecated` — *"Pixel-preservation
quarantine for the Wave 5 documents/admin sweep. Do not add entries and do not import outside those
directories; burn this table down by replacing each alias with semantic tokens/components."*

The ban is enforced in `apps/web/eslint.config.js:209-229`: a `no-restricted-imports` rule on the
`colorClasses` **named import**, with three `ignores` carve-outs:

```js
ignores: [
  'src/features/documents/**/*.{ts,tsx}',
  'src/features/admin/**/*.{ts,tsx}',
  'src/lib/designTokens.ts',
],
```

So the rule is already `error` everywhere else. The carve-out is the whole remaining debt.

### Reproduction command

Everything in this document is derived by script, not hand-counted. From `apps/web`:

```bash
node -e '
const fs=require("node:fs/promises"), path=require("node:path");
const IMPORTS=/import\s*\{[^}]*\bcolorClasses\b[^}]*\}\s*from\s*["\x27][^"\x27]*designTokens["\x27]/;
const out=[];
(async function walk(d){
  for (const e of await fs.readdir(d,{withFileTypes:true})) {
    const p=path.join(d,e.name);
    if (e.isDirectory()) { await walk(p); continue }
    if (!/\.(ts|tsx)$/.test(e.name)) continue;
    const code=await fs.readFile(p,"utf8");
    if (!IMPORTS.test(code)) continue;
    const syms=new Set([...code.matchAll(/colorClasses\.([A-Za-z0-9_]+)/g)].map(m=>m[1]));
    out.push({file:p.replaceAll(path.sep,"/"),uses:(code.match(/\bcolorClasses\./g)||[]).length,symbols:syms.size});
  }
})("src").then(()=>{
  out.sort((a,b)=>b.uses-a.uses||a.file.localeCompare(b.file));
  console.log("importers",out.length,"occurrences",out.reduce((s,x)=>s+x.uses,0));
  for (const o of out) console.log(o.uses, o.symbols, o.file);
});'
```

### Headline numbers (re-derived 2026-08-19)

| Measure | Value |
|---|---|
| Files that **import** the `colorClasses` symbol | **52** |
| …in `src/features/documents/**` | **40** |
| …in `src/features/admin/**` | **12** |
| …outside the two carve-out directories | **0** |
| Total `colorClasses.*` member accesses | **1,901** |
| Distinct alias keys **defined** in the table | **129** |
| Distinct alias keys **actually used** | **129** (zero dead entries) |
| Alias values that are a **single** Tailwind utility | **129 / 129** |

Two corrections worth recording:

- `00 §2:128` is confirmed: **all 52** real importers live inside the two carve-out directories.
  `02-design-system-consistency.md`'s "43 of 52" is superseded — the 52 figure is the importer count,
  and there is no residual set outside the carve-out.
- A naive `grep -rl colorClasses src` returns **54** files. The extra two are **not** importers:
  `src/lib/designTokens.ts:568` (the definition itself) and
  `src/features/stock-adjustments/api/queries.ts:23` (a code **comment** referencing the quarantine).
  Any future burn-down measurement must filter on the import statement, not the bare string.

---

## 2. Per-file importer inventory

Ordered by occurrence count descending — this is also the recommended *reverse* order of attack
(see §4). "Symbols" is the number of distinct `colorClasses.*` keys the file touches, which is the
better proxy for review effort than raw occurrences.

| File | `colorClasses.*` occurrences | distinct symbols |
|---|---:|---:|
| `src/features/admin/pages/MonitoringPage.tsx` | 145 | 44 |
| `src/features/admin/pages/PaymentsPage.tsx` | 113 | 23 |
| `src/features/admin/pages/InvoicesPage.tsx` | 79 | 22 |
| `src/features/documents/components/CreateCreditNoteForm.tsx` | 75 | 26 |
| `src/features/documents/CreateCreditNotePage.tsx` | 73 | 16 |
| `src/features/documents/CreateReturnNotePage.tsx` | 69 | 23 |
| `src/features/documents/return-notes/ReturnNoteDetailPage.tsx` | 60 | 27 |
| `src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx` | 59 | 14 |
| `src/features/documents/delivery-notes/DeliveryNoteDetailPage.tsx` | 57 | 24 |
| `src/features/documents/components/ReturnNoteMetadata.tsx` | 56 | 34 |
| `src/features/admin/pages/SubscriptionsPage.tsx` | 54 | 19 |
| `src/features/documents/components/CreateReturnNoteForm.tsx` | 53 | 23 |
| `src/features/documents/credit-notes/CreditNoteDetailPage.tsx` | 51 | 26 |
| `src/features/documents/invoices/InvoiceDetailPage.tsx` | 50 | 14 |
| `src/features/documents/components/RelatedDocumentsPanel.tsx` | 48 | 25 |
| `src/features/documents/ReturnNoteListPage.tsx` | 48 | 22 |
| `src/features/documents/components/DeliveryNoteConsolidation.tsx` | 47 | 22 |
| `src/features/admin/pages/BillingDashboardPage.tsx` | 45 | 17 |
| `src/features/documents/sales-orders/SalesOrderDetailPage.tsx` | 45 | 14 |
| `src/features/admin/components/TenantDetailModal.tsx` | 43 | 25 |
| `src/features/documents/components/costing/LandedCostBreakdown.tsx` | 42 | 14 |
| `src/features/documents/components/CreditNoteDetail.tsx` | 38 | 16 |
| `src/features/admin/pages/TenantsPage.tsx` | 37 | 23 |
| `src/features/documents/components/CreditNoteList.tsx` | 36 | 16 |
| `src/features/admin/pages/CompanyOwnersPage.tsx` | 34 | 20 |
| `src/features/documents/components/DocumentActions.tsx` | 34 | 20 |
| `src/features/documents/components/DeliveryConfirmationModal.tsx` | 32 | 20 |
| `src/features/admin/pages/AuditLogsPage.tsx` | 30 | 18 |
| `src/features/documents/components/OutstandingAmountSection.tsx` | 28 | 18 |
| `src/features/documents/quotes/QuoteDetailPage.tsx` | 28 | 13 |
| `src/features/documents/components/costing/AdditionalCostsForm.tsx` | 25 | 11 |
| `src/features/documents/components/DocumentAttachments.tsx` | 25 | 17 |
| `src/features/documents/components/PaymentHistorySection.tsx` | 24 | 13 |
| `src/features/admin/pages/AdminLoginPage.tsx` | 23 | 14 |
| `src/features/documents/components/DocumentTotals.tsx` | 21 | 10 |
| `src/features/documents/components/DocumentActionBar.tsx` | 20 | 6 |
| `src/features/documents/components/RelatedDocumentsTab.tsx` | 19 | 10 |
| `src/features/documents/components/TaxExemptionNotice.tsx` | 17 | 14 |
| `src/features/documents/components/DocumentInfo.tsx` | 16 | 4 |
| `src/features/admin/components/AdminLayout.tsx` | 15 | 9 |
| `src/features/documents/components/DocumentPartnerInfo.tsx` | 15 | 6 |
| `src/features/admin/pages/AdminDashboardPage.tsx` | 13 | 5 |
| `src/features/documents/components/ReturnConditionSelect.tsx` | 11 | 11 |
| `src/features/documents/components/ReturnReasonSelect.tsx` | 11 | 11 |
| `src/features/documents/components/DocumentPaymentHistory.tsx` | 10 | 7 |
| `src/features/documents/components/DocumentHeader.tsx` | 9 | 6 |
| `src/features/documents/components/DesignationCell.tsx` | 5 | 5 |
| `src/features/documents/components/PurchaseOrderLandedCostBreakdown.tsx` | 5 | 3 |
| `src/features/documents/components/PurchaseOrderAdditionalCosts.tsx` | 4 | 2 |
| `src/features/documents/components/NotesCell.tsx` | 2 | 2 |
| `src/features/documents/components/DocumentOutstandingCallout.tsx` | 1 | 1 |
| `src/features/documents/DeliveryNoteConsolidationPage.tsx` | 1 | 1 |

The distribution is heavily skewed: the top 10 files carry **786 / 1,901** occurrences (41%), and the
bottom 20 carry **242** (13%). Ten files sit at ≤11 occurrences and account for **59** between them.
That shape drives the sequencing below.

---

## 3. Mechanical vs manual split

Every one of the 129 alias values is a **single** Tailwind utility (`textGray500: 'text-gray-500'`,
`borderGray200: 'border-gray-200'`, …). There is no compound-string alias. That makes the *textual*
substitution mechanical, but it does **not** make the migration mechanical, because the target is a
**semantic** token, and semantics are not recoverable from a palette name.

**Mechanically substitutable — the alias has an exact, already-used semantic equivalent.**
Where `semanticColorTokens` / `textColors` / `borderColors` already expose a token whose emitted class
string is byte-identical, the replacement is a rename and preserves pixels exactly. This covers the
high-frequency neutrals — `textGray500`, `textGray900`, `textGray700`, `borderGray200`,
`borderGray300`, `bgGray50`, `divideGray200` and their hover/focus siblings — i.e. the bulk of the
1,901 occurrences.

*Caveat that makes even this half non-blind:* one palette alias frequently maps to **several**
semantic tokens depending on role. `text-gray-500` is `textColors.tertiary` on a label but
`textColors.muted` on a disabled control; picking one mechanically produces a pixel-identical but
**semantically wrong** result, and the next theme change breaks it. A scripted pass must therefore
emit a **proposal per occurrence for human confirmation**, never an unattended rewrite.

**Manual — the alias encodes intent that must be re-expressed as a component or an intent token.**
Three groups:

1. **Status/semantic colour** (`bgGreen100`/`textGreen800`, `bgRed50`/`textRed800`, amber/yellow/orange
   pairs). These are almost always an inline status chip that should become `StatusBadge` with a
   `StatusTone`, not a token swap. This overlaps directly with the **C6** population that T7(a) just
   made visible — see §6.
2. **Brand/interactive blue** (`textBlue600`, `bgBlue600`, `borderBlue500`, `focusRingBlue500`,
   `hoverBgBlue700`, `hoverTextBlue800`). These belong to `Button` / `EntityLink` / the focus-ring
   token, not to per-file classes; several are re-implementing the `Button` atom inline.
3. **Structural greys used as chrome** (`bgGray50` on table headers, `borderGray200` on card edges).
   The correct target is `tokens.table.*` / `tokens.card.*` — a component adoption, not a colour swap.

**Working estimate for budgeting** (to be re-derived, not trusted, at scheduling time): ~65-70% of
occurrences are rename-with-confirmation; ~30-35% require a component decision. The 52 files split
roughly into 30 small/mechanical, 12 medium, and 10 heavy (the >45-occurrence head of the table).

---

## 4. Proposed sequencing

**Ascending by occurrence count — the reverse of the table above.** Rationale:

1. **The tail is where the pattern library gets written.** The ten files at ≤11 occurrences are
   single-purpose components (`NotesCell`, `DesignationCell`, `DocumentOutstandingCallout`,
   `ReturnReasonSelect`, …). Migrating them first produces the per-alias mapping decisions cheaply, on
   diffs a reviewer can actually read, before any of them is applied 145 times.
2. **`features/documents/components/**` before `features/documents/*Page.tsx`.** The pages compose the
   components; when a shared component adopts `tokens.card.*`, the page's own duplicate chrome
   classes often become deletable rather than translatable. Doing pages first migrates code that the
   component pass would have removed.
3. **`features/admin/**` last, and as its own unit.** The admin surface is 12 files but carries the
   single largest file in the set (`MonitoringPage.tsx`, 145 occurrences / 44 distinct symbols) and is
   a different visual context (internal super-admin, not tenant-facing). It should be one wave-slice
   with its own visual check, not interleaved with the documents work.
4. **`MonitoringPage.tsx` gets its own slice.** 44 distinct symbols in one file is not a sweep item.

Suggested slicing for budgeting, with the occurrence load each slice carries (sums to 1,901):

| Slice | Contents | Files | Occurrences |
|---|---|---:|---:|
| S1 | `features/documents` tail | 20 | 269 |
| S2 | `features/documents` mid components | 13 | 552 |
| S3 | `features/documents` detail/create pages | 7 | 449 |
| S4 | `features/admin` except `MonitoringPage` | 11 | 486 |
| S5 | `features/admin/pages/MonitoringPage.tsx` alone | 1 | 145 |

Every slice ends with `pnpm typecheck && pnpm lint` and a **visual** check — the aliases exist
precisely to preserve pixels, so a purely green run proves nothing about appearance.

---

## 5. The two options for lifting the carve-out

### Option A — migrate, then lift (recommended)

Run S1…S5, then delete the two directory `ignores` entries at `eslint.config.js:212-213`, and finally delete
the `colorClasses` export from `designTokens.ts` once the importer count reaches 0.

- **Consequence:** the debt is actually gone; the `@deprecated` table disappears; no baseline file is
  ever created for it, so nothing can silently re-grow.
- **Cost:** the full 52-file / 1,901-occurrence migration must be funded before the rule tightens.
- **Risk:** long-lived branch conflicts against the documents feature area, which is actively worked
  (the DN-consolidation lane touches `features/documents/**`). Slices must be short-lived and merged
  continuously, never batched into one PR.

### Option B — lift with a recorded baseline

Delete the `ignores` entries **now**, and simultaneously record the current 52 files as an accepted
baseline (either an `eslint-disable` header per file, or a ratchet file in the style of
`tools/audit-design-system-baseline.json`) so the rule fires only on **new** importers.

- **Consequence:** the carve-out stops being directory-shaped. A brand-new file inside
  `features/documents/` can no longer import `colorClasses` — today it silently can, which is how the
  quarantine keeps growing. The existing 52 stay legal until migrated.
- **Cost:** 52 suppression markers to add and later remove; the baseline must be regenerated whenever
  a file is legitimately migrated, or it goes stale.
- **Risk:** a recorded baseline reads as "resolved" on dashboards while 1,901 raw palette classes
  remain. If Option B is chosen, the report must state the residual explicitly and keep `UI-40` open.

**Do not** simply delete the `ignores` entries without either path: that turns all 52 importers into
`error`-level lint failures and breaks `pnpm lint`, CI's `frontend-lint` job (`ci.yml:853`) and
`scripts/preflight.sh` on the next run. That is the exact outcome `00 §5:246` carves Wave 0 out of.

**Recommendation:** Option B as an immediate, cheap **stop-the-bleed** (it closes the "new file in a
carve-out directory" hole), then Option A's slices to burn it down. They are not mutually exclusive;
B is the ratchet, A is the payment.

---

## 6. Interaction with the C6 detector fix (T7 part a)

The same commit that adds this document fixes `tools/audit-design-system.mjs`'s C6 regex family, which
had no `Tone`/`Tones` alternation and additionally could not see maps identified by their **value
type**. C6 went from **0** to **82** baseline entries; the design-system baseline moved `736 → 818`
entries with **C1–C5 unchanged** (12 / 250 / 458 / 14 / 2).

**Read the 82 carefully — it is an entry count, not a defect count.**

| Measure | Value |
|---|---:|
| C6 baseline entries | **82** |
| Distinct source sites (`file:line`) | **46** |
| Distinct files | **41** |

The gap is inherent to how the detector has always worked: `STATUS_RE` is an alternation **family**,
each alternation is matched independently, and one declaration can satisfy several of them. A line
like `const STATUS_TONES: Record<InvoiceStatus, StatusTone> = {` (`src/features/admin/pages/InvoicesPage.tsx:14`)
now matches three alternations and therefore carries three distinct baseline entries. This was already
true of C6's pre-existing alternations; the fix did not introduce it, and de-duplicating would change
the baseline-key semantics shared by C1–C5, which is out of Wave 0's scope.

**The honest burn-down target for a later wave is 46 source sites across 41 files** (82 is the number
the audit gate will print). Whoever schedules that work should expect entry counts to fall faster than
site counts.

The C6 population overlaps the manual half of this plan (§3 group 1): a local `Record<…, StatusTone>`
map and a hand-rolled `bg-green-100 text-green-800` chip are usually the same defect seen from two
angles. Whoever schedules the `colorClasses` migration should schedule the C6 burn-down with it, or
the second pass will re-touch the same lines.

**Ownership boundary, recorded for the parallel enforcement lane.** The enforcement package **P2**
references this same C6 detector class for its tamper tests, and its `Record<X, StatusTone>` case is
coordinated with this task. **Wave 0 T7 owns exactly**: the `STATUS_RE` family in
`apps/web/tools/audit-design-system.mjs`, the regenerated `apps/web/tools/audit-design-system-baseline.json`,
the C6 fixtures added to `apps/web/tools/__tests__/audit-design-system.test.mjs`, and this document.
**Wave 0 T7 does not own and has not touched** any tamper test, any file in the enforcement worktree,
or `apps/web/eslint.config.js`. P2's executor should reconcile against the shipped detector contract
described above — value-type matching on `Record<…, StatusTone>` plus `Tone`/`Tones` name suffixes,
feature/page-scoped `.tsx` only.

---

## 7. Explicit non-actions taken by Wave 0

- **No change to `apps/web/eslint.config.js`.** None. The carve-outs at `:209-229` are byte-identical.
- **No importer migrated.** All 52 still import `colorClasses`.
- **No `colorClasses` entry added, removed or renamed** in `designTokens.ts`.
- **The migration remains unscheduled and must be assigned to a later wave and budgeted**
  (`00 §5:246`).
