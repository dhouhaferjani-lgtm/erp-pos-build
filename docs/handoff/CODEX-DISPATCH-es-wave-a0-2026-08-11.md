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
>   workflow, **D-11/Q5** ES-43, and the ES-42 device-permission grant if it turns out to be a policy
>   choice) → `blocked_owner`; (C) an architecture contradiction (here: **R-3**, the fleet-wide driver
>   vs the command's documented "deliberately no fleet-wide mode") → `blocked_architecture`.
>   Set the YAML `status` + `blockers` and end your run.
> - **No clock.** Do not call `date`. Use `git rev-parse --short HEAD` as the `updated:` marker.
> - Per-milestone registers go to `docs/handoff/reviews/es-wave-a0/`. Branch NOT merged, NOT pushed.

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
**M0 verifies `HEAD == BASE_SHA` before writing any code.**

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
| **ES-08** | verifier fix | **M1** — extend `fiscal:verify-event-chain`: `payload` ↔ `canonical_bytes`, `integrity_status`, sealed coordinates, `sequence_number` contiguity, fleet-wide driver |
| **ES-07** *(narrowed)* | verifier fix | **M2** — `pos:verify-chains` **receipt** arm reads fiscal-era rows + the `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash` mirror cross-check |
| **ES-06** | verifier fix (**detection half only**) | **M3** — divergence detection on sealed rows. The workflow / second-approver half is **owner-gated D-8** |
| **ES-16**, **ES-17** | **none of the six classes** — stragglers | **M3** — you propose each row's contract; the inline reviewer approves it **at M3's review, before implementation** |
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
| **ES-42 permission grant** | *which* permission the ingestion route requires, and which principal holds it | See **R-4**. If the grant is a policy choice rather than a mechanical reuse of an existing permission + role, STOP `blocked_owner`. |

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

### R-2 🚨 THE RECEIPT CARVE-OUT IS DELIBERATE — do NOT just delete `whereNull('fiscal_event_id')`
Verified in code at the base: the exclusion carries a documented rationale (Task 21 F1 round-2) —
*"projection rows … have their authoritative integrity verified by `fiscal:verify-event-chain` … their
`fiscal_hash` is the canonical-bytes SHA-256 from the fiscal event, **not** the legacy pipe-string
SHA-256 this command recomputes"* (`VerifyPosChainCommand.php`, `verifyReceiptChain()` and
`findReceiptChainBreak()` docblocks; the same carve-out at both sites).
**Consequence:** dropping the filter feeds canonical-bytes-hashed rows into a pipe-string recomputation
and manufactures a **false red** on a healthy tenant — the mirror image of the defect you are fixing.
**The fix is an ADDITIONAL fiscal-era arm** (plus the mirror cross-check), leaving the legacy arm's hash
shape intact. Both arms must report, and a terminal with only projected receipts must **stop** returning
`is_valid: true` over a count of 0 (`:293-318`, `:337-346`).

### R-3 🚨 "FLEET-WIDE DRIVER" vs THE COMMAND'S DOCUMENTED REFUSAL — reconcile, do not silently break
ES-08 requires *"a fleet-wide driver"*. `VerifyEventChainCommand`'s own docblock says the opposite:
*"There is deliberately no fleet-wide mode: the gate is anchored on an actor who exists in exactly one
tenant, so 'verify every tenant with this actor' has no coherent meaning."* The command is a
`TenantScopedCommand` whose `--tenant` **binds** tenancy (cat-(b) conversion, 2026-08-05) and whose
permission check is re-scoped to the actor's `tenant_id` inside a try/finally.
- **The permission gate and the tenant binding are NOT negotiable.** Do not weaken `--actor-id`, do not
  turn `--tenant` back into a WHERE predicate (that is the 42P01 regression the cat-(b) wave fixed).
- **Acceptable shape:** a driver that *iterates tenants and re-invokes the per-tenant verification under
  each tenant's own binding*, resolving an authorised actor per tenant (or refusing that tenant loudly
  and continuing with a non-zero aggregate exit) — the `TenantScopedCommand` iteration precedent.
  A tenant that cannot be authorised must be **reported**, never silently skipped: a silent skip
  re-creates exactly the "verified over zero rows" lie this lane exists to kill.
- **If the driver cannot be built without violating the actor-anchored gate, that is STOP condition C**
  (`blocked_architecture`) — cite both sources with `file:line`. Do not improvise a service account.

### R-4 🚨 ES-42's `can:` IS A LIVE DEVICE ROUTE — a permission alone can take the fleet offline
`Route::post('/pos/sync/fiscal-events', …)` carries the module's middleware tuple but **no `can:`**,
while its siblings in the same group do (`Fiscal/routes.php` — `best-effort-parse`,
`resolve-parse-failure`, `refund-compensations`, `dead-lettered-projections` all carry one).
This is the row. **But this endpoint is the POS devices' ingestion path.** Therefore:
1. **Enumerate the principal** — establish, from code, what a POS device authenticates as and which
   role/permissions that principal holds. *Enumerate; never read.*
