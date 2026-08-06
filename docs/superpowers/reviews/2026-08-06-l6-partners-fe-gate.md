# Merge gate — `fix/client-bugs-partners-fe` (BUG-003/004/006/007)

**Reviewer:** adversarial frontend-conventions gate (Opus)
**Branch:** `fix/client-bugs-partners-fe` @ `a8c2c9ff8`, 4 commits on `origin/dev` @ `fe0df479e`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l6-partners`
**Spec:** `docs/bug-reports/2026-08-05-client-bugs.md` · **Forensics:** `docs/sessions/RETRO-client-bugs-2026-08-06.md`
**Date:** 2026-08-06

## VERDICT: APPROVE-WITH-FIXES

One MAJOR (a translated message instructs an action the UI cannot perform) plus 8 MINORs.
No BLOCKER. No evasion, no baseline manipulation, no false-green. The BUG-006 request-count
assertion is real — proven red on base with the exact forensic signature.

---

## 1. Gates re-run (nothing accepted as reported)

| Gate | Command | Result |
|---|---|---|
| Typecheck | `pnpm --filter @autoerp/web typecheck` | clean |
| Lint (+audits) | `pnpm --filter @autoerp/web lint` | **0 errors**, 6517 warnings (pre-existing baseline noise) |
| `audit:keys` | in lint | Gate C: **0 new**, 0 stale |
| `audit:design-system` | in lint | **743 acknowledged, 0 new, 0 stale** |
| `audit:quantity` | in lint | 0 total |
| eslint-rules RuleTester | in lint | 3 rules, all cases pass |
| vitest partners + lib/formErrors + routes | `npx vitest run src/features/partners src/lib/formErrors.test.ts src/routes` | **13 files / 119 tests passed** |
| vitest import | `npx vitest run src/features/import` | 8 files / 34 passed |
| vitest inventory | `npx vitest run src/features/inventory` | 41 passed / **2 files, 3 tests failed — pre-existing (see §5)** |
| vitest lib + i18n | `npx vitest run src/lib src/__tests__/i18n` | 18 passed / **1 file, 3 tests failed — pre-existing (see §5)** |
| PHPUnit (by path) | `tests/Feature/Partner/ tests/Feature/Accounting/AccountingTenantIsolationTest.php tests/Feature/Pricing/PricingTenantIsolationTest.php` | **194 tests, 708 assertions, OK** (4 skipped) |
| PHPStan L8 | `./vendor/bin/phpstan analyse app/Modules/Partner` | No errors |
| Pint | `--test` on the 3 changed PHP files | pass |

### Baseline honesty
`apps/web/tools/audit-design-system-baseline.json` is **not in the diff** (`git diff --stat fe0df479e..a8c2c9ff8`
lists 23 files, no baseline). Neither is the tanstack-keys baseline nor the quantity baseline.
No `--write-baseline` absorption. **PASS.**

### Mechanism audit
`git diff fe0df479e..a8c2c9ff8 | grep -E '^\+.*(eslint-disable|@ts-ignore|@ts-expect-error|phpstan-ignore|baseline|\.skip|as any|as unknown as)'`
→ **zero hits**. No alias tables, no detector-keyword suppressions, no renamed-equivalent literals.
The one indirection worth naming is the backend query-builder table read — adjudicated in §3.

---

## 2. Red-proof: the tests are not vacuous

The four new spec files were copied verbatim onto a detached worktree at base `fe0df479e`
(node_modules symlinked from the branch worktree) and run there.

```
Tests  15 failed | 6 passed (21)
```

Decisive result for the retro's central finding — `partnerListRouteType.test.tsx:120`
("ISSUES A REQUEST, and one for type=supplier") fails on base with:

```
AssertionError: expected 1 to be greater than 1
   <title>Suppliers | IziPOS</title>
