## M2 — Round 2 adversarial merge-gate review

**Scope read:** brief §2/§3/§6 M2 row + spec §3.1 (View A/A2), §3.3 (View C), §3.4 (C9), §4.2 (OI‑9 badge), §6.3. **Amending authority applied:** `dn-consolidation-build.progress.yaml` 2026‑08‑19 owner re‑pin (`base_sha 60df88a01`) and the `preflight_policy` amendment (touched‑files green + failure set ⊆ base). Diff reviewed: `60df88a01..HEAD`, with M2 isolated as `8e4d21b58..HEAD`.

**Lenses.** `frontend-conventions` — applies, primary. `treasury` — applies (the un‑billed exposure line and row/aggregate money). `general` — applies. `inventory-costing` / `fiscal-pos` / `tenancy-authz` backend surfaces — **not touched by M2**: `git diff --name-only 8e4d21b58..HEAD` is `apps/web/**` + `docs/**` only, so no migration, no queue, no `app()` call site, no scale‑resolver call site, and no fiscal projection is in this milestone's diff. Rule 19's backend half is therefore vacuous here; its FE half is checked below.

**Round‑1 closure check (all 13 findings).** 1 ✅ · 2 ✅ · 3 ✅ · 4 ✅ (fix‑round RED/GREEN split is durable; initial‑commit gap disclosed as a deviation) · 5 ✅ (disclosed via currency in the count) · 6 ✅ · 7 ✅ · 8 ✅ (residual now recorded) · 9 ✅ · 10 ✅ · 11 ✅ · 12 ◑ (component extracted; the shared‑molecule edit remains — see 6 below) · 13 ✅.

---

## Register

**1. — P2 — CONFIRMED — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:52-64,301` + `apps/web/src/features/documents/deliveryNoteBillingRefusal.ts:29`**
Every batch refusal that is *not* `already_invoiced` is unattributable on M2's headline action, and this is a **regression against the surface M4 will delete in its favour**. `validateDeliveryNotes` (`DeliveryNoteToInvoiceConverter.php:414-441`) also emits `wrong_currency` / `no_lines` / `wrong_partner` / `not_confirmed`; the controller maps those to `CONSOLIDATION_VALIDATION_FAILED` **with a populated `details.documents[]`** (`DocumentConversionController.php:313-321`). The client parser hard‑gates on `envelope['code'] !== 'DELIVERY_NOTE_ALREADY_INVOICED'` and returns `null`, so `submit()`'s catch does nothing and `useDeliveryNotes.ts:193-197` falls through to `toast.error(getErrorMessage(error))` — a **toast** carrying the untranslated backend English from `DeliveryNoteBatchValidationException:20-31`. Spec `:598` is explicit that the same `details` shape carries these reasons "so no failure mode is unattributable"; the retiring component it replaces already blocks this case client‑side with an existing translated key (`DeliveryNoteConsolidation.tsx:104-110`, `sales:deliveryNotes.consolidation.errors.differentCurrencies`).
**Failure:** a TND company with an export customer holding one EUR DN (999.87) and three TND DNs. M1 deliberately keeps foreign‑currency rows in this list (`DeliveryNoteBillingProjectionTest::test_index_returns_foreign_currency_rows_but_aggregates_only_company_currency_rows`), and round 2 now renders them correctly as `999,87 EUR` (`:90`) — so they read as first‑class, billable rows. `isRowSelectable` gates only on `invoiced_at === null` (`:301`), so select‑all takes all four. Submit → whole batch refused → a transient toast reading "All delivery notes must have the same currency", in English regardless of `i18n` language, with **no inline region, no row marked, no document named**. The operator cannot tell which of the four is the offender and the selection is left intact, so every retry fails identically.
**Disposition:** spec §3.4 assigns the same‑partner/same‑currency guard lift to **View B (M3)** — so this is legitimately close‑before‑merge rather than an M2 blocker, but M3's lift must cover View A too, or `parseDeliveryNoteBillingRefusal` must be widened to `CONSOLIDATION_VALIDATION_FAILED` at M5. It is currently in neither the handback's residual list nor its deviations list.

**2. — P3 — CONFIRMED — `apps/web/src/features/documents/api/deliveryNotes.ts:120-130`**
The round‑1 finding‑1 fix sends `status: 'confirmed'` unconditionally, so the tab's **"All" / "Tous"** filter shows only *confirmed* delivery notes. The fix is correct for billability (the converter accepts nothing else, `DeliveryNoteToInvoiceConverter.php:419-422`) and correct for the aggregate, but the tab label now overstates its contents, and this narrowing is described in the handback's fix narrative while being absent from its **Deviations** section (brief §7 item 10). Spec §3.1's stated request shape carries no `status` param.
**Failure:** a clerk opens "All" on a customer with two draft DNs awaiting confirmation, sees neither, and concludes none exist. The only surface that would show them is `/delivery-notes`, gated `moduleKey="inventory"` (the F‑3 residual), which this same user may not hold.

**3. — P3 — CONFIRMED — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:340`**
`PartnerUnbilledBalanceLine` returns `null` whenever `aggregates` is undefined — which covers `isLoading`, `isError`, and any transport failure, not just "no data". A money‑exposure line whose whole justification (spec §3.1 View A2, R13 §3 View 4) is that omitting it understates exposure fails **silently and identically to "zero"**.
**Failure:** the aggregate request 500s; the balance card renders receivable alone with no error affordance, and the operator reads a systematically understated exposure — the exact defect View A2 exists to close.

