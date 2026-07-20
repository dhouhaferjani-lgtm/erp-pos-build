Verified the decisive findings myself. Issuing the exit verdict.

---

REJECT

## 1. Findings

### BLOCKER

**B1 — `apps/web/src/locales/en/treasury.json:228-237` (+ `fr/treasury.json:228`, `ar/treasury.json` has no `events` block) — untranslated key renders on the primary new ⑤a surface.**
Independently confirmed. `InstrumentEventType` gained `case Issued = 'issued'` (`apps/api/app/Modules/Treasury/Domain/Enums/InstrumentEventType.php:10`), and `InstrumentDetailPage.tsx:278` renders `t(\`treasury:instruments.events.${event.event_type}\`)`. Grep across all three locale files returns **zero** `issued` keys; `ar/treasury.json` has no `events` block at all. `issued` is the *first* event on every outbound instrument, so every supplier/expense cheque detail page shows the literal string `instruments.events.issued`. Rule 11 violation. It is legible in two of the five screenshots submitted as exit evidence (`02-supplier-cheque-cleared.png`, `03-supplier-cheque-bounced.png`) — the evidence package contradicts the "live exit PASS" claim, and four prior gates missed it.
**Fix:** add `issued` to en/fr, backfill the whole `instruments.events` block into `ar`, add an audit asserting every `InstrumentEventType` member resolves in all three locales.

### MAJOR

**M1 — `OutboundInstrumentService.php:182,338,492,696` + `Expense/Application/Listeners/SyncExpenseOnInstrumentLifecycle.php:16,50-52` — expense reset runs after commit, in a non-queued listener that throws.**
Confirmed: all four lifecycle events dispatch via `DB::afterCommit(...)`; the listener is a plain `final readonly class` (no `ShouldQueue`, no retry) and throws `DomainException` when the instrument can't be resolved as outbound. Spec §4.3 requires the expense reset to be atomic with the lifecycle transaction ("Never 'reverse the JE now, fix the subledger later'"). A failing sync on `clear()` commits the GL entry *and* the cash-out movement, then 500s — expense permanently stuck `is_paid = false`, no retry, no reconcile sweep to catch it.
**Fix:** invoke the reset through a `Shared/Contracts` port inside the transaction, or make the listener `ShouldQueue` + idempotent + non-throwing and add a drift check to `treasury:reconcile`.

**M2 — `Expense/Application/Services/ExpenseService.php:622-742` (digest at `:697-706`) — rule-6 breach + triplicated digest algorithm.**
`ExpenseService` imports and drives Treasury internals directly (`PaymentInstrument`, `InstrumentEvent`, `InstrumentLifecycleService`, `InstrumentAccountResolver`, `OutboundRepositoryValidator`) and hand-rolls the issue action-key/semantic digest. The same `json_encode` block is duplicated at `PaymentController.php:1017-1027` and a third canonical form at `OutboundInstrumentService.php:716-732`. Treasury→Expense *is* event-only as required; Expense→Treasury is hard-coupled. Any future digest change in one copy silently turns replays in the others into conflicts — or lets a genuine replay through as a new post.
**Fix:** one Treasury-owned `issue()` behind a contract; both callers delegate.

**M3 — `app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php:72-101` — backfill blind to inactive accounts.**
Validates `type` and promotes `is_system`, never checks `is_active`. `InstrumentAccountResolver.php:25-30` filters `->where('is_active', true)`. A brownfield tenant with a deactivated `403`/`4035` gets `exit 0` and a clean report, then every supplier-cheque issue 500s on `MissingInstrumentAccountException`. Not covered by `PayableInstrumentAccountsTest.php`.
**Fix:** treat inactive as invalid → `$invalid++` + non-zero exit, or reactivate under `--force`.

**M4 — `apps/web/e2e/smoke/treasury-phase5a-outbound.smoke.ts:280-282` — vacuous assertion, false green.**
Confirmed by reading the file. `not.toContainText(/^\s*0(?:[.,]0+)?\s*TND\s*$/)` is evaluated against the region's *entire* `textContent` (`"Payables schedule47,600 TND0 Overdue · …"`). An `^…$`-anchored regex can never match that string, so the negated assertion passes **unconditionally — including when the payable total is genuinely zero**. This is the only business assertion in test 3, which degrades to "a region with the right aria-label exists."
**Fix:** scope to the total element and assert the exact expected amount derived from `supplierAmount`.

**M5 — `treasury-phase5a-outbound.smoke.ts:467` — assertion satisfied by a field label.**
`page.getByText(/Paid|Payé|مدفوع/).first()` matches the `"Paid From"` label in the Payment Details card, which renders regardless of paid state. `05-expense-paid-after-clear.png` corroborates: the only badge reads **"Posted"**. The business outcome *is* hard-asserted at the API layer (`:459-462`), so the feature is likely correct — but `REPORT.md:39`'s UI claim is unearned.
**Fix:** assert by role/testid; re-capture screenshot 05; correct `REPORT.md:39` if no paid badge exists.

**M6 — `apps/web/src/features/treasury/InstrumentDetailPage.tsx:248` — direction-blind labels.**
Confirmed: hardcoded `t('treasury:instruments.receivedDate')` and a "Current Location" panel, despite `direction` being on the model at `:65`. Every issued cheque shows "Received Date" — semantically wrong for an instrument the company issued and owes. Visible in screenshots 02/03/04. The list page was correctly split into receivable/payable schedules; the detail page breaks the distinction one click in.

### MINOR

