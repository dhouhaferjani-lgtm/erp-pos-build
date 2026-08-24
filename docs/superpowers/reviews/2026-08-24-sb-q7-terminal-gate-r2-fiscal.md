# Gate record — Session B lane Q-7 (terminal-claim hardening), fiscal/POS lens r2

Fix round `ff2bcfd81` + `f1d34ec99` on r1 target `d760748b0` (base `83448e0bd`),
worktree `.worktrees/sb-q7-terminal-claim`, branch `fix/sb-q7-terminal-claim-hardening`.
Diff under review: `git diff d760748b0..HEAD` — 9 files, +434/−36.
r1 records: `docs/superpowers/reviews/2026-08-24-sb-q7-terminal-gate-r1-fiscal.md`
(CHANGES-REQUESTED) and `…-r1-tenancy.md` (ACCEPT-with-conditions).
Fix-round brief: `docs/sessions/session-B-2026-08-23/BRIEF-Q7-fixround.md`.

**Verdict: spec ✅ / quality APPROVED (accept-with-conditions).**

r1's CRITICAL finding 1 is CLOSED, and I closed it by re-running the scenario end to end
against real PostgreSQL rather than by reading the diff: the refusal, the reason
requirement, the audit row that names the orphaned shift, the shift that stays OPEN, and
the four chain columns that do not move. Every r1/tenancy mandatory item lands. Nothing in
the fix round touches money, quantity, a queue, a projection, the SQLite time contract, or
an existing Event class. Three new Minor findings, none blocking. Two conditions remain and
both are merge-mechanics, not code.

## Conditions (must be discharged at merge)

- **F2-C1 (blocking at merge — supersedes r1 fiscal F-C2 and tenancy C-1).** Manifest union
  arithmetic, recomputed against dev as it stands TODAY (`75b180277`, not the
  `446393c86`-ish assumed in the dispatch). Correct post-merge values: `gated_ceiling`
  **1162**, POS `classes` **155**. See "Manifest union" — the dispatch's 1155/152 and r1's
  1154/152 are both STALE.
- **F2-C2 (blocking at merge — the last live half of r1 finding 1).** The runbook /
  owner-visible obligation for resolving an orphaned `pos_shifts` row. `release()` now makes
  the operator confront the orphan and records which shift it was, but **there is still no
  surface anywhere that can close it**, and I re-verified that from scratch this round (see
  Gate verified §3). Exact obligation text for the LEDGER is at the end of this record. The
  code half is done; this row is not a code change and must not be re-dispatched as one.

## Disposition of r1 fiscal findings

| r1 | disposition |
|---|---|
| **1 [CRITICAL]** open-shift release ⇒ silent projection hole | **CLOSED.** `TerminalController.php:522` now takes `ReleaseTerminalRequest`; the guard is `:540-561`; refusal is 409 `TERMINAL_HAS_OPEN_SHIFT` with `open_shift_id` at `:557`; `forced`/`openShiftId` reach the event at `:575-576` and the audit payload at `DomainEventSubscriber.php:896-897`. Docblock rewritten `:475-520` and now states the consequence honestly instead of asserting a remedy that does not exist. Independently probed on PG — Gate verified §1. |
| **3 [Important]** no fiscal-invariance pin on `release()` | **CLOSED.** `TerminalClaimHardeningTest.php:448-500` seeds `genesis_seed`/`current_sequence` 4242/`current_year`/`last_hash`, snapshots via `rawChainState()` (`:702-716`, raw query builder — no Eloquent cast can launder a difference), and asserts all four byte-identical after the release with per-column failure messages. Mutation-probed (Gate verified §2). |
| **4 [Important]** "JET/audit" docblock claim untrue | **CLOSED.** `TerminalReleased.php:13` now reads "so the AUDIT REGISTER reads consistently"; `:19-28` and `TerminalClaimed.php:20-27` both carry an explicit "AUDIT REGISTER ONLY — NOT the NF525 JET export" block citing `Nf525DataProvider.php:292-297`. I re-read that whitelist this round: `app/Modules/POS/Application/Services/Nf525DataProvider.php:293-297` still admits exactly `terminal.activated` / `terminal.deactivated` / `terminal.software_updated`. The docblock is now accurate. |
| **2 [Important]** nothing invalidates the released device | **CARRIED** (brief scoped it out — POS lane). See residuals. |
| **5 [Important]** `zChainState` `count()` vs MAX | **CARRIED.** Re-located after the fix round: `TerminalController.php:844` (`count()`) vs `:845`/`:827` (`orderByDesc('z_number')`). |
| **6 [Minor]** brownfield `findByDevice` "oldest wins" | **CARRIED.** Now `TerminalController.php:752-753`. |
| **7 [Minor]** no-op release indistinguishable | **CARRIED, not folded.** The brief made it optional; the implementer instead documented the ordering (`:529-534`). Merged with new finding N-3 below. |