2. Prefer an **existing** permission the device principal already holds. If none exists, the fix needs a
   permission + a role grant — which is a **seeder change plus a `permission:cache-reset` deploy step**
   (the permission cache is tenant-blind) and, if it is a policy choice, an **owner gate → STOP**.
3. **The refusal contract is two-sided and both halves are mandatory:** an authenticated tenant user
   **without** the permission gets `403` **and persists nothing** (no `fiscal_events` row, no quarantine
   row); the **legitimate device caller still succeeds end-to-end**. A diff that ships only the 403 half
   is a production outage waiting for the next deploy.
4. Any permission/seeder addition is a **deploy obligation** — record it in the session report's deploy
   section (rule: `origin/dev` auto-deploys staging and runs `tenants:migrate`).

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

### R-6 ES-09 IS TWO CALL SITES AND TWO DEFECTS — close both, at both
Verified at the base: **both** `resolveChainPlacement()` implementations key the chain head on
`(tenant_id, terminal_id)` only —
`TerminalRegistrySnapshotService.php` (`resolveChainPlacement`) and
`VirtualAdminFiscalEventService.php` (`resolveChainPlacement`) — while every other component is
`(tenant, company, terminal, chain_context)`-scoped. The **correct contrast is `OutboxIngestor.php:173-179`**
(snapshot `:132`) — read it before writing the fix and match its scoping, do not invent a third shape.
Second defect on the same row: both stamp `integrity_status = Verified` / `payload_parse_status = Parsed`
**unconditionally**, skipping `verifyLinkage` / `verifyClock`. Live callers named by the row:
`ACCOUNT_STATUS_CHANGED`, `DEPOSIT_RECEIPT`.
- **Refusal contract (§5 guard class):** a red test that drives the previously-accepted bad input — a
  two-context terminal (`z_session` + `operational`, i.e. **every v3 terminal**) where the unscoped
  resolver picks a `previous_hash` from the wrong chain — asserting the guard now refuses **and that the
  refused attempt persisted nothing**. Plus the green half: the legitimate single-context write still
  succeeds, with an unchanged sequence/hash.
- **Field occurrence is SUSPECTED** (snapshot `:102`) — the *defect* is CONFIRMED. Do not claim
  production rows are corrupted without a probe; if you run one, report the integer count.

### R-7 ES-16 / ES-17 ARE STRAGGLERS — the contract is PROPOSED, then APPROVED, then implemented
Handover §5's footnote is explicit: rows that fit none of the six classes *"must state its verification
contract explicitly in its own milestone review and get it approved there"* — and names **ES-16** and
**ES-17** among them (quarantine operator-workflow / read-surface gaps: no event, no verifier, no guard,
no data to repair).
**Mechanically, at M3:** (a) write the proposed contract for each row into the milestone's review input
(what the fix must demonstrate, and what would falsify it); (b) run the review; (c) implement **only**
the approved contract. A straggler implemented before its contract is approved is a **milestone failure**,
not a style problem. Do **not** improvise a seventh class.
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
 └─► M1  ES-08 — fiscal:verify-event-chain + fleet-wide driver       [fiscal-pos + treasury]
      │
      └─► M2  ES-07 (narrowed) — receipt arm + the mirror check      [fiscal-pos + treasury]
           │
           └─► M3  ES-06 detection half + ES-16/ES-17 stragglers     [fiscal-pos + treasury]
                │
                └─► M4  ES-09 + ES-41 + ES-42 — the guard class      [fiscal-pos + treasury]
                     │
                     └─► M5  ratchets + WHOLE-LANE GATE              [fiscal-pos + treasury]
