# Gate record — Session B lane Q-7 (terminal-claim hardening), fiscal/POS lens r1

Commit `d760748b0` on base `83448e0bd`, worktree `.worktrees/sb-q7-terminal-claim`,
branch `fix/sb-q7-terminal-claim-hardening`. Dual gate; this is the FISCAL/POS half.
Tenancy/authz half: `docs/superpowers/reviews/2026-08-24-sb-q7-terminal-gate-r1-tenancy.md`
(ACCEPT-with-conditions) — its verified items are not re-litigated here.

**Verdict: spec ✅ / quality ❌ CHANGES-REQUESTED.**

The three things the brief asked for on the fiscal identity are correct and I verified
them under real lock contention on PostgreSQL: exactly one device can own a chain at any
instant, one device cannot hold two chains in a company, and `release()` preserves
`genesis_seed` / `current_sequence` / `last_hash` byte-for-byte so a re-homed till
continues the chain instead of restarting it. The migration's three legs are correct on
live schema and the fleet census is clean.

What blocks r1 is downstream of `release()`, not inside it. The lane's own docblock
justifies not blocking an open shift with "closing out the orphaned shift is the shift
surface's job" (`TerminalController.php:474-480`). That premise is **false for every v3
terminal**, which is every terminal this controller provisions (`:136`, `:569`), and the
consequence is a silent projection hole for the replacement till — finding 1. That must be
closed or explicitly owner-ruled before merge.

## Conditions (all must be discharged before/at merge)

- **F-C1 (blocking).** Resolve finding 1 — either default-refuse `release()` on an OPEN
  shift with a `force` + `reason` override, or land an owner ruling row that accepts the
  orphaned-shift consequence with the deploy runbook step that resolves it manually. A
  code comment is not a ruling.
