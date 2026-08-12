# Codex A→Z dispatch — Event-Sourcing remediation, **Lane A0: honest verification** (2026-08-11)

**Model/effort (owner directive):** Codex SOL 5.6, HIGH effort. Workhorse mode: implement end-to-end,
TDD red-first.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF by
> invoking Opus through the CLI bridge `scripts/adversarial-review.sh` (it calls
> `claude -p --model opus`), read its register, and loop scoped fix rounds until ACCEPT — then move on.
> - **Track state in `docs/handoff/progress/es-wave-a0.progress.yaml`** — read it first, update it after
>   every milestone (status, commit SHA, verdict path, fix_rounds). It is the resume point if you crash.
> - The bridge call, once per milestone:
>   ```
>   scripts/adversarial-review.sh \
>     --brief   docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md \
>     --milestone M<n> \
>     --lenses  "fiscal-pos,treasury" \
>     --range   <base_sha>..HEAD \
>     --out     docs/handoff/reviews/es-wave-a0/M<n>-round<r>.md \
>     --round   <r>
>   ```
>   Exit **0 = ACCEPT** · **2 = CHANGES-REQUIRED** · **3 = tool error → treat as CHANGES-REQUIRED
>   (fail closed; never proceed on a tool error)**. Record the `--out` path in the milestone's
>   `verdict:` and the exit result in `last_verdict:`.
> - Wherever a milestone below says "hand back", "the orchestrator runs", or "gate" — that is the
>   harness's self-review loop (the milestone's `review_lenses` in the YAML are the lenses to apply).
> - **Resume semantics:** a fresh session resumes by reading the YAML + the newest register under
>   `docs/handoff/reviews/es-wave-a0/`. Never re-run a milestone whose `status: passed`.
> - **STOP and escalate only at the three harness STOP conditions:** (A) fix rounds exhausted
>   (`max_fix_rounds: 5`) → `blocked_review`; (B) an owner gate (here: **D-8/Q1** ES-06 second-approver
>   workflow, **D-11/Q5** ES-43, and — **only if M4's first check finds the device principal does not
>   hold `pos.operate_terminal`** — the ES-42 grant) → `blocked_owner`; (C) an architecture
>   contradiction (here: **R-3**, if the manifest-driven fleet driver cannot be built without violating
>   the actor-anchored gate) → `blocked_architecture`. Set the YAML `status` + `blockers` and end your run.
> - **Gate wiring, deliberate:** no milestone in the YAML carries an `owner_gate:` field. The harness
>   reads that field as an **unconditional** STOP (harness step 4 / condition B), and every gate in this
>   wave is *conditional*. The `owner_gates:` list is therefore an **informational record**
>   (`blocks_milestone: none`); the conditional logic lives in the milestone text below — trust the
>   milestone text, and stop only when its stated condition actually fires.
> - **No clock.** Do not call `date`. Use `git rev-parse --short HEAD` as the `updated:` marker.
> - Per-milestone registers go to `docs/handoff/reviews/es-wave-a0/`. Branch NOT merged, NOT pushed.

**Revision 2 (2026-08-11).** Brief-gate round 1 fixes, per
`docs/superpowers/reviews/2026-08-11-es-briefs-gate-r1.md` (verdict CHANGES-REQUIRED, 9 findings):
the harness gate wiring (no milestone carries an `owner_gate:` field; conditionality lives in the
milestone text), **R-2 rewritten** — the "false red" mechanism is disproven and ES-07 is a
coverage/reporting defect plus a missing mirror, **R-3 rewritten** — the fleet driver is
**manifest-driven** with no invented identity, **R-6/M4 rewritten** — ES-09 is a **correctness**
contract and ES-41 a trigger-presence one, **M3 split into M3 + M3b** so the straggler contract
proposals are a committed artifact the reviewer can inspect, and **ES-42's permission LOCKED** to the
existing `pos.operate_terminal`.

**Revision 1 (2026-08-11).** First dispatch of this lane. It follows the event-sourcing entry gate
being **CLOSED at round 4** (`dev` @ `479afed98`); the register snapshot, the corrections addendum and
the handover are the contract this brief transmits. **The only open item at dispatch is the base SHA.**

---

## 0. Read before starting (in this order)

| # | Document | Why |
|---|---|---|
| 1 | `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md` | **The method.** §3 Lane A0 row-set + per-lane entry criteria · §4 non-negotiables · §5 verification contract (the *graduated* classes and the straggler footnote) · §6 P0 table · §7 must-nots · §8 owner questions. |
| 2 | `docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md` | **The contract-of-record.** Read **every row you touch, in full** — ES-06, ES-07, ES-08, ES-09, ES-16, ES-17, ES-41, ES-42, ES-43 (snapshot lines 129–132, 144–145, 174–176; theme **T5** at `:253`). |
| 3 | `docs/handoff/ES-REGISTER-CORRECTIONS-2026-08-11.md` | **Snapshot + addendum = the contract.** Where they disagree, the addendum wins for the rows it lists — §1 narrows **ES-07** (this is the single most load-bearing correction in this wave). |
| 4 | `docs/handoff/OWNER-QUESTIONS-es-remediation-2026-08-11.md` | The owner sheet. **D-8** (ES-06 workflow half) and **D-11** (ES-43) are the two items that bound this lane's scope. |
| 5 | `CLAUDE.md` rules 1–21 (esp. **8** events immutable, **13** constructor injection, **17** testing pitfalls, **19** precision, **20** POS cross-layer contracts, **21** branch discipline) | Non-negotiable house rules. |

**Do NOT read** `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/` as the contract. It is untracked
(`.gitignore:58`) and entry-gate round 3 ruled the live path **UNFIT as a commit-scoped program input**.
It is provenance only. **Line mapping:** a pointer written `00-CONSOLIDATED-REGISTER.md:N` is snapshot
line **`N + 50`**.

---

## ⛔ HARD PREREQUISITES

### 1. VERIFY THE CONTRACT — a mechanical step, not a phrase

The program's contract is a **hash-verified snapshot**. M0's *first* action is to prove the copy you
are reading is the copy the program was gated on. Run, at the pinned base:

```
git show <BASE_SHA>:docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md | tail -n +63 | shasum -a 256
# expected: 04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540
```

**A mismatch ABORTS the wave** — set `status: blocked_precondition`, put the observed digest in
`blockers:`, STOP. Do **not** "reconcile" it, do not edit the snapshot (it is never edited; corrections
live only in the addendum), and do not fall back to the `docs/sessions/` original.

*(The offset `63` is the snapshot's own documented body start; if a future edit changes the header
length the snapshot header says to adjust it — but on this base it is 63 and the digest above is the
expected value.)*

### 2. PIN THE BASE SHA — execute and paste into the report

