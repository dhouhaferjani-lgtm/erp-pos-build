# L6 follow-ups — client-bug batch BUG-003/004/006/007

**Source:** merge-gate records
`docs/superpowers/reviews/2026-08-06-l6-partners-fe-gate.md` (APPROVE-WITH-FIXES)
and `docs/superpowers/reviews/2026-08-06-l6-import-wizard-gate.md` (spec ✅ / quality CHANGES-REQUESTED).
**Branch the findings came from:** `fix/client-bugs-partners-fe` on `origin/dev @ fe0df479e`.
**Closed in the fix round (not repeated here):** FE M1, m1, m2, m4-docblock, m7; import F1, F2, F3, and
the F4 size-boundary comment.

Everything below was explicitly ruled *ticketable* — none of it blocks the merge.

---

## P2 — correctness gaps a user can reach

### T1 — `PartnerReferenceCounter` guards 3 of ~14 `partner_id` tables
`apps/api/app/Modules/Partner/Application/Services/PartnerReferenceCounter.php`

Covered: `documents`, `payments`, `pos_receipts`. **Not** covered: `journal_lines`, `vouchers`,
`workshop_work_orders`, `scheduling_appointments`, `pos_orders`, `withholding_certificates`,
`promotion_usages`, `coupon_usages`, `vehicles`, `partner_price_lists`, `buyer_seller_mappings`,
`platform_supplier_mappings`, `pos_customer_aliases`.

So an Otospex partner with an open work order, or a partner holding an unredeemed voucher, still
soft-deletes and leaves those rows pointing at an invisible partner (the FKs never fire on a soft
delete). The docblock has been narrowed to say exactly this — the code's scope claim is now honest,
but the gap is real.

**Constraint on the fix, from the gate's rule-6 adjudication:** do NOT just add more
`db->table('…')` reads. The query-builder approach was accepted *as-is* because it matches house
precedent (`Fiscal/.../OutboxIngestor.php:644` reads `pos_terminals`), but it trades a compile-time
dependency for an unguarded schema dependency. Widening the sweep requires a `Shared/Contracts`
reader per owning module (the directory already holds `Document/OperationResolverInterface.php`,
`Treasury/*`, `POS/TerminalSyncHealthSource.php`) so each module answers "do I reference this
partner?" for itself and the coupling is testable from the owning side.

Suggested first slice: `journal_lines`, `vouchers`, `workshop_work_orders` — the three with real
financial or operational weight.

### T2 — deleting a partner leaves its detail cache warm (FE gate m8)
`apps/web/src/features/partners/PartnerDetailPage.tsx` — `onSuccess` invalidates the `partners`
list predicate only. The exact key `tenantScopedKey(['partner', id])` is untouched, so
back-navigation renders the deleted partner from cache until the 404 lands.
**Fix:** `queryClient.removeQueries({ queryKey: tenantScopedKey(['partner', id]) })` in `onSuccess`.

### T3 — push-back effect double-fetches a bookmarked `?type=…&page=N` (FE gate m3)
`apps/web/src/features/partners/PartnerListPage.tsx` — the route-type push-back goes through
`tableState.setFilter`, which also calls `setPageState(1)` (`useTableState.ts:169-176`). Opening a
bookmarked `/purchases/suppliers?type=customer&page=3` fetches `type=supplier&page=3`, then
`type=supplier&page=1`. Correct data, one wasted request. Documented in the effect comment.
**Fix:** a page-preserving filter setter, or fold it into T4.

---

## P3 — hardening and hygiene

### T4 — `useTableState` should re-seed `filters` when `defaultFilters` changes
The root cause behind BUG-006's page-local workaround.

**Sizing (corrected — the original commit overstated it):** the hook has exactly **three**
consumers — `PartnerListPage.tsx`, `ProductListPage.tsx`, `MenuListPage.tsx`. Blast radius is
small. The difficulty is **semantic, not scale**: naively re-seeding whenever `defaultFilters`
changes would stomp a user-set filter on every render, because `defaultFilters` is a fresh object
literal each time. The fix needs a previous-defaults ref and a "only if the DEFAULT itself changed"
comparison. Once it lands, the page-local effect in `PartnerListPage` can be deleted.

### T5 — route-source assertion should lock count parity, not the literal 6 (FE gate m5)
`apps/web/src/features/partners/__tests__/partnerListRouteType.test.tsx` enumerates four exact
single-line strings and asserts exactly six keyed matches. A seventh partner route element written
multi-line, or with a different attribute order, escapes both halves.
**Fix:** assert `routesSource.match(/<Customer(?:ListPage|Form)\b/g).length` equals the keyed-match
count, so parity is the invariant rather than a magic number.

