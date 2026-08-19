# ⚠️ DRAFT — Codex micro-lane dispatch: **G-3 — TRAINING receipts move real money in Treasury/GL** (2026-08-18)

> **DRAFT — NOT DISPATCHED.** The parent orchestrator gates this brief (mechanical ROUND-0 precheck +
> adversarial gate) before it is renamed to `CODEX-DISPATCH-g3-training-money-gate-2026-08-18.md` and
> handed to Codex Desktop. **Everything marked `<PARENT: …>` is unset and MUST be set at dispatch.**

**Model/effort (owner directive):** Codex SOL 5.6, HIGH effort. Workhorse mode: implement end-to-end,
TDD red-first.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF by
> invoking Opus through the CLI bridge `scripts/adversarial-review.sh`, read its register, and loop
> scoped fix rounds until ACCEPT — then move on.
> - **Track state in `docs/handoff/progress/g3-training-money-gate.progress.yaml`** — read it first,
>   update it after every milestone (`status`, `commit`, `verdict`, `last_verdict`, `fix_rounds`).
>   It is the resume point if you crash.
> - The bridge call, once per milestone (lenses come from that milestone's `review_lenses`):
>   ```
>   scripts/adversarial-review.sh \
>     --brief   docs/handoff/CODEX-DISPATCH-g3-training-money-gate-2026-08-18.md \
>     --milestone M<n> \
>     --lenses  "<comma list from the milestone's review_lenses>" \
>     --range   <base_sha>..HEAD \
>     --out     docs/handoff/reviews/g3-training-money-gate/M<n>-round<r>.md \
>     --round   <r>
>   ```
>   Exit **0 = ACCEPT** · **2 = CHANGES-REQUIRED** · **3 = tool error → treat as CHANGES-REQUIRED
>   (fail closed; never proceed on a tool error)**.
> - **Resume semantics:** a fresh session resumes by reading the YAML + the newest register under
>   `docs/handoff/reviews/g3-training-money-gate/`. Never re-run a milestone whose `status: passed`.
> - **STOP and escalate only at the three harness STOP conditions:** (A) fix rounds exhausted
>   (`max_fix_rounds: 5`) → `blocked_review`; (B) an owner gate (see §OWNER GATES) → `blocked_owner`;
>   (C) an architecture contradiction → `blocked_architecture`. Set the YAML `status` + `blockers`
>   and end your run.
> - **No clock.** Do not call `date`. Use `git rev-parse --short HEAD` as the `updated:` marker.
> - Branch **NOT merged, NOT pushed.** The parent merges after reading the whole-lane register.

---

## 0. Read before starting (in this order)

| # | Document | Why |
|---|---|---|
| 1 | `docs/superpowers/tickets/2026-07-31-treasury-bridge-training-money-legs.md` | **The ticket of record.** The defect, the fix shape, and the launch-exposure note. |
| 2 | `docs/handoff/LEDGER.md` row **G-3** | The single owes-list entry this lane closes. G-3 is also a named precondition inside **G-5**'s `treasury.shift_variance_gl_enabled` flip condition — closing it here unblocks one box of that gate and nothing else. |
| 3 | `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` | The surface. Read `apply()` **whole** (it is one long method with a documented history) before touching a line of it. |
| 4 | `docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md` row **ES-19** | The OP-18 runtime question M1 answers. **ES-19 is Lane A1's row — you do not fix it here.** |
| 5 | `CLAUDE.md` rules 1–21, especially **4** (no scope creep), **13** (constructor injection), **19** (precision), **20** (POS cross-layer contracts — the projection-test clauses are binding on M2) | Non-negotiable house rules. |

---

## ⛔ HARD PREREQUISITES

### 1. PIN THE BASE SHA

```
git -C <repo> fetch origin dev
git -C <repo> rev-parse origin/dev            # → BASE_SHA
git -C <repo> log -1 --format='%H %ci %s' origin/dev   # → paste verbatim into M0
git -C <repo> status --porcelain              # → MUST be empty
```

`base_sha: <PARENT: pin at dispatch — the fresh origin/dev tip>` goes into the YAML, M0's report
header, and the session report. Do **not** reuse a stale pin from a sibling brief.

**M0 base check (all three):**
1. `git merge-base --is-ancestor <base_sha> HEAD` passes.
2. `git diff --stat <base_sha>..HEAD` is **empty** at branch creation (this lane pins the tip itself,
   so there is no admin-delta allowance).
3. `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` at `HEAD`
   still shows the defect: the only `trainingFlag` reference is the §4.6 guard (**semantic anchor:**
   the `if (CashRoundingCutover::applies(...) && $view->payload->trainingFlag !== true)` line, at
   `:406` as of 2026-08-18 — **re-derive the line number, cite the anchor**), and the
   `foreach ($view->payments as $payment)` tender-leg loop sits **above** it with no training gate.
   If the defect is already fixed on your base: `status: blocked_precondition`, record what you found,
   STOP. Do not "re-fix" it.

### 2. WORKTREE

Dedicated worktree **`.worktrees/g3-training-money-gate`**, branch
**`codex/g3-training-money-gate-2026-08-18`**, created from `BASE_SHA`.
**Never `git stash`** (the stash stack is repo-global and shared across worktrees).
Do not push; do not merge; do not commit in a shared `dev` worktree.

**Commit series:** `<PARENT: assign the commit-message series prefix at dispatch, e.g. "G3.<seq>">`.
Do not invent another series.

---

## 🎯 SCOPE — one defect, one runtime check

| # | What this lane ships | Milestone |
|---|---|---|
| 1 | The **OP-18 / ES-19 runtime check**: does a training-mode sale currently throw? Recorded either way. **No production code.** | **M1** |
| 2 | The **training gate hoist**: a TRAINING `SALE_RECEIPT` writes **ZERO** Payment / GL / repository-movement rows through the bridge; the non-training path is **byte-identical** | **M2** |

### 🚫 NOT IN SCOPE — do not touch

| Area | Why |
|---|---|
| **ES-19 / OP-18 itself** (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts`, the operational appenders, `training_operational` wiring) | Lane A1's row. M1 **observes and records**; it does not fix. Any `apps/pos/**` production edit in this diff is out of scope. |
| **`PosCoreReceiptProjection`** and the `pos_receipts.training_flag` read model | Correct today: training receipts are mirrored with `training_flag = true` and the reporting services already filter `training_flag = false` (`SalesReportService`, `LiveSalesReportService`, `OwnerSalesSummaryService`). The read-model mirror is deliberate — training receipts must still be printable/auditable. |
| **`TREASURY_SHIFT_VARIANCE_GL_ENABLED`** / `treasury.shift_variance_gl_enabled` | LEDGER **G-5**, owner-gated. Closing G-3 removes one box from that condition; it does **not** authorize the flip. |
| **Backfill / remediation of training receipts that already wrote money rows** | Owner gate — see §OWNER GATES. M2 ships the forward fix and a **detection query**; it does not delete or reverse historical rows. |
| Any other projector, the netting pre-pass, the maturity-refund legs, the alert paths | Not the defect. Note adjacent findings and continue (rule 4). |

---

## 🧷 RIDERS — five binding items

### R-1 THE GATE'S PLACEMENT IS CONSTRAINED BY WHERE `$view` IS RESOLVED
`trainingFlag` lives on the **canonical view** (`$view->payload->trainingFlag`), and `$view` is only
resolved **inside** `DB::transaction(...)`, **after** the legacy null-key short-circuit
(`$this->canonicalReader->forSaleReceipt($event)` — `:353` as of 2026-08-18, re-derive). A gate placed
before the transaction would have to re-read `training_flag` off the raw payload — a **second** source
of truth for the same fact, exactly the class of divergence the file's own comments spend fifty lines
warning about.

**Required shape:** resolve `$view` as today, then early-return on training **before**
`computeNettedAmounts()` and before the tender-leg loop. Keep the single-source read
(`$view->payload->trainingFlag`), and keep the existing `!== true` / `=== true` polarity consistent
with the §4.6 guard so the two gates can never disagree about the same receipt.

**Do NOT** simply add a second condition to the §4.6 `if` — that guards the *rounding entries*, which
already run after the loop. The loop is the defect.

### R-2 THE NON-TRAINING PATH MUST BE PROVABLY UNCHANGED
This file is the money path for every POS sale on every tenant. The acceptance is not "training is
gated"; it is **"training is gated AND nothing else moved."**
- The diff must be **additive only** inside `apply()` — no reordering of the legacy short-circuit, the
  advisory lock, `computeNettedAmounts()`, or the `$isRefund` derivation.
- Evidence: the existing bridge suites green **by path** (at minimum
  `tests/Feature/Treasury/PosBridgeSpineTest.php`, `PosBridgeInstrumentTest.php`,
  `PosBridgeInstrumentRefundTest.php`, `PosRefundReceiptBridgeTest.php`,
  `TreasuryReceiptBridgeNettingTest.php`, `TreasuryReceiptBridgeRoundingGlTest.php`,
  `tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php` — **re-enumerate the real list**, do not trust
  this one), plus the `git diff` pasted and read at M3.

### R-3 THE TRAINING/REFUND INVARIANT — a training refund is invisible to `$isRefund` TODAY
`FiscalPayloadConstraintValidator` enforces `invoice_type_code === 'TRAINING'` ⟺ `training_flag === true`
(**semantic anchor:** the `payload_training_flag_mismatch` throw, `:907-914` as of 2026-08-18). The
bridge derives `$isRefund` from `invoice_type_code === 'REFUND' || === 'VOID'`. Therefore **a training
refund cannot carry a REFUND invoice type**, and today the bridge books it as a plain **cash-IN sale**.
- This **reinforces** the fix rather than complicating it: the gate keys on `trainingFlag` alone, so it
  catches training sales, training refunds and training voids identically — zero money rows for all.
- **Verify this invariant in code at M2** and record it. If it does **not** hold as stated, that is a
  finding to record, not a licence to broaden scope.
- Do **not** "fix" the refund typing of training receipts. Once training writes nothing, the typing is
  moot inside this bridge.

### R-4 RULE 20 IS BINDING ON THE PROJECTION TEST — this is where projection tests usually lie
`CLAUDE.md` rule 20: **queued jobs and fiscal projections run with NO `CompanyContext`.** A projection
test that binds context in `setUp()` masks the worker reality and will pass against code that explodes
in production.
- The test **must** `app(CompanyContext::class)->clear()` before calling `apply()`.
- Scale resolution takes an **explicit currency** (`getScale($currency)` / `getScaleSafe($currency, 3)`);
  a bare no-arg `getScale()` throws in that context (rule 19).
- No float touches money anywhere in the diff or the fixtures. Money as strings, `CurrencyScale`
  helpers at the boundary.
- **A real PostgreSQL run before ANY green claim.** Local PG on 5432 (Docker 5433 is broken).

### R-5 "ZERO ROWS" MEANS ENUMERATED ZERO, NOT AN ABSENT ASSERTION
The M2 test proves **three** counts are zero for a TRAINING `SALE_RECEIPT`, each enumerated explicitly:
1. **`payments`** rows for the event (`fiscal_event_id = $event->id`) — zero.
2. **`journal_entries`** (and their lines) sourced from the event — zero. Note
   `journal_entries(source_type, source_id)` is **not globally unique**, so assert on a scoped query,
   not on a `firstWhere`.
3. **Repository/balance movements** written through the movement port — zero.

An assertion of the form "no exception was thrown" is **not** evidence. Neither is a single
`assertDatabaseMissing` standing in for all three.

---

## 🏗 MILESTONE STRUCTURE

```
M0  preflight — base pin, defect still present, citation inventory   [no lenses; setup only]
 │
 └─► M1  OP-18 / ES-19 runtime check — recorded either way            [fiscal-pos]
      │      (NO production code; the finding does NOT gate M2)
      │
      └─► M2  the training gate hoist + projection test               [treasury, fiscal-pos]
           │
           └─► M3  WHOLE-LANE GATE over the integrated branch          [treasury, fiscal-pos]
```

### M0 — Preflight (no production code)

1. The base pin + the **three-part base check** (HARD PREREQUISITES §1). Worktree + branch created.
2. **Citation inventory**: for every `file:line` this brief cites, record `brief:line → actual:line`
   + the **symbol** + a **semantic anchor**. **M0 FAILS if any in-scope citation is unresolved.**
3. **Declare the regression set**: every suite directory the diff will touch
   (`apps/api/tests/Feature/Treasury/`, `apps/api/tests/Feature/Fiscal/`, and anything the
   re-enumeration in R-2 adds). Tests run **BY PATH**, always.
4. Completion = `status: passed` with `base_sha` recorded. No adversarial register at M0.

### M1 — The OP-18 / ES-19 runtime check (observation only)

**The question, stated exactly:** *does a training-mode sale on a real device build currently reach the
Treasury bridge at all, or does it throw first — and where?*

**What the register already says (SUSPECTED, static only — re-verify, never trust):**
- `ES-19`: `training_operational` is never passed by any production call site, so an operational append
  on a training terminal carries `training_flag: true` on an `'operational'` chain context, which
  `validateChainContext` rejects.
- Corroborating anchors to check yourself: `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` —
  `const chainContext = request.chain_context ?? 'operational'` (`:565`) and the
  `payload training_flag=true requires training_* chain_context` throw (`:815-819`);
  `apps/pos/src/lib/offline/receiptService.ts` — the `engine.append(tx, { event_type: 'SALE_RECEIPT', … })`
  call (`:493-505`) passes **no** `chain_context`; only `apps/pos/src/lib/fiscal/zSessionAuthoring.ts`
  (`zChainContext`, `:204-206`) ever selects a `training_*` context, and only for the Z-session family.
- The server-side mirror of the same invariant lives in
  `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:266-272` — so even an
  event that somehow got authored would be refused at ingestion.

**Deliverable:** a finding file at `docs/handoff/reviews/g3-training-money-gate/M1-op18-runtime-check.md`
recording, with `file:line` for each step:
1. **Where** the throw occurs (device authoring vs. server ingestion vs. nowhere) — name the exact
   guard and the exception class.
2. **Whether** the throw is unconditional for a training SALE, or reachable only on some path.
3. **The consequence for G-3:** if training sales cannot be authored today, the bridge defect is
   currently **latent** rather than live — state that plainly, with the caveat that Lane A1's ES-19 fix
   makes it live, and that server-ingested/legacy/manually-replayed events are not covered by a device
   guard.
4. If your verification **contradicts** the SUSPECTED reading (i.e. training sales *do* author fine),
   say so with the same rigour and mark ES-19 as needing re-classification — **record it, do not fix it.**

**Binding:** this milestone **does not gate M2**. The bridge must be correct regardless of what the
device does today. A finding of "latent, not live" is **not** grounds to downgrade, defer or skip the
fix — say so in the finding file so no later reader mistakes it for one.

**No production code in this milestone.** Grep-and-read only; quote `--include` patterns, use `grep -E`
for alternation, and read the line **after** each match (an empty grep is not proof until the syntax is
verified).

### M2 — The training gate hoist + the projection test

**Red first, in this order:**
1. Write the projection test **against the unfixed code** and watch it go **RED** — a TRAINING
   `SALE_RECEIPT` currently creates Payment + GL + movement rows. Paste the actual red output.
2. Hoist the gate per **R-1**.
3. Watch it go **GREEN** — all three counts zero (**R-5**).
4. Add/keep the **non-training companion assertion**: an otherwise-identical non-training receipt still
   writes exactly what it wrote before (**R-2**), in the same test file, so the pair reads as one
   contract.
5. **Revert-replay** the fix commit: revert, prove the covering test goes red, restore.

**Also required in M2:**
- The **detection query** for the deploy obligation: a documented, per-tenant read-only SQL/artisan
  query that answers *"do any already-projected TRAINING receipts have Payment/GL/movement rows on this
  tenant?"* It goes in the session report and the handback — it is **not** a migration, **not** a
  backfill, and it must not be wired to run automatically (see §OWNER GATES).
- The **R-3 invariant verification**, recorded.
- Any comment in the file that now misdescribes reality gets corrected — in particular the existing
  §4.6 comment block claiming *"Training receipts never reach GL at all"*, which was aspirational
  before this lane and becomes true only with this fix. State the **new** truth; do not delete the
  history.

**No user-facing strings change in this lane**, so no i18n work is expected. If a milestone somehow
introduces one, en + fr are mandatory (`apps/web` additionally has `ar`) — and that is a signal you
have left scope: record it and check.

### M3 — Whole-lane gate

Re-run the **full accumulated evidence** over the integrated branch with **every lens used in the
wave** (`treasury`, `fiscal-pos`). Paste `git diff --name-only <BASE_SHA>..HEAD` and read it. Prove the
negatives: no `apps/pos/**` production file, no `PosCoreReceiptProjection` change, no
`shift_variance_gl_enabled` reference, no migration, no event added/renamed/retired (rule 8), no
backfill.

---

## 📋 PER-MILESTONE EVIDENCE CONTRACT

| Milestone | Code evidence | Artifact evidence | The evidence that is easy to fake, and its antidote |
|---|---|---|---|
| **M0** | none | base check output pasted; citation inventory with **unresolved = 0**; regression set declared | "I read the file." **Antidote:** the reviewer re-derives two citations of its choosing and confirms symbol + anchor. |
| **M1** | none (observation only) | the finding file, with the throw located at `file:line` and the live-vs-latent consequence stated | A finding that concludes "suspected confirmed" from the register text rather than from code. **Antidote:** the file must cite the **device** guard AND the **server** guard independently, and state which one fires first. |
| **M2** | red output pasted, then green; three enumerated zero-counts; the non-training companion assertion; revert-replay | the detection query; the R-3 invariant record; the corrected comment | A test that passes because the fixture never had payments to begin with. **Antidote:** the SAME fixture, with the training flag flipped, must produce **non-zero** rows on the non-training side of the pair — asserted, in the same test. |
| **M3** | full re-run; `git diff --name-only` pasted | proof of the negatives (list above) | "Nothing else was touched." **Antidote:** the file list is **pasted and read**, not asserted. |

---

## 📏 HOUSE RULES (binding, every milestone)

- **TDD red-first per task**; **revert-replay** every behavioural fix commit.
- **Tests BY PATH only. The full PHPUnit suite is FORBIDDEN — it crashes the machine.**
- **A real PostgreSQL run before ANY green claim.** `[PG]` tests skip **loudly**.
- **Rule 19 precision.** No float touches money or quantity; explicit currency into scale resolution in
  projection/queued contexts; money as strings.
- **Rule 20** projection-test contract: clear `CompanyContext` before `apply()`; never bind it in
  `setUp()` for a projection test.
- **Rule 13**: constructor injection with `private readonly`; **no `app()` helper in production code**
  (test-only `app()` for context clearing is the documented exception).
- **Rule 8**: this lane adds, renames and retires **no** event.
- **Rule 6**: cross-module access via `Shared/Contracts/`, events, or a module's public service class.
- **No scope creep (rule 4)** — note adjacent findings and continue.
- `pint` + `phpstan` level 8 clean on touched files.
- Dedicated worktree; **never `git stash`**; **do not push**; **do not merge**.

---

## 🚦 REVIEWER GATES

| Milestone | Lenses | What the gate is really asking |
|---|---|---|
| M0 | *(none — setup only)* | — |
| M1 | fiscal-pos | Was the throw **located in code**, or inferred from the register? Does the finding state live-vs-latent honestly, without using it as a reason to defer M2? |
| M2 | treasury, fiscal-pos | Are all three row classes proven zero? Is the non-training path provably byte-identical? Is the gate a single-source read of `$view->payload->trainingFlag`? Does the test clear `CompanyContext` and pass explicit currency? |
| M3 | treasury, fiscal-pos | Whole-branch: no device code, no read-model change, no flag flip, no backfill, evidence complete. |

Every **P1** finding must be closed or explicitly ruled by an owner gate before the milestone passes;
**P2** close-before-merge; **P3** may ship with a ticket recorded in the tree. A review with no
parseable `VERDICT:` line is a **tool error → CHANGES-REQUIRED**, never a pass.

---

## 🔒 OWNER GATES

| id | Question | Blocks | Status |
|---|---|---|---|
| `g3-historical-training-money` | Training receipts already projected on staging (or any tenant) may **already carry** Payment/GL/movement rows. Reversing or deleting them is a **money-ledger remediation**, not a bug fix. M2 delivers a **detection query only**. If the query is run and finds rows, the disposition (reverse / write off / leave with a documented note) is the **owner's** ruling. | none (M2 ships the forward fix regardless) | conditional — STOP `blocked_owner` **only** if a milestone appears to require writing or reversing a historical row |
| `g3-g5-flip` | Closing G-3 satisfies one box of LEDGER **G-5**'s flip condition. It does **not** authorize flipping `treasury.shift_variance_gl_enabled`. | none | standing constraint — touching the flag is an immediate STOP |

---

## 📦 DELIVERABLE — handback only

**One branch, NOT merged, NOT pushed.**

1. **`codex/g3-training-money-gate-2026-08-18`** in worktree **`.worktrees/g3-training-money-gate`**.
2. **`docs/handoff/progress/g3-training-money-gate.progress.yaml`** complete and reflecting reality.
3. **Review records** under `docs/handoff/reviews/g3-training-money-gate/` — one file per milestone per
   round, **plus M1's OP-18 finding file**.
4. **A session report** at `docs/sessions/codex-g3-training-money-gate-report.md`: per milestone —
   files touched, tests + commands + **actual output**, decisions, deviations with rationale, concerns;
   plus **the detection query verbatim**, the **LEDGER G-3 close-out line** the parent should write, and
   the M1 finding's consequence for ES-19 / Lane A1.

---

## ❓ OPEN AT DISPATCH — parent must resolve

1. **`base_sha`** — unset. Pin the fresh `origin/dev` tip at dispatch.
2. **Commit series prefix** — unset.
3. **Does the parent want the detection query run on staging as part of this lane's handback**, or is
   that a separate deploy-operator task? The brief currently ships the query **unrun** (this worktree
   has no staging access, and staging PG is not on AX42).