```
git -C <repo> rev-parse --verify dev            # → BASE_SHA
git -C <repo> log -1 --format='%H %ci %s' dev   # → paste verbatim
git -C <repo> status --porcelain                # → your worktree MUST be clean
```

`BASE_SHA` is quoted in the YAML (`base_sha:`), in M0's report header, and in the session report.

**M0 base check (replaces strict equality — the pin commit itself advances dev, so equality can
never hold): create the worktree/branch from the CURRENT local dev tip. Then verify ALL THREE:**
1. **`base_sha` is an ancestor of `HEAD`** — `git merge-base --is-ancestor <base_sha> HEAD`.
2. **The contract digest verifies at `HEAD`** —
   `git show HEAD:docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md | tail -n +63 | shasum -a 256`
   == `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540`.
3. **`git diff --stat <base_sha>..HEAD` touches ONLY administrative paths** —
   `docs/handoff/progress/*.progress.yaml`, `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md`,
   `docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md`, `docs/superpowers/reviews/*`. If any OTHER
   path appears in that diff, the base is **contaminated**: STOP, set `blocked_precondition`, request
   an orchestrator re-pin.

`base_sha` remains the reviewed **CONTENT baseline**: `df85d43f404a9e55fd29b0e3c7533852966652df`.

### 3. WORKTREE

Dedicated worktree **`.worktrees/es-wave-a0`**, branch **`codex/es-wave-a0`**, created from `BASE_SHA`
off **local `dev`**. Do not commit in a shared `dev` worktree. **Never `git stash`** — the stash stack
is repo-global across worktrees. Do not push; do not merge.

### 4. LANE ENTRY CRITERIA — none

Handover §3: *"**A0** | Nothing — this is the head of the program."* A0 is the head; it blocks every
other lane's exit criteria. Nothing upstream is owed to you, and nothing downstream may start citing
your verifiers until **M5's red-run evidence exists** (see the A0 EXIT RULE below).

---

## 🎯 SCOPE — nine rows, and exactly nine

| Row | Class (§5) | This wave does |
|---|---|---|
| **ES-08** | verifier fix | **M1** — extend `fiscal:verify-event-chain`: `payload` ↔ `canonical_bytes`, `integrity_status`, sealed coordinates, `sequence_number` contiguity, **manifest-driven** fleet driver |
| **ES-07** *(narrowed)* | verifier fix | **M2** — `pos:verify-chains` **coverage/reporting** of fiscal-era receipts + the `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash` mirror cross-check |
| **ES-06** | verifier fix (**detection half only**) | **M3** — divergence detection on sealed rows. The workflow / second-approver half is **owner-gated D-8** |
| **ES-16**, **ES-17** | **none of the six classes** — stragglers | **M3** proposes each row's contract as a **committed artifact**, approved at M3's review; **M3b** implements the approved contract |
| **ES-09** | guard/constraint | **M4** — chain-head resolution scoped by `company_id` + `chain_context`, both server-authoring services |
| **ES-41** | guard/constraint | **M4** — per its snapshot row (`:174`) |
| **ES-42** | guard/constraint | **M4** — per its snapshot row (`:175`); refusal contract per §5 |
| **ES-43** | forward-only emission-analog | **EXCLUDED — owner-gated D-11/Q5.** Not in this wave's scope until answered |

**Nothing else.** ES-01…ES-05, ES-10 and the whole of Lanes A1/B/C/D/E/F/G/H/I/X are **not yours**
(handover §3). If a fix here appears to need an A1 emission or a Lane-B drawer change, that is a scope
signal, not an invitation: note it in the report and continue.

---

## ✅ OWNER GATES (cite; do not re-ask, do not decide)

| Gate | Item | Effect on this wave |
|---|---|---|
| **D-8** (= Handover **Q1**) | ES-06 second approver + correcting-fiscal-event design | **Detection ships either way.** The workflow half is OUT of this wave. If an M3 finding turns on the workflow shape → STOP `blocked_owner`. |
| **D-11** (= Handover **Q5**) | ES-43 unkeyed SHA-256 vs NF525 signature | **ES-43 is EXCLUDED from A0's scope until answered.** Handover §5: *"Do not implement ahead of that ruling — if it defers to FR, ES-43 leaves A0's exit scope entirely."* |
| **ES-42 permission grant** | *which* permission the ingestion route requires, and which principal holds it | **DECIDED — `pos.operate_terminal`, an existing seeded permission (R-4).** The gate survives only as a conditional: if M4's first check finds the device principal does **not** hold it in the fixture, a grant becomes a policy choice → STOP `blocked_owner`. Otherwise this gate never fires. |

**Not gates, and already ruled — do not re-litigate:** the ES-07 narrowing (addendum §1), the
OK-BY-DESIGN list (handover §4.7), and the corrected loyalty rationale.

---

## 🧷 RIDERS — twelve binding items

### R-1 🚨 THE Z-ARM IS **NOT** BLIND — the register's blanket claim was WITHDRAWN
*(Addendum §1; handover §1 and §6 row 1.)* `pos:verify-chains`' **Z-report** branch counts **all**
`pos_z_reports` rows *including projected ones* (`VerifyPosChainCommand.php:372-398`, no
`fiscal_event_id` filter) and delegates to `ZReportHashService::verifyZReportChain()` (`:210-216`),
whose `verifyFiscalEventsArm()` (`:251-293`) **walks the `z_session` / `training_z_session`
`fiscal_events` chains and re-hashes `canonical_bytes` against `current_hash` / `previous_hash` from the
terminal `genesis_seed`.**
- **Touching the Z arm beyond what the mirror cross-check requires is OUT OF SCOPE.** Do not "fix" it,
  do not refactor it, do not rewrite it as if it were blind.
- **Any A0 red-run fixture must tamper a RECEIPT-side row (or the missing mirror).** A tampered
  `z_session` chain already fails today — using it as your red fixture proves nothing about your diff.

### R-2 🚨 ES-07 IS A **COVERAGE / REPORTING** DEFECT PLUS A MISSING MIRROR — and the "false red" story is WRONG
**Read this before you design M2; an earlier revision of this brief got the mechanism backwards.**

The carve-out's *rationale* is real and documented (Task 21 F1 round-2: *"projection rows … have their
authoritative integrity verified by `fiscal:verify-event-chain` … their `fiscal_hash` is the
canonical-bytes SHA-256 from the fiscal event, not the legacy pipe-string SHA-256 this command
recomputes"* — the same docblock at both `verifyReceiptChain()` and `findReceiptChainBreak()`). But the
inference that *broadening the count manufactures a false red* **does not survive the code**:

- `VerifyPosChainCommand::verifyReceiptChain()` (`:293-323`) uses `whereNull('fiscal_event_id')` **only
  to compute `$count`**; the actual verification delegates to
  `ReceiptHashService::verifyTerminalChain()` (`:318`).
- `verifyTerminalChain()` (`ReceiptHashService.php:178-213`) **self-partitions**: it runs
  `verifyTerminalChainFiscalArm()` (`:234-252`) — which canonical-hashes `canonical_bytes` against
  `current_hash` with genesis-seed linkage — and only then `verifyLegacyArm()` (`:334-398`), which
  **re-applies `whereNull('fiscal_event_id')` itself** (`:355`) and dispatches per row on
  `sealed_hash_algorithm`.
- **Therefore a canonical-bytes row can never reach a pipe-string recomputation**, whatever the
  command's own filter does.

**The two real defects are:**
- **(a) COVERAGE and REPORTING.** Fiscal-era receipts are not counted, so a terminal whose receipts are
  all projected returns `is_valid: true` over a **count of 0** (`:310-316`), and the fiscal arm's result
  is never surfaced per-arm in the command's output. "Success over zero rows" must end: fiscal-era rows
  must be **counted** and their verification **reported per arm**.
- **(b) THE MISSING MIRROR.** No `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash` comparison
  exists anywhere in `VerifyPosChainCommand.php` or `ReceiptHashService::verifyTerminalChain()`. That
  cross-layer control is a **new arm**.

**The mechanism is YOURS to choose** — a safe removal of the command-level filter plus per-arm
reporting, or an explicit fiscal-era pass — guided by the code reality above. There is **no mandate**
to add a duplicate fiscal arm, and no "leave the filter alone" rule; what is mandated is that the
counts, the per-arm reporting and the mirror are correct, and that nothing changes the **hash shape**
any row is verified under. The red-run contract is unchanged: **T-c must go red**, and the per-arm
counts must **prove nonzero fiscal-era coverage** (a zero-count arm proves nothing).

### R-3 🚨 "FLEET-WIDE DRIVER" vs THE COMMAND'S DOCUMENTED REFUSAL — reconcile, do not silently break
ES-08 requires *"a fleet-wide driver"*. `VerifyEventChainCommand`'s own docblock says the opposite:
*"There is deliberately no fleet-wide mode: the gate is anchored on an actor who exists in exactly one
tenant, so 'verify every tenant with this actor' has no coherent meaning."* The command is a
`TenantScopedCommand` whose `--tenant` **binds** tenancy (cat-(b) conversion, 2026-08-05) and whose
permission check is re-scoped to the actor's `tenant_id` inside a try/finally.
- **The permission gate and the tenant binding are NOT negotiable.** Do not weaken `--actor-id`, do not
  turn `--tenant` back into a WHERE predicate (that is the 42P01 regression the cat-(b) wave fixed).
  The gate is an actor row read **inside** the bound tenant, with the Spatie registrar re-scoped to
  `$actor->tenant_id` in a try/finally (`VerifyEventChainCommand.php:198-260`); `--tenant`,
  `--terminal`, `--chain-context` and `--actor-id` are all required today (`:68-100`).
- **THE DRIVER IS MANIFEST-DRIVEN. This is the shape; do not design a different one.**
  It accepts an **operator-supplied manifest (tenant → actor id)** at invocation. For each tenant
  **listed in the manifest**, it runs the **existing single-tenant verification**, preserving the actor
  gate exactly as designed — the same lookup, the same `can('fiscal.events.verify_chain')`, the same
  try/finally re-scoping, under that tenant's own binding.
- **Nothing is resolved implicitly and nothing is invented.** A tenant **missing from the manifest**, or
  one whose supplied actor **fails authorization**, is **REPORTED LOUDLY in the output** and drives a
  **non-zero aggregate exit** — never silently skipped. A silent skip re-creates exactly the "verified
  over zero rows" lie this lane exists to kill. **No service account, no new identity model, no
  "resolve an actor per tenant" heuristic** may be introduced.
- **Terminal / context enumeration:** *within* a tenant, enumerate the chains from the **distinct
  `(terminal_id, chain_context)` pairs present in `fiscal_events`**. State the enumeration source
  explicitly in the milestone report — an unstated source is an unverifiable coverage claim.
- **If the manifest shape proves unworkable during implementation** (it cannot be built without
  violating the actor-anchored gate), that is **STOP condition C** (`blocked_architecture`) — cite both
  sources with `file:line`. Do not improvise around it.

### R-4 🚨 ES-42's `can:` IS A LIVE DEVICE ROUTE — a permission alone can take the fleet offline
`Route::post('/pos/sync/fiscal-events', …)` carries the module's middleware tuple but **no `can:`**,
while its siblings in the same group do (`Fiscal/routes.php` — `best-effort-parse`,
`resolve-parse-failure`, `refund-compensations`, `dead-lettered-projections` all carry one).
This is the row. **But this endpoint is the POS devices' ingestion path.** Therefore:

1. **THE PERMISSION IS LOCKED: `pos.operate_terminal`. Do not choose, do not invent, do not reseed.**
   It already exists in the seeder and is already granted to the roles a POS operator holds
   (`RolesAndPermissionsSeeder.php:515-520`, `:590-597`, `:638-658` — manager and cashier both carry
   it), and it already gates the **sibling device sync surface**: `ZReportSyncController.php:61` opens
   with `Gate::authorize('pos.operate_terminal')`. Gating the fiscal-event ingestion route with the same
   permission is a **mechanical reuse**, not a policy choice.
2. **M4's FIRST action is to verify the principal, not to assume it.** The device principal is the
   bearer identity `apps/pos` authenticates as — an ordinary tenant `User` holding a Sanctum token
   (`apps/pos/src/lib/api.ts:61-80` sets `Authorization: Bearer <token>` + `X-Company-Id`;
   `AuthController.php:291-310` mints it with `pos:*` abilities). **Prove, in the fixture, that this
   principal holds `pos.operate_terminal` before adding the gate.** If it does **NOT**, a grant becomes
   a policy + deploy choice → set `status: blocked_owner` (gate `ES-42-device-permission-grant`) and
   STOP. If it does — which is the expectation — **proceed; do not stop.**
3. **The refusal contract is two-sided and both halves are mandatory:** an authenticated tenant user
   **without** the permission gets `403` **and persists nothing** (no `fiscal_events` row, no quarantine
   row); the **legitimate device caller still succeeds end-to-end** — exercise the fixture through the
   device sync path (`apps/pos/src/lib/sync/syncService.ts:406-440` posts to `/pos/sync/fiscal-events`)
   and assert it **succeeds**. A diff that ships only the 403 half is a production outage waiting for
   the next deploy.
