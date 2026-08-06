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

---

# ROUND 2 — narrow re-verification of the fix round

**Range:** `git diff a8c2c9ff8..HEAD` — 6 commits, HEAD `5747708bb`. **Date:** 2026-08-06.
**Scope:** only the items this gate raised (M1, m1–m9, Q3) plus regression sanity. Not a re-review
of round 1.

## VERDICT: CLEAR TO MERGE (FE half)

All round-1 items are closed or honestly ticketed. Zero new findings at BLOCKER/MAJOR/MINOR.

---

## R2.1 Gates re-run

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web typecheck` | clean |
| `pnpm --filter @autoerp/web lint` | **0 errors** (6519 warnings, pre-existing class) |
| `audit:keys` | Gate C **0 new**, 0 stale |
| `audit:design-system` | **743 acknowledged, 0 new, 0 stale** — unchanged from round 1, so the new `Checkbox` + label markup introduced no violation |
| `audit:quantity` | 0 total |
| eslint-rules RuleTester | 3 rules pass |
| `npx eslint` on the 10 changed files | **0 errors** (47 warnings, all `no-unsafe-type-assertion` / `no-unnecessary-condition` on pre-existing lines) |
| `npx vitest run src/features/partners src/features/import` | **18 files / 140 tests passed** |
| `npx vitest run src/features/inventory src/lib` | 403 passed / **3 failed — the same three known-red** (`tenantScope` ×2, `StockByLocationPage` ×1) |
| `phpunit tests/Feature/Partner/` | **138 tests, 566 assertions, OK** (4 skipped) |

No baseline file appears in `git diff --stat a8c2c9ff8..HEAD`. Suppression grep over the round-2
diff: zero hits.

---

## R2.2 M1 — CLOSED via the preferred route, and it actually persists

The toggle is real end-to-end, not a decorative control. Verified each link in the chain:

- **Atom, not a raw control.** `PartnerForm.tsx:802` uses `<Checkbox id="is_active" {...register('is_active')} />`
  from `components/atoms/Checkbox/Checkbox.tsx`, whose docblock lists exactly this RHF pattern.
  `type="checkbox"` is hardcoded inside the atom and `type` is `Omit`ted from its props, so it cannot
  be overridden; `forwardRef` keeps `register`'s ref intact (props carry no `ref` under `forwardRef`).
  Design-system audit confirms 0 new violations.
- **Types + default.** `PartnerFormData.is_active: boolean` (`:109`), `Partner.is_active: boolean`
  (`:58`), `defaultValues.is_active: true` (`:232`) with the model default cited.
- **Seeded on edit.** `:341` `is_active: partner.is_active` inside the `reset()` payload. The read
  side always supplies it: `PartnerData.php:49` declares `public bool $is_active` (non-nullable) and
  `:97` fills it — so there is **no silent-deactivation risk** from an absent field.
- **Persists.** `onSubmit` spreads the whole form data; `apiPost('/partners', data)` (`:374`) and
  `apiPatch('/partners/'+id, data)` (`:386-387`). Backend: `CreatePartnerRequest.php:97` and
  `UpdatePartnerRequest.php:103` both `['sometimes','boolean']`; `Partner.php:134` has `is_active`
  in `$fillable`; `store` mass-assigns `...$validated`, `update` calls `$partnerModel->update($validated)`.
  **Confirmed: no backend change was needed.**
- **Three red→green cases** (`partnerIsActiveToggle.test.tsx`): create defaults to `is_active: true`
  in the POST body (`:122-139`); an inactive partner renders unchecked and reactivation round-trips
  through PATCH (`:141-169`); and **the exact deactivation path the blocked toast points at** —
  active partner → untick → `PATCH … { is_active: false }` (`:171-199`).
- **Role gating is correct, and it is the FORM's gate, not a separate one — which is right.**
  The edit route is `RequirePermission permission="contacts.update"` (`routes/index.tsx:548`);
  the backend PATCH is `can:partners.update` (`Partner/routes.php:47`). `contacts.update` =
  `['admin','manager']` is a strict **subset** of `partners.update` = `['admin','manager','operator']`
  (`hooks/permissionsMap.generated.ts:45,143`), so everyone who can open the form can also save it —
  no 403 trap. `UpdatePartnerRequest::authorize()` returns `true` and adds no per-field rule, so a
  separate FE gate on the toggle would have been *stricter than the backend*, i.e. wrong. Correct call.
- **ar spread hazard survives.** `isActive` / `isActiveHint` are scalars directly under `partners`,
  so the shallow `...arSales.partners` spread cannot drop an en fallback the way it would for a
  nested object — and both keys are present anyway (`ar/sales.json:96-97`). All five
  `partners.delete.*` keys still present in ar. Re-verified by parsing the JSON, not by eyeballing.

The `blocked` copy ("Deactivate it instead") is now **true**. M1 closed.

---

## R2.3 m1 — CLOSED

`partnerListRouteType.test.tsx:193-205` now asserts `key[0] === 'partners'`, `key[1] === 'customer'`,
`key.at(-2)/at(-1)` for tenant/company, and the substring check is **removed**. The comment records
why the old form locked nothing. Correct fix, and the only removal in the round-2 test diff besides
R2.6's replaced 413 case.

---

## R2.4 m2 — CLOSED, and their honesty check holds under mutation

The fix is `shouldFocusError: false` at `ProductForm.tsx:204` with the mechanism documented.
I did not take the new fixture on trust — I ran **two mutations** in a throwaway detached worktree at
`HEAD` (removed afterwards):

**Mutation A — neuter `focusFirstInvalidField` (early `return null`):**
```
× formErrors.rhf.test.tsx > stays authoritative when shouldFocusError is disabled   FAIL
✓ formErrors.rhf.test.tsx > is OVERRIDDEN by react-hook-form when shouldFocusError is left on
× ProductFormInvalidSubmit > all 3 cases                                            FAIL
```
So the fixture's "fix" branch is genuinely pinned to the helper — it is not passing because jsdom
happened to focus `beta`. And its "defect" branch correctly stays green, because it asserts RHF's
behaviour, which does not depend on our helper.

**Mutation B — flip `shouldFocusError` back to `true` in `ProductForm.tsx`:**
```
✓ ProductFormInvalidSubmit.test.tsx (3 tests)   — including the new multi-error case
✓ formErrors.rhf.test.tsx (2 tests)
```
**Their disclosure is exactly right.** The new `ProductForm` multi-error case *does* pass without the
fix, because `ProductForm` registers in DOM order today so RHF's pick coincides — and they label it
in-file as `NOTE ON STRENGTH: this case is a REGRESSION GUARD, not a red-first test`, pointing at
`formErrors.rhf.test.tsx` for the discriminating proof. That is the correct handling of a
non-discriminating test: keep it (it fails the day a field is added out of DOM order, on the real
form) and put the truth in the label. The fixture at `formErrors.rhf.test.tsx:32-55` forces the two
orders apart deliberately (registers `alpha` then `beta`, renders `beta` then `alpha`) and waits out
RHF's deferred `setTimeout(_focusError)` before asserting.

---

## R2.5 m4 / m7 / Q3 — CLOSED

- **m4:** `PartnerReferenceCounter.php:9-36` now opens with "Counts rows in THREE specific tables",
  names all thirteen uncovered tables, states the Otospex work-order / voucher consequence in the
  open, points at the ticket, and records the rule-6 constraint verbatim ("must not grow further
  without a `Shared/Contracts` reader owned by the module that owns the table"). The scope claim is
  now honest; the gap is ticketed as T1 with the constraint attached.
- **m7:** `PartnerDetailPage.tsx:262-266` — non-409 failures now `console.error` the raw error and
  toast `t('sales:partners.delete.failed', { entity })`. `getErrorMessage` import dropped. The
  previously-unused key is now wired; no raw English server string reaches the operator.
- **Q3:** `PartnerListPage.tsx:160-173` — the comment no longer claims a "wider change". It states
  the real reason (three consumers; the difficulty is semantic — `defaultFilters` is a fresh literal
  each render, so re-seeding needs a previous-defaults ref), documents the m3 page-reset side effect,
  and cites the ticket. This is the correction I asked for, applied verbatim.

---

## R2.6 Import commit `34a920e13` — strengthens BUG-004, does not disturb it

Adjacent-scope (same file, same bug) and strictly additive to the taxonomy I verified in §3:
401/419 → `sessionExpired`, 413 → `tooLarge` (correctly ordered *before* the generic
`status !== 422`), plus a defensive branch for the interceptor's bare `Error('Network error')`.
The 422 → `parseError` invariant and the `console.error` are untouched.

**The removed 413 test was replaced, not dropped:** `ImportWizardPage.uploadErrors.test.tsx:171`
asserts `tooLarge`, `:152` covers 401/419 via `it.each`, `:194` covers the sentinel, `:237` asserts
the copy no longer claims the file is fine, and `:214-235` is a catalog test asserting all five
taxonomy keys exist in **en and fr with distinct copy**. Locale re-parsed: en and fr `wizard.upload`
now both hold `{parseError, serverError, networkError, sessionExpired, tooLarge}`;
`ar/import.json` still has **no `wizard` key**, so ar inherits the whole subtree from en via
`i18n.ts:366`. Inheritance claim still true after the change.

*Observation, not a finding:* the `INTERCEPTOR_NETWORK_ERROR` branch string-matches an error message
against a literal in `api.ts` for a refactor that has not happened. It is documented, harmless, and
unreachable today. If `isApiError` is ever repaired, prefer deleting the sentinel over keeping the
string coupling.

---

## R2.7 Ticket `2026-08-06-l6-partners-followups.md` — T1–T10 complete and accurate

Every deferred item is present with the right file, the right diagnosis and the gate's constraint:
T1←m4, T2←m8, T3←m3, T4←Q3 (with the sizing corrected to three named consumers), T5←m5, T6←m9,
T9←Q4, plus the import gate's T7/T8. The known-red table records all three pre-existing failures
**including the `arLocaleCoverage` ×3 I flagged as undisclosed (m6)**, attributed as such.
Spot-checked for hallucination: the ticket says the inventory failures are the `locations` and
`product-movements` key shapes — the actual assertion diffs are
`expected [ 'locations', …(3) ]` and `expected [ 'product-movements', 'prod-1', …(6) ]`. Accurate.

**T10 (IBAN flake) — reasoning spot-checked and sound.** The reported failure
("Unable to find an accessible element with the role `option` and name /Amen Bank CFCTTNTT/i")
maps exactly to `PartnerForm.test.tsx:337-340`, a `waitFor` around an async-loaded bank option —
so "the banks query did not resolve inside the `waitFor` budget" is the right mechanism, and the
default 1 s budget under heavy multi-directory load is a plausible trigger. Their control experiment
is the correct one (revert `PartnerForm.tsx` to `a8c2c9ff8`; flake persists; the failing assertion is
on a dropdown the toggle does not touch). Consistent with my own observations: green in 2 solo runs
of `partners.test.tsx`, green in 2 full `src/features/partners` runs (119 and 140 tests). Not caused
by this branch. Their proposed fix (`findByRole` / longer timeout / seed the query) is right.

---

## R2.8 Weakening, scope creep, hygiene

- **Removals in the round-2 test diff:** exactly two — the substring key assertion (replaced by two
  stronger positional ones) and the 413 case (replaced by a more specific one). **Nothing weakened.**
- **Commit hygiene held:** one concern per commit, disjoint file sets — `2b54e3d94` M1,
  `cf021464c` m1, `1e2dc467a` m2, `e7066db35` m4/m7/Q3, `34a920e13` import F1–F3, `5747708bb` docs.
  Cherry-pickable.
- **No scope creep:** every source change traces to a gate item or the sibling import gate. No new
  feature surface beyond the `is_active` control the gate demanded.
- **Working-tree note:** `docs/superpowers/reviews/2026-08-06-l6-import-wizard-gate.md` shows as
  modified in the worktree — that is the sibling reviewer's file, not mine, and not part of this
  assessment. This Round-2 section is appended uncommitted.

## Remaining blockers: NONE (FE half)

T1–T10 are ticketed and none gates the merge. No commit, push or merge performed by this review.

---

## R3 — narrow single-commit review, `2fd5b0867` (2026-08-06 connection-timing fix)

Reviewer: tenancy-authz-reviewer (Sonnet, capped-model posture). Scope: exactly `2fd5b0867` on
`fix/client-bugs-partners-fe` (`b6678f025..HEAD` has no sibling commit). Not re-proving the live
204/409 behaviour — checking the code that produced it.

**1. `PartnerReferenceCounter` — all three query sites fixed, no cached property.**
`apps/api/app/Modules/Partner/Application/Services/PartnerReferenceCounter.php:75` now holds
`private readonly DatabaseManager $db` (was `ConnectionInterface`). All three counts —
`:90` `documents`, `:96` `payments`, `:100` `pos_receipts` — call `$this->db->connection()->table(...)`
inline inside `countFor()`; no connection is ever assigned to a property or resolved outside the
method body. Clean.

**2. `PartnerController` — both transaction sites fixed, no other capture site, no direct
instantiation.** Constructor at `PartnerController.php:47-54` now injects `DatabaseManager`.
`store()` (`:214`) and `update()` (`:294`) both call
`$this->db->connection()->transaction(...)`. `grep -n '\$this->db'` on the file returns exactly
those two lines — no other method uses `$this->db`. `destroy()` (`:339-378`) never touches `$db` at
all; it goes through `Partner::where(...)` (Eloquent's own connection resolver, unaffected by this
class of bug) and the now-fixed `PartnerReferenceCounter`. Repo-wide grep for
`new PartnerController` / `new PartnerReferenceCounter` across `app/` and `tests/`: zero hits —
container-only construction, so the constructor-signature change breaks nothing.

**3. Precedent (`Treasury\...\InstrumentAccountResolver`) verified to actually do what's claimed.**
`InstrumentAccountResolver.php:13,17,25` injects `DatabaseManager` and calls `$this->database->table(...)`
— no explicit `->connection()`. Confirmed in `vendor/laravel/framework/.../DatabaseManager.php:485-491`
that `DatabaseManager::__call()` forwards any undefined method (including `table()`) to
`$this->connection()->$method(...)`, and `connection()` (`:93-95`) re-derives the connection name via
`getDefaultConnection()` (`:382-384`, `config('database.default')`) on every invocation. So the
precedent resolves at call time exactly like the fix's explicit `->connection()->table(...)` form —
functionally identical, just via magic-method vs. explicit call. Precedent is real and sound.

**4. New test genuinely discriminates — verified empirically, not just reasoned.** Restored the
pre-fix `PartnerReferenceCounter.php` (via `git show 2fd5b0867~1:...`) into the worktree, ran
`PartnerReferenceCounterConnectionTimingTest` — `test_counter_resolves_connection_at_call_time_not_construction_time`
**FAILED** (`expected ['documents'=>1], got []`), `test_counter_never_leaks_a_reference_row_...`
still passed (expected — it only guards the opposite-direction regression). Restored the fixed file
(`git status` clean afterward, no residual diff). Ran again against the fix: both tests **PASS**.
The test is a real regression guard, not a tautology. Its documented reasoning for why `actingAs()`
can't catch this (`ResolveTenancy::tenantFromBearer()` is the only branch that swaps
`database.default` in single-schema test mode, and `actingAs()` never exercises it) was not
independently re-derived line-by-line here but is consistent with the rest of the diff and not
load-bearing for the verdict.

**5. Rule 13 + lint/static-analysis spot-checks.** No `app()` calls anywhere in the diff
(`git show 2fd5b0867 | grep 'app('` — zero hits); both fixed classes use constructor injection with
`private readonly`. `./vendor/bin/pint --test` on the three touched PHP files: `{"result":"pass"}`.
`./vendor/bin/phpstan analyse` (level per `phpstan.neon`) on the same three files: `[OK] No errors`.
Ran the pre-existing `DeletePartnerTest` (11 tests / 29 assertions) — all green, confirming the fix
doesn't regress the 409-blocking behaviour it sits next to.

**6. Ticket `2026-08-06-l6-partners-followups.md` T11 audit and nuance.**
Re-ran the grep bound: `grep -rlE 'private readonly (Connection|ConnectionInterface) \$' app` under
`apps/api` returns exactly the 10 files listed in T11 — accurate, not stale.
Independently traced *why* the two flagged risks differ, since the ticket doesn't spell this out:
- `OutboxIngestor` → `FiscalEventIngestionController` (`FiscalEventIngestionController.php:44,46-47`)
  **does** `extend Controller` and directly constructor-injects `OutboxIngestor` — the identical
  shape to the bug just fixed (base `Illuminate\Routing\Controller::getMiddleware()` at
  `vendor/.../Routing/Controller.php:40` is inherited, so `Route::controllerMiddleware()`
  (`vendor/.../Routing/Route.php:1130-1133`) forces early `container->make()` via `getController()`
  before `ResolveTenancy` runs). This one is a live, credible risk, correctly flagged.
- `RecordCustomerDepositService` → `PartnerDepositController` (`PartnerDepositController.php:25`)
  is a **standalone `final class` that does NOT extend `Illuminate\Routing\Controller`** and
  implements no `HasMiddleware`. Per `Route::controllerMiddleware()` (`Route.php:1125-1136`), neither
  the `HasMiddleware` static branch nor the `method_exists($controllerClass,'getMiddleware')` branch
  fires for such a class, so `getController()` is never called during `gatherMiddleware()` — the
  early-construction trigger this whole bug class depends on does not apply. That is *why* deposits
  demonstrably work live: the capture almost certainly happens after `ResolveTenancy` has already
  swapped to the tenant connection, on the normal late-dispatch path, not because it's immune to
  the general pattern.
  The ticket's own framing (`"not yet confirmed safe or broken, just not yet checked"`, applied to
  the pair as a whole) does satisfy the instruction not to read this as a confirmed defect — it is
  phrased as an open question, not an assertion of brokenness. **Minor gap, not a blocker:** the
  ticket would be stronger if T11 stated explicitly that `PartnerDepositController` doesn't extend
  `Controller`/implement `HasMiddleware` (the concrete reason the live evidence and the "same shape
  on paper" caveat can coexist), rather than leaving a future reader to re-derive that distinction.
  Recommend folding this one-paragraph nuance into T11 before anyone burns time re-investigating
  `RecordCustomerDepositService` from scratch.

### Findings
- **[Minor]** `docs/superpowers/tickets/2026-08-06-l6-partners-followups.md` T11 — the
  `RecordCustomerDepositService` risk note doesn't state the concrete reason (its controller,
  `PartnerDepositController.php:25`, doesn't extend `Illuminate\Routing\Controller`/implement
  `HasMiddleware`, so the early-construction trigger doesn't fire) that reconciles "same shape on
  paper" with "demonstrably works live." Suggest a one-line addition; does not block this commit.
- No Important or Critical findings on the reviewed commit itself.

### Verdict
**CLEAR.** The three verified query sites and the two verified transaction sites are the complete
set of pre-existing `$this->db`/`ConnectionInterface` capture points in these two files; the fix is
applied at every one of them, at call time, with no cached property anywhere. The cited Treasury
precedent does resolve at call time (confirmed against Laravel's `DatabaseManager` source, not
assumed). The regression test is real — empirically fails against the reverted pre-fix class and
passes against the fix — not a tautology. No `app()` use; Pint and PHPStan are clean on the touched
surface; the pre-existing `DeletePartnerTest` suite still passes. The T11 audit's 10-file grep bound
reproduces exactly, and its two flagged risk items are honestly framed as unconfirmed rather than
as confirmed defects (one minor tightening suggested above, non-blocking).

This is a narrow, mechanical, well-scoped fix with a genuine regression test; nothing here needs
escalation beyond Sonnet-level review.

**VERDICT: spec ✅ quality APPROVED**
