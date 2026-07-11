# Gate 1 Review — Fix List for Codex Continuation

> Adversarial review of `feat/design-system-unification` @ 22 commits (worktree `../erp.design-sweep`), 2026-07-10. Five independent review lanes; all claimed verification evidence independently reproduced (design audit 430/0/0, tanstack 0, typecheck clean, lint 0 errors, 188 FE + 55 BE targeted tests green, purchases dir at zero for C1/C2/C3/C5).
> **Overall verdict: APPROVE-WITH-FIXES, merge-gated on the 2 blockers.** The architecture is sound — baseline is honest (replayed across all 22 commits: monotonic 494→419, never laundered), payloads on rebuilt pages are field-identical to origin/dev, deletions/migrations/ports all verified. Apply the fixes below IN THIS ORDER before continuing Wave 5.

---

## BLOCKERS (fix first, each with a test that fails before the fix)

**BL-1 — PartnerPicker: empty-string value corrupts every migrated form.**
`components/molecules/pickers/PartnerPicker.tsx:136` gates rehydration on `typeof value === 'string' && selectedPartnerId !== null`, but nearly every migrated caller passes `''` when nothing is selected (DocumentForm `field.value ?? ''` :551 with `partner_id: ''` default :221; CreateCreditNotePage :405; QuoteRequestCreatePage :179; ContactFormPage :296; ContactDetailPage :193; CustomerHistoryAuditPage :152; ReviewIngestionPage :118/:131). `''` passes the gate → `GET /partners/` → Laravel rtrims the slash → partners **index** → `toValue()` on an array → `{id: undefined, name: undefined}` truthy → picker flips to a blank "selected" chip, search input gone; clear → `onChange(null)` → callers write `''` back → stuck.
Fix: normalize `''`→`null` when computing `selectedPartnerId` (~:112) AND gate `selectedPartnerId !== null && selectedPartnerId !== ''`. Align callers to `field.onChange(next?.id ?? null)` (currently `?? ''`, e.g. DocumentForm :552). **Add a `value=''` unit test** (renders search input, no fetch fired).