4. **Deploy note (record it, and record that it is empty):** reusing `pos.operate_terminal` needs **no
   permission migration, no seeder change and no `permission:cache-reset`**. Say so explicitly in the
   session report's deploy section — "nothing owed" is a deploy fact worth stating. *(Only if item 2
   escalates does the seeder + tenant-blind cache-reset obligation appear, and then it is the owner's
   call, not yours.)*

### R-5 ES-41 IS TWO CLAIMS WITH TWO CONFIDENCES — do not treat them as one
Snapshot `:174` and the register's confidence line (`:102`): **CONFIRMED (driver gate) / SUSPECTED (seal
branch exploitable)**.
- **Confirmed half:** the immutability migrations return early on non-`pgsql`
  (`2026_05_14_100002_create_fiscal_events_immutability.php` `up()`; the receipt trigger migration
  `2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php` does the same), so the
  SQLite test/dev surface has **zero** immutability enforcement and a whole regression class is
  untestable there.
- **SUSPECTED half:** the `pending_seal → fiscalized` branch of `prevent_receipt_modification()` is an
  unconditional `RETURN NEW` with no column guards, unlike the `fiscalized → voided` branch right below
  it, which enumerates guarded columns. **Verify it against code before asserting it is exploitable**;
  if the sweep refutes it, say so in the register and ship only the confirmed half.
- **Rule 17 applies:** the `[PG]` surface is the only one where a trigger claim can be proven. `[PG]`
  tests skip **loudly**, never silently. A SQLite-green test is not evidence about a PG trigger.
- **Do not "fix" the driver gate by making the triggers run on SQLite.** The honest remedies are:
  make the untestable class *visible* (a `[PG]`-gated regression test that proves the trigger refuses),
  and/or make the driver gap explicit rather than implicit. Choose, justify, and pin it.
- **The contract is TRIGGER PRESENCE, scoped to this row** — assert on PG that the trigger exists and
  refuses; **document** the non-PG driver scope rather than claiming to have "fixed" it. Widening this
  into a general immutability-enforcement redesign is scope creep (rule 4).

### R-6 ES-09 IS A **CORRECTNESS** ROW, NOT A REFUSAL ROW — two call sites, two defects, close both
Verified at the base: **both** `resolveChainPlacement()` implementations key the chain head on
`(tenant_id, terminal_id)` only — `TerminalRegistrySnapshotService.php:443-463` and
`VirtualAdminFiscalEventService.php:372-388` — while every other component is
`(tenant, company, terminal, chain_context)`-scoped. The **correct contrast is
`OutboxIngestor.php:172-181`** (snapshot `:132`), which reads the prior row on
`tenant_id + company_id + terminal_id + chain_context`: read it before writing the fix and match its
scoping; do not invent a third shape. The **schema has enforced that shape since**
`2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31` — the unique constraint is
`(tenant_id, company_id, terminal_id, chain_context, sequence_number)`, and `chain_context` is
CHECK-constrained to `operational | z_session | training_operational | training_z_session`.
Second defect on the same row: both stamp `integrity_status = Verified` / `payload_parse_status = Parsed`
**unconditionally**, skipping `verifyLinkage` / `verifyClock`. Live callers named by the row:
`ACCOUNT_STATUS_CHANGED`, `DEPOSIT_RECEIPT`.
- **The contract is CORRECTNESS, not refusal — do not write a "the guard now rejects it" test.**
  Nothing here should end up refusing a legitimate write; the after-state is that a write which
  previously resolved the **wrong** head now resolves the **right** one.
  - **Before-fix RED:** on a fixture with a **two-context terminal** (`z_session` + `operational`, i.e.
    **every v3 terminal**), demonstrate the **wrong / context-blind head resolution** — the unscoped
    resolver picks a `previous_hash` (and a `sequence_number`) from the other context's chain.
  - **After-fix GREEN:** a **two-context append SUCCEEDS**, with the head resolved per
    `(company_id, chain_context)` on each context independently, each chain's sequence and linkage
    intact — **and no unconditional `Verified` / `Parsed` stamp** (the verdicts are derived, not
    assumed).
  - A single-context fixture passes **vacuously** and is not evidence.
- **Field occurrence is SUSPECTED** (snapshot `:102`) — the *defect* is CONFIRMED. Do not claim
  production rows are corrupted without a probe; if you run one, report the integer count.

### R-7 ES-16 / ES-17 ARE STRAGGLERS — the contract is PROPOSED, then APPROVED, then implemented
Handover §5's footnote is explicit: rows that fit none of the six classes *"must state its verification
contract explicitly in its own milestone review and get it approved there"* — and names **ES-16** and
**ES-17** among them (quarantine operator-workflow / read-surface gaps: no event, no verifier, no guard,
no data to repair).
**Mechanically — and the mechanism matters, because the harness reviews a COMMITTED RANGE
(`--range <base_sha>..HEAD`), not a scratch buffer.** A proposal that lives only "in the review input"
is invisible to the reviewer, and an approval arriving mid-milestone has nowhere to land. Therefore the
flow is split across **two milestones**:
- **M3** — write the proposed contract for each row (what the fix must demonstrate, and what would
  falsify it) into a **committed document**: `docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md`.
  The diff M3's reviewer inspects then *contains* the proposal, and **M3's bridge review approves or
  rejects that artifact**. **No straggler implementation lands in M3.**
- **M3b** — implement **only** the approved contracts, gated by M3b's own bridge review. If M3's review
  rejected or amended a contract, M3b implements the amended one.
A straggler implemented before its contract is approved is a **milestone failure**, not a style problem.
Do **not** improvise a seventh class.
Scope anchors: ES-16 = a `z_session_lifecycle` quarantine creates zero projection rows and appears in
neither partition of `DeadLetteredProjectionsController` and is not resolvable by
`ParseFailureResolutionService` (`OutboxIngestor.php:918-924`; `DeadLetteredProjectionsController.php:105-119`).
ES-17 = `fiscal_event_quarantine` rows (`sequence_conflict`, `malformed_envelope`) have **no resolution
path at all**; `resolved_at`/`resolved_by` are only ever read (`FiscalEventQuarantine.php:93-119`, no
writer found in `app/`).

### R-8 ES-43 IS EXCLUDED — and if it ever lands, it is FORWARD-ONLY
Owner-gated **D-11/Q5**. Do not implement it, do not "prepare" it, do not add signature columns to your
verifier's required set. **Record for the successor** (handover §5): its implementation half populates
`signature_*` **at INSERT time for NEW rows only**; it can never touch a sealed row (the immutability
trigger freezes those six columns —
`2026_05_14_100002_create_fiscal_events_immutability.php:71`, `:80`, `:96-100`), so the
pre-implementation era keeps `signature_status = 'not_required'` **permanently**. Its V3-analog is *your*
verifier extended by one assertion — which is why the row is tracked against A0 even though it is out of
scope. **Design M1's checks so that adding a signature assertion later is additive**, not a rewrite.

