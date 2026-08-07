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

## Cross-lane — `apps/web/src/lib/api.ts` residuals (owned by the media lane)

Recorded here because both round-2 gates flagged that this hand-off existed only verbally, and
"a verbal lane assignment with no written ticket is how this batch's originating bug survived for
days" (import gate R2-2). `api.ts` is owned by `fix/client-bugs-media-onboarding`; the partners lane
must not touch it. Nothing below blocks this branch.

### X1 — the 419 CSRF auto-retry is unreachable (assigned: media lane)
`apps/web/src/lib/api.ts:190-202` retries a 419 after refreshing the CSRF cookie, but the whole
interceptor block is gated on `isApiError(error)` (`api.ts:50-56`), which requires
`response.data.error !== undefined`. Laravel emits no render handler for `TokenMismatchException`,
so a 419 body is the framework default `{"message":"CSRF token mismatch."}` — no `error` key —
`isApiError` returns **false**, and the retry never runs.

The media lane's fix hoists the 419 handling **above** the `isApiError` gate. Two consequences for
the import wizard when that lands:
1. A transparently-retried 419 stops surfacing at all, so `wizard.upload.sessionExpired` will fire
   only for genuinely dead sessions. That is the desired end state; no wizard change needed.
2. See X2 — the same repair is the trigger for the sentinel coupling.

### X2 — `INTERCEPTOR_NETWORK_ERROR` string-couples to an `api.ts` literal, and breaks SILENTLY
`apps/web/src/features/import/pages/ImportWizardPage.tsx:55` holds
`const INTERCEPTOR_NETWORK_ERROR = 'network error'`, compared at `:100` against
`error.message.toLowerCase()`. The literal it mirrors is
`new Error('Network error')` at **`apps/web/src/lib/api.ts:168`** — a different file, a different
lane, with no import and no shared constant between them.

**The failure is silent by construction.** If that message is ever retitled (e.g. to
"Network request failed"), no test anywhere goes red: the wizard test at
`ImportWizardPage.uploadErrors.test.tsx` constructs its **own** `new Error('Network error')` rather
than driving the real interceptor, so it keeps passing while production quietly degrades network
failures back to `parseError` — BUG-004, partially reinstated. The degradation is to today's
behaviour rather than to something worse, which is the only reason this is not urgent.

**Ruling, from the FE gate (R2.6) — prefer DELETION over maintenance:**
> "If `isApiError` is ever repaired, prefer deleting the sentinel over keeping the coupling."

So the intended lifecycle is: media lane repairs `isApiError` into a plain axios guard → `api.ts:168`
becomes reachable *or* is removed outright → whoever lands that **deletes**
`INTERCEPTOR_NETWORK_ERROR` and its branch from `ImportWizardPage.tsx`, rather than exporting the
literal from `api.ts` to keep the two ends in sync. The branch exists purely to make the taxonomy
survive a refactor that has not happened yet; once it has happened, it is dead weight with a
silent-breakage mode.

Whoever edits `api.ts:168` or `isApiError`: grep for `INTERCEPTOR_NETWORK_ERROR` first.

### X3 — the 401 `sessionExpired` toast is usually destroyed before it is read (import gate R2-3)
`redirect` is `window.location.assign` (`api.ts:91-93`), called synchronously at `:111` before the
rejection propagates — a full document navigation that tears down the SPA and the sonner
`<Toaster>`. The wizard's `toast.error` then fires in a microtask against a dying document. The
classification is still right, and the message the operator needed ("sign in again") is delivered by
the redirect itself; and because `redirectedToLogin` is a module-level once-guard (`api.ts:88`,
`:109-111`), a **second** 401 in the same document does not redirect — there the toast is the only
feedback and the branch is load-bearing. Recorded so nobody later "fixes" the 401 branch believing
the toast is what the operator reads. 419 has no race at all (`handleUnauthorized` is 401-only).

### X4 — the "probably fine" regression guard is `en`-only (import gate R2-4)
`ImportWizardPage.uploadErrors.test.tsx` asserts the softened `serverError` copy against `en` only.
`fr` was updated correctly (verified: no "probablement correct" remains), but nothing stops a future
`fr` retranslation reintroducing the false assurance. **Fix:** one `it.each(['en','fr'])` with a
per-locale forbidden-phrase list.

---

## P0 live-verification fix (2026-08-06) — connection-capture defect + follow-ups