## Disposition of the tenancy r1 conditions handed to this round

| item | disposition |
|---|---|
| **C-2 / tenancy 3** — FAILED leg is permanent-until-manual | **CLOSED.** Migration `2026_08_23_140000_harden_pos_terminals_identity_and_lifecycle.php:121-142` adds "BOTH FAILURE STATUSES ARE PERMANENT UNTIL A HUMAN ACTS", states `up()` completes either way so the bookkeeping row is written and neither leg re-runs, and gives the verbatim line `grep -E 'POS TERMINAL IDENTITY/LIFECYCLE HARDENING:.*status=(BLOCKED\|FAILED)' <log>`. The same grep is repeated on `run()` (`:346-352`) and on `blocked()` (`:380-388`) — i.e. at both producers, which is the right place for a deploy-time obligation. Swallow semantics unchanged, as instructed. The tenancy r1 note that this docblock "FAILED" to say so is now discharged. |
| **T-5** cross-company 404 | **CLOSED.** `TerminalClaimHardeningTest.php:502-540`: asserts `assertNotSame(403, …)` *then* 404, and that `HW-FOREIGN` survives. Mutation-probed (Gate verified §2). |
| **T-8** `assertStatus(500)` frozen as contract | **CLOSED.** `TerminalClaimHardeningTest.php:336` is now `assertGreaterThanOrEqual(500, …)` with the docblock at `:315-322` naming the unfixed `generateTerminalCode()` TOCTOU follow-up (`TerminalController.php:979-985`, still `count()+1`). |
| **T-6** `max:255` on a `varchar(100)` column | **CLOSED and over-delivered.** `ClaimTerminalRequest.php:42` and `RequestTerminalRequest.php:42` are `max:100` with the column citation; two NEW cases pin it (`TerminalClaimHardeningTest.php:352-388`) including that no terminal row is created on the `request` path. |
| **tenancy 2 / 4 / 7** | CARRIED as residuals (brief scoped them out). |

## New findings (r2)

1. **[Minor] `TerminalController.php:511-516` — two of the four line citations in the new
   status-code docblock are already stale.** It cites `toggleTrainingMode()` at `:770-777`;
   the actual guard is `:781-788` (code string at `:784`). It cites `DEVICE_ALREADY_BOUND`
   at `:940-947`; the actual response is `deviceAlreadyBoundResponse()` `:950-958` (code
   string at `:954`). The other two are fine: `archive()` `:193-200` contains the real `:197`,
   `claim()` `:387-393`/`:437-444` contain the real `:390`/`:441`. Why it matters: this
   docblock is the *only* record of a deliberate cross-endpoint status divergence, and a
   reader who follows a wrong line number lands in unrelated code and concludes the divergence
   was accidental. **Fix:** re-point to `:781-788` and `:950-958`, or drop the line numbers and
   name the methods only (they are stable; line numbers are not).