### R-9 ES-06: DETECTION MEANS *DIVERGENCE IS DETECTABLE*, not *the workflow is fixed*
The defect (snapshot `:129`): `ParseFailureResolutionService` UPDATEs `payload`, flips
`payload_parse_status → parsed` and `integrity_status → verified` on an **already-sealed** row;
`canonical_bytes` is frozen and **never re-derived or compared**; every projector reads `payload`
(`ParseFailureResolutionService.php:63-80,165-179,291-351`; the immutability trigger's column whitelist
at `2026_05_14_100002_…:73-111` is what *permits* the payload write).
**A0 ships the detection:** after your M1 work, a payload that no longer agrees with `canonical_bytes`
is **loudly detectable** by the verifier, and the detection is demonstrated **red** on a fixture built
by driving the real resolution path (not by hand-editing a row, where possible — a fixture that cannot
be produced through the production path is weaker evidence; say which you used and why).
**A0 does NOT ship:** the second approver, the correcting-event scheme, or any change to what the
resolution service is *allowed* to do. That is **D-8**.

### R-10 THE TWO PROGRAM-WIDE CHECKS ARE **RATCHETS**, NOT RED WALLS
Handover §5: *"an **orphaned-event ratchet** in CI (emitted with zero registered listeners → fail on new
drift, **baseline the existing 14**)"* and *"a **projector-emission** architecture test (every registered
`FiscalEventProjector` that writes a POS projection emits its corresponding domain event) — this is the
regression guard that would have prevented T1 entirely."*
- **A1 has not run.** Today **no** v3 projector emits its domain event (that is ES-01/03/04). A
  projector-emission test written as a hard assertion is therefore a **red wall on `dev` from the moment
  it lands** — unacceptable. Ship it as a **ratchet with an explicit, enumerated baseline/skip-list**,
  each entry naming the register row that will remove it (ES-01, ES-03, ES-04, …), so A1 deletes entries
  rather than editing the test's logic.
- Same discipline for the orphaned-event ratchet: **baseline exactly the existing 14 dead events, by
  name**, and fail on the **15th**. A baseline that is a count rather than a list is not a ratchet.
- **Both must be proven to bite:** add a deliberate 15th orphan / a deliberate un-baselined projector in
  a throwaway commit, show the check RED, revert, show GREEN. A ratchet nobody has seen fail is a
  ratchet nobody knows is wired.

### R-11 🚨 THE A0 EXIT RULE — the whole reason this lane is first
Handover §5, verbatim in force: **until Lane A0 lands, "no fix may cite either command as evidence of
correctness"**, and **"A0's own exit criterion is a RED RUN"** — the corrected verifiers demonstrated
**failing** against a deliberately tampered fixture *before* they are trusted to pass.
- Every milestone that touches a verifier owes **both halves in the same diff**: red against the tamper,
  green against the clean equivalent.
- **Per-check discrimination is mandatory at M1:** each new check must be shown to fail *individually*
  (a fixture that trips only that check), or a single coarse assertion can pass for five checks and
  nobody will know which one is inert.
- The wave's report must state, in one line: *"as of `<sha>`, these verifiers have been demonstrated to
  fail on <the enumerated tamper shapes>"* — that sentence is what unblocks every downstream lane.

### R-12 DOSSIER BOUNDARIES — do not absorb neighbours
Handover §7 and §3: do **not** re-run the audit or re-derive severity; do **not** decide any §8 question;
do **not** absorb work owned elsewhere (Lane B's drawer rows live in
`FINDINGS-shift-variance-gl-2026-08-11.md`, ES-31 belongs to the DN-consolidation build, the VAT
refund-netting gap belongs to launch-program E1, Wave-3 owns the inventory/COGS tasks); do **not** touch
`docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/`. Also out of bounds for this wave:
`docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md`, `docs/handoff/FINDINGS-other-problems-2026-08-11.md`,
`scripts/dev-scan-stack.sh`, and the SV Stage-1 wave's files
(`docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md` + its YAML) — a sibling wave owns those.

---

## 🏗 MILESTONE STRUCTURE

Everything is sequential. Each milestone is one or more commits, gated by the harness before the next
begins.

```
M0  preflight — artifacts + fixtures only, no production code        [fiscal-pos + treasury]
 │
 └─► M1  ES-08 — fiscal:verify-event-chain + manifest fleet driver   [fiscal-pos + treasury]
      │
      └─► M2  ES-07 (narrowed) — coverage/reporting + the mirror     [fiscal-pos + treasury]
           │
           └─► M3  ES-06 detection + straggler contract PROPOSALS    [fiscal-pos + treasury]
                │
                └─► M3b ES-16/ES-17 — implement the APPROVED contracts [fiscal-pos + treasury]
                     │
                     └─► M4  ES-09 + ES-41 + ES-42 — three contracts [fiscal-pos + treasury]
                          │
                          └─► M5  ratchets + WHOLE-LANE GATE         [fiscal-pos + treasury]
```

### M0 — Preflight (artifacts and fixtures only; no production code)

1. **Contract digest** — the command in HARD PREREQUISITES §1. Mismatch ⇒ abort the wave.
2. **The M0 base check (HARD PREREQUISITES §2 — ancestor + digest + admin-only delta, NOT strict
   `HEAD == BASE_SHA` equality)**, tree clean, worktree correct, branch created.
3. **Seeded v3 tenant fixture.** A0's own deliverable per handover §3 (and A1's entry criterion is
   *"A0's verifiers merged and green on a seeded v3 tenant"* — you build what A1 will stand on).
   Requirements: a tenant with a `fiscal_schema_version >= 3` terminal carrying **both** chain contexts
   (`z_session` **and** `operational`) — the two-context shape is what makes ES-09's defect reachable —
   with projected `pos_receipts` (i.e. `fiscal_event_id IS NOT NULL`) whose `fiscal_hash` mirrors the
   corresponding `fiscal_events.current_hash`. Prefer extending the existing test-fixture machinery over
   inventing a parallel one (see item 4). Rule 17: valid UUIDs for every FK; check the actual schema for
   required columns before writing the seeder/fixture.