- **F-C2 (blocking at merge, supersedes the tenancy record's C-1).** Manifest union
  arithmetic, recomputed against today's dev. See "Manifest union" below: `gated_ceiling`
  **1154**, POS `classes` **152**. The tenancy record's 1149 / POS 151 is STALE.
- **F-C3 (ledger rows, non-blocking).** Record findings 2, 3, 4, 5, 6 as residuals and the
  two handed-over rulings (§Rulings) as owner-visible rows.

## Findings

1. **[CRITICAL] `TerminalController.php:474-480` + `:482-523` — releasing a terminal that
   has an OPEN shift hands the replacement device a terminal whose shifts and Z reports
   silently stop projecting, and the docblock's stated remedy does not exist.**
   The lane's justification is that the orphaned shift is "the shift surface's job". I
   checked every shift-close surface:
   - `ShiftController::close()` returns a hard 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED` when
     `fiscal_schema_version >= 3` (`ShiftController.php:123-135`, code at `:127`).
   - `ShiftController::open()` likewise (`:60-71`, code at `:63`).
   - `SyncController::syncCloseShift()` likewise (`SyncController.php:50-63`, code at `:56`).
   - `device_loss_incidents` is a schema-only register: grep across `apps/api/app`,
     `apps/api/routes`, `apps/api/database` finds **no production writer and no endpoint**
     (only `Domain/Models/DeviceLossIncident.php`, `Domain/Enums/DeviceLossIncidentStatus.php`
     and `tests/Feature/Fiscal/DeviceLossIncidentTest.php`, which pins table shape only).
   - `requestTerminal()` provisions at `fiscal_schema_version => 3`
     (`TerminalController.php:569`), `store()` likewise (`:136`).
   So for every terminal this controller creates there is **no** server-side way to close an
   orphaned shift. The chain of consequence, all verified in code:
   - dead device A's SESSION_OPEN has already projected an OPEN `pos_shifts` row
     (`ZSessionLifecycleProjection.php:171-184`);
   - admin releases; device B claims (lane test `:445-464` proves the re-claim works);
   - B authors SESSION_OPEN locally; `projectPosShiftOpen()` sees a different OPEN shift on
     the terminal and **silently `return`s** — `ZSessionLifecycleProjection.php:146-153`.
     No exception, no quarantine, no dead-letter. B's shift never exists server-side.
   - B's SESSION_CLOSE then throws `SESSION_CLOSE %s arrived before its pos_shift %s was
     projected; retrying until the SESSION_OPEN projection lands`
     (`ZSessionLifecycleProjection.php:205-216`) — it never lands, so this retries to
     exhaustion and dead-letters, forever.
   - B's Z_REPORT projection writes `pos_z_reports.shift_id`
     (`ZReportProjection.php:112`), which carries FK `pos_z_reports_shift_id_foreign →
     pos_shifts(id) ON DELETE RESTRICT` (verified on live PG schema). It cannot land either.
   Net: the fiscal chain in `fiscal_events` stays intact (that is the record of truth and it
   is fine), but the replacement till's shifts and Z reports are silently absent from every
   server projection — X/Z, GL bridge, treasury, reports — with no operator-visible signal.
   That is "silently-dropped rows" on the exact scenario the endpoint was built for.
   The lane's own test (`TerminalClaimHardeningTest.php:474-495`) sets up precisely this
   precondition (inserts an OPEN `pos_shifts` row, releases, asserts 200) and asserts nothing
   about what follows.
   **Fix (preferred):** default-refuse with a named 409 (`TERMINAL_HAS_OPEN_SHIFT`, carrying
   the shift id), and accept `force: true` + a mandatory `reason` to proceed; carry
   `open_shift_id` and `forced` into the `TerminalReleased` audit payload. That keeps the
   remedy for a genuinely dead device, makes the operator confront the orphan, and leaves an
   auditable trail of which shift each release orphaned. **Minimum acceptable:** an owner
   ruling row plus a deploy runbook step for resolving the orphaned `pos_shifts` row, and a
   correction to the `:479-480` docblock which currently asserts a remedy that does not exist.

2. **[Important] `TerminalController.php:482-523` — nothing invalidates the released
   device, so `release()` can re-create the two-tills-on-one-chain state this lane exists to
   remove.** The binding is only ever checked at acquisition. After release:
   - the old device's `initialize()` refreshes from `GET /pos/terminals/{id}` and never
     compares the returned `hardware_identifier` against its own device id
     (`apps/pos/src/stores/terminalStore.ts:614-673`); the `by-device` lookup that *would*
     miss is only reached at `:707-728`, i.e. when there is **no** cached terminal;
   - shift open/close carry no binding check (`ShiftController.php:47-103` — grep for
     `hardware_identifier` in that file returns nothing);
   - fiscal-event ingestion carries no binding check
     (`FiscalEventIngestionController.php` — grep for `hardware` returns nothing;
     `OutboxIngestor.php` keys only on `terminal_id`).
   The one path that *does* validate the binding is
   `TerminalSyncHealthSourceService::physicalForDevice()`
   (`.../TerminalSyncHealthSourceService.php:31-41`, consumed by
   `InventoryCountingController.php:490-501`) — counting only.
   So an admin releasing a terminal whose device is actually alive gets two devices
   authoring into one chain until the `fiscal_events` chain key
   `(tenant_id, company_id, terminal_id, chain_context, sequence_number)` refuses the loser
   and parks real sales as `sequence_conflict`. The migration docblock (`:33-38`) names that
   exact outcome as the failure mode it is closing.
   **Fix:** device-side — in `initialize()`'s cached-terminal branch, compare the refreshed
   `hardware_identifier` against `getDeviceId()` and, on mismatch, clear the local binding
   and force re-claim. POS lane, not this one; must be an owner-visible residual because
   `release()` is what makes the state reachable.

3. **[Important] `TerminalClaimHardeningTest.php:406-464` — no test pins the fiscal
   invariant of `release()`.** Both release cases assert `hardware_identifier` becomes null;
   neither asserts that `current_sequence`, `last_hash`, `genesis_seed` and `current_year`
   are unchanged. That is the single fiscal contract of the endpoint — a re-homed terminal
   CONTINUES its chain and never restarts it — and it is exactly what a well-meaning future
   refactor ("release should reset the terminal for the new device") would break, silently
   and irreversibly. I verified the behaviour is correct today: `release()` writes only
   `hardware_identifier` (`:507-509`), `Terminal` has no `boot`/observer/saving hook that
   touches chain columns (grep of `apps/api/app/Modules/POS/Domain/Terminal.php`), and a
   direct PG probe (below, probe 4) shows all four columns byte-identical across the release
   write. **Fix:** two `assertSame` lines in
   `test_release_clears_the_binding_and_emits_the_audit_event`.

4. **[Important] `TerminalReleased.php:11-12` / `TerminalClaimed.php` docblocks claim the
   events make "the JET/audit register read consistently" — the JET half is not true.**
   `Nf525DataProvider.php:292-297` whitelists exactly three terminal audit event types
   (`terminal.activated`, `terminal.deactivated`, `terminal.software_updated`), and
   `Nf525XmlBuilder::addTerminalEvents()` maps exactly those three
   (`Nf525XmlBuilder.php:469-471`); `Nf525EventType` has no case for a binding change
   (`Nf525EventType.php:14-28`). So `terminal.claimed` / `terminal.released` land in the
   audit register (`DomainEventSubscriber.php:846-892` → `persistEvent()` `:1069-1091` →
   `AuditService::record()`) and are **absent from the NF525 JET export**.
   Mitigating: `terminal.training_mode_changed` is already absent from the same whitelist,
   so this is a pre-existing pattern, not a new regression, and adding a JET event type is a
   real certification decision, not a one-line change. **Fix:** correct the two docblocks to
   say "audit register" (drop "JET"), and record an owner row asking whether an NF525 device
   re-binding is a reportable terminal event.

5. **[Important — the parked residual, re-scoped] `TerminalController.php:785` —
   `zChainState`'s `z_hash_sequence` is a `count()` while its sibling `z_number` is a MAX,
   and Q-7 turns this from a disaster-recovery path into a routine one.**
   `:768` derives `z_number` from `orderByDesc('z_number')->first()`; `:785` derives
   `z_hash_sequence` from `ZReport::forTerminal($id)->count()`. Two different rules over one
   table, so they diverge the moment any Z row for the terminal is absent from
   `pos_z_reports` — including, concretely, the population finding 1 creates (Z reports that
   cannot land because their shift row was silently dropped). Consumption chain, verified:
   `apps/pos/src/lib/sync/syncService.ts:1552` `pullZChainState` → `upsertZChainState` →
   `apps/pos/src/lib/offline/zReportService.ts:441` `newHashSequence = z_hash_sequence + 1`
   → sealed into the Z_REPORT fiscal event payload as
   `legacyReportReference.hash_sequence` (`zReportService.ts:723`).
   Blast radius is bounded: `computeZReportHash()` takes
   `{previousHash, zNumber, terminalId, generatedAt, reportData}`
   (`zReportService.ts:444-450`) and does **not** include `hash_sequence`, so a wrong count
   does not break the Z hash chain — it seals a false sequence number into an immutable
   event (rule 8: never correctable). Correctly parked by the brief; what changes is
   reachability — before this lane a different physical device could only bootstrap a
   terminal's Z chain from the server after a local DB loss; `release()` makes it a supported
   routine operation. **Fix for the follow-on ticket:** derive `z_hash_sequence` the same way
   `z_number` is derived (from the latest row), so the two fields cannot diverge.

6. **[Minor / ruling to record] `TerminalController.php:684-693` — `findByDevice()` is now
   deterministic but still silently picks a chain when the binding is ambiguous.**
   `orderBy('created_at')->orderBy('id')` is a total order and is exactly what the brief
   asked for, so this is spec-conformant. But the population it serves is the brownfield
   tenant whose duplicates BLOCKED the unique index, and on that population "oldest wins"
   is deterministic-but-arbitrary: the comment's premise ("it is the one that already has
   receipts", `:690`) is an assumption, not a check. A device that adopts the wrong row
   adopts the wrong NF525 chain. The fiscally fail-closed alternative is to refuse when
   `count() > 1` (the operator already has a BLOCKED log line naming the tenant) or to order
   by chain activity (`current_sequence` DESC). No change requested — recording the ruling
   so the choice is visible.

7. **[Minor] `TerminalController.php:499-503` — the idempotent no-op release returns 200
   and is indistinguishable from a real release in the response.** Deliberate and correctly
   argued (no phantom audit row). Worth one line in the response or an owner note, because
   an operator retrying a release cannot tell whether their first call took effect or
   somebody else's did. Not a defect.

## Rulings on the two questions handed over by the tenancy half

**(a) Should `release()` be blocked or warned while a shift is OPEN? → BLOCK BY DEFAULT,
with an explicit `force` + `reason` override. Not accept-as-is.**
The lane's argument against a *hard* block is correct and I endorse it: a lost device's
shift can no longer be closed from the device, so a hard block would make exactly the
terminals that need re-homing the ones that can never be re-homed. But "accept-as-is" is
wrong, because the consequence is not an untidy row — it is finding 1's silent projection
hole for the replacement till, and the docblock's stated remedy ("the shift surface's job")
does not exist for v3 terminals (`ShiftController.php:63,127`; `SyncController.php:56`;
no `device_loss_incidents` writer). Default-refuse with `TERMINAL_HAS_OPEN_SHIFT` naming the
shift id, `force: true` + mandatory `reason` to proceed, and `open_shift_id` + `forced`
carried into the `TerminalReleased` audit payload. That preserves the remedy, forces the
operator to confront the orphan, and leaves a per-release record of which shift was
orphaned. The orphaned-shift resolution surface itself belongs to the D-1 stack and must
be an owner-visible row, not a comment — otherwise `release()` is a remedy that hands back
a terminal the replacement device cannot use.

**(b) The parked `zChainState` `count()` defect → Important, correctly out of scope for
Q-7, but its owner row must be re-scoped.** See finding 5. The defect is real, the
mis-derived value is sealed into an immutable Z_REPORT payload, and the Z hash chain itself
is unaffected. What Q-7 changes is reachability, not severity: `release()` promotes
"a different physical device bootstraps this terminal's Z chain from the server" from a
disaster-recovery path to a supported operation. The residual ticket should say so, and
should specify the fix as "derive `z_hash_sequence` from the latest row, like `z_number`",
not "audit the count".

## Gate verified

1. **Exactly one device owns a chain at any instant — proven under real lock contention on
   PostgreSQL 5433**, not only by the in-request `DB::listen` interposition the lane's own
   test uses. Two OS processes, overlapping transactions, throwaway DB `autoerp_gate_q7f`:
   process A `BEGIN; UPDATE … WHERE id=? AND hardware_identifier IS NULL` → 1 row, holds the
   lock 3s; process B issues the same conditional UPDATE 1s in → **blocked 2.00s, then
   rows=0**; final `hardware_identifier = 'HW-A'`. `claim()` maps `rows=0` to 409
   `TERMINAL_ALREADY_CLAIMED` (`TerminalController.php:437-444`). The winner is never
   overwritten.
2. **One device cannot hold two chains in a company — the partial unique is the arbiter,
   under contention.** Second probe pair: A claims T1 with `HW-SAME` and holds; B claims T2
   with `HW-SAME` → **blocked 2.02s then SQLSTATE 23505** on
   `pos_terminals_unique_hardware_identifier`. Non-concurrent probe gives the same 23505 with
   `DETAIL: Key (tenant_id, company_id, hardware_identifier)=(…, HW-A) already exists`.
   `isUniqueViolation()` (`:874-881`) maps it to 409 `DEVICE_ALREADY_BOUND`.
3. **The 409 narrowing is honest — a code collision is NOT mis-attributed.** Probed on PG:
   a `pos_terminals_unique_code` violation's driver message is
   `duplicate key value violates unique constraint "pos_terminals_unique_code" | DETAIL: Key
   (tenant_id, company_id, location_id, code)=(…) already exists` — `str_contains(…,
   'hardware_identifier')` is **false**, so `isUniqueViolation()` correctly declines it and
   it rethrows. The hardware violation's message contains `hardware_identifier` (via the
   index name) — **true**. The method reads `$e->getPrevious()?->getMessage()`
   (`:876`), i.e. the PDO message without Laravel's appended SQL, which is what makes the
   discrimination work; the docblock's reasoning at `:868-873` is accurate.
4. **`release()` preserves the chain — verified, both by reading and by probe.** The write
   is `$terminal->update(['hardware_identifier' => null])` (`:507-509`) and nothing else;
   `Terminal` has no boot hook, observer or saving mutator touching chain columns. PG probe:
   before `{current_sequence: 4242, last_hash: 'deadbeef…', genesis_seed: 'ffff…',
   current_year: 2026}` → after, all four byte-identical with `hardware_identifier` null.
   A re-homed terminal continues the chain; it never restarts it.
   The receipt-chain hand-off to the replacement device is real: `pullTerminalState` reads
   `genesis_seed` / `last_hash` / `hash_sequence` from `GET /pos/terminals/{id}`
   (`apps/pos/src/lib/sync/syncService.ts:1411-1462`) and its `FiscalRegressionError` guard
   preserves a locally-ahead head (`:1473-1487`).
5. **Migration, all three legs, verified against live PG schema** (`\d pos_terminals` on
   `autoerp_gate_q7f` after the lane's migration ran):
   - `pos_terminals_unique_hardware_identifier UNIQUE, btree (tenant_id, company_id,
     hardware_identifier) WHERE hardware_identifier IS NOT NULL AND deleted_at IS NULL` —
     matches the brief verbatim and the code's assumptions;
   - `pos_terminals_type CHECK (type = ANY (ARRAY['web','physical','virtual_admin']))`;
   - `pos_terminals_active_logic CHECK ((is_active = false OR deactivated_at IS NULL AND
     deactivation_reason IS NULL) AND (deactivation_reason IS NULL OR deactivated_at IS NOT
     NULL))` — admits both legitimate `is_active = false` shapes (requested-not-yet-activated
     with NULL deactivation columns, and deactivated with the empty-string reason the
     controller writes), pinned at test `:201-236`, round trip at `:238-260`.
   Soft-delete exemption probed directly: archiving the holder frees the hardware id for
   another terminal (probe 3).
   The FAILED-leg concern the tenancy record raises (its finding 3) is **confirmed and I
   weigh it the same way from the fiscal side**: `run()` catches `Throwable`, logs
   `status=FAILED` and returns (`…140000_harden_pos_terminals_identity_and_lifecycle.php:
   339-347`) while `up()` completes, so the bookkeeping row is written and the leg never
   re-runs. Fiscally this is acceptable *because* the application layer now settles the
   claim by conditional UPDATE regardless of the index — the index is the backstop, not the
   guarantee (`TerminalController.php:407-435`, and the docblock says so at `:398-402`).
   Keep C-2's dual `status=BLOCKED|FAILED` grep.
6. **Per-tenant census — executed on every `tenant%` DB on PG 5433 (10 databases).**
   All three legs would apply cleanly on the whole local fleet; no tenant is BLOCKED.

   | database | pos_terminals rows | leg 1 dup groups | leg 2 bad type | leg 3 incoherent |
   |---|---|---|---|---|
   | tenant019fbe86-944a-7252-8a3b-8c341dfa9de9 | 10 | 0 | 0 | 0 |
   | tenant019fcf48-49a3-7230-aaf5-1c5daa44b3b3 | 1 | 0 | 0 | 0 |
   | tenant019fe276-750a-709d-8968-d1364e3459b6 | 4 | 0 | 0 | 0 |
   | tenant01a01b77-21a0-73f7-a893-96f689fbe815 | 5 | 0 | 0 | 0 |
   | tenant01a03028-9470-70e6-83ca-cdc354f17cf1 | 0 | 0 | 0 | 0 |
   | tenant01a033c6-3f61-73ce-b7f4-026b75656b71 | 0 | 0 | 0 | 0 |
   | tenant3f16ac36-1cc6-4a5d-82bd-4f14831be040 | 0 | 0 | 0 | 0 |
   | tenant4c3a1260-ed30-4ee6-8755-a9d823d61403 | 0 | 0 | 0 | 0 |
   | tenantbe3cd47a-e4a1-4941-8b77-dde33f6ca4ce | 0 | 0 | 0 | 0 |
   | tenantf6c592ac-2199-4095-96b4-e4244442dd80 | 0 | 0 | 0 | 0 |

   (Local naming is `tenant<uuid>` with no underscore; the tenancy record's three census
   queries are the exact ones run, verbatim.)
7. **B-3 regression: intact and correctly ordered.** `TerminalLocationPosEnabledTest`
   12/12 green on PG. The LOCATION_POS_DISABLED refusal still fires at all four acquisition
   paths and still sits AFTER `is_active` and BEFORE the claim settlement
   (`TerminalController.php:361-393`), re-pinned by
   `TerminalClaimHardeningTest.php:258-274` (inactive + already-claimed → 422
   `TERMINAL_INACTIVE`, not 409).
8. **Audit-event shape mirrors `TerminalDeactivated` exactly.**
   `handleTerminalClaimed` / `handleTerminalReleased` (`DomainEventSubscriber.php:846-892`)
   use the same `persistEvent(aggregateType: 'Terminal', aggregateId: terminalId,
   eventType: getEventName(), payload: [terminal_code, …])` call as
   `handleTerminalDeactivated` (`:897-911`); both are registered in `subscribe()`
   (`:1190-1194`). Payload carries terminal id (as aggregate id), terminal code, hardware
   id, actor and company; `persistEvent` stamps user from `Auth::id()` (`:1077-1078`) and
   `AuditService::record()` stamps tenant + impersonation attribution. `TerminalReleased`
   carries the BROKEN binding, which is the only thing an auditor can work from once the
   column is null — correct. Actor resolution `(string) auth()->id()` matches every sibling
   (`:267`, `:305`, `:742`).
9. **Rule-8 / immutability: clean.** Two NEW event classes; no existing fiscal Event class
   renamed, restructured or deleted. No parallel refund event type invented; this lane
   touches no receipt/refund/void surface.
10. **Rule 19 / rule 20 scan of the whole diff: clean.** `git diff dev...HEAD` contains no
    `onQueue`, no `toISOString`, no `parseFloat`, no `(float)`, no `Number(`, and no
    bare no-arg `getScale()`. No queued job or projection is added, so no horizon coverage
    entry is needed and `HorizonQueueCoverageTest` is unaffected. No `apps/pos` file is
    touched, so the SQLite TEXT-timestamp contract and the device-authored-shift-field merge
    contract are not in play. No money or quantity column is read or written.
11. **Executed on PostgreSQL 5433 (throwaway DB `autoerp_gate_q7f`, dropped) and on
    sqlite, BY PATH only. Never the full suite.**

    | file | PG | sqlite |
    |---|---|---|
    | `tests/Feature/POS/TerminalClaimHardeningTest.php` | 14 passed (46 assertions) | included below |
    | `tests/Feature/POS/Migrations/PosTerminalsIdentityLifecycleConstraintsTest.php` | 15 passed | 6 passed / 9 skipped (ALTER TABLE … ADD CONSTRAINT is pgsql-only — correct) |
    | `tests/Feature/POS/TerminalLocationPosEnabledTest.php` (B-3) | 12 passed | included below |
    | `tests/Feature/POS/TerminalDeviceLookupTest.php` | 4 passed | included below |
    | `tests/Feature/POS/TerminalLifecycleEventsTest.php` | 7 passed (37 assertions) | included below |
    | `tests/Feature/Fiscal/TerminalRegistrySnapshotTest.php` | 20 passed (77) | — |
    | `tests/Feature/Fiscal/Nf525VerifyChainParityTest.php` | 3 passed (15) | — |
    | `tests/Feature/Fiscal/DeviceLossIncidentTest.php` | 13 passed (39) | — |
    | `tests/Feature/Fiscal/ReceiptChainRebuildTest.php` | 22 passed / 3 skipped (149) | — |
    | `tests/Feature/POS/ZReportImmutabilityTest.php` | 17 passed (36) | — |
    | `tests/Feature/POS/VirtualAdminTerminalResolverTest.php` | 3 passed (8) | — |
    | `tests/Feature/POS/TerminalCreationFiscalSchemaVersionTest.php` | 6 passed (15) | — |
    | `tests/Feature/POS/TerminalActivationTest.php` | 3 passed | — |
    | `tests/Feature/POS/TerminalResourcePolicyTest.php` | 5 passed | — |

    sqlite combined run of the five terminal files: **43 passed / 9 skipped (132
    assertions)**, all skips inside `PosTerminalsIdentityLifecycleConstraintsTest` and all
    with an explicit pgsql-only reason.
12. **PHPStan level 8 on the five changed backend files: `[OK] No errors`.**
    **Pint `--test` on all eight changed files: `{"result":"pass"}`.**
    **Manifest checker in-branch (`apps/api/tools/feature-lane-manifest-check.php`): OK** —
    "1381 Feature classes in 74 groups; every group has a disposition; every declared lane is
    present in ci.yml; every --filter entry is anchored and uniquely matched", 1147 gated
    against a 1147 ceiling.
13. **Scope discipline: clean against Session A's collision matrix.** No PIN/`has_pins`
    surface, no `PosAuthController`/`PinVerifier`, no VAT resolution, no StockLevel read
    path, no web document page, no X/Z surface (`ZReportProjection` / `Nf525DataProvider` /
    `ShiftController` were READ for this review, not modified — `git diff --stat` lists ten
    files and none of them). `DomainEventSubscriber.php` is shared and high-traffic:
    merge-conflict watch only; the lane's edit is two handler methods plus two `subscribe()`
    entries, all additive.
14. **The `.github/workflows/ci.yml` edit is justified — do NOT revert it.** Independently
    confirmed: one line (`:958`) appends `TerminalClaimHardeningTest|
    PosTerminalsIdentityLifecycleConstraintsTest` to the existing `backend-test-pgsql`
    `--filter` allowlist. Same precedent B-3 set on the same list. Without it, the PG-only
    CHECK/index cases execute nowhere, since the POS lane is parked behind
    `vars.SELF_HOSTED_RUNNER_READY`.

## Manifest union (supersedes the tenancy record's C-1)

Measured, not inferred — the checker was run on both sides.

| | gated classes (checker) | `gated_ceiling` | POS `classes` |
|---|---|---|---|
| base `83448e0bd` | 1145 | 1145 | 149 |
| **dev today** (`9316506d4`, contains Q-6 `681c7bf29`) | **1152** | 1152 | **150** |
| branch `d760748b0` | 1147 | 1147 | 151 |
| **post-merge union** | **1154** | **1154** | **152** |

Dev added 7 gated classes over the base (POS 149→150 via Session A's N-5 `PosAuthHasPinsTest`;
Fiscal 79→80; Inventory 108→111; Loyalty 17→18; one further group 2→3). This lane adds 2 POS
classes. Union: `gated_ceiling` **1154**, POS `classes` **152**. Take Fiscal `80`,
Inventory `111`, Loyalty `18` and the 2→3 group from dev **verbatim**; union both POS notes
(dev's N-5 raise text + this lane's Q-7 raise text). Then re-run
`php tools/feature-lane-manifest-check.php` **from `apps/api/`** (the checker is at
`apps/api/tools/`, not repo root). Taking either side's number unchanged leaves 1154 gated
classes under a lower ceiling and trips `tools/feature-lane-manifest-check.php`.

## What to fix before merge

Close finding 1 (default-refuse `release()` on an OPEN shift with a `force` + `reason`
override, or land an explicit owner ruling + runbook step) and set the manifest to
`gated_ceiling` 1154 / POS `classes` 152; findings 2–7 are ledger residuals.