- `OutboundInstrumentService.php:380-396` vs `:70-83` — replay of `clear` after a successful `represent` returns 422 conflict instead of the original result (digest action `'represent'` vs `'clear'` under the same key). Reachable from ⑤b tier-3 matching.
- `InstrumentActionConflictException.php:9` — extends `DomainException` → semantic-replay conflicts return 422 alongside ordinary validation failures; clients can't tell "retry unsafe" from "invalid transition". Map to 409.
- `OutboundInstrumentService.php:525-605` vs `PaymentController.php:900,1014` — opposite lock orders on documents/instrument. Latent, not live (no reachable cycle constructed). Pin the canonical order before ⑤b adds a third writer.
- `ExpenseService.php:669-674` — replacement cycle derived from a `LIKE` count on `idempotency_key`; correctness carried entirely by the metadata row lock. Store an explicit attempt counter.
- `EventServiceProvider.php:69-74` — no Expense listener for `InstrumentBounced`; a bounced expense cheque keeps `is_paid = true`. GL stays correct; reporting-truth gap. **Confirm intended product behavior.**
- `PaymentController.php:1027-1036` — replay lookup omits the `tenant_id`/`company_id` scope pair. Not a leak (instrument already re-fetched scoped + locked, UUID key), but the only unscoped lookup in the new surface.
- `treasury-phase5a-deploy-checklist.md:48-51` — verification steps have **no UI path in this branch**; zero `apps/web` callers of `clear-outbound`/`bounce-outbound`/`represent`/`cancel-outbound`. Mark them API-driven or state UI lands later.
- `treasury-phase5a-deploy-checklist.md:24` — unstated whether a per-tenant `FAILURE` aborts the remaining tenants under `tenants:run`.
- `smoke.ts:23-26` — all 7 tests silently skip in CI without `TREASURY_PHASE5A_API_BASE`. Valid as evidence, zero regression protection.
- `useExpenses.ts:249-254` — bare `['instruments']` prefix over-invalidates tenant-scoped keys. **Third consecutive round reported.**
- `generated.d.ts:2058` — `'checks_to_pay'`/`'effets_payable'` mixes English and French stems while the receivable pair is uniformly English. Faithfully regenerated (not a rule-7 breach), but cheapest to rename now, before tenant data depends on the string.

## 2. Invariant checklist

| Invariant | Status |
|---|---|
| 403/4035 seeder-owned, liability children, idempotent, fail-loud | ⚠️ Verified except inactive-account gap (M3) |
| Idempotency before GL; replay before transition validation; mismatch fails; replay returns original IDs | ✅ Verified (`OutboundInstrumentService.php:71-95,224-246,381-406,533-555`; DB unique + CHECK) — one wart |
| Numeric-string + bcmath at explicit scale; no ambient CompanyContext | ✅ Verified — **zero** float casts in the backend diff; `getScaleSafe($company->currency, 3)` in console |
| GL shapes: issue moves no cash, clear only debit, bounce compensates, representation next cycle, stable lock order | ✅ Verified (lock order MINOR-latent only) |
| Deferred supplier: legacy path suppressed, one JE, no bank line, no movement | ✅ Verified (`PaymentController.php:1006,1185`; `DeferredSupplierPaymentTest.php:94-226`) |
| Cancel reopens allocations/documents atomically; cleared rejected; replay safe | ⚠️ Partial — expense metadata is outside that transaction (M1) |
| Routes in Treasury group, company isolation, malformed UUID 404, admin/accountant yes / manager no | ✅ Verified (`routes.php:145-159`; `findInstrument:446-457` guards `Str::isUuid` pre-query; seeder `:224-225,482,706`) |
| Reconcile outbound portfolio check without freezing; alerts distinguish inbound/outbound | ✅ Verified (`ReconcileTreasuryCommand.php:198-220,362-368`) |
| Expense settlement event-only Treasury→Expense | ❌ Direction is event-only, but Expense→Treasury is hard-coupled (M2) and the reset isn't atomic (M1) |
| FE cash preserves non-paper; instrument = cheque/effet only; effect maturity required; cheque maturity cleared; bank fallback off; split schedules | ✅ All verified with real failing-without-fix tests |
| Deployment checklist: migration count/order, backfill, per-tenant reseed + cache reset, stop conditions | ✅ Verified (3 migrations match; `:26-27` per-tenant reseed + `permission:cache-reset` present) |

## 3. Test-evidence assessment

The **backend** suite is genuinely strong: `OutboundInstrumentConcurrencyTest.php:41-44` disables `RefreshDatabase` transaction wrapping on pgsql and forks a real second process over an independent PDO — real row locks, not simulation. Rollback is proven by real failure injection, not mocks. No `assertTrue(true)`, no mocking of the unit under test. Gaps: no clear-after-represent replay test, no inactive-account backfill test.

The **e2e** package is half-earned. The API-layer assertions are substantive and prove the ⑤a state machine end to end (issue leaves expense unpaid `:442`, clear moves the exact amount `:296-319`, bounce restores balance `:341`, re-presentation `:352-374`, cancel reopens `:499`). No soft assertions, no swallowed catches. But two of the UI assertions cannot detect the states `REPORT.md` claims they prove, and screenshot 05 shows "Posted", not paid.

**Not verified at all:** `pnpm lint`, `typecheck`, and the touched Vitest suites were denied by permissions in this round and in all four prior rounds. No reviewer has ever independently reproduced a test count on this branch. Given the two historical false-green incidents, someone with execution rights must run these before exit.

---

VERDICT: spec ❌ + quality CHANGES-REQUESTED

**Before ⑤a exit:** fix the missing `events.issued` key (en/fr + backfill `ar`); make the expense reset atomic with the lifecycle transaction (or queued/idempotent/non-throwing with a reconcile drift check); collapse the three digest copies into one Treasury-owned `issue()`; make the backfill fail loud on an inactive 403/4035; replace the two vacuous smoke assertions and re-capture screenshots 02/03/05; then have someone with execution permission actually run lint, typecheck, and the touched suites.