4. **THE TAMPERED-FIXTURE TOOLKIT — built on the existing infrastructure, not from scratch.**
   `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php` already carries the exact shape:
   `seedValidChain()`, `seedTamperedChain()`, `seedQuarantineConflict()` and a low-level `insertEvent()`
   helper; `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php` carries
   `storeParseFailedFiscalEvent()` and drives the real resolution path. **Extend these; do not fork them.**
   The toolkit owes **the two tamper shapes named in handover §5**, each as a named, reusable helper:
   - **(T-a) payload rewritten on a sealed event** — `payload` no longer agrees with `canonical_bytes`
     (the ES-06 surface). Build it through the production resolution path where possible; where not,
     document why the direct-write variant is the honest fixture.
   - **(T-b) `previous_hash` from the WRONG `chain_context`** — a link that is internally well-formed
     and *hashes correctly*, but belongs to the other context on the same terminal (the ES-09 surface).
     This is the fixture that separates "the chain re-hashes" from "the chain is the right chain".
   - **(T-c, M2's) the mirror disagreement** — `pos_receipts.fiscal_hash` ≠ its
     `fiscal_events.current_hash` (handover §5 names this as a third tamper shape for the receipt arm).
   Each helper must be **proven to produce the intended divergence** (assert the fixture's own shape),
   or a "red" run later may be red for the wrong reason.
5. **Read and record the narrowed ES-07 claim (R-1) and the ES-07 partitioning reality (R-2)** into
   M0's artifact: the three ES-07 sub-claims with their addendum verdicts (2 STAND, 1 WITHDRAWN); the
   `file:line` of the receipt carve-out **and** of the self-partitioning in
   `ReceiptHashService::verifyTerminalChain()` / `verifyTerminalChainFiscalArm()` / `verifyLegacyArm()`
   that makes the "false red" story untrue; and the confirmed **absence** of any
   `fiscal_hash` ↔ `current_hash` mirror comparison. This is the artefact the M0 reviewer checks.
6. **Citation freshness sweep.** Every `file:line` this brief and the snapshot cite for your nine rows,
   re-derived at `BASE_SHA` into `old:line → new:line` + **the symbol it names** + a semantic anchor
   ("the `whereNull('fiscal_event_id')` predicate inside `verifyReceiptChain()`"). Line numbers are
   evidence, not addresses. **M0 FAILS if any citation for an in-scope row is unresolved.**

### M1 — ES-08: make `fiscal:verify-event-chain` able to fail

Extend the command with the four checks the row names, plus the driver:

| Check | What it must assert |
|---|---|
| **payload ↔ canonical_bytes** | the stored `payload` still agrees with the frozen `canonical_bytes` (the ES-06 mutation surface). This is the check the whole program is waiting for. |
| **`integrity_status`** | a **quarantined row with an intact hash must stop reporting verified** (snapshot `:131` names exactly this false-pass). |
| **sealed coordinates** | re-validate the sealed coordinate set for each row rather than trusting the stored stamp. |
| **`sequence_number` contiguity** | gaps are reported — today the walk asserts linkage, not contiguity. |
| **manifest-driven fleet driver** | per **R-3**. An operator-supplied `tenant → actor id` manifest; per listed tenant, the **existing** single-tenant verification under that tenant's binding with the actor gate untouched. Non-zero aggregate exit on any tenant's failure; a tenant **missing from the manifest** or whose actor **fails authorization** is **reported loudly**, never skipped silently. **No service account, no new identity model.** State the terminal/context enumeration source (distinct `(terminal_id, chain_context)` pairs in `fiscal_events`). |

**Verifier-class contract (§5) — all of it, in this diff:**
- **RED** against **T-a** and **T-b**, **GREEN** on the clean equivalents. Both runs are part of the diff.
- **Per-check discrimination**: each new check individually demonstrated red on a fixture that trips
  only it (R-11).
- V1 `—`, V2 `—` (write the dashes; never leave the column blank — handover §5).
- Preserve the existing exit-code contract (0 verified / 1 break-or-incident / 2 transient) and the
  existing permission + tenant-binding behaviour (`VerifyEventChainCommand.php:68-100`, `:198-260` —
  R-3). The **chain-walking logic is not being redesigned**; you are adding checks to it.
- `VerifyEventChainCommandTest.php` is the regression home; run **by path**.

### M2 — ES-07 (narrowed): coverage / reporting, and the mirror

**Read R-2 first — it disproves the "false red" story an earlier revision told.**

1. **COVERAGE + PER-ARM REPORTING.** Fiscal-era receipts must be **counted**, and their verification
   **surfaced per arm** in the command's output, so a terminal whose receipts are all projected can no
   longer return `is_valid: true` over a **count of 0** (`VerifyPosChainCommand.php:293-323`; the same
   carve-out in `findReceiptChainBreak()` `:335-346`). **The mechanism is yours** — a safe removal of
   the command-level `whereNull('fiscal_event_id')` plus per-arm reporting, or an explicit fiscal-era
   pass. Whichever you choose, **no row's hash shape may change**: `verifyTerminalChain()` already
   self-partitions (`ReceiptHashService.php:178-213`, `:234-252`, `:334-398`), and the legacy arm
   re-applies the `fiscal_event_id IS NULL` predicate itself at `:355`.
2. **The mirror cross-check** — `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash`, as a **new
   arm**. The addendum confirms **no such comparison exists anywhere** in `VerifyPosChainCommand.php` or
   `ReceiptHashService::verifyTerminalChain()`. This is the cross-layer control (rule 20's spirit:
   projections and their source-of-truth are two layers, and nothing was comparing them).
3. **The Z arm is NOT blind (R-1)** — touch it only insofar as the mirror check requires. Any Z-arm line
   in the diff must be justified in the milestone report.

**Red-run contract (unchanged):** RED on **T-c** (mirror disagreement) and on a tampered projected-receipt
chain; GREEN on the clean equivalent; the reported **per-arm counts must prove nonzero fiscal-era
coverage** (a zero-count arm proves nothing); and a **negative control** — a tenant whose receipts are
all *legacy* still verifies exactly as before (no behaviour change on the shape that already worked).

### M3 — ES-06 detection half + the straggler contract PROPOSALS

**Order inside the milestone matters:**
1. Implement **ES-06 detection** (R-9).
2. **Write the proposed contracts for ES-16 and ES-17** (R-7) into
   **`docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md`** and **commit it** — the harness
   reviews the committed range, so the proposal has to be *in the diff the reviewer inspects*. For each
   row state what the fix must demonstrate and what would falsify it.
3. **Run the M3 review.** It gates **both** the ES-06 detection work and the proposal artifact: the
   reviewer approves, amends or rejects each proposed contract. Fix rounds behave normally.
4. **No ES-16 / ES-17 implementation in this milestone.** That is M3b.

If the ES-06 work reaches the second-approver / correcting-event question → **STOP `blocked_owner`**
naming **D-8** exactly. Do not design the workflow "provisionally".

### M3b — ES-16 / ES-17: implement the APPROVED contracts

Implement each straggler **strictly to the contract M3's review approved** (as amended there), then run
M3b's own bridge review. Implementing beyond, or against, the approved contract is a **milestone
failure**, not a style problem. If implementation reveals the approved contract is unachievable, that is
a finding for M3b's review — amend and re-review; do not silently re-scope.

### M4 — ES-09 + ES-41 + ES-42: **three different contracts — do not conflate them**

Only ES-42 is a refusal. Writing one "the guard now rejects it" test shape across all three produces two
wrong tests.

- **ES-09 — CORRECTNESS contract** (per **R-6**). Scope chain-head resolution by `company_id` +
  `chain_context` at **both** services (`TerminalRegistrySnapshotService.php:443-463`,
  `VirtualAdminFiscalEventService.php:372-388`), matching `OutboxIngestor.php:172-181`, the shape the
  schema has enforced since `2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31`; and stop
  the unconditional `Verified` / `Parsed` stamping.
  **Before-fix RED:** a fixture with a **two-context terminal** demonstrates the wrong / context-blind
  head resolution. **After-fix GREEN:** the **two-context append SUCCEEDS**, heads resolved per
  `(company_id, chain_context)`, each chain's sequence and linkage intact, and **no unconditional
  `Verified` stamp**. A single-context fixture passes vacuously — it is not evidence.
- **ES-41 — TRIGGER-PRESENCE regression, scoped to its snapshot row** (per **R-5**). Assert on **PG**
  that the immutability trigger is present and refuses; the **non-PG driver scope is DOCUMENTED, not
  "fixed"**. Confirmed driver-gate half; SUSPECTED seal-branch half **verified before assertion** — if
  the sweep refutes it, say so and ship only the confirmed half. `[PG]` tests skip **loudly**.
- **ES-42 — the two-sided REFUSAL contract** (per **R-4**), gated with the **existing, LOCKED**
  `pos.operate_terminal`. **First**, verify in the fixture that the **device principal holds it**; if it
  does not → `status: blocked_owner` (gate `ES-42-device-permission-grant`) and STOP; otherwise proceed.
  Then: an authenticated tenant user **without** the permission gets `403` and **persists nothing** (no
  `fiscal_events` row, no quarantine row, no second write), **and in the same diff** the fixture's
  **device sync succeeds end-to-end** through the real client path
  (`apps/pos/src/lib/sync/syncService.ts:406-440` → `POST /pos/sync/fiscal-events`).
  **Deploy note:** reusing `pos.operate_terminal` requires **no permission migration, no seeder change,
  no `permission:cache-reset`** — state that explicitly in the report.

V1 `—`, V2 `—`, V3 only if a change also alters a projection.

### M5 — Program-wide ratchets + the WHOLE-LANE GATE

1. **The orphaned-event CI ratchet** and **the projector-emission architecture test**, both as ratchets
   with enumerated baselines, both proven to bite (**R-10**). Architecture tests live under
   `apps/api/tests/Architecture/` — follow the existing ratchet shape there rather than inventing one.
2. **The whole-lane review**: re-run the **full accumulated evidence** over the integrated branch with
   **both lenses**. Per the harness, this is the last gate and it is not a formality.
3. **The A0 EXIT STATEMENT (R-11)** — one line in the report naming the SHA and the tamper shapes the
   verifiers have been demonstrated to fail on. Without it, A0 has not exited, whatever the tests say.

---

## 📋 PER-MILESTONE EVIDENCE CONTRACT

Non-code milestones need **honest evidence forms** — "tests pass" is not available to them, and a green
test is not evidence when green was the prior state.

| Milestone | Code evidence | Artifact / non-code evidence | The evidence that is easy to fake, and its antidote |
|---|---|---|---|
| **M0** | none (no production code) | the contract digest **matching**, pasted; `BASE_SHA` == `HEAD`; the v3 fixture; the three tamper helpers **with self-assertions**; the ES-07 sub-claim table; the citation sweep with **unresolved = 0** | A prose "I built the fixtures" claim. **Antidote:** each tamper helper asserts the divergence it creates, and the reviewer re-derives **two citations of its choosing** and confirms the anchors. |
| **M1** | red-run against **T-a** and **T-b**, green on clean; per-check discrimination, one fixture per new check; phpstan/pint; tests by path | the **manifest-driven** driver's per-tenant invocation, its loud reporting of missing/unauthorised tenants + non-zero aggregate exit, and the stated terminal/context enumeration source — described and tested | One coarse assertion standing in for five checks. **Antidote:** remove each check in turn and show the corresponding fixture goes green — i.e. **every check is individually load-bearing.** |
| **M2** | red on **T-c** + a tampered projected chain; green on clean; **per-arm counts proving nonzero fiscal-era coverage**; **negative control** on an all-legacy tenant | a diff-line justification for **every** Z-arm line touched (R-1) | Broadening the count and calling it fixed while the output still reports one undifferentiated verdict. **Antidote:** a test with **both** a legacy and a projected receipt on the same terminal, each verified under its own hash shape, **both reported with nonzero counts**. |
| **M3** | ES-06 detection red-first on the mutated-payload fixture | **`M3-straggler-contracts.md` COMMITTED in this milestone's range**, carrying both proposed contracts | Implementing a straggler and back-filling its "contract". **Antidote:** the proposal is a committed file in M3's diff, the reviewer's approval is in M3's register, and the implementation lives in a **later** milestone (M3b). |
| **M3b** | ES-16/ES-17 implementation, red-first, to the approved contracts | a line-by-line mapping from each approved contract clause to the code/test that satisfies it | Implementing a *different*, easier contract. **Antidote:** the mapping cites the approved clause verbatim; anything amended is re-reviewed, not silently re-scoped. |
| **M4** | **ES-09:** before-fix red on a **two-context** terminal (wrong head resolved) + after-fix green where the **two-context append SUCCEEDS** per `(company_id, chain_context)`; **ES-41:** `[PG]` trigger-presence test; **ES-42:** 403 + nothing persisted + the device path still succeeding | ES-42's principal check (does the device principal hold `pos.operate_terminal`?) and the **"no permission migration needed"** deploy note; ES-41's SUSPECTED-half verdict with evidence; the documented non-PG driver scope | Writing a refusal test for ES-09 (it is a **correctness** row — nothing should start refusing), or a 403 test with no "device still works" counterpart. **Antidote:** ES-09's after-state is a **successful** two-context append; ES-42's green half is in the **same** diff; a single-context ES-09 fixture passes vacuously. |
| **M5** | both ratchets green on the branch **and demonstrated RED** on a deliberate violation (throwaway commit, reverted) | the whole-lane register; the **A0 exit statement** | A ratchet baselined by count. **Antidote:** the baseline is an **enumerated list of 14 named events** (and named projectors), each annotated with the register row that will delete it. |

---

## 📏 HOUSE RULES (binding, every milestone)

- **Events are immutable forever (rule 8).** This lane adds no new event class; if you believe one is
  needed, that is a scope signal → report, do not mint it. Rule 8 binds the **class**, not the dispatch
  site.
- **TDD red-first per task**; **revert-replay** every fix commit (revert, prove the covering test goes
  red, restore).
- **Tests BY PATH only. The full PHPUnit suite is FORBIDDEN — it crashes the machine** (handover §4.3,
  CLAUDE rule). The declared regression set must include **every suite directory the diff touches**.
- **A real PostgreSQL run before ANY green claim.** Local PG on 5432 (Docker 5433 is broken). `[PG]`
  tests skip **loudly** on other drivers — decisive for R-5, where SQLite has no triggers at all.
- **Rule 20 test-environment contracts:** queued jobs and fiscal projections run with **no
  `CompanyContext`** — projection tests must `app(CompanyContext::class)->clear()` **before** `apply()`
  (binding context in `setUp` masks the worker reality), and scale resolution takes an **explicit
  currency**. Any new `onQueue('x')` needs a matching `apps/api/config/horizon.php` entry
  (`HorizonQueueCoverageTest`).
- **Rule 19 money discipline** — `CurrencyScale::bcformatStrict` / `QuantityScale`; no float near money
  or quantity; explicit currency in console/queued/projection contexts.
- **Strict types**; constructor injection with `private readonly`; **no `app()` helper** in production
  code (the documented test idiom `app(CompanyContext::class)->clear()` is fine).
- **Migrations/backfills idempotent, self-guarding and unattended-safe** — `origin/dev` auto-deploys
  staging and runs `tenants:migrate`. A0 should need **no** migration; if one appears, justify it and
  make it self-guarding.
- **Sealed bytes are sealed.** Nothing in A0 changes what hashes, and no fix may write to `fiscal_events`
  outside a test fixture. Any test that hand-writes a sealed row must say so and explain why the
  production path could not build the fixture.
- **en + fr i18n** for every user-facing string (rule 11). Console output is operator-facing: follow the
  existing commands' convention rather than inventing one.
- **No scope creep (rule 4).** Fix exactly the register row; note adjacent findings and continue.
- **Do not "fix" the OK-BY-DESIGN items** (handover §4.7) — including the POS Treasury bridges not
  emitting `PaymentRecorded` (gate-reconfirmed) and the projector's direct loyalty call.
- `pint` + `phpstan` level 8 clean on touched files; `deptrac` must not regress its baseline.
- Dedicated worktree; **never `git stash`**; do not commit to a shared `dev` worktree; **do not push**.

---

## 🚦 REVIEWER GATES

**Both lenses at EVERY milestone** — handover §4.2 assigns A0 to `fiscal-pos-reviewer` +
`treasury-reviewer`. No milestone passes on one half.

| Milestone | Lenses | What the gate is really asking |
|---|---|---|
| M0 | fiscal-pos, treasury | Does the digest match? Do the tamper helpers actually tamper? Is the v3 fixture two-context? Is `unresolved = 0` real? |
| M1 | fiscal-pos, treasury | Is every new check individually load-bearing? Is the driver **manifest-driven**, with the actor gate and tenant binding untouched, no invented identity, and the enumeration source stated (R-3)? |
| M2 | fiscal-pos, treasury | Was the Z arm left alone (R-1)? Is every row still verified under its own hash shape? Do the **per-arm counts** prove nonzero fiscal-era coverage? Does the mirror check exist and fail (R-2)? |
| M3 | fiscal-pos, treasury | Is `M3-straggler-contracts.md` **committed in this range** and reviewable? Did ES-06 stop at detection (R-9)? Is straggler implementation **absent** (it belongs to M3b)? |
| M3b | fiscal-pos, treasury | Does the implementation match the contract **as approved at M3**, clause by clause (R-7)? |
| M4 | fiscal-pos, treasury | Is ES-09 tested as **correctness** (two-context append succeeds) rather than as a refusal? Is ES-41 scoped to trigger presence with the non-PG gap documented? Was ES-42's device principal **verified** to hold `pos.operate_terminal`, and is the device-success half in the same diff (R-4)? |
| M5 | fiscal-pos, treasury | Do the ratchets bite? Is the baseline enumerated? **Is the A0 exit statement true?** |

Per the harness: every **P1** finding must be closed or explicitly ruled by an owner gate before the
milestone passes; **P2** close-before-merge; **P3** may ship with a ticket recorded in the tree.
A review with no parseable `VERDICT:` line is a **tool error → CHANGES-REQUIRED**, never a pass.

---

## 📦 DELIVERABLE — handback only

**One branch, NOT merged, NOT pushed.** The orchestrator merges to local `dev` after the wave completes
and the whole-lane register is read.

1. **`codex/es-wave-a0`** in worktree **`.worktrees/es-wave-a0`**, created from `BASE_SHA` off local `dev`.
2. **`docs/handoff/progress/es-wave-a0.progress.yaml`** complete — every milestone `status`, `commit`,
   `verdict`, `last_verdict`, `fix_rounds`; wave `status` and `blockers` reflecting reality.
3. **Review records** under `docs/handoff/reviews/es-wave-a0/` — one file per milestone per round
   (`M<n>-round<r>.md`), **plus M3's `M3-straggler-contracts.md`** (the approved ES-16/ES-17 contracts).
4. **A session report** at `docs/sessions/codex-es-wave-a0-report.md`: per milestone — files touched,
   tests + commands + **actual output**, decisions taken, deviations with rationale, concerns; plus
   **the A0 exit statement (R-11)**, the **deploy obligations** — which for ES-42 as scoped is
   explicitly **none** (reusing `pos.operate_terminal` needs no permission migration, no seeder change,
   no `permission:cache-reset`); state that rather than omitting it — and any tickets raised. *(`docs/sessions/` is gitignored — that is the
   correct home for the ephemeral report per CLAUDE rule 15; the durable evidence is the YAML + the
   registers, which are tracked.)*

**Owner sheet for every gated item:** `docs/handoff/OWNER-QUESTIONS-es-remediation-2026-08-11.md` —
**D-8** (ES-06 workflow), **D-11** (ES-43). Name the exact item ID in `blockers:` when you stop on one;
do not paraphrase the question.

---

## ❓ OPEN AT DISPATCH — one item

**The base SHA.** Everything else is disposed: the row-set is fixed by handover §3, the ES-07 narrowing
by the addendum, the class contracts by handover §5, ES-43's exclusion by D-11, and ES-06's split by D-8.

**One orchestrator action owed, not Codex's:** confirm that no sibling wave is mid-flight on
`app/Modules/Fiscal/**` or `app/Modules/POS/Commands/**` at pin time — the SV Stage-1 wave dispatched
alongside this one is scoped to `apps/pos`, `apps/web` and the treasury/compliance settings surface, and
must not collide here.
