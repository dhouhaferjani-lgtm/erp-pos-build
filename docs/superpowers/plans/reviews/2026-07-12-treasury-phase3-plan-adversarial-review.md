# Treasury Phase ③ — Adversarial Plan Review (Rev 1 → Rev 2)

> **Date:** 2026-07-12 · **Plan reviewed:** `docs/superpowers/plans/2026-07-12-treasury-phase3-cash-visibility.md` Rev 1 (commit `abaacbdad`)
> **Lanes (owner tiering):** Lane 1 = treasury-reviewer on **Fable 5** (Wave A money path) · Lane 2 = treasury-reviewer on **Opus** (Waves B–E)
> **Verdicts:** Lane 1 CHANGES-REQUIRED (0 BLOCKER / 2 HIGH / 4 MEDIUM / 3 LOW) · Lane 2 CHANGES-REQUIRED (0 BLOCKER / 2 HIGH / 3 MEDIUM / 8 LOW)
> **Reconciliation → plan Rev 2** (same file, `## Plan-review reconciliation` section) + one transparent spec §10 amendment (race-test substitution, recorded in spec §15).

---

## LANE 1 — Wave A money path (treasury-reviewer, Fable 5)

Everything below was verified by reading the code, not the docs. Paths relative to `apps/api` unless noted.

### Verified-correct plan claims (ground truth for implementers — do not re-derive)

- **A1 stored values:** `journal_entries.status` is `string('status')->default('draft')` (`database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:19`); `JournalEntryStatus` backs `'draft'|'posted'|'reversed'` (`app/Modules/Accounting/Domain/Enums/JournalEntryStatus.php:9-11`). The plan's `WHERE ... status = 'posted'` predicate matches the real stored value. Enforcement point on replay is exactly the status-flip UPDATE inside `postEntryNow` (`GeneralLedgerService.php:2912-2913`), which runs inside `transfer()`'s savepoint (`TreasuryMovementService.php:241-253`) and is caught at `:298-304`. Reversal entries use distinct source_types (`*_reversal`, e.g. `GeneralLedgerService.php:3802`) — no index collision.
- **A2 field names** match the adjustment exemplar exactly: JE create keys (`GeneralLedgerService.php:906-916`), line keys (`:921-928`), guard shape (`:893-895`). `generateEntryNumber` is **private** at `:3859` but same class — callable; takes the same per-company advisory lock (`:3867-3869`). `JournalCode::fromSourceType('treasury_transfer')` falls through to `Misc`/OD via the `default` arm (`JournalCode.php:34-41`). `JournalLine` casts `debit`/`credit` `decimal:3` (`JournalLine.php:54-55`). `JournalEntry` casts `status` to the enum (`JournalEntry.php:79`).
- **A3 signatures:** `TransferIntent` constructor names/order match the plan's named-args call exactly (`TransferIntent.php:26-38`); note `occurredAt` is `?CarbonImmutable` — pass `null` (as planned), never `now()` (Carbon → TypeError). `TransferResult->outLeg/inLeg` (`TransferResult.php:17-18`); `MovementResult->movementId/balanceAfter/ordinal/wasIdempotentHit` (`MovementResult.php:19-22`). `RepositoryMovement` has `journal_entry_id`, `transfer_group_id`, `amount` (`RepositoryMovement.php:28,30,22`; casts `:51-53`). `RepositoryType` cases confirmed (`RepositoryType.php:9-12`); `PaymentRepository` fields confirmed; port `freeze()` at `TreasuryMovementService.php:357`; `RepositoryFrozenException(string $repositoryId, string $frozenReason)` (`RepositoryFrozenException.php:19-22`).
- **A3 flow soundness:** service outer `DB::transaction` + `transfer()`'s own (`:166`) nests as a savepoint on the same connection (second savepoint at `:241`); `RepositoryMovement::findOrFail` after `transfer()` returns sees the savepoint-released legs (same PDO, `DB::commit` at `:297`). §5.2.3d cleanup is inside the same outer transaction — correct. `$draft->delete()` cascades lines (`2025_11_30_100000...:49`); `JournalEntry` has no `deleting` guard or SoftDeletes; drafts carry no chain hash/sequence; `entry_number` is max()+1-derived → no permanent gap.
- **Race-shape mechanics:** with the A1 index, the second attempt's 23505 fires FIRST at `postEntryNow`'s status-flip UPDATE (`GeneralLedgerService.php:2912`), preceding the leg inserts (`TreasuryMovementService.php:248-259`) — inside the savepoint, caught. Without the index (sqlite), the out-leg `idempotency_key` unique fires instead — also caught (`isUniqueViolation` handles sqlite 23000 + "UNIQUE constraint failed", `:674-689`). `handleTransferIdempotentHit` excludes `journal_entry_id` from leg validation (`:582-583`, `:585-612`) — tolerates legs written by either path. Either shape resolves per spec.
- **A4 route shadowing:** none. Earlier POSTs under the group: `'/payment-repositories'` (1 segment, `routes.php:82`) and `'/payment-repositories/{repository}/adjustments'` (3 segments, `:92`); no 2-segment POST wildcard; GETs method-disjoint. Group middleware anchor `routes.php:31` byte-exact. Seeder anchors exact: `RolesAndPermissionsSeeder.php:224/:474/:694`; `PermissionSeeder.php:88-89` (and `treasury.adjust` is indeed absent there). Controller mirror accurate (`RepositoryAdjustmentController.php:41-43`).
- **A4 exception mapping:** `RepositoryFrozenException`, `CurrencyMismatchException`, `IdempotencyConflictException` ALL extend `DomainException` (`RepositoryFrozenException.php:17`, `CurrencyMismatchException.php:16`, `IdempotencyConflictException.php:21`); no earlier render closure shadows them; generic `DomainException` → `{error:{code:'BUSINESS_ERROR',message}}` 422 at `bootstrap/app.php:356-364`. **The plan's "catch and rethrow as DomainException" contingency is dead code — skip it.** No custom `ValidationException` render exists → FormRequest 422s use default Laravel `{message, errors:{field:[...]}}`.
- **Wave A completeness vs spec §5.1–§5.4:** `different:` rule ✓, notes max ✓, occurred_at always-now ✓, canonical-envelope test ✓, virtual exclusion ✓, sub-scale normalization ✓, both seeders ✓. Two spec-§10 gaps → F2, F6.