```

### M0 — Preflight (artifacts and fixtures only; no production code)

1. **Contract digest** — the command in HARD PREREQUISITES §1. Mismatch ⇒ abort the wave.
2. **`HEAD == BASE_SHA`**, tree clean, worktree correct, branch created.
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
5. **Read and record the narrowed ES-07 claim (R-1) and the carve-out rationale (R-2)** into M0's
   artifact: the three ES-07 sub-claims with their addendum verdicts (2 STAND, 1 WITHDRAWN), and the
   `file:line` of the deliberate receipt carve-out. This is the artefact the M0 reviewer checks.
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
| **fleet-wide driver** | per **R-3**. Non-zero aggregate exit on any tenant's failure; a tenant that cannot be authorised is **reported loudly**, never skipped silently. |

**Verifier-class contract (§5) — all of it, in this diff:**
- **RED** against **T-a** and **T-b**, **GREEN** on the clean equivalents. Both runs are part of the diff.
- **Per-check discrimination**: each new check individually demonstrated red on a fixture that trips
  only it (R-11).
- V1 `—`, V2 `—` (write the dashes; never leave the column blank — handover §5).
- Preserve the existing exit-code contract (0 verified / 1 break-or-incident / 2 transient) and the
  existing permission + tenant-binding behaviour (R-3). The **chain-walking logic is not being
  redesigned**; you are adding checks to it.
- `VerifyEventChainCommandTest.php` is the regression home; run **by path**.

### M2 — ES-07 (narrowed): the receipt arm and the mirror

1. **A fiscal-era receipt arm** in `pos:verify-chains` that verifies projected receipts under the
   *right* hash shape (R-2), so a terminal whose receipts are all projected can no longer return
   `is_valid: true` over a count of 0 (`VerifyPosChainCommand.php:293-318`; same carve-out in
   `findReceiptChainBreak()` `:337-346`).
2. **The mirror cross-check** — `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash`. The addendum
   confirms **no such comparison exists anywhere** in `VerifyPosChainCommand.php` or
   `ReceiptHashService::verifyTerminalChain()`. This is the cross-layer control (rule 20's spirit:
   projections and their source-of-truth are two layers, and nothing was comparing them).
3. **The Z arm is NOT blind (R-1)** — touch it only insofar as the mirror check requires. Any Z-arm line
   in the diff must be justified in the milestone report.

**Red-run contract again:** RED on **T-c** (mirror disagreement) and on a tampered projected-receipt
chain; GREEN on the clean equivalent; and a **negative control** — a tenant whose receipts are all
*legacy* still verifies exactly as before (no behaviour change on the shape that already worked).

### M3 — ES-06 detection half + the ES-16 / ES-17 stragglers

**Order inside the milestone matters:**
1. Implement **ES-06 detection** (R-9).
2. **Write the proposed contracts for ES-16 and ES-17** (R-7) into the review input.
3. **Run the M3 review.** The reviewer approves or rejects each proposed contract.
4. **Then** implement ES-16/ES-17 to the approved contract, and re-run the review (that is a normal fix
   round; increment `fix_rounds`).

If the ES-06 work reaches the second-approver / correcting-event question → **STOP `blocked_owner`**
naming **D-8** exactly. Do not design the workflow "provisionally".

### M4 — ES-09 + ES-41 + ES-42 (the guard / constraint class)

- **ES-09** per **R-6**: scope chain-head resolution by `company_id` + `chain_context` at **both**
  services, match `OutboxIngestor`'s shape, and stop the unconditional `Verified` / `Parsed` stamping.
- **ES-41** per **R-5**: confirmed driver-gate half, SUSPECTED seal-branch half verified before assertion.
- **ES-42** per **R-4**: the `can:` gate, the principal enumeration, the two-sided refusal contract, and
  the deploy obligation.

**Refusal contract (§5), for each of the three:** a **red test that drives the previously-accepted bad
input**, asserting the guard now refuses it, **plus a state assertion that the refused attempt persisted
nothing** (no `fiscal_events` row, no quarantine row, no second write). The **green half — the legitimate
caller still succeeds — is part of the same diff.** V1 `—`, V2 `—`, V3 only if a guard also changes a
projection.

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
| **M1** | red-run against **T-a** and **T-b**, green on clean; per-check discrimination, one fixture per new check; phpstan/pint; tests by path | the fleet-wide driver's tenant-iteration and its loud-refusal behaviour, described and tested | One coarse assertion standing in for five checks. **Antidote:** remove each check in turn and show the corresponding fixture goes green — i.e. **every check is individually load-bearing.** |
| **M2** | red on **T-c** + a tampered projected chain; green on clean; **negative control** on an all-legacy tenant | a diff-line justification for **every** Z-arm line touched (R-1) | Deleting `whereNull(...)` and calling it fixed. **Antidote:** a test with **both** a legacy and a projected receipt on the same terminal, each verified under its own hash shape, both reported. |
| **M3** | ES-06 detection red-first on the mutated-payload fixture | the **proposed** ES-16/ES-17 contracts, dated **before** their implementation commits | Implementing a straggler and back-filling its "contract". **Antidote:** the proposal commit precedes the implementation commit, and the reviewer's approval is in the register between them. |
| **M4** | per row: red test on the bad input + **nothing persisted** + the green legitimate path | ES-42's principal enumeration (who calls the route, with what permission) and the deploy note; ES-41's SUSPECTED-half verdict with evidence | A 403 test with no "device still works" counterpart. **Antidote:** the green half is in the **same** diff, and for ES-09 the negative fixture is a **two-context terminal** — a single-context test passes vacuously. |
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
| M1 | fiscal-pos, treasury | Is every new check individually load-bearing? Does the driver preserve the actor-anchored gate (R-3)? |
| M2 | fiscal-pos, treasury | Was the Z arm left alone (R-1)? Is the legacy hash shape intact (R-2)? Does the mirror check exist and fail? |
| M3 | fiscal-pos, treasury | Was each straggler's contract approved **before** implementation (R-7)? Did ES-06 stop at detection (R-9)? |
| M4 | fiscal-pos, treasury | Is the green half present for every refusal? Is ES-09's fixture two-context? Is ES-42's principal enumerated, not assumed (R-4)? |
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
   (`M<n>-round<r>.md`).
4. **A session report** at `docs/sessions/codex-es-wave-a0-report.md`: per milestone — files touched,
   tests + commands + **actual output**, decisions taken, deviations with rationale, concerns; plus
   **the A0 exit statement (R-11)**, any **deploy obligations** (ES-42 permission/seeder +
   `permission:cache-reset`), and any tickets raised. *(`docs/sessions/` is gitignored — that is the
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