### T6 — the supplier branch of `navigate(basePath)` is untested (FE gate m9)
`apps/web/src/features/partners/__tests__/partnerDelete.test.tsx` mounts only
`/sales/customers/:id`. The clients-vs-fournisseurs redirect after delete rests on reading
`PartnerDetailPage.tsx:157-164`.
**Fix:** parameterise the fixture over `/purchases/suppliers/:id` too.

### T7 — `PARSE_FAILED` typed error code for the import wizard (import gate F4)
`apps/api/app/Modules/Import/Presentation/Controllers/MigrationWizardController.php:43` and `:56-58`
return `{"error": "<string>"}` while every handler in `bootstrap/app.php` returns
`{"error":{"code","message"}}`. Consequences: the FE cannot key off `error.code` for the parse case
(it can only infer from the *absence* of a code), and `getErrorMessage()` returns `undefined` for
these responses while `isApiError` still asserts the `AxiosError<ApiError>` type — a live type lie
for any future caller.
**Fix:** emit `{"error":{"code":"PARSE_FAILED","message":…}}` (and `STORAGE_FAILED` for the 500),
then have `describeUploadFailure` branch on `error.code` rather than on HTTP status. That also
removes the client/server size-boundary coupling documented at the `maxSize` call site.

### T8 — re-selecting the same file after a failed upload is a no-op (import gate F5)
`apps/web/src/features/import/components/FileUpload.tsx` never resets the `<input type="file">`
`value` (neither in `handleInputChange` nor in `clearFile`), while `ImportWizardPage` clears its own
`selectedFile`. Re-picking the *same* file fires no `change` event, so the "try again" the new
error copy advises does nothing unless the operator drag-drops or reloads. Pre-existing, but the new
messages now depend on it.
**Fix:** `e.target.value = ''` after `handleFile(file)` in `handleInputChange`.

### T9 — promote the blocked-submit contract beyond ProductForm (FE gate Q4 / retro N3)
`apps/web/src/lib/formErrors.ts` is a shared module with exactly one consumer. The gate ratified the
placement **conditionally**: sweep the other entity forms (partners, documents, expenses, …) so a
blocked submit is never silent anywhere, and once there are ≥2 consumers, promote the
toast + focus + `shouldFocusError:false` *pairing* into a `useBlockedSubmitFeedback()` hook in
`src/hooks/` so no form re-derives the toast key and the `getElementById` dance.

---

## Known-red at `fe0df479e` (branch hygiene — not introduced by this batch)

Base-verified by both the implementer and the FE gate on a detached worktree at `fe0df479e`:

| File | Failing tests |
|---|---|
| `src/features/inventory/__tests__/tenantScope.test.tsx` | 2 — `locations` and `product-movements` key shapes |
| `src/features/inventory/pages/StockByLocationPage.test.tsx` | 1 — `rebalance.ts:11` `flatMap` of undefined via `RebalancingView.tsx:19` |
| `src/__tests__/i18n/arLocaleCoverage.test.ts` | 3 — `ar/common.json`, `ar/workshop-technicians.json`, `ar/vehicles.json` (FE gate m6; undisclosed by the first pass) |

### T10 — `PartnerForm.test.tsx` bank-account IBAN case is a load-dependent flake

`src/features/partners/PartnerForm.test.tsx` > "adds a bank account on the edit page, derives its
IBAN, and submits without blocking" fails intermittently, and ONLY when several feature directories
run in one vitest invocation. Failure mode:

```
TestingLibraryElementError: Unable to find an accessible element with the role "option"
and name /Amen Bank CFCTTNTT/i
```

i.e. the async banks query has not resolved inside the `waitFor` budget. Observed roughly 2 failures
in 10 heavy multi-directory runs; 0 failures in 3 solo runs of the file, 2 full `src/features/partners`
runs (100 tests) and 3 further heavy runs. Explicitly checked against the `is_active` change that
touches the same component: reverting `PartnerForm.tsx` to `a8c2c9ff8` does not remove it, and the
failing assertion is on a bank dropdown the toggle does not touch.

**Fix:** give the bank-option lookup an explicit `findByRole` / longer `waitFor` timeout, or seed the
banks query rather than waiting on the network mock. Until then it will keep costing reviewers a
re-derivation.