```

That is **zero new partner requests after navigating Clients → Fournisseurs**, i.e. the TanStack
cache-replay signature the retro proved live (`RETRO §1.1`: `[S] new reqs: []`). The
request-count-increase assertion at `partnerListRouteType.test.tsx:137-139` is load-bearing;
the params assertion at `:142-144` is the second half and would have passed vacuously alone —
the file's own comment says exactly this and the comment is TRUE.

The 6 tests that pass on base are all intentionally-passing: the two `PartnerForm` mechanism
tests (the "freezes when NOT keyed" case documents the bug; the "keyed" case is a local-fixture
fix), the two BUG-004 unchanged-behaviour cases (422 → parseError, non-HTTP → parseError),
the permission-hides case (viewer had no delete button before either) — and **one that should
not pass**: see finding m1.

---

## 3. Per-bug verification

### BUG-006 — route partnerType authoritative — PASS (spec met)

- **Authoritative overwrite.** `PartnerListPage.tsx:155-158` — `if (partnerType) queryParams['type'] = partnerType`,
  the `&& !queryParams['type']` guard removed. Matches spec fix #1 exactly.
- **partnerType in the tenant-scoped key.** `PartnerListPage.tsx:176` —
  `tenantScopedKey(['partners', partnerType ?? 'all', queryParams])`. `audit:keys` re-run: 0 new.
  `partnersInvalidationPredicate` (`_invalidation.ts:9-23`) requires `length >= 3` and `k[0]==='partners'`
  with tenant/company as the last two — the new 5-element key still matches, so the BUG-007 delete
  invalidation reaches it. Verified by reading, and by the partnerDelete test passing.
- **`key=` on all SIX route elements.** `routes/index.tsx:520, 530, 550, 767, 777, 797` — verified by
  grep; those are the only `<CustomerListPage>` / `<CustomerForm>` occurrences in the file.
  `CustomerDetailPage` is correctly left unkeyed (it is `:id`-driven and derives context from
  `location.pathname` fresh each render — `PartnerDetailPage.tsx:157-164`).
- **Source assertion can't rot silently.** `partnerListRouteType.test.tsx:214-233` uses the
  `src/routes/routes.test.tsx:1-4` convention (`readFileSync(process.cwd()/src/routes/index.tsx)`).
  The `toHaveLength(6)` regex breaks LOUDLY on a reformat, so the dangerous direction (silent
  vacuous pass) is covered. It is not airtight for a 7th route — see m5.
- **Effect does not loop or fight `useTableState`.** `PartnerListPage.tsx:164-170` is guarded by
  `currentTypeFilter !== partnerType`; `setFilter` (`useTableState.ts:169-176`) writes that exact
  value, so the second pass no-ops. The URL-sync effect (`useTableState.ts:119-149`) uses
  `replace: true`, so the transient `?type=<stale>` write is not bookmarked into history.
  **There is no user-facing `type` filter control on this page** (the only filter controls are
  status tabs, has_balance, search — `PartnerListPage.tsx:123-147`), so the effect cannot stomp a
  user choice. Confirmed no infinite loop by reading both state paths and by the 4 passing tests.
- **`tenantScope.test.tsx` edit is a key-shape update only.** Diff = +5 lines (`'all',` plus a
  4-line comment), **0 removed**. The assertion is still a full-array `toContainEqual` with
  `'tenant-A','company-1'` as the trailing suffix. Nothing weakened. **Ratified.**
- **Form-variant verdict — RATIFIED.** Their claim ("mechanism real, unreachable live, covered by
  `key=` anyway") holds: the retro tried both live nav paths and got `supplier` both times
  (`RETRO §1.2`), and their mechanism test at `partnerListRouteType.test.tsx:282-295` proves the
  reconciliation freeze only via a synthetic `<Link>` from `/sales/customers/new` straight to
  `/purchases/suppliers/new` — a link that does not exist in the app. That is honest and clearly
  labelled in the file docblock. `key="customer"`/`key="supplier"` on `:530/:550/:777/:797` closes
  it regardless. **Challenge withheld.**
- Acceptance criterion "a partner with `type=customer` never renders in the suppliers view" is met
  transitively (correct request params + correct backend scoping, `PartnerController.php:53-65`),
  not by a rendered-rows assertion. Acceptable; noted.

### BUG-007 — partner delete — PASS with one MAJOR

- **Pattern fidelity vs `ProductDetailPage`:** `Button variant="danger"` (`PartnerDetailPage.tsx:392-401`,
  atom at `components/atoms/Button/Button.tsx:19`), `ConfirmDialog variant="danger" isLoading=`
  (`:891-905`), `invalidateQueries({ predicate: partnersInvalidationPredicate(tenantId, companyId) })`
  (`:243-246`), `navigate(basePath)` (`:249`) where `basePath` is derived from `location.pathname`
  (`:157-164`) → `/sales/customers` vs `/purchases/suppliers` vs `/partners`. Correct list.
  *(The supplier branch is not covered by a test — only the customers route is exercised
  (`partnerDelete.test.tsx:78-89`). See m9.)*
- **Permission gating HIDES.** `{canDeletePartner && <Button …>}` at `:392` — conditional render,
  not `disabled`. Asserted at `partnerDelete.test.tsx:153-162` with a `viewer` role.
- **i18n complete in en/fr/ar, and the `ar` requirement is REAL.** Verified in `src/lib/i18n.ts:274+`:
  the `ar` block merges `partners: { ...enSales.partners, ...arSales.partners, countLabels:{…},
  empty:{…}, messages:{…}, types:{…}, validation:{…} }` — `delete` has **no** deep-merge branch, so
  `...arSales.partners` replaces the whole `delete` object. A partial `ar.delete` would have
  rendered raw keys. All 5 keys are present (`ar/sales.json:95-101`). **Their claim is correct
  and the mitigation is correct.** (Note: `sales` is not covered by `arLocaleCoverage.test.ts`,
  whose enforced set is common/validation/workshop-*/vehicles/vehicle-ownership/scheduling/pickers —
  so this was a genuine manual-diligence catch, not a test-enforced one.)
- **409 toast translated.** `PartnerDetailPage.tsx:252-262` matches
  `isApiError && status===409 && data.error.code==='PARTNER_HAS_DOCUMENTS'` → `t('sales:partners.delete.blocked')`.
  The test at `partnerDelete.test.tsx:164-195` uses the REAL catalog (asserts `/cannot be deleted/i`
  and the interpolated name), not a key echo. Good.
- **Backend guard.** `PartnerController.php:344-363` (409 + `PARTNER_HAS_DOCUMENTS` + `details` counts),
  `PartnerReferenceCounter.php:42-54`. Soft-deleted documents do not block
  (`->whereNull('deleted_at')` at `:45`, tested `DeletePartnerTest.php:250-259`); other partners'
  documents do not block (tested `:261-275`). Both claims **verified by re-running PHPUnit**.
- **Rule 6 adjudication — cross-module query-builder reads: ACCEPTED, with a caveat.**
  Rule 6 forbids *importing models* across modules. `PartnerReferenceCounter` imports only
  `Illuminate\Database\ConnectionInterface` and reads `documents`/`payments`/`pos_receipts` by
  table name, so it creates **no compile-time dependency** and no deptrac edge. The cited precedent
  is real and I verified it: `Fiscal/Application/Services/OutboxIngestor.php:644` reads
  `pos_terminals` (a POS-module table) the same way, and
  `Fiscal/Application/Services/TerminalRegistrySnapshotService.php:347-357` carries an explicit
  comment justifying the raw `db->table()` path with the same reasoning
  (`ParseFailureResolutionService.php:158-165` documents a third instance, attributed to a prior
  Opus review round). So this is an established house precedent, not a novel dodge.
  **Caveat to record:** it trades a compile-time dependency for an *unguarded schema* dependency.
  The canonical alternative is a `Shared/Contracts` reader (the directory already holds
  `Document/OperationResolverInterface.php`, `Treasury/*`, `POS/TerminalSyncHealthSource.php`), which
  would make the coupling explicit and testable from the owning side. Not required to merge —
  but the counter should not be extended further without one.

### BUG-003 — blocked product save — PASS

- `lib/formErrors.ts` (new): `collectErrorFieldNames()` flattens RHF `errors` into dotted
  `register()` paths incl. field arrays (`formErrors.ts:47-66`); `focusFirstInvalidField()` walks
  `form.querySelectorAll('[name]')` — **DOM order**, not error-key order (`:76-88`), feature-detects
  `scrollIntoView`, focuses with `preventScroll: true`.
- `ProductForm.tsx:257-265`: generic `onInvalid` → `toast.error(t('inventory:products.validationBlocked'))`
  + `focusFirstInvalidField`. **No category special-casing** — confirmed by reading; the handler never
  names a field.
- Toast key present in all three catalogs: `en/inventory.json:174`, `fr/inventory.json:174`,
  `ar/inventory.json:182`. (`ar` here is belt-and-braces: `i18n.ts:377` deep-merges
  `products: { ...enInventory.products, ...arInventory.products }`, so en would have survived.)
- **Owner question stays flagged, not decided.** Commit `74066542b` message carries an explicit
  `OWNER QUESTION (not changed here): should the Parapharmacy "Product Category" be required for
  every vertical?` with the archaeology (`9ee3132ab`). Correct handling — no unilateral product
  decision. **PASS.**
- DOM-order focus-walk correctness: the helper is correct in isolation
  (`formErrors.test.ts:44-57` proves DOM order beats error-key order). It is **not** the last
  writer at runtime — see m2.

### BUG-004 — import error taxonomy — PASS

Taxonomy verified against the controller's **actual** emissions
(`apps/api/app/Modules/Import/Presentation/Controllers/MigrationWizardController.php:32-62`):

| Emission | Source | FE mapping | Correct? |
|---|---|---|---|
| 422 | `$request->validate(['file'=>['required','file','mimes:csv,txt,xlsx,xls','max:10240']])` — `:34-36` | `parseError` | yes |
| 422 | `catch (\Throwable) → 'Could not parse file…'` — `:55-58` | `parseError` | yes |
| 500 | `if ($path === false) 'Failed to store file'` — `:42-44` | `serverError {{status}}` | yes |
| 413 / 419 / 5xx from infra | outside the controller | `serverError {{status}}` | yes |
| no response | network/CORS/timeout | `networkError` | yes |

**422 is genuinely the only parse-failure status this controller emits.** The mapping at
`ImportWizardPage.tsx:60-73` is a faithful inversion. Error-object plumbing verified: the axios
response interceptor (`lib/api.ts:161-208`) re-rejects the original `AxiosError` on every path
(including the non-typed-envelope path, since `isApiError` returns false for an nginx HTML body and
falls through to `error instanceof Error ? error : …`), so `axios.isAxiosError(error)` and
`error.response?.status` are both intact at the call site.
Raw error is **console.error'd, not swallowed**: `ImportWizardPage.tsx:413`.
i18n: `en/import.json` + `fr/import.json` gained `serverError`/`networkError`.
**The "ar inherits en" claim is TRUE and I verified it structurally:** `src/locales/ar/import.json`
has only top-level `options`/`preview`/`mapping` — **no `wizard` key at all** — and `i18n.ts:366` is
`import: { ...enImport, ...arImport, mapping: {…} }`, so the entire `wizard` subtree (including both
new keys) comes from `enImport`. No raw-key rendering in ar.

---

## 4. Findings

### MAJOR

**M1 — The blocked-delete message tells the operator to do something the UI cannot do.**
`apps/web/src/locales/en/sales.json:71` ("…**Deactivate it instead.**"),
`apps/web/src/locales/fr/sales.json:71` ("…**Désactivez-le plutôt.**"),
`apps/web/src/locales/ar/sales.json:100` ("…**قم بتعطيله بدلاً من ذلك.**").
There is **no `is_active` affordance anywhere in the partner UI**: `PartnerForm.tsx` has zero
`is_active` references (the only hit is `getCountries({ is_active: true })` at `:240`), and
`PartnerDetailPage.tsx:341-344` renders the status badge read-only. The spec itself flagged the
missing toggle (`BUG-007` root cause, "Also missing: no `is_active` toggle in `PartnerForm.tsx`…
though `UpdatePartnerRequest.php:103` accepts it"). Shipping the instruction without the control
sends the operator into a dead end in three languages, at the exact moment they are already blocked.
**Fix directive:** either land spec item 5 (an `is_active` toggle in `PartnerForm.tsx`, backend
already accepts it) in this batch, or delete the final sentence from all three `partners.delete.blocked`
strings.

### MINOR

**m1 — One new test passes on the unfixed base and does not lock what it claims.**
`apps/web/src/features/partners/__tests__/partnerListRouteType.test.tsx:179-201`
("scopes the react-query key by partner type") asserts `JSON.stringify(key)).toContain('customer')`,
which the OLD key `['partners', {…type:'customer'…}, tenant, company]` already satisfied. Verified:
it is one of the 6 tests that PASS on `fe0df479e`. The real lock is `tenantScope.test.tsx:190-198`.
**Fix:** assert positionally — `expect(key[1]).toBe('customer')`.

**m2 — react-hook-form re-focuses AFTER `onInvalid`, overriding the DOM-order walk.**
`node_modules/react-hook-form/dist/index.esm.mjs` (v7.67.0) `handleSubmit`:
`if (onInvalid) { await onInvalid(...) } _focusError(); setTimeout(_focusError);`.
`_focusError` iterates the `_fields` registry (registration order) and calls `ref.focus()` **without**
`preventScroll`. So on a multi-error submit the element chosen by `formErrors.ts:76-88` is focused,
then immediately replaced — and the browser scrolls to RHF's pick instead. The reported single-error
case is unaffected, and the only integration test that asserts `document.activeElement`
(`ProductFormInvalidSubmit.test.tsx:106-135`) is a single-error case, so the DOM-order guarantee
is asserted only in the isolated helper test. **Fix:** pass `shouldFocusError: false` to `useForm`
in `ProductForm.tsx` so the DOM-order walk is authoritative, or drop the "DOM ORDER" guarantee from
the `formErrors.ts:70-74` docblock and the commit message.

**m3 — The push-back effect resets pagination and can double-fetch.**
`PartnerListPage.tsx:166-170` routes through `tableState.setFilter`, which also does
`setPageState(1)` (`useTableState.ts:169-176`). On a bookmarked `/purchases/suppliers?type=customer&page=3`
the page fires `GET …type=supplier&page=3`, then a second `GET …type=supplier&page=1`.
Harmless, but undocumented. **Fix:** note it in the effect comment or add a page-preserving setter.

**m4 — The 409 guard covers 3 of ~14 `partner_id` tables while the comment claims to protect
"the partner's financial history".** `PartnerReferenceCounter.php:42-54` counts
`documents`/`payments`/`pos_receipts`. `partner_id` also exists on `journal_lines`, `vouchers`,
`workshop_work_orders`, `scheduling_appointments`, `pos_orders`, `withholding_certificates`,
`promotion_usages`, `coupon_usages`, `vehicles`, `partner_price_lists`, `buyer_seller_mappings`,
`platform_supplier_mappings`, `pos_customer_aliases` (`apps/api/database/migrations/tenant/*`).
An Otospex partner with an open work order, or a partner holding a voucher, still deletes.
This is spec-conformant (the spec asked for "documents/treasury rows") — the mismatch is the
comment's scope claim. **Fix:** narrow the docblock at `PartnerReferenceCounter.php:9-22` to the
three tables it actually guards and ticket `journal_lines`/`vouchers`/`workshop_work_orders`.

**m5 — The route source assertion is not airtight for a 7th element.**
`partnerListRouteType.test.tsx:217-233` enumerates 4 exact single-line strings and asserts exactly
6 keyed matches. A new partner route element written multi-line or with a different attribute order
escapes both halves. **Fix:** assert `routesSource.match(/<Customer(?:ListPage|Form)\b/g).length`
equals the keyed-match length, so *count parity* is the invariant rather than a fixed 6.

**m6 — Undisclosed pre-existing red.** `src/__tests__/i18n/arLocaleCoverage.test.ts` fails 3 tests
(`ar/common.json`, `ar/workshop-technicians.json`, `ar/vehicles.json`). I verified these are
**identical on `fe0df479e`** (base worktree, same 3 failures) — not introduced here — but they were
not in the branch's pre-existing-failure list. **Fix:** add them to the branch's known-red list so
the next reviewer isn't re-deriving it.

**m7 — `sales:partners.delete.failed` is added in en/fr/ar and never used.**
`PartnerDetailPage.tsx:261` falls back to `getErrorMessage(mutationError)`, which returns the raw
(English, server-authored) `error.message`. **Fix:** use `t('sales:partners.delete.failed', { entity: entityLabel })`
as the generic fallback, or delete the unused key from all three catalogs.

**m8 — The deleted partner's detail cache is not invalidated.**
`PartnerDetailPage.tsx:243-246` invalidates only the `partners` list predicate; the exact key
`tenantScopedKey(['partner', id])` stays warm. Back-navigation renders the deleted partner from
cache before the 404 lands. **Fix:** add
`queryClient.removeQueries({ queryKey: tenantScopedKey(['partner', id]) })` in `onSuccess`.

**m9 — The supplier branch of `navigate(basePath)` is untested.**
`partnerDelete.test.tsx:78-89` mounts only `/sales/customers/:id`. The clients-vs-fournisseurs
routing claim rests on reading `PartnerDetailPage.tsx:157-164`. **Fix:** parameterise the fixture
over `/purchases/suppliers/:id` as well.

---

## 5. Pre-existing-failure claims — spot-verified by the detached-base method

Detached worktree at `fe0df479e` with `node_modules` symlinked from the branch worktree:

```
npx vitest run src/features/inventory/__tests__/tenantScope.test.tsx \
               src/features/inventory/pages/StockByLocationPage.test.tsx
→ Test Files 2 failed (2) | Tests 3 failed | 6 passed (9)
   × inventory queryKey shapes > wraps stock page keys (.307, .308, .313)
   × inventory queryKey shapes > wraps platform and product subcomponent keys (.314-.318)
   × StockByLocationPage > renders the stock matrix page and product row
```

**Identical failures, identical names, on base. Claim RATIFIED.** (The `StockByLocationPage` failure
originates in `src/features/inventory/lib/rebalance.ts:11` via `RebalancingView.tsx:19` — untouched
by this diff.)

**PartnerForm IBAN flake:** `npx vitest run src/features/partners/partners.test.tsx` run **twice** —
`48 passed` both times. No flake observed on this machine; the claim is neither confirmed nor
contradicted, treat as unreproduced.

Additional pre-existing red found and base-verified: `arLocaleCoverage.test.ts` ×3 (see m6).

---

## 6. Batch hygiene

| Check | Result |
|---|---|
| One commit per bug | `85ba96ffb` BUG-006 · `2d559451f` BUG-007 · `74066542b` BUG-003 · `a8c2c9ff8` BUG-004 |
| Cherry-pickable / no cross-contamination | yes — per-commit `--stat` shows disjoint file sets; the only shared file family is `locales/*`, and each commit touches only its own namespace (`sales` / `inventory` / `import`) |
| Rule 18 tokens on touched `.tsx` | yes — new UI uses `Button` atom + `ConfirmDialog` (both token-internal); `audit:design-system` 0 new; lint 0 errors |
| No hardcoded user-facing strings | yes — every new string is a `t()` key present in en/fr(/ar); the only English literal is the server-side 409 `message`, which the FE replaces with a translated string |
| Nothing outside the four bugs | yes — no build artifacts, no baselines, no `tsbuildinfo`, no unrelated refactors |
| Design-system conventions | `PageHeaderTitle`, `DataTable`, `Button`, `ConfirmDialog`, `FilterTabs`, `SearchInput` all pre-existing/atom usage; no raw `<table>`, no raw form controls added |

---

## 7. Answers to the branch's four reviewer questions

**Q1 — `partnerType` in the key AND in `queryParams`: keep or drop one?**
**Keep both — they are not actually redundant.** `queryParams['type']` is the *wire* contract (the
backend filter at `PartnerController.php:53-65`); the key segment is the *cache-identity* contract.
Dropping the key segment re-derives identity from a mutable params bag, which is precisely the
class of coupling that produced BUG-006 (the retro's own root cause: "`queryParams` is byte-identical,
so the key is unchanged and TanStack serves the cached customer page"). Dropping the params one
breaks the request. The cost is one array element, already locked by `tenantScope.test.tsx:190-198`.
**Keep — and fix m1 so the segment is asserted positionally rather than by substring.**

**Q2 — Is the `tenantScope.test.tsx` lock edit OK?**
**Yes.** The diff is +5 / −0: one `'all',` element at index 1 plus a 4-line comment. The assertion
remains a whole-array `toContainEqual` with `'tenant-A','company-1'` as the trailing suffix — the
property the test exists to lock. Nothing was loosened, no matcher was widened, no case removed.
**Ratified.**

**Q3 — Page-local effect as a stopgap vs a `useTableState` re-seed ticket?**
**Accept the page-local effect for this batch — but size the ticket honestly as SMALL and file it now.**
Reasons to accept: the effect is correct, guarded, loop-free, and cannot fight a user control
(the page exposes no `type` filter); and touching `useTableState` mid-fix would be rule-4 scope creep.
But the branch's justification ("re-seeding `defaultFilters` inside the shared `useTableState` hook
is a separate, wider change" — `PartnerListPage.tsx:162-163`) overstates the blast radius: the hook
has exactly **three** consumers — `PartnerListPage.tsx:110`, `ProductListPage.tsx:141`,
`MenuListPage.tsx:23`. The real difficulty is semantic, not scale: naive re-seeding on
`defaultFilters` change would stomp a user-set filter on every render, so the fix needs a
prev-defaults ref and a "only if the default itself changed" comparison. Reword the comment to say
that, and file the ticket rather than leaving the reasoning only in a code comment.

**Q4 — `lib/formErrors.ts` placement as a new shared surface?**
**Ratified.** It has no React, no i18n and no feature imports; it takes `unknown` + an
`HTMLFormElement` and returns an element — the same shape as its neighbours `tenantScopedKey.ts`,
`decimal.ts`, `utils.ts`. Its test is co-located as `src/lib/formErrors.test.ts`, matching the
existing `src/lib/i18nRawKeyCoverage.test.tsx` convention. Two conditions: (a) it is currently a
"shared" module with exactly one consumer — the retro's N3 item ("blocked-submit visibility
contract… a house-wide UX contract, not a product-form test") is the follow-up that earns the
placement, so ticket the sweep across the other entity forms; (b) once there are ≥2 consumers,
promote the toast+focus *pairing* into a `useBlockedSubmitFeedback()` in `src/hooks/` so each form
doesn't re-derive the toast key and the `getElementById` dance (`ProductForm.tsx:257-265`).

---

## 8. Merge condition

Land **M1** (one-line ×3, either direction) before merge. **m1** and **m2** are strongly
recommended in the same pass — both are single-line and both close a "the test/comment claims more
than the code delivers" gap of exactly the kind this batch exists to punish. **m3–m9** may be
ticketed.

No commit, push or merge performed by this review.