**4. — P3 — CONFIRMED — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:237`**
Round 2 localised the row date column (`:82-84`, using `i18n.resolvedLanguage`) but the persistent refusal region still prints `document.invoice_date ?? '—'` raw. `invoice_date` is `toDateString()` server‑side (`DocumentConversionController.php:503-504`), i.e. `2026-08-12`. Two dates on the same screen now use two different formats. Inherited verbatim from M1 (`DeliveryNoteConsolidation.tsx:299`), so a shared fix.

**5. — P3 — CONFIRMED — `apps/web/src/locales/{en,fr}/sales.json` → `deliveryNotes.partnerTab.unbilledLine.count_*`**
The A2 copy is now `"({{count}} {{currency}} delivery notes)"`, i.e. `(3 TND delivery notes)`. Spec §3.1 prescribes *"Delivered, not yet invoiced — X (n delivery notes)"*. The change is the right remedy for round‑1 finding 5 (the aggregate is company‑currency‑scoped by `DeliveryNoteController.php:195` while the row list is not), and the en/fr wording is idiomatic in both — but it is a specced‑string deviation recorded only in the fix narrative, not in the deviations list.

**6. — P3 — CONFIRMED — `apps/web/src/components/molecules/FilterTabs/FilterTabs.tsx:27`**
The shared molecule's `aria-pressed` addition (round‑1 finding 12, first half) is still in the M2 diff and still absent from the deviations list. Correct ARIA for these plain buttons and harmless to the molecule's other consumers, but it is a shared‑component edit made to serve a milestone assertion (`PartnerDeliveryNotesTab.test.tsx:116`) — CLAUDE rule 4 wants that declared. The second half of finding 12 **is** closed: `DeliveryNoteBillingStatus` now lives in its own file.

**7. — P3 — CONFIRMED — `apps/web/src/features/documents/api/deliveryNotes.test.ts:44-61`**
The shipped‑call contract test covers only `filter: 'uninvoiced'`. Nothing asserts that `filter: 'invoiced'` sends `{status:'confirmed', invoiced:1}` or that `filter: 'all'` sends `{status:'confirmed'}` with no billing flag — the two branches of `filterParam` at `deliveryNotes.ts:120`. A future edit that drops `status` from the non‑default branches, or that emits `all: 1` (which `applyDeliveryNoteFilters` silently ignores, `DeliveryNoteController.php:175-179`), stays green.

**8. — P3 — CONFIRMED — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:297-306`**
The `selection` prop is passed unconditionally, so a `deliveries.view`‑only user (no `invoices.create`) sees row checkboxes and a select‑all with no action button anywhere on the tab. Spec §3.1 says *"a `viewer` sees the list and no button"*; the checkboxes are dead UI for that role. Covered by a passing test only in the negative direction (`:130-134` asserts the button is absent, not that selection is).

**9. — P3 — CONFIRMED — `docs/handoff/progress/dn-consolidation-build.progress.yaml:98-105`**
Bookkeeping lags HEAD: M2 records `commit: 3b1af7fbc`, `fix_rounds: 1`, `last_verdict: CHANGES-REQUIRED`, `verdict: …/M2-round1.md`, while HEAD is `52aae14b7`. Expected to be reconciled with this round's register path and verdict before M3 starts (brief §7).

---

## Bypasses I tried that FAILED (implementation held)