**BL-2 — Service lines lose their service identity on save (D3 claimed GREEN, isn't).**
`DocumentForm.tsx:145-159` `buildLinePayload` sends only `product_id` (empty→null for service lines) and never `service_id`/`is_service`; backend already supports `lines.*.service_id` (`CreateDocumentRequest.php:104-109`, `prohibits:lines.*.product_id`). A Workshop service saves as a bare free-text line; on reload `initialLines` (:348) restores neither field → badge gone, `!line.is_service` logic misclassifies.
Fix: in `buildLinePayload`, emit `service_id` and OMIT `product_id` when `is_service`; restore `service_id`/`is_service` in the `initialLines` mapping. **Add an end-to-end DocumentForm test**: add service line → submit payload contains `service_id`, no `product_id`; reload from server data → badge and classification intact. Correct the progress-doc GREEN claim.

## MAJORS

Scanner/tooling:
- **MJ-1** `tools/audit-design-system.mjs:26-32` — tag regexes truncate at the first `>` inside attributes: `<input onChange={(e) => ...} className={tokens.input.base}/>` is invisible (~105 real C2/C3 violations missed; permanent evasion hole). Fix: `[^>]*` → `(?:=>|[^>])*` in all five TAG_RE entries; re-run `--write-baseline`; commit the honest baseline growth with a note. The same fix is being applied to the inventory manifest's C2/C3 python command by Claude (done — see manifest).
- **MJ-2** `audit-design-system.mjs:103-109` — baseline key has no occurrence disambiguator: a copy-pasted identical violation rides an existing entry. Fix: append per-file duplicate ordinal (`|#2`), same idea as the tanstack scanner's offset disambiguation.

Line-item UX (Wave 1):
- **MJ-3** `DocumentLineEditor.tsx:~394` — `handleAddService` hardcodes `tax_rate: '0'`, `tax_configuration_id: null`; Workshop labor is taxable (FR/TN) → silent 0-VAT invoice lines. Fix: resolve service tax like the product path (:353, :369-370) or company default rate.
- **MJ-4** `LineItemEntryBar.tsx` — dropdown now opens on every focus but has NO blur/click-outside close (only Escape or committed add) → permanent z-20 overlay when tabbing through. Fix: close on pointerdown-outside.
- **MJ-5** `LineItemEntryBar.tsx:~186-189` — `handleKeyDown` early-returns on empty query, so focus-suggestions are keyboard-unreachable (ArrowDown highlights, Enter can't commit). Fix: commit highlighted product when `isOpen && products.length > 0` before the empty-query bail-out.
- **MJ-6** `DocumentLines.tsx` quantity Cell renders raw `line.quantity` ("2.0000"); old QuoteDetailPage used `formatQuantity`. Fix: `formatQuantity(line.quantity, ...)` per precision contract.

Settings (Wave 2):
- **MJ-7** CompanyPage save invalidates only `['company-settings']` (`CompanyPage.tsx:188`) but `useLineDesignationFeature` reads `['company-config']` (`CompanyConfigContext.tsx:58`) → toggle needs a full reload to take effect. Fix: also invalidate `tenantScopedKey(['company-config'])` on save success.

Partner consolidation (Wave 3):
- **MJ-8** `PartnerPicker.tsx:288` — inline add-new uses type-blind `t('partner.addNew')` = "Add new customer", shown in supplier contexts (DocumentForm POs, ReviewIngestionPage). Fix: type-keyed labels (`partner.addNew.customer/.supplier/.generic`) ×3 locales.
- **MJ-9** `DocumentForm.test.tsx:59-82` + `DocumentForm.tenantScope.test.tsx` re-stub PartnerPicker with a `vi.mock` (handover forbade stubbing the picker) — this is exactly what masked BL-1, and the tenantScope test "invalidates partner creation against active tenant only" asserts a predicate implemented INSIDE the stub (tests the mock, not production). Fix: render the real picker in edit-mode + inline-create tests (mock only `api.get`); drop/replace the vacuous assertion.

Procurement rebuilds (Wave 4):
- **MJ-10** `SupplierInvoiceCreatePage.tsx:445,453,528,870` — i18n keys `purchases:supplierInvoices.create.manualLine.add|remove|batchNumber` missing from en/fr/ar → raw keys render on the page. Fix: add 3 keys ×3 locales.
- **MJ-11** `SupplierInvoiceCreatePage.tsx:79-87`, `QuoteRequestCreatePage.tsx:42-46` — zod schemas are all unconstrained `z.string()`: resolver can never fail, no inline `FormField` errors rendered anywhere. Decorative zod ≠ the canonical DocumentForm pattern. Fix: real constraints (issueDate nonempty; location required in delivered mode via `superRefine`; etc.) + inline error rendering.
- **MJ-12** Rebuilt create pages (supplier invoice, quote request, standalone receipt) lack breadcrumb + `useUnsavedChangesGuard` (canonical: `DocumentForm.tsx:26,304,487`) → dirty-form navigation silently discards input. Fix: wire both, or record an explicit deferral in the progress doc.

## MINORS (batch into one cleanup commit)

- `GoodsReceiptListPage.tsx` — rebuilt but retains **37 hardcoded color literals** (baseline-acknowledged); sweep it since the file was already touched.
- SaveSplitButton on the two create pages: "Save & Close" duplicates the primary action exactly — drop it or differentiate.
- `paymentStatus.ts` has no unit test (the deleted `PaymentStatusBadge.test.tsx` wasn't replaced) — add `isPaymentStatus`/`paymentStatusTone` tests; also note the consolidation inlined 3 duplicate JSX blocks across detail pages (acceptable, but consider a tiny shared cell).
- Handover's route/page dedup silently skipped: dead `documents/ReturnNoteDetailPage.tsx` (router uses `return-notes/`), dead `reports/pages/AgedReceivablesPage.tsx` (router uses `finance/`), stray `finance/AgedReceivablesPage.test.tsx` — delete or log deferral.
- Migration `2026_07_10_100000_add_line_designation_override_to_companies.php`: add `Schema::hasColumn` guard (re-runnable convention); drop the no-op `->after()` (PostgreSQL).
- `DocumentLines.tsx:~104-106` — line notes now shown even with the designation feature OFF (PDF hides them when off): confirm intent or restore the gate.
- Add one full-template PDF render test (through `DocumentPdfService` + `invoice.blade.php`) proving `$company` reaches the include (current test renders the component directly).
- Article sub-line duplicates the product name when description is untouched — suppress when identical.
- LineItemEntryBar focus-fetch sends no `per_page` — add a small explicit limit (e.g. 20, like ProductPicker).
- Tooling: `--write-baseline` logs pre-dedup count (509 vs 494 written) — print set size; C6 regex misses untyped `const statusMap = {...}` — add `Map|Maps` to the const-form suffix; shorthand resolver is scope-blind (accept only in-scope ancestor declarations, else default-deny).
- Unannounced status-tone shifts (RelatedDocumentsTab "current" chip → info; QuoteRequestList lost/cancelled green→neutral; SupplierInvoiceList draft/posted) — fine, but list them in the progress doc so the owner's visual pass expects them.
- `data-testid="submit-rfq-group"` dropped from QuoteRequestCreatePage (no live references — confirm and move on).
- Dev `console.warn` at `SupplierInvoiceCreatePage.tsx:216` (pre-existing) — remove while there.
- `ar` locale missing all `sales:invoices.paymentStatus.*` keys (pre-existing) — add while there.

## PROCESS RULES for the continuation

1. **Do not declare any further Wave-5 directory clean (or ESLint-promote it) until MJ-1 lands** — the current scanner would certify directories clean with tokenized raw controls remaining.
2. Every deviation or deferral goes in `docs/handoff/design-system-sweep-progress.md` explicitly — the silently-skipped route dedup and the overstated D3 GREEN claim were both caught this gate; silent gaps cost a full review lane.
3. Keep the discipline that passed this gate: never `--write-baseline` to hide new work (the replay check WILL be run again), targeted test runs only, worktree stays clean.
4. After the fix wave: re-run the full evidence set (design audit, tanstack audit, typecheck, lint, targeted suites incl. the NEW BL-1/BL-2 tests) and update the progress doc before continuing Wave 5 (documents C1/C5/C7, then remaining dirs, then Wave 6).

## What was verified clean (don't redo)

Baseline honesty (replayed, never laundered); tanstack shorthand fix real + pinned + default-deny; ESLint palette widened at WARN and ERROR levels, strict dirs still 0 errors; all 4 D4 ports present; all 7 partner call sites migrated; PartnerSearchSelect/PartnerSelect/SupplierPicker genuinely deleted; AddPartnerModal invalidation covers all live key prefixes with tenant isolation; submit payloads byte-identical on all rebuilt pages (3 supplier-invoice modes, quote requests, standalone receipt, detail payments); all 6 list filters preserved; D2 both gates removed with ARIA corrected and barcode path untouched; D7 density implemented correctly incl. DesignationCell edit-mode unaffected and QuoteDetailPage genuinely migrated; blade-template deviation judged justified (blades read the deleted config directly); zero stale `config('features` reads; Company model/DTO/FormRequest correct; FR helper text carries the "dénomination précise" guidance.
