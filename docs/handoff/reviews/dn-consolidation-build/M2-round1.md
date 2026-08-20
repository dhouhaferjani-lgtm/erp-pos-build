I read the brief's M2 section, the amending progress YAML (2026-08-19 owner re-pin + differential-preflight amendment), spec §3.1/§3.3/§4.2/§6.3, and verified every claim against the code at `60df88a01..HEAD` (M2 = `49f7aa451..483457a41`).

**Lenses applied:** frontend-conventions (applies), treasury (applies — the un-billed money line and row totals), general (applies). No inventory-costing/fiscal-chain surface is touched by M2 (backend diff is empty for these four commits — verified: `git show --stat` on all four shows only `apps/web` + docs).

---

## Register

**1. — P1 — CONFIRMED — `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:173-180` + `apps/web/src/features/documents/api/deliveryNotes.ts:109-131`**
The "Un-invoiced" filter the M2 tab sends is `whereDeliveryNoteUninvoiced()` = `whereNull('payload->invoiced_at')` only (`Document.php:651-654`). `baseQuery()` applies no status scope (`HandlesDocuments.php:42-47`) and `applyFilters` only filters status when the client sends one (`:143-150`). `getPartnerDeliveryNotes` never sends `status`. The report the spec says shares this predicate *does* scope it: `UninvoicedDeliveryNoteService.php:73` adds `->where('status', DocumentStatus::Confirmed)`.
**Failure:** customer with 5 confirmed un-invoiced DNs (1 000.000) + 2 cancelled DNs (400.000). The balance card renders "Delivered, not yet invoiced — 1 400.000 TND (7 delivery notes)" — the View A2 exposure figure is wrong by 400.000 and counts cancelled goods. Select-all → "Create invoice from selected (7)" → the whole batch is refused by `DeliveryNoteToInvoiceConverter.php:420-422`, so the tab's default action is unusable for any partner carrying a draft/cancelled DN. Note that M1's own projection test only proves exclusion by *explicitly passing* `'status' => DocumentStatus::Confirmed->value` in the query string (`DeliveryNoteBillingProjectionTest.php:296,304`) — a param the M2 client does not send, so nothing in the branch covers the shipped call.