`DELETE /api/v1/partners/{id}` 500'd unconditionally in live verification:
`SQLSTATE[42P01] Undefined table: relation "documents" does not exist (Connection: central)`.
Root cause and fix are in `PartnerReferenceCounter` and `PartnerController::$db`
(commit on this branch, see PR description) — both constructor-injected
`Illuminate\Database\ConnectionInterface`, which Laravel resolves to a connection object at
`PartnerController`'s construction time. `PartnerController` is `make()`'d during
`Route::gatherMiddleware()` → `controllerMiddleware()` (every controller's `getMiddleware()`
method — inherited from the base `Illuminate\Routing\Controller`, present even with zero
`$this->middleware()` calls — forces the container to build the controller instance before the
route's own middleware pipeline runs), which is BEFORE `ResolveTenancy` swaps
`database.default` from `central` to the tenant DB. Fixed by switching both to
`Illuminate\Database\DatabaseManager` and resolving `->connection()` at query/transaction call
time (same pattern as `Treasury\Application\Services\InstrumentAccountResolver`).

### T11 — repo-wide audit: constructor-injected `ConnectionInterface`/`Connection` is a
tenancy-blind capture class, not just a Partner-module bug

Grep starting bound (constructor property promotion on either type, `apps/api/app` tree,
2026-08-06):

```
app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php
app/Modules/Fiscal/Application/Services/OutboxIngestor.php
app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php
app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php
app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php
app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php
app/Modules/POS/Application/Services/Nf525DataProvider.php
app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php
app/Modules/POS/Domain/Services/ReceiptHashService.php
app/Modules/Partner/Application/Services/RecordCustomerDepositService.php
```

10 hits (this grep does not distinguish "constructed as a controller dependency during
`gatherMiddleware()`" — the exposed case — from "constructed deep in a call chain after tenancy
is already resolved" — safe in practice, same shape on paper). Each needs the same question asked
that this ticket's fix answers for Partner: *is this class (transitively) a constructor dependency
of a controller reachable via a routed HTTP request, or is it only ever resolved after tenancy has
already flipped (e.g. inside a queued job's `handle()`, or a console command)?* The two highest-risk
hits are `OutboxIngestor` (constructor-injected into `FiscalEventIngestionController`, the exact
same controller-dependency shape as the bug just fixed here — **not yet confirmed safe or broken,
just not yet checked**) and `RecordCustomerDepositService` (same module as this fix, same
risk profile). R3 reviewer note (2026-08-06): the reconciling detail behind "deposits demonstrably
work live" is that `PartnerDepositController.php:25` is a standalone class that does NOT extend
`Illuminate\Routing\Controller`, so `Route::controllerMiddleware()`'s `method_exists(...,
'getMiddleware')` probe fails and the early pre-tenancy construction never fires for it —
whereas `FiscalEventIngestionController.php:44` DOES extend the base `Controller` and therefore
carries the exact trigger shape. The audit's discriminator is thus: flagged service reachable
from a controller that extends the base `Controller` class = real risk. The Fiscal/POS console commands are lower risk (commands don't run
`Route::gatherMiddleware()`) but should still be confirmed rather than assumed.

### T12 — test-methodology gap: `actingAs()` structurally cannot catch connection-capture defects

`DeletePartnerTest` (and the large majority of this suite's feature tests) authenticate via
`$this->actingAs($user, 'sanctum')`, which sets the authenticated user directly on the guard and
never sends a request through `ResolveTenancy`'s bearer-token branch — the only branch that
flips `database.default` in single-schema test mode
(`app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php::tenantFromBearer()`). With no
real bearer token, the connection is never swapped mid-request in the first process at all, so
whether a class captured its connection before or after tenancy resolution is not an observable
distinction under `actingAs()` — a suite that is 100% `actingAs()`-authenticated is structurally
blind to this entire defect class, no matter how much coverage it has. This is a gap in the test
*methodology*, not in any one test's assertions, and should feed whatever net-tightening /
regression-prevention list this program's retro maintains: routed feature-test coverage for any
tenancy-timing-sensitive path needs at least one real-bearer-token round-trip (`POST
/api/v1/auth/login` + `Authorization: Bearer …`) exercising the actual middleware pipeline, not
`actingAs()`. `PartnerReferenceCounterConnectionTimingTest` (this branch) demonstrates the
alternative for the unit layer — swap `database.default` directly between construction and call —
but that only covers classes reachable in isolation; the controller-construction-timing half of the
bug (verified live in this fix, §1 above) has no automated regression coverage today and would need
the real-bearer-token round-trip to get one.

### T13 — orphaned `media_assets` rows on attachment unlink (media-lane-adjacent)

Unlinking a media attachment leaves orphaned `READY`-status `media_assets` rows behind — 2 found
during this program's live verification work. Purge gap; adjacent to
`project_media_unification_strategy` but not investigated further here — out of this lane's scope.

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

---

## R2-S residuals — partner reference-guard extension (2026-08-08)

T1 above is **CLOSED** by lane `fix/r2s-partner-refguard`: 15 module-owned `PartnerReferenceSource`
implementations covering 24 tables / 27 columns, plus a live-schema sweep that fails on any
partner-shaped or partner-FK column with no recorded decision. What follows is what the two merge
gates raised and this lane deliberately did NOT do.

### T11 — non-leading indexes on 6 counted tables (treasury m-2, verified & widened)

The delete guard now issues **24 count queries** per `DELETE /api/v1/partners/{id}`. Six of the
counted columns are indexed only as a NON-LEADING member of a composite, so a bare
`WHERE <col> = ?` cannot seek on them and Postgres will generally sequential-scan:

| Table | Existing index | Counted column |
|---|---|---|
| `payments` | `(tenant_id, partner_id)` | `partner_id` |
| `payment_instruments` | `(tenant_id, partner_id)` | `partner_id` |
| `pos_deposit_receipts` | `(tenant_id, company_id, customer_id)` | `customer_id` |
| `pos_account_charge_receipts` | `(tenant_id, company_id, customer_id)` | `customer_id` |
| `pos_account_payment_receipts` | `(tenant_id, company_id, customer_id)` | `customer_id` |
| `loyalty_members` | `(tenant_id, customer_id)` | `customer_id` |

(The gate named 2 tables; the schema says 6. `vouchers.partner_id`, `journal_lines.partner_id` and
`loyalty_members (loyaltyable_type, loyaltyable_id)` are already leading-column indexed and are fine.)

Not a launch blocker — this is one admin-initiated request, not a hot path, and every one of these
tables is tenant-scoped so the scans are small on a single tenant's DB. **Fix:** either add
single-column indexes on the six, or scope the counts by `tenant_id`/`company_id` so the existing
composites are usable. Decide before a tenant with a large `payments` table lands; capture as a
deploy note either way.

### T12 — `payments` is counted status-blind (treasury m-3)

`TreasuryPartnerReferenceSource` counts every `payments` row for the partner regardless of status —
a cancelled/voided payment blocks the delete exactly as a settled one does. That is **defensible and
intentional** (the row still points at the partner; `payments` has no soft delete, so there is no
"already invisible" state to exclude), but it is an asymmetry worth stating: `documents` DOES exclude
soft-deleted rows, so two money tables behave differently under the same guard. Document it, or give
`payments` a status exclusion — do not leave it undecided.

### T13 — first-class partner archive / deactivation is the better end state (treasury recommendation)

The guard's honest outcome is that a partner with any trading history can never be deleted, only
blocked — and the 409 tells the operator to "deactivate it instead", which is already the real
answer. The strategic fix is a first-class archive/deactivate flow (hide from pickers, keep history
addressable) rather than continuing to widen a delete guard nobody can satisfy. **Do not widen the
guard further** as a substitute for this.

### T14 — `partner_price_lists` deviates from this ticket's line 21 (gate m-5)

Line 21 lists `partner_price_lists` among the uncovered tables. R2-S deliberately does NOT count it,
together with `partner_bank_accounts` and `party_contacts`: all three are `cascadeOnDelete`, i.e. the
schema declares them owned BY the partner, and they describe it rather than record anything it took
part in. Counting them would make any fully-configured partner permanently undeletable while
protecting nothing. Recorded in code as `PartnerReferenceCounter::EXCLUDED_PARTNER_COLUMNS`. Flagged
here because it is a conscious deviation from this ticket's own list, not an oversight.

### T15 — Workshop submodules are invisible to deptrac (gate m-6)

`deptrac.yaml`'s layer collectors are `app/Modules/[^/]+/(Domain|Application|Infrastructure|Presentation)/.*`.
The Workshop module nests one level deeper (`app/Modules/Workshop/WorkOrder/Application/...`,
`Workshop/Bundle/...`, `Workshop/Technician/...`), so **no Workshop file is in any layer** and none of
its hexagonal boundaries are enforced. Found while placing `WorkshopPartnerReferenceSource`. Fix the
glob (or add explicit Workshop layers) — expect it to surface pre-existing violations, so it needs a
baseline bump, which is why it is not folded into this lane.

### T16 — the guard's fixture matrix is SQLite-only by construction (gate m-4)

`DeletePartnerReferenceGuardTest` inserts minimal referencing rows with foreign keys DEFERRED
(`PRAGMA defer_foreign_keys`, since SQLite ignores `PRAGMA foreign_keys` inside `RefreshDatabase`'s
transaction). Those fixtures would be **rejected outright by Postgres**, whose FKs are checked at
statement time — so this test class cannot be part of any future PG-backed test lane without
building real parent graphs for all 24 tables. Note it before anyone tries to flip the suite to PG.

Related, still open from the original lane: `Fiscal/.../OutboxIngestor.php:644` still
constructor-injects `ConnectionInterface` (the BUG-007 defect class), and the deptrac ratchet already
fails on `dev` (98 baseline / 102 actual, `SharedContracts on ModuleDomain` +4) independently of this
work.

#### T11 index correction (round-2 gate m-2, 2026-08-08 — the "6 tables" count is itself an undercount)

Verified against migrations: EIGHT further counted columns lack a usable leading index —
`documents.partner_id` and `vehicles.partner_id` (only `(tenant_id, partner_id)` /
`(company_id, partner_id)` composites), `pos_customer_aliases.server_partner_id` (only
`(tenant_id, company_id, server_partner_id)`), and FIVE with no index at all:
`vouchers.issued_to_partner_id`, `workshop_work_order_lines.core_deposit_partner_id`,
`buyer_seller_mappings.buyer_partner_id`, `buyer_seller_mappings.seller_partner_id`,
`platform_supplier_mappings.partner_id`, `expense_recurrence_templates.partner_id`.
Note `vouchers` becomes unindexable AS A WHOLE because the OR's second arm
(`issued_to_partner_id`) is unindexed. The remediation already proposed above
(single-column indexes, or scope the counts by tenant/company) covers all of them —
only the count was wrong (2 → 6 → ~14 columns).