2. **[Minor] `TerminalController.php:540-561` — the open-shift guard is check-then-act with
   no lock or transaction, which is the exact shape this lane hardened out of `claim()`.**
   `claim()` settles by conditional `UPDATE … WHERE hardware_identifier IS NULL` (`:407-435`)
   precisely because an in-memory check can be overtaken. `release()` reads `$openShiftId`
   at `:543-547` and writes at `:565-567` with nothing in between holding the row. If a
   device's `SESSION_OPEN` projects into that window, the release proceeds as `forced=false`
   / `open_shift_id=null` — an orphan the audit register does NOT name, which is the one
   guarantee F-1 exists to give. Bounded and near-unreachable in practice (the premise of a
   release is a dead device, and an alive one is r1 finding 2's territory), and the fiscal
   chain is untouched either way. **Fix (follow-on, not this lane):** wrap the probe and the
   update in one transaction with `lockForUpdate()` on the terminal, or re-probe after the
   update and dispatch the event with whatever the second read found.
3. **[Minor] `TerminalController.php:483-486` — the new docblock over-claims that `store()`
   and `requestTerminal()` "provision EVERY terminal at v3"; a third path provisions v2.**
   `getOrCreateWebTerminal()` creates web terminals at `fiscal_schema_version => 2`
   (`TerminalController.php:718`, with a load-bearing comment at `:707-717` explaining why),
   and a v2 terminal's shift CAN be closed through `ShiftController` — the device-authority
   409 only fires at `>= 3`. It does not change the ruling: a web terminal has no device and
   so never carries a `hardware_identifier`, meaning `release()` on one lands in the no-op
   branch and never reaches the guard. But the docblock is the artefact a future reader will
   trust when deciding whether the orphan warning applies, and as written it would over-warn
   on the one terminal class that has a working remedy. Also fix the two cited line numbers:
   `store()` is `:137`, not `:136`; `requestTerminal()` is `:628`, not `:569`. **Fix:** say
   "every DEVICE-BOUND terminal (`store()` `:137`, `requestTerminal()` `:628`); the web path
   `:718` is v2 and carries no hardware binding, so it cannot reach this guard."
4. **[Minor] `TerminalController.php:529-536` — the no-op branch answers a bare 200 even when
   the terminal HAS an open shift, so an operator diagnosing exactly that state is told
   nothing.** Verified by probe (Gate verified §1, leg D): unbound terminal + OPEN shift ⇒
   200, no audit row, shift untouched. Fiscally correct — a no-op breaks no binding, so it
   orphans nothing — and the ordering is right (ruling A). But it is the same blind spot as
   carried finding 7, now with a concrete consequence: after a forced release the orphan is
   invisible on every subsequent call. **Fix:** fold into carried finding 7's residual — the
   no-op response should carry `released: false` and, when one exists, `open_shift_id`.

**Two things I checked and did NOT find a defect in** (recording them so r3 does not re-open
them):
- **The mandatory `reason` cannot be bypassed with a truthy non-boolean `force`.** I probed
  `force` as `1`, `"1"`, `true` and `"true"` — all four answer 422 with the binding intact.
  Reason: `ReleaseTerminalRequest.php:36` declares `force` with the `boolean` rule, so
  Laravel's `validateRequiredIf` takes the `shouldConvertToBoolean()` branch
  (`vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php:2425`,
  helper at `:2442`) and coerces the `required_if:force,true` operand to a real boolean,
  matching what `$request->boolean('force')` (`TerminalController.php:541`) reads. The
  implicit-rule comment at `ReleaseTerminalRequest.php:37-38` is accurate.
- **`open_shift_id` as a flat sibling of `code`/`message` inside `error` is the house idiom,
  not a divergence.** I enumerated the API: 35 sites put domain keys flat inside `error`
  (e.g. `ZReportSyncController.php:190` `expected`/`received`, `PaymentController.php:473`,
  `StockTransferController.php:376`, `InvoiceController.php:1121`); only
  `EnsureTenantIsActive.php:39,55` nests them under `details`. The lane follows the majority.

## Ruling A — the no-op branch is checked BEFORE the open-shift guard. Is that fiscally safe? YES.

**Safe, and it is the correct order.** Three reasons, each verified rather than argued:

1. **It cannot be used to evade the guard.** The branch is reached only when
   `hardware_identifier IS NULL` (`:535`). There is no binding to break, so no device is
   unbound, so nothing becomes orphaned by that call. Probe D: unbound terminal + OPEN shift
   ⇒ 200, zero `audit_events` rows, shift still `OPEN`, `hardware_identifier` still null.
2. **The reverse order would corrupt the audit register.** If the guard came first, a
   retried release (network hiccup, double-click, an admin repeating a colleague's action)
   against an already-released terminal with a still-open orphan would answer 409 — pushing
   the operator to re-send with `force=true` — and that second forced call would write a
   SECOND `terminal.released` audit row for a release that never happened, naming an orphan
   it did not create. An audit register that records releases which did not occur is strictly
   worse than one that is quiet, and this lane's own comment at `:531-533` already argues that
   for the audit-event suppression.
3. **The idempotency contract survives.** A retried force+reason release stays a clean 200
   no-op and does not duplicate the orphan record written by the first call — which matters
   because the audit row is the ONLY artefact anyone resolving the orphan can work from
   (F2-C2). Probe C wrote exactly one row; probe D wrote none.

The residual cost is finding 4 (the retry says nothing about the still-open orphan). That
is an observability gap, not a fiscal one.

## Ruling B — `TERMINAL_HAS_OPEN_SHIFT` is 422 on `archive()`/`toggleTrainingMode()` and 409 on `release()`. → **ACCEPT the divergence as documented. Do NOT require alignment in this lane.**

The divergence is real and I re-verified all three sites: `archive()` `TerminalController.php:194-201`
(422 at `:200`), `toggleTrainingMode()` `:781-788` (422 at `:787`), `release()` `:549-561`
(409 at `:560`). Accept, because:

- **The semantics genuinely differ, and 409 is the more accurate of the two.** On archive and
  toggle the open shift is an unconditional precondition with no override — the caller must go
  away and fix it. On release it is a *state conflict with a documented override in the same
  request*, which is what 409 means. Forcing release down to 422 to match would be aligning on
  the less correct code.
- **It matches this endpoint's own family.** `claim()` answers 409 for
  `TERMINAL_ALREADY_CLAIMED` at `:390` and `:441`, and `deviceAlreadyBoundResponse()` answers
  409 at `:954`. `release()` is claim's inverse; a 422 here would be the outlier on the
  binding surface even as it matched the archive surface.
- **No client is broken today, because no client exists.** Tenancy r1 finding 2 stands:
  `apps/web/src/features/pos/api/terminalApi.ts` exports `archiveTerminal` (`:91`),
  `deactivateTerminal` (`:112`), `toggleTrainingMode` (`:131`) and **no** `releaseTerminal`.
  There is nothing in-tree to align *with*.
- **The reasoning is written down at the point of divergence** (`:509-520`), which is the bar
  a deliberate inconsistency has to clear.

**Condition attached (rides on the tenancy-2 residual, not on this merge):** whoever builds
the FE release surface must branch on `error.code`, not on the HTTP status, and must handle
409 → surface `error.open_shift_id` → re-send with `force: true` + a typed `reason`. If that
lane instead adds a generic status-based handler, the divergence becomes a live defect. That
sentence belongs in the tenancy-2 LEDGER row.

## Gate verified

1. **r1 finding 1 re-run end to end on PostgreSQL 5433, throwaway DB `autoerp_gate_q7f2`
   (created and DROPped this session), with an INDEPENDENT probe class written outside the
   repo** (`scratchpad/Q7GateProbeTest.php` — the worktree was not modified). 6 probes, 36
   assertions, all green:
   - **A — refusal.** Terminal bound `HW-PROBE`, chain seeded `{current_sequence: 777,
     genesis_seed: '9'×64, last_hash: 'e'×64, current_year: 2026}`, one OPEN `pos_shifts` row.
     `POST …/release {reason}` ⇒ **409**, `error.code = TERMINAL_HAS_OPEN_SHIFT`,
     `error.open_shift_id` = the real shift id, `hardware_identifier` still `HW-PROBE`, the
     four chain columns byte-identical, **zero** `audit_events` rows, shift still `OPEN`.
   - **B — force without reason ⇒ 422**, binding intact, zero audit rows.
   - **B2 — truthy `force` variants** `1` / `"1"` / `true` / `"true"` ⇒ **422 in all four**,
     binding intact. No bypass of the mandatory reason.
   - **C — force + reason ⇒ 200.** `hardware_identifier` null; chain columns byte-identical
     across the write (`current_sequence` re-read as `777`); exactly **one** real
     `audit_events` row, `event_type = terminal.released`, `user_id` = the acting user, and
     its persisted payload verbatim:
     `{"forced":true,"reason":"PROBE: device destroyed, shift unclosable","released_by":"…","open_shift_id":"dc69f6c2-…","terminal_code":"POS94","hardware_identifier":"HW-PROBE"}`
     — so **the reason does reach the audit row**, not just the event object. Shift still
     `OPEN` afterwards: a forced release does not pretend to have closed it.
   - **D — no-op ordering** (ruling A): unbound terminal + OPEN shift ⇒ 200, zero audit rows,
     shift `OPEN`.
   - **E — the docblock's stated consequence is HONEST.** After force+release, a replacement
     device claims the terminal (200), and the exact predicate
     `ZSessionLifecycleProjection::projectPosShiftOpen()` evaluates at
     `ZSessionLifecycleProjection.php:147-153` — `Shift::where('terminal_id', …)
     ->where('status', ShiftStatus::Open)->exists()` — is still **true**, and the row it finds
     is the ORPHAN. So the replacement device's `SESSION_OPEN` hits the silent `return` at
     `:152`, its `SESSION_CLOSE` hits the retry-to-exhaustion throw at `:203-217`, and its
     Z report cannot land. The docblock at `TerminalController.php:493-505` describes reality.
2. **Mutation probes (2 tests, 8 assertions, PG) — the two new pins have teeth.**
   - F-3: the raw `chain()` read DOES detect a re-seed (mutate `genesis_seed`/`current_sequence`/
     `last_hash`/`current_year` directly ⇒ all four `assertNotSame` fire). Combined with the
     read of `release()`'s single write (`TerminalController.php:565-567` writes
     `hardware_identifier` and nothing else) and `Terminal.php` having no boot hook/observer
     on chain columns, the invariant is both true and guarded.
   - T-5: the 404 comes from company scoping, not from a dead route — the identically shaped
     call on an IN-company terminal answers 200 and clears its binding, while the
     other-company terminal answers 404 with `HW-FOREIGN-PROBE` intact.
3. **F2-C2 re-verified from scratch: no orphan-resolution surface exists anywhere.** Not just
   the three r1 endpoints — I traced every writer of `ShiftStatus::Closed` in `app/`:
   `ZSessionLifecycleProjection.php:251` (device-authored `SESSION_CLOSE` only) and
   `ShiftManagementService.php:163,178`. The service's only non-test callers are
   `ShiftController.php:39` and `SyncController.php:28`, and both hard-409
   `SHIFT_DEVICE_AUTHORITY_REQUIRED` for `fiscal_schema_version >= 3`
   (`ShiftController.php:60-70` and `:124-134`, `SyncController.php:53-63`) — which is every
   terminal this controller provisions (`TerminalController.php:137`, `:628`).
   `grep -rln "pos_shifts\|Shift::" app/Console` ⇒ **no artisan command either**. The orphan
   can only be resolved by direct SQL. Hence F2-C2.
4. **Rule 8 / immutability: clean.** `TerminalReleased` gains two constructor properties
   (`TerminalReleased.php:42-43`) — both **appended with defaults**, on an event class that is
   NEW in this unmerged lane and therefore has no historical payloads and no in-flight
   serialized jobs. No existing Event class is renamed, restructured or deleted, and no
   parallel refund/void event type is invented (this lane touches no receipt surface).
5. **Rule 19 / rule 20 scan of the fix-round diff: clean.** `git diff d760748b0..HEAD` matches
   none of `onQueue|toISOString|parseFloat|(float)|Number(|getScale()`. No queued job, no
   projection, no horizon entry needed, no money or quantity column read or written, no
   `apps/pos` or `apps/web` file touched — so neither the SQLite TEXT-timestamp contract nor
   the device-authored-shift-field merge contract is in play.
6. **Scope discipline: clean, and the brief's prohibitions hold.** `git diff --stat
   d760748b0..HEAD` = 9 files: `DomainEventSubscriber.php`, `TerminalClaimed.php`,
   `TerminalReleased.php`, `TerminalController.php`, `ClaimTerminalRequest.php`,
   **new** `ReleaseTerminalRequest.php`, `RequestTerminalRequest.php`, the tenant migration,
   `TerminalClaimHardeningTest.php`. **No** `apps/api/tests/feature-lane-manifest.json`,
   **no** `.github/**`, **no** `apps/pos/**`, **no** `apps/web/**`. **No new test CLASS** —
   the five new cases are added to the existing `TerminalClaimHardeningTest`, so the manifest
   does not move on the branch side (still 1147 / POS 151). B-3's `LOCATION_POS_DISABLED`
   ordering pin at `TerminalClaimHardeningTest.php:258-274` is untouched and green.
7. **Executed BY PATH on PostgreSQL 5433 (`autoerp_gate_q7f2`) and on sqlite. Never the full
   suite.**

   | file | PG | sqlite |
   |---|---|---|
   | `tests/Feature/POS/TerminalClaimHardeningTest.php` | **19 passed (76 assertions)** | **19 passed (76)** |
   | `tests/Feature/POS/Migrations/PosTerminalsIdentityLifecycleConstraintsTest.php` | 15 passed (42) | 15 run: 6 passed (13) / **9 skipped** (`ALTER TABLE … ADD CONSTRAINT` is pgsql-only — correct, each skip carries a reason) |
   | `tests/Feature/POS/TerminalLocationPosEnabledTest.php` (B-3) | 12 passed (29) | 12 passed (29) |
   | `tests/Feature/POS/TerminalDeviceLookupTest.php` | 4 passed (7) | 4 passed (7) |
   | `tests/Feature/POS/TerminalLifecycleEventsTest.php` | 7 passed (37) | 7 passed (37) |
   | `tests/Feature/POS/TerminalActivationTest.php` | 3 passed (9) | 3 passed (9) |
   | `tests/Feature/POS/TerminalResourcePolicyTest.php` | 5 passed (20) | 5 passed (20) |
   | `tests/Feature/Fiscal/TerminalRegistrySnapshotTest.php` | 20 passed (77) | — |
   | `tests/Feature/Fiscal/Nf525VerifyChainParityTest.php` | 3 passed (15) | — |
   | `tests/Feature/Fiscal/DeviceLossIncidentTest.php` | 13 passed (39) | — |
   | `tests/Feature/Fiscal/ReceiptChainRebuildTest.php` | 22 passed / 3 skipped (149) | — |
   | `tests/Feature/POS/ZReportImmutabilityTest.php` | 17 passed (36) | — |
   | `tests/Feature/POS/VirtualAdminTerminalResolverTest.php` | 3 passed (8) | — |
   | `tests/Feature/POS/TerminalCreationFiscalSchemaVersionTest.php` | 6 passed (15) | — |
   | *(gate-only, outside the repo)* `scratchpad/Q7GateProbeTest.php` | 6 passed (36) | — |
   | *(gate-only, outside the repo)* `scratchpad/Q7GateProbe2Test.php` | 2 passed (8) | — |

   `TerminalClaimHardeningTest` grew 14→19 cases and 46→76 assertions across the fix round.
8. **PHPStan level 8 on all 9 changed files (8 backend + the test): `[OK] No errors`.**
   **Pint `--test` on the same 9: `{"result":"pass"}`.**
   **`php tools/feature-lane-manifest-check.php` from `apps/api/`: EXIT=0** — "1381 Feature
   classes in 74 groups; every group has a disposition; every declared lane is present in
   ci.yml; every --filter entry is anchored and uniquely matched against 1770 test classes",
   1147 gated under a 1147 ceiling.

## Manifest union (supersedes r1 fiscal F-C2, tenancy C-1, and the dispatch's 1155/152)

Measured by reading both manifests, not inferred. Merge-base is `83448e0bd` for both sides.

| | `gated_ceiling` | POS `classes` |
|---|---|---|
| merge-base `83448e0bd` | 1145 | 149 |
| branch `f1d34ec99` | 1147 | 151 |
| **dev today `75b180277`** | **1160** | **153** |
| **post-merge union** | **1162** | **155** |

Branch delta vs base is **POS only, +2** (every other group identical). Dev delta vs base is
+15 across eight groups: `Coupon 2→3`, `Document 77→79`, `Fiscal 79→80`, `Inventory 108→111`,
`Loyalty 17→18`, `POS 149→153`, `Product 55→57`, `Taxation 31→32`. So the union is dev's
manifest **verbatim** with POS raised 153→155 and `gated_ceiling` raised 1160→1162, and the
POS `note` unioned (dev's N-1/N-5/b6ii raise text + this lane's Q-7 raise text naming
`TerminalClaimHardeningTest` + `PosTerminalsIdentityLifecycleConstraintsTest`). Then re-run
`php tools/feature-lane-manifest-check.php` **from `apps/api/`** (the checker lives at
`apps/api/tools/`, not repo root). Taking either side's number unchanged leaves 1162 gated
classes under a lower ceiling and trips the `$gatedClasses > $gatedCeiling` check.

Note for the parent: dev has moved twice since r1 (1152 → 1160). Re-read
`git show dev:apps/api/tests/feature-lane-manifest.json` at the moment of the merge; do not
copy 1162 forward if dev advances again first — the invariant is `dev + 2` / `POS dev + 2`.

## LEDGER — residual register carried out of Q-7

| # | severity | anchor (file:line) | what |
|---|---|---|---|
| Q7-R1 | Important | `apps/pos/src/stores/terminalStore.ts:611-680` (cached-terminal branch; `getDeviceId()` at `:710`, by-device fallback at `:712`) | **Device-side binding invalidation is missing.** `initialize()`'s cached branch refreshes the terminal from `GET /pos/terminals/{id}` and never compares the returned `hardware_identifier` against its own device id, so a released-but-alive device keeps authoring into a chain it no longer owns. POS lane. `release()` is what makes the state reachable. |
| Q7-R2 | Important | `TerminalController.php:844` (`count()`) vs `:845`/`:827` (`orderByDesc('z_number')`) | **`zChainState.z_hash_sequence` is a `count()` while `z_number` is a MAX.** They diverge the moment any Z row is absent from `pos_z_reports` — including the population a forced release creates. Consumed by `apps/pos/src/lib/sync/syncService.ts:1552` → `apps/pos/src/lib/offline/zReportService.ts:441` → sealed into the Z_REPORT payload as `legacyReportReference.hash_sequence` (`zReportService.ts:723`), i.e. immutable (rule 8). The Z hash itself is unaffected (`computeZReportHash()` `zReportService.ts:444-450` excludes `hash_sequence`). **Fix:** derive it from the latest row, like `z_number`. Q-7 changes reachability, not severity: `release()` promotes server-bootstrap of a terminal's Z chain from disaster recovery to a routine operation. |
| Q7-R3 | Minor (ruling recorded) | `TerminalController.php:752-753` | **`findByDevice()` is deterministic but arbitrary on the brownfield population.** `orderBy('created_at')->orderBy('id')` is spec-conformant; on the duplicate-binding tenants that BLOCKED the unique index, "oldest wins" is an assumption, and a device adopting the wrong row adopts the wrong NF525 chain. Fail-closed alternative: refuse when `count() > 1`. No change requested. |
| Q7-R4 | Minor | `TerminalController.php:529-536` (+ new finding 4) | **A no-op release is indistinguishable from a real one, and hides an existing orphan.** Should carry `released: false` and, when present, `open_shift_id`. |
| Q7-R5 | Important | `apps/web/src/features/pos/api/terminalApi.ts:91,112,131` (no `releaseTerminal`) | **No in-product release surface.** The remedy exists in the API and nowhere an operator can click. **Ruling B rider:** that lane must branch on `error.code`, not HTTP status, and must handle 409 → show `error.open_shift_id` → re-send with `force: true` + typed `reason`. |
| Q7-R6 | Minor | `apps/api/app/Modules/POS/routes.php:63-77` | **Non-UUID `{id}` ⇒ 500 on PostgreSQL**, controller-wide (not a `release()` regression). Fix the whole route group with `->whereUuid('id')`; do not fix `release()` alone. |
| Q7-R7 | Minor (ruling recorded) | migration `…140000_harden_pos_terminals_identity_and_lifecycle.php:264-266` | **Uniqueness grain is effectively PER-COMPANY.** One physical device MAY hold a terminal in two companies of the same tenant, and may therefore author into two distinct NF525 chains under two legal entities. Deliberate; the only grain compatible with `TerminalDeviceLookupTest.php:79-95`. No change requested. |
| Q7-R8 | Owner question | `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:293-297`; `Nf525XmlBuilder::addTerminalEvents()`; `Nf525EventType` | **Is a device re-binding a REPORTABLE NF525 terminal event?** `terminal.claimed` / `terminal.released` land in `audit_events` and are absent from the JET, matching the existing treatment of `terminal.training_mode_changed`. Adding a JET event type alters a certified export — a certification decision, not a code change. |
| Q7-R9 | Minor | `TerminalController.php:979-985` | **`generateTerminalCode()` TOCTOU** — `count()+1` under no lock; a concurrent create yields a `pos_terminals_unique_code` violation surfacing as 5xx. Pinned loosely (`>= 500`) at `TerminalClaimHardeningTest.php:336` so the eventual fix does not go red here. |
| Q7-R10 | Minor (new, r2) | `TerminalController.php:511-516` | Two stale line citations in the status-code docblock (`:770-777` → `:781-788`; `:940-947` → `:950-958`). |
| Q7-R11 | Minor (new, r2) | `TerminalController.php:540-561` | Open-shift guard is check-then-act with no lock; a shift projecting into the read→write window yields an orphan the audit row does not name (`forced=false`, `open_shift_id=null`). |

| Q7-R12 | Minor (new, r2) | `TerminalController.php:483-486` vs `:718` | Docblock over-claims "EVERY terminal at v3"; `getOrCreateWebTerminal()` provisions v2 (which has a working shift-close remedy). Cited line numbers `:136`/`:569` are also stale (`:137`/`:628`). Harmless over-warning, but it is the artefact future readers trust. |

## F2-C2 — obligation text for the LEDGER (verbatim)

> **Q7-OWES-1 (deploy runbook + owner row, non-waivable before the first forced release in
> production).** `POST /api/v1/pos/terminals/{id}/release` with `force=true` deliberately
> ORPHANS an OPEN `pos_shifts` row, and **no server surface, artisan command or endpoint
> exists that can close it** — verified 2026-08-24: every `ShiftStatus::Closed` writer is
> `ZSessionLifecycleProjection.php:251` (device-authored `SESSION_CLOSE` only) or
> `ShiftManagementService.php:163,178`, whose only callers `ShiftController.php` and
> `SyncController.php` hard-409 `SHIFT_DEVICE_AUTHORITY_REQUIRED` for every
> `fiscal_schema_version >= 3` terminal, which is every terminal the POS provisions
> (`TerminalController.php:137`, `:628`); `grep -rln "pos_shifts\|Shift::" apps/api/app/Console`
> returns nothing. Until the orphan is resolved, the REPLACEMENT device's shifts and Z reports
> do not exist server-side: its `SESSION_OPEN` is silently dropped at
> `ZSessionLifecycleProjection.php:147-153`, its `SESSION_CLOSE` retries to exhaustion at
> `:203-217`, and its Z report cannot land because `pos_z_reports.shift_id` is FK-RESTRICTed.
> The fiscal chain in `fiscal_events` stays intact; the projections do not.
> **What is owed:** (a) a runbook step that, after any forced release, finds the orphan via
> the audit register —
> `SELECT payload->>'open_shift_id', payload->>'reason', occurred_at, user_id FROM audit_events
> WHERE event_type = 'terminal.released' AND (payload->>'forced')::boolean IS TRUE ORDER BY
> occurred_at DESC;` — and closes that `pos_shifts` row by hand (status, `closed_at`,
> `closed_by`, and a variance/expected-cash pair that satisfies the `pos_shifts_variance_calc`
> CHECK on PostgreSQL), with a named accountable role; and (b) an owner ruling on whether a
> first-class orphan-resolution surface (the D-1 stack / `device_loss_incidents`, currently a
> schema-only register with no production writer and no endpoint) is required before the first
> tenant can be told the release endpoint is safe to use unattended. A code comment is not a
> ruling and the audit row is not a remedy.

## What to fix before merge

Nothing in code. Set the manifest to `gated_ceiling` **1162** / POS `classes` **155** against
dev `75b180277` (re-read dev at merge time; the invariant is dev+2), and land **Q7-OWES-1**
as an owner-visible LEDGER row with the runbook step — the endpoint is otherwise a remedy
that hands back a terminal the replacement device cannot use. Q7-R1…R11 are residuals.