**2. — P1 — CONFIRMED — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:265-288`**
Ratified OI-8 condition 2 (brief §3: "Name every lost document with its taker… the requirement is that the UI renders **all** of it, not a count") is not met on the surface M2 ships. The persistent region renders only `billingRefusal.title`, `.guarantee` and "Remove these {{count}} and retry". Per-DN attribution exists only as a row decoration (`:157-179`), i.e. only for rows on the currently rendered page, and the taking invoice's **date** is never rendered anywhere — `refusal.invoice_date` is consumed solely as a truthiness sentinel at `:172`. Compare M1's own compliant implementation, which renders number + date + lane label + link inside the region (`components/DeliveryNoteConsolidation.tsx:282-323`).
**Failure:** `selectedIds` is not cleared on page change (`:328` `onPageChange={setPage}` — only `changeFilter` resets it, `:207-212`). Operator selects rows on page 1, pages to 2, selects more, submits; the server refuses a page-1 DN. The region says "Remove these 1 and retry" with no document number, no taker, no link, and the marked row is not rendered. The operator cannot learn which DN was lost or which invoice took it.

**3. — P2 — CONFIRMED — `apps/web/src/features/documents/delivery-notes/PartnerDeliveryNotesTab.tsx:152`**
`formatMoney(deliveryNote.total ?? '0')` is `useCurrency().format`, which hardcodes the **company** currency (`hooks/useCurrency.ts:59-66` → `formatAmount(value, currency, …)` → `lib/format.ts:122,134-136` appends that code). The row's own `deliveryNote.currency` is in the `Pick` type and is ignored. M1 deliberately keeps foreign-currency rows in this list — see `DeliveryNoteBillingProjectionTest::test_index_returns_foreign_currency_rows_but_aggregates_only_company_currency_rows`.
**Failure:** a EUR 99.875 delivery note for a TND company renders as `99,875 TND` in the tab the operator uses to pick DNs to invoice. One-arg fix: `formatAmount(dn.total, dn.currency)`.

**4. — P2 — CONFIRMED — `docs/handoff/HANDBACK-dn-consolidation-build-2026-08-12.md` (M2 section, `+306..+375`)**
Brief §7 item 2 requires per-item **failing-test-first evidence** ("test file + what its failure looked like before the fix"); item 10 requires a deviations list. The M2 section contains neither — it lists passing counts only. `grep -n "failed before"` over the handback returns two hits, both in M1 sections (`:153`, `:167`). All four M2 commits bundle test + implementation in one commit, so the commit graph supplies no red-first evidence either. Under CLAUDE rule 2 and the standing check, this milestone's behavioural changes carry no red-first proof.

**5. — P3 — CONFIRMED — `PartnerDeliveryNotesTab.tsx:247-263`**
The summary box prints `aggregates.count` as "(n delivery notes)" directly above a table whose row count can differ, because `deliveryNoteAggregates()` adds `->where('currency', $currency)` (`DeliveryNoteController.php:195`) while the row query does not. Undisclosed to the operator and not mentioned in the handback. Adjacent to deferred OI-11, but the *presentation* of a count that disagrees with the visible rows is new in M2.

**6. — P3 — CONFIRMED — `PartnerDeliveryNotesTab.tsx:214-219`**
When every selected row was refused, `removeAndRetry` clears the selection, sets `billingRefusal` to `null`, and submits nothing. The persistent region — including the "no invoice was created, no number used" guarantee — vanishes with no replacement message. (Inherited from M1's `removeRefusedDeliveryNotes`, `DeliveryNoteConsolidation.tsx:170-181`; replicated here.)

**7. — P3 — CONFIRMED — `PartnerDeliveryNotesTab.tsx:146`**
`new Date(deliveryNote.document_date).toLocaleDateString()` passes no locale, so the date column ignores the active i18n language and follows the browser. This was already recorded as a bridge P3 at M1 ("raw date formatting") and is reproduced in new code.

**8. — P3 — CONFIRMED — `apps/api/app/Modules/Document/Presentation/routes.php:270-272` vs `apps/web/src/features/partners/PartnerDetailPage.tsx:219-226`**
The tab is FE-gated on `hasModule('Sales') && deliveries.view`, but its data route `GET /delivery-notes` carries `can:deliveries.view` **only** — no `module:Sales` (contrast `:274-276` and `:292-295`, which M1 did gate). Adding it would be a revocation beyond M2's scope, so the correct disposition is to **report** it as a residual alongside F-3; it is absent from the handback's residual list.

**9. — P3 — CONFIRMED — `apps/web/src/locales/{en,fr}/sales.json` → `deliveryNotes.partnerTab.coexistence`**
Spec §4 prescribes a four-line helper block ("…Bill from the **sales order** when… / Bill from **delivery notes** when… / Once a delivery note is on an invoice…"). The shipped string keeps line 1 and a variant of line 4 and drops the two lane-guidance lines. Defensible editorially, but it is an undeclared deviation from a specced string (brief §7 item 10).

**10. — P3 — CONFIRMED — `PartnerDeliveryNotesTab.tsx:171-176`**
`invoiced_at: refusal.invoice_date ?? 'attributed'` injects a non-date sentinel string into a field typed as an ISO timestamp purely to force `DeliveryNoteBillingStatus` down its non-null branch. It works today only because that component never parses the value.

**11. — P3 — CONFIRMED — `PartnerDetailPage.tsx:477-479` + `PartnerDeliveryNotesTab.tsx:354-371`**
`PartnerUnbilledBalanceLine` is mounted as a direct child of the balance card's `<dl>` (`:466`) but renders `<div><Link>…</Link></div>` with no `<dt>`/`<dd>`, unlike every sibling row. Invalid definition-list content model and inconsistent with the card's own markup.

**12. — P3 — CONFIRMED — `apps/web/src/components/molecules/FilterTabs/FilterTabs.tsx:27`; `DeliveryNoteDetailPage.tsx:29`**
Two scope/placement notes: a shared molecule (`FilterTabs`, used by other features) gained `aria-pressed` inside the M2 commit — benign and correct ARIA for these plain buttons, but a shared-component edit made to serve a milestone assertion (rule 4); and `DeliveryNoteBillingStatus` — consumed by the detail page — is exported from a file named `PartnerDeliveryNotesTab.tsx`, so a page-level component imports from a tab component.

**13. — P3 — CONFIRMED — `DeliveryNoteDetailPage.billingStatus.test.tsx:88-104`**
Only the positive case is asserted. There is no test that an **un-invoiced** DN renders no billing line, which is the assertion that would catch a regression in the `invoiced_at !== null` guard at `DeliveryNoteDetailPage.tsx:147`.

---

## Bypasses I tried that FAILED (implementation held)

- **`invoiced=1` unsupported → "Invoiced" tab silently shows everything.** Refuted: `DeliveryNoteController.php:177-179` handles it via `whereDeliveryNoteInvoiced()` (`Document.php:662-665`).
- **`invoiced_at` absent from `/documents/{id}` → detail page shows a phantom "Invoiced" line for every DN.** Refuted: `showAny` returns `DocumentData` (`DocumentController.php:186`) and `DocumentData::fromModel` always projects the field from `DeliveryNoteBillingState::fromPayload` (`DocumentData.php:140,256-259`).
- **`refusal.invoice_id === undefined` → `entityRoutes.document(undefined)`.** Refuted: `parseDeliveryNoteBillingRefusal` normalises every optional field through `nullableString` (`deliveryNoteBillingRefusal.ts:18-20,43-46`).
- **C9 invalidation misses a key or leaks across tenants.** Refuted: all five namespaces match the real keys (`useDeliveryNotes.ts:107,126`; `PartnerDetailPage.tsx:238`) and `deliveryNotesTenantScope.test.tsx:283-315` proves current-tenant hits and cross-tenant misses.
- **Sidebar entry breaks the group's 1:1 permission↔route alignment.** Refuted: `Sidebar.tsx:300` uses `reports.financial`, matching `routes/index.tsx:2048`; `Sidebar.test.tsx` asserts both directions.
- **Missing fr keys / raw key strings rendered.** Refuted: programmatic en/fr parity check over every `t()` call in the new files — zero missing, zero en-only/fr-only, and the OI-9 wording is byte-exact to spec §4.2 ("Invoiced (source not recorded)" / "Facturé (origine non enregistrée)").
- **Module gate is a `moduleKey` alias no-op.** Refuted: `hasModule('Sales')` from `useCompanyConfig`, and `PartnerDetailPage.deliveryNotes.gates.test.tsx:140-148` proves an **admin** with every permission gets no tab under `defaultCompanyConfig` (whose `all_enabled_modules` genuinely lacks `Sales` — fixture verified, so the test is non-vacuous). Permission and module assertions are separate tests.
- **Hardcoded colors / `parseFloat` / `any`.** Refuted: grep clean on the new files; `npx tsc --noEmit` exits 0.
- **Tests are green only on paper.** Refuted: I ran them — 6 files / 66 tests passed, matching the handback's claim exactly.

---

**Disposition:** findings 1 and 2 are P1 (a wrong money figure and an unusable default action on the milestone's headline surface; a ratified owner UI condition rendered as a count). 3 and 4 are P2. The rest may ship with tickets recorded.

VERDICT: CHANGES-REQUIRED