### Findings

**F1 [HIGH] Task A2 — the `requires_enclosing_transaction` test can never pass as written.**
`RefreshDatabase` wraps every test in a transaction — level starts at 1. Documented in-repo: `tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php:231-238`. The plan's test is still level 1 → guard never throws → `expectException` fails permanently; worst-case an implementer "fixes" it by weakening the guard. Fix — mirror `TreasuryMovementServiceRecordTest.php:226-246`:
```php
while (DB::transactionLevel() > 0) {
    DB::rollBack();
}
$this->assertSame(0, DB::transactionLevel());
try {
    $this->expectException(\LogicException::class);
    app(GeneralLedgerService::class)->createRepositoryTransferJournalEntry(/* args */);
} finally {
    DB::beginTransaction(); // restore wrapper for RefreshDatabase teardown
}
```

**F2 [HIGH] Task A4 — spec divergence on the REQUIRED concurrent-race test, justified by a false claim.**
Spec §10 Rev 2 requires a concurrent-race test; the plan substitutes the sequential race-shape test claiming "true two-connection concurrency is exercised by the port's own suite" — **cannot verify; appears false** (the port's replay test is sequential, `TreasuryMovementServiceTransferTest.php:414-430`; no two-connection test exists in that file). Engineering-wise the sequential shape exercises the identical 23505-inside-savepoint path (verified mechanics above), so the substitution may be acceptable — but it must be honest: implement a genuine two-connection test, or correct the rationale and log the deviation. Silence ships an unreviewed weakening of the L1-1 BLOCKER remediation.

**F3 [MEDIUM] Task A1 — the inline migration code contradicts the exemplar and silently removes index coverage from the default test suite.**
The procurement exemplar has **no driver guard** — it runs the raw partial `CREATE UNIQUE INDEX` unconditionally (`2026_06_26_120000_unique_journal_entries_source_procurement.php:42-49`); sqlite supports partial indexes; the default suite runs sqlite `:memory:` (`phpunit.xml:41-42`). With the plan's guard the index never exists on the fast loop, so A5's pins only bite in the `treasury-spine-pgsql` CI job. Fix: delete both driver guards; keep `IF NOT EXISTS` + the status-scoped predicate (non-negotiable per L1-1); optionally follow the exemplar's naming style.

**F4 [MEDIUM] Task A3 — both flagged imports are wrong as written; verified names:**
- `use App\Shared\Contracts\CurrencyScaleResolverInterface;` (`app/Shared/Contracts/CurrencyScaleResolverInterface.php:14`) — NOT `...Contracts\Currency\...`.
- `use App\Shared\Domain\CurrencyScale;` (`app/Shared/Domain/CurrencyScale.php:13`) — NOT `...Shared\Support\...`.
- `bcformatStrict(string $value, int $scale): string` confirmed (`CurrencyScale.php:130`). `getScale('EUR')` short-circuits to the static ISO map, never touches CompanyContext (`CurrencyScaleResolver.php:37-41`) — rule-19-safe; EUR → scale 2.
- `TreasuryMovementService` needs no import (same namespace), nor `MovementResult` in the DTO.

**F5 [MEDIUM] Task A5 — reconcile invocation doesn't match the cited pattern; risks a vacuously-green pin.**
Actual pattern: `Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id])` (`ReconcileTreasuryTest.php:228-231`) with `app(CompanyContext::class)->clear()` in setUp (`:79-81`, rule 20). Without `--tenant`, the command iterates the central tenant directory — if the fixture tenant isn't registered there, it reconciles zero repositories and exits 0: the pin passes without examining anything. Fix: clear CompanyContext (may be left bound by preceding HTTP requests), call with `--tenant`, keep the freeze/drift assertions (`audit_events` is the right table — `AuditEvent.php:36`).

**F6 [MEDIUM] Wave A — spec §10's "missing GL account 422 (cross-GL only)" test is in no task's test list.**
Add to A3: cross-GL pair with `gl_account_id = null` on one side → `DomainException` (422 class) + `JournalEntry::where('source_type','treasury_transfer')->count() === 0` (before-any-write proof).

**F7 [LOW] Task A3 — normalization-test assertion will trip on the model cast.** `RepositoryMovement` casts `amount` `decimal:3` (`RepositoryMovement.php:51`) → `$out->amount` reads `'10.000'` even for EUR scale-2. Assert `bccomp($out->amount, '10.00', 2) === 0` or pin raw storage via `DB::table('repository_movements')`.

**F8 [LOW] Task A4 — backend locales are en + fr only** (`lang/` has no `ar/`; `messages.treasury` at `lang/en/messages.php:65` + `lang/fr/messages.php:65`). Don't create a backend `ar` dir.

**F9 [LOW] Task A4/A5 — sqlite vs pgsql expectations.** The port's own replay tests skip on non-pgsql (`TreasuryMovementServiceTransferTest.php:414-417`); `isUniqueViolation` handles sqlite, so the endpoint replay tests will most likely pass on the fast loop — if they misbehave there, use the port suite's `markTestSkipped` pattern (pgsql coverage guaranteed by the `treasury-spine-pgsql` CI job), NEVER a change to the port or the index.

**VERDICT: CHANGES-REQUIRED** — F1, F2 (HIGH) · F3–F6 (MEDIUM) · F7–F9 (LOW). No BLOCKER — the planned money path itself is sound; every defect is test scaffolding, pasteable-code drift from verified conventions, or an undeclared spec deviation.

---

## LANE 2 — Waves B–E (treasury-reviewer, Opus)

Every §15 spec-reconciliation item (L2-1..L2-8, L3-1..L3-8) is represented in a concrete task step (mapping at the end). Findings are real gaps a task-isolated implementer would hit.

### Findings

**H1 [HIGH] · [D2] The repository `currency` the transfer modal depends on is not in the list payload or the FE type — no task adds it.**
Spec §5.5 requires currency-filtering the *to*-repo Select and driving `MoneyInput` (whose `currency` prop is **required** — `MoneyInput.tsx:29`) from the source repo. But the FE `PaymentRepository` interface has no `currency` field (`usePaymentRepositories.ts:7-15`) and the backend list resource does not emit one — `formatRepository()` returns `id/code/name/type/bank_name/account_number/iban/bic/balance/is_active/gl_account_id/gl_account`, **no `currency`** (`PaymentRepositoryController.php:262-278`). Fix: add `'currency' => $repository->currency` to `formatRepository()` and `currency: string` to the FE interface — one unlisted deliverable in D2, without which the modal is non-buildable.

**H2 [HIGH] · [B4] The maturity command records its audit event UNCONDITIONALLY — mirroring it for notifications spams every manager daily.**
`InstrumentMaturityAlertsCommand::alertCompany()` records per company per run even when both counts are 0 (`InstrumentMaturityAlertsCommand.php:129-137`, no count guard). Mirroring that cadence sends every `treasury.manage` holder a daily empty "maturity alert" — inbox spam that trains users to ignore the bell. Fix: gate the notification send on `received_due_count + deposited_overdue_count > 0`; test that no notification is sent when nothing is maturing.

**M1 [MEDIUM] · [B4] `freezeAndAlert()` has no `$tenant`/`$company` in scope.**
`private function freezeAndAlert(PaymentRepository $repository, string $reason)` (`ReconcileTreasuryCommand.php:786`) receives only those two; companies are fetched in a separate loop (`:189-192`). Fix: resolve recipients with `$this->alertRecipients->forCompany($repository->tenant_id, $repository->company_id)`; for `company_name` load `$repository->company` (relation exists — `PaymentRepository.php:156`) or pass `Company` as a new param. The plan's drop-in snippet as written will not compile.

**M2 [MEDIUM] · [B4] The failure helper is `logAlertFailure($repository, $reason, $channel, $e)`, not `alertFailed(...)` — and it is `PaymentRepository`-typed, so it cannot be reused at the portfolio-drift / maturity sites.**
Actual helper: `logAlertFailure(PaymentRepository $repository, string $reason, string $channel, Throwable $e)` (`ReconcileTreasuryCommand.php:832`). At the freeze site call it as `$this->logAlertFailure($repository, $reason, 'notification', $exception)`. Portfolio-drift (`alertPortfolioDrift(Company $company, …)` `:424`) and the maturity command have no repository — add a repository-less failure logger (or generalize), and in the maturity command mirror its own `Log::error('treasury.instrument.maturity_alert_failed', …)` pattern (`InstrumentMaturityAlertsCommand.php:58`).

**M3 [MEDIUM] · [C1] The `direction` filter must be applied before the row COUNT at `:92`, not merely "before pagination".**
`$base` built at `:91`, `$total = (clone $base)->count()` at `:92`, pagination applied inline at `:96-102`. Placing the filter after `:92` leaves `meta.total`/`last_page` counting the unfiltered set. Fix: insert immediately after `:91` so count, rows, and totals aggregate all see it.

**L1 [LOW] · [D3] i18n config path is `apps/web/src/lib/i18n.ts`** (not `src/i18n.ts`), and a new namespace is ~7 edits: 3 imports (en/fr/ar), 3 `resources` entries (the `ar` block uses spread-merge, e.g. `:306`), 1 `ns:` array entry (`:416`).

**L2 [LOW] · [D3] The stated reason for not using an invalidation prefix is backwards.** A raw leading prefix `['notifications', userId]` DOES prefix-match both scoped keys (trailing tenant/company suffix is irrelevant to a prefix match — same logic as the L2-3 movements prefix). Correct the rationale; either the prefix or the two exact keys work.

**L3 [LOW] · [C2] Scope nits:** the controller derives `$company` (`:55`) and uses `$company->currency` (`:68`) — no `$companyCurrency` variable; the scale resolver is ALREADY constructor-injected (`:49`). Inline `\DomainException` for `flows_window` validation maps to the canonical 422 (`bootstrap/app.php:356`) but diverges from `RepositoryMovementController`'s `$request->validate()` shape — acceptable (D5 hardcodes 7), note the divergence.

**L4 [LOW] · [C1] `GetCashMovementsRequest` needs a `direction()` accessor** (mirror `fromDate()/repositoryId()` at `:30-49`), a `?string $direction` param on `generate()`, and the controller arg threading (`ReportsController::cashMovements` `:225-246`).

**L5 [LOW] · [B1] Secondary index should lead with the morph type:** `['notifiable_type','notifiable_id','read_at']` (unreadNotifications filters all three; `uuidMorphs` already indexes `(notifiable_type, notifiable_id)`).

**L6 [LOW] · [B3] "Mirror DailyExpiryCheck EXACTLY" is slightly self-contradictory** — the exemplar does NOT call `forgetCachedPermissions()`; the plan correctly ADDS it (L3-3): phrase as "mirror the query shape, additionally flush the registrar." The spy fallback in test 2 is fragile (PermissionRegistrar is a container singleton the `->permission()` scope also resolves — a Mockery spy breaks the real query); all three registrar methods exist in vendor (`PermissionRegistrar.php:106/114/140`); prefer the real 2-tenant harness.

**L7 [LOW] · [D2] Component-layer mislabel:** `Modal` is an organism; `FormField` is an **atom** (`components/atoms/FormField/FormField.tsx`).

**L8 [LOW] · [D2] The repo list endpoint is tenant-scoped, not company-scoped** (`PaymentRepositoryController::index` filters only `tenant_id`, `:29-33`) — the modal could list another company's repos; picking one 404s at the service. Pre-existing; client-filter to the active company if the data allows, else accept the 404 path — note it.

### Verified accurate (no action)
- B2: `$request->user()` resolves tenant-DB `Identity\Domain\User` (Notifiable at `User.php:54`); `unreadNotifications()->count()` and `unreadNotifications->markAsRead()` are both real Laravel APIs; `read_at?->toIso8601String()` safe (datetime cast); the custom meta matches `RepositoryMovementController::index` (`:104-109`); `NotificationServiceProvider` genuinely absent from `bootstrap/providers.php`; Cart provider shape confirmed.
- B3: `companyMemberships()` + status filter real (`User.php:137,231-233`); `PaymentRepository->code`/`->company()` exist; team=tenant correct.
- C1: `generate()` signature + `$base` at `:91` confirmed; `scaleResolver` already injected (`:60-62`); the per-currency PG aggregate is correct.
- C2: `CASH_TYPES` are enum instances (`:41-45`); `repository_movements.amount` is `decimal(15,3)` → `SUM(m.amount)` exact, no CAST; `occurred_at` indexed; direction values `in`/`out` (enum + CHECK).
- D1: `PERMISSIONS['treasury.*'] = ['admin','treasury','accountant','manager']` (`:65-67`); `SERVER_AUTHORITATIVE_PERMISSIONS` at `:237`; copy that bundle for `treasury.transfer`.
- D2: all six scoped invalidation keys verified + the raw movements prefix rationale correct; `getErrorMessage` at `api.ts:61`; sonner is the treasury convention.
- D3: TopBar static button + hardcoded dot confirmed (`:154-161`); `useNavigate` available (`:2,24`).
- D4: route/nav insertion points, `ArrowLeftRight` import, Sidebar `finance` namespace, `OffsetPagination`/`DateRangeFilter` paths all confirmed.
- D5: `useCashPosition` uses `apiGet` and the endpoint returns a single `{data:{...}}` object with `flows` inside `data` — `apiGet` remains correct; both dashboard grids exist for insertion; `hasModule(name):boolean` at `CompanyConfigContext.tsx:32`.
- Type consistency across tasks holds (D2↔A4, D3↔B2, D4↔C1 incl. totals in meta, D5↔C2).
- Native `DomainException` → canonical 422 confirmed (`bootstrap/app.php:356-364`).

### §15 reconciliation coverage (Lane 2 scope)
L2-1→C1 ✓ · L2-2→D1 ✓ · L2-3→D2 ✓ · L2-4→C2 ✓ · L2-5→C1 ✓ · L2-6→D4 ✓ · L2-7→C2 ✓ · L2-8→D2 ✓ · L3-1→B3 ✓ · L3-2→B3 ✓ · L3-3→B3 ✓ · L3-4→B2 ✓ · L3-5→B2 ✓ · L3-6→D3 ✓ · L3-7→D3 ✓ · L3-8→D3 ✓. No reconciliation gaps.

**VERDICT: CHANGES-REQUIRED** — H1, H2 (HIGH) · M1–M3 (MEDIUM) · L1–L8 (LOW).