- **`status=confirmed` silently dropped by an enum guard.** Refuted — `DocumentStatus::Confirmed = 'confirmed'` and `applyFilters` does `tryFrom` → `inStatus` (`HandlesDocuments.php:143-150`).
- **Aggregates computed before the status filter, so round‑1 finding 1 only half‑fixed.** Refuted — `DeliveryNoteController.php:111-116` clones *after* both filter passes; `deliveryNoteAggregates` mutates only its clone (`:193-209`), which is why rows stay un‑currency‑filtered while the aggregate is scoped.
- **Off‑page refusals still only decorate rendered rows.** Refuted — the region iterates `billingRefusal.documents` in full (`:215-261`), and the test drives it with `per_page: 1` across two pages (`PartnerDeliveryNotesTab.test.tsx:174-225`), asserting number + date + lane + link inside `role="alert"`.
- **All‑refused still nukes the guarantee sentence.** Refuted — `removeAndRetry` returns before `setBillingRefusal(null)` when `remaining.length === 0` (`:151-157`); test `:227-255` asserts the en guarantee survives and `mutateAsync` is not re‑called.
- **Row money still company‑currency.** Refuted — `formatAmount(dn.total, dn.currency)` (`:90`), and the EUR test is non‑vacuous: the mock spreads `importOriginal`, so the real `formatAmount` runs and produces `99,88 EUR`.
- **`<dl>` content model still invalid.** Refuted — `div > dt/dd` inside the card's `<dl>` (`PartnerDeliveryNotesTab.tsx:343-361` under `PartnerDetailPage.tsx:466`), matching every sibling row.
- **Missing/untranslated fr keys.** Refuted — programmatic flatten/diff over `sales` and `finance`: zero en‑only, zero fr‑only; the only byte‑identical pairs are `columns.date`/`columns.total` ("Date"/"Total"), legitimately identical in fr. OI‑9 wording is byte‑exact to spec §4.2 in both.
- **Sidebar entry breaks the group's 1:1 permission↔route alignment (View C) or smuggles a grant.** Refuted — `Sidebar.tsx:300` `reports.financial` == `routes/index.tsx:2048`; `Sidebar.test.tsx:179-197` asserts both directions; no seeder or `permissionsMap.generated.ts` edit is in the diff.
- **Rule 19 breach on the FE.** Refuted — `grep parseFloat|Number(|toFixed|any` over all new M2 files is clean; aggregates flow as decimal strings through `useCurrency().format` / `formatAmount`.
- **M2 smuggled a backend, migration or queue change.** Refuted — `git diff --name-only 8e4d21b58..HEAD` is 18 `apps/web` files + 3 docs. No `horizon.php`, no migration, no `app()`.
- **Tests are green only on paper.** Refuted — I ran them. Focused M2 set: **72/72, 6 files**. Exact §6.3 sweep (`src/features/documents src/features/partners src/features/finance src/routes src/components/organisms/Sidebar`): **707 passed / 2 failed of 709**, and both failures are the declared inherited residuals (`finance/api.test.ts` `group_by=location`, `finance/hooks/__tests__/tenantScope.test.tsx` aged‑payables key) in files this branch does not touch. `tsc --noEmit` exit 0; ESLint over the touched set **0 errors** (80 warnings, all pre‑existing `colorClasses`/`no-unnecessary-condition` noise in untouched lines). Failure set ⊆ base ⇒ the amended `preflight_policy` is satisfied.
- **Red‑first is narrative only.** Mostly refuted — `ed282949a` is **tests‑only** (2 files, +114/−2) and `3b1af7fbc` is the implementation; the added assertions (`status: 'confirmed'` in the params object, `(3 TND delivery notes)`, the two coexistence lines, `99,88 EUR`, `dt`/`dd`) are structurally unsatisfiable against `483457a41`'s source. The residual gap — the four original M2 feature commits bundle test+impl — is now explicitly disclosed as a process deviation in the handback, which is the round‑1 finding‑4 remedy.

---

**Disposition.** No P1 survives round 2; all four round‑1 blockers (P1‑1, P1‑2, P2‑3, P2‑4) are closed against code I read and tests I ran, and nine of the ten P3s are closed or disclosed. Finding 1 is a real P2 regression, but spec §3.4 assigns the same‑currency guard to View B, so it is close‑before‑merge (M3 must cover View A, or M5 widens the parser) rather than an M2 gate failure. Findings 2–9 ship with tickets recorded per brief §6.

VERDICT: ACCEPT
