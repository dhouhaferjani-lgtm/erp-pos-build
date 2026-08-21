# Codex micro-lane dispatch: **device Z/X/EOD SALE-branch gross-as-net VAT decomposition** (2026-08-18, dispatched 2026-08-21)

> **GATED + DISPATCHED 2026-08-21.** Round-0 precheck passed (all three defect sites live at
> `c6d6308ae`); adversarial brief gate (Codex Sol, record in the session scratchpad) returned 6
> findings, ALL applied in this revision: (1) M1 ruling channel restructured — see R-1/M1; (2) the
> progress YAML now exists at `docs/handoff/progress/z-sale-branch-decomposition.progress.yaml`;
> (3) `scripts/adversarial-review.sh` verdict parse hardened (exact final-line match, fail-closed);
> (4) real-writer red-first moved into M2, masking fixtures enumerated; (5) stale metadata resolved
> (LEDGER row **C-2** exists — this lane closes it); (6) verification uses the live
> `pnpm lint:ratchet` gate, not raw lint.

**Executor:** an Opus implementation agent under the parent orchestrator (owner directive 2026-08-21:
Opus implements, Codex Sol/specialist agents gate). Workhorse mode: implement end-to-end, TDD red-first.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF via
> `scripts/adversarial-review.sh`, read its register, and loop scoped fix rounds until ACCEPT.
> - **Track state in `docs/handoff/progress/z-sale-branch-decomposition.progress.yaml`** — read it
>   first, update after every milestone. It is the resume point if you crash.
> - The bridge call, once per milestone:
>   ```
>   scripts/adversarial-review.sh \
>     --brief   docs/handoff/CODEX-DISPATCH-z-sale-branch-decomposition-2026-08-18.md \
>     --milestone M<n> \
>     --lenses  "<comma list from the milestone's review_lenses>" \
>     --range   <base_sha>..HEAD \
>     --out     docs/handoff/reviews/z-sale-branch-decomposition/M<n>-round<r>.md \
>     --round   <r>
>   ```
>   Exit **0 = ACCEPT** · **2 = CHANGES-REQUIRED** · **3 = tool error → CHANGES-REQUIRED (fail closed)**.
> - **STOP conditions:** (A) fix rounds exhausted (`max_fix_rounds: 5`) → `blocked_review`;
>   (B) an owner/reviewer gate → `blocked_owner`; (C) architecture contradiction →
>   `blocked_architecture`. **This lane has a REAL gate at M1 that WILL fire** (§M1) — it is not
>   hypothetical, and the wave is designed to stop there and wait.
> - **No clock.** Do not call `date`. Use `git rev-parse --short HEAD` as the `updated:` marker.
> - Branch **NOT merged, NOT pushed.**

---

## 0. Read before starting (in this order)

| # | Document | Why |
|---|---|---|
| 1 | `docs/superpowers/tickets/2026-08-01-device-z-sale-branch-gross-as-net.md` | **The ticket of record — read it FIRST; it prescribes this lane's shape**: own micro-lane, fiscal-pos-reviewer gate, sealing/versioning decision, real-writer end-to-end fixtures for the sale path. |
| 2 | `docs/superpowers/reviews/2026-08-01-lane-c-wave2-consolidated-findings.md` **finding 4** | The corrected refund-side pattern this lane mirrors, and the fixture-masking failure mode it names. |
| 3 | The three defect sites, read whole (§SCOPE) | `apps/pos/src/lib/offline/zReportService.ts`, `apps/pos/src/lib/offline/endOfDayPreview.ts`, `apps/pos/src/api/reportApi.ts`. |
| 4 | `apps/pos/src/lib/offline/__tests__/refundReportingEndToEnd.test.ts` | The **REAL-WRITER** fixture pattern the sale-path tests must mirror. |
| 5 | `docs/handoff/LEDGER.md` rows **D-1** and **D-3** | The device build stack this fix rides. |
| 6 | `CLAUDE.md` rules 1–21, especially **4**, **8** (events immutable forever — the crux of M1), **11**, **17**, **19**, **20** | Non-negotiable house rules. |

---

## ⛔ HARD PREREQUISITES

### 1. PIN THE BASE SHA

```
git -C <repo> fetch origin dev
git -C <repo> rev-parse origin/dev            # → BASE_SHA
git -C <repo> log -1 --format='%H %ci %s' origin/dev   # → paste verbatim into M0
git -C <repo> status --porcelain              # → MUST be empty
```

`base_sha` = the LOCAL dev tip at worktree creation (`git rev-parse dev` — the commit carrying this gated brief + progress YAML; per the harness, the EXECUTOR records it in the YAML at M0; origin/dev `c6d6308ae` is in its first-parent chain). Do not reuse a stale pin.

**M0 base check (all three):**
1. `git merge-base --is-ancestor <base_sha> HEAD` passes.
2. `git diff --stat <base_sha>..HEAD` is empty at branch creation.
3. **The asymmetry is still present** at `HEAD` in all three files (§SCOPE table). If any site is
   already fixed: `status: blocked_precondition`, record which, STOP.

### 2. WORKTREE

Dedicated worktree **`.worktrees/z-sale-decomposition`**, branch
**`codex/z-sale-branch-decomposition-2026-08-18`**, from `BASE_SHA`.
**Never `git stash`** (repo-global stack). Do not push; do not merge.

**Commit series:** `z-decomp M<m>.<s>:` (assigned at dispatch 2026-08-21).

---

## 🎯 SCOPE — the defect, verified in code 2026-08-18

The POS cart's `unit_price` is **tax-INCLUSIVE** and `cartStore.recalcLineTotal()` derives
`line_total = unit_price × qty − discount`, then **extracts** `tax_amount` out of that figure
(`apps/pos/src/stores/cartStore.ts:175-201`). So **`lines[].line_total` is GROSS/TTC** — on a sale row
exactly as on a refund row. `receiptService.ts` copies both cart values verbatim onto the SQLite row.

The refund branches were corrected by the Lane C wave-2 fix; **the sale branches were not.** There are
**THREE** structurally separate consumers of `offline_receipts.lines[]`, not two — `generateLocalXReport`
does **not** share `aggregateReportData`'s loop, it carries its own third copy:

| # | File | Refund branch (CORRECT) | Sale branch (**DEFECT**) |
|---|---|---|---|
| 1 | `apps/pos/src/lib/offline/zReportService.ts` (`aggregateReportData`, `:803`) | `:864-876` — `lineGross = bcabs(line_total)`, `lineNet = bcsub(gross, vat)`, with the explanatory comment at `:850-863` | **`:903-916`** — `lineNet = line.line_total` (which IS gross), then `lineGross = bcadd(lineNet, lineVat)` |
| 2 | `apps/pos/src/lib/offline/endOfDayPreview.ts` | `:284-295` — same corrected derivation; the comment at `:263-278` explicitly states `line_total` is GROSS *"(as on a sale row)"* and that the sale branch was left alone as out-of-wave scope | **`:297-304`** — identical gross-as-net error |
| 3 | `apps/pos/src/api/reportApi.ts` (`generateLocalXReport`, `:392`) | `:459-470` — corrected, comment at `:426-442` | **`:488-498`** — identical gross-as-net error |

*(Line numbers are evidence as of 2026-08-18, **not addresses**. Re-derive every one at `BASE_SHA` and
cite the semantic anchor.)*

**What is WRONG:** the per-rate `vatByRate` decomposition inside signed `Z_REPORT` / `X_REPORT` events —
net overstated by the VAT amount per taxed line, gross overstated by the same.
**What must stay OUT OF SCOPE:** the headline totals. `gross_sales` / `net_sales` / `tax_amount`
come from `receipt.total` / `receipt.subtotal` / `receipt.tax_amount` (`zReportService.ts:898-901`).
**Do not touch the headline aggregation** — this is a per-rate decomposition fix and nothing else.

> 🚨 **ANNOTATION (ordered by the M1 ruling, `M1-ruling.md` §"Sub-item rulings" F-2).** The
> original justification for that instruction — *"which the writer already stores correctly"* — is
> **FALSE**, and the ruling requires it to be annotated rather than silently corrected.
> `cartTotals.ts:36` defines `subtotal = Σ line_total`, and `line_total` is **GROSS**
> (docblock `:7`); with no transaction discount `total === subtotal` exactly (`:40`, `:52`, `:66`);
> `receiptService.ts:145-158` sums the same into the stored `subtotal` (`:547`). Therefore signed
> **`net_sales` equals `gross_sales`** on every taxed shift — the F-2 defect, matching audit R-01's
> "unnamed 5th defect" (`docs/superpowers/audits/2026-08-05-production-v1-readiness.md:185`).
> The instruction to leave it alone **still stands** (ruling condition 4: F-2 is a sibling lane
> bound to the same device build), but it stands as a **scope boundary, not a correctness claim**.
> Consequence the executor must not paper over: with this lane's fix and F-2 unfixed, a sealed Z
> has `Σ vat_breakdown.net_amount ≠ net_sales` by the shift's full VAT — the identity inversion the
> ruling accepted knowingly.

**The live symptom** (accepted interim asymmetry, orchestrator ruling 2026-08-01): a fully-refunded
taxed sale leaves a **+VAT / −0 residue** in the Z buckets instead of netting to zero — e.g. +2.00 net /
+2.00 VAT on a 12.00-gross / 2.00-VAT line.

### 🚫 NOT IN SCOPE

| Area | Why |
|---|---|
| Headline `gross_sales` / `net_sales` / `tax_amount` aggregation | **Out of scope — but NOT because it is correct** (that justification is FALSE; see the annotation in §SCOPE). It is a separate defect, **F-2**, ruled into a sibling lane bound to the same device build (M1 ruling condition 4). Changing it here would be scope creep, not a correction. |
| The **refund** branches in all three files | Already fixed. **Do not "tidy" them** — they are the reference pattern. |
| `receiptService.ts` / `cartStore.ts` writer semantics | The writer is correct; the readers are wrong. Changing `line_total` semantics would break the whole precision contract. |
| Server-side Z/X report projections and any `apps/api/**` change | The defect is device-local. If a server consumer of the per-rate breakdown is found, **record it** (it is a downstream finding, likely a separate lane). |
| Backfill of already-signed `Z_REPORT` events | Rule 8: immutable forever. Superseding is the only lever, and that is the M1 gate's subject. |
| Any other Z/X field, layout or copy | Not the defect. |

---

## 🧷 RIDERS — six binding items

### R-1 🚨 THE SIGNED-BYTES DECISION IS A GATE, NOT A CHOICE YOU MAKE
The fix **changes the bytes of signed `Z_REPORT` / `X_REPORT` events for ordinary sale-only shifts.**
That is a fiscal-sealing change under rule 8, and the ticket assigns it a **fiscal-pos-reviewer gate**.

**M1 exists to present the decision and STOP.** You do **not** pick an option. You produce a decision
memo with both options fully argued, then set `status: blocked_review` and end the run until the gate
rules. Resuming without a recorded ruling is a harness violation.

**The two options, both of which the memo must argue honestly:**

| | **Option A — new `event_version`** | **Option B — in-place semantic correction** |
|---|---|---|
| Shape | Bump the Z/X payload `event_version`; old events keep the old (wrong) decomposition semantics, new events carry the corrected one; the version is the discriminator a verifier reads. | Correct the computation in place at the current version; the change is treated as a bug fix to a derived field, with no version discriminator. |
| Cost | A version bump touches the payload registry, the server-side validator/parser, verification tooling, and any consumer that branches on version. It is the honest shape when signed history exists that must stay interpretable. | Zero migration surface. Dishonest **if** signed history exists — two different meanings for one version, and nothing in the bytes says which. |
| Where it is decided | The fiscal-pos gate. | The fiscal-pos gate. |

**The tenant-#1 cheap-window argument (present it; do not treat it as the answer):** the first real
tenant has **no signed Z/X history yet**. If this correction ships in the device build **before that
tenant's first shift**, there is nothing to backfill and nothing to reinterpret *for them* — which makes
Option B materially cheaper **for that tenant only**. The memo must state plainly what this argument
does **not** cover: existing **staging** history, any demo/pilot device that has already closed shifts,
and any tenant onboarded on a pre-fix build. The gate weighs those; you do not.

**Deliverable shape for the memo:** a file under the wave's review directory naming, for each option —
the exact files/symbols it would touch, the consumers that branch on version, whether any signed
history exists at `BASE_SHA` (**verify — enumerate the evidence**, do not assume), and the reversal cost
if the gate later changes its mind.

### R-2 MIRROR THE REFUND PATTERN — do not invent a third derivation
The corrected pattern is exactly: **`lineGross` comes from `line_total`; `lineNet = lineGross − lineVat`;
`lineVat` comes from `tax_amount`.** Apply it to each sale branch with the sign/`bcabs` treatment
appropriate to that branch (the sale branch is positive-signed and must **not** acquire `bcabs`, which
would silently swallow a legitimately negative row).

**The three fixes must be structurally identical to each other.** A reviewer reading all three side by
side should see one pattern applied three times, not three interpretations.

### R-3 THE SCALE ARGUMENT IS PART OF THE FIX (rule 19)
The refund branches pass the currency scale explicitly (`bcsub(gross, vat, decimals)` /
`bcadd(..., scale)`); the **sale branches call `bcadd`/`bcsub` with no scale argument**, silently taking
`decimal.ts`'s **default of 3** (`apps/pos/src/lib/decimal.ts:22-28`). On a scale-0 or scale-2 currency
that is a latent precision drift on the same lines you are already touching.

**Pass the currency scale explicitly in every arithmetic call inside the branches you fix**, matching the
refund side. This is in scope precisely because it is the same expressions. Do **not** go scale-hunting
elsewhere in the files (rule 4) — record anything you spot and continue.

### R-4 🚨 THE EXISTING SALE FIXTURES ARE WRONG AND MUST BE CORRECTED, NOT WORKED AROUND
The ticket is explicit: *"Existing sale fixtures author `line_total` as NET — they mask the bug by not
matching the real writer."* This is the same masking pattern the refund-side finding called out.

- **Every sale fixture you rely on must be corrected to match the real writer**, i.e. `line_total` =
  gross/TTC, with `tax_amount` extracted out of it, exactly as `cartStore.recalcLineTotal()` produces.
- **Correcting a fixture is a red-flag operation** — it is indistinguishable, in a diff, from weakening a
  test to fit the code. Therefore: **name every fixture you correct, explicitly, in the milestone
  report**, with the old value → new value and the writer expression that justifies it. A silently
  corrected fixture fails the gate.
- Where a corrected fixture makes an *existing* assertion go red **by design**, say so explicitly and
  show that the new expected value is derived from the writer, not from the current output.

### R-5 REAL-WRITER END-TO-END FIXTURES ARE THE ACCEPTANCE — mirror `refundReportingEndToEnd.test.ts`
The proof is **not** a unit test over a hand-authored `lines` JSON. The ticket requires **real-writer
end-to-end** coverage for the sale path: drive the actual writer (cart → `receiptService` → SQLite row)
and then read it back through each of the three consumers, asserting the per-rate decomposition.

- Mirror the structure of `apps/pos/src/lib/offline/__tests__/refundReportingEndToEnd.test.ts`.
- Cover **all three** consumers — Z (`zReportService`), EOD preview (`endOfDayPreview`), and X
  (`generateLocalXReport` in `apps/pos/src/api/reportApi.ts`).
- Cover the **mixed-rate** case (two rates on one receipt) and the **sale + refund in one shift** case,
  which is where the interim asymmetry is observable: after the fix, a fully-refunded taxed sale must net
  the per-rate buckets to **zero**.
- **SQLite TEXT-timestamp trap (rule 20):** any date/shift boundary bound into a SQL comparison against
  a `datetime('now')` column must go through `apps/pos/src/lib/db/sqliteTime.ts` `toSqliteUtc()` —
  never an ISO-8601 `toISOString()` value, which silently excludes same-day rows.
- **Watch for vitest zombie worker pools** after a hang (`ps aux | grep 'node (vitest'`) — they survive
  a parent kill and will OOM the machine. Run scoped by path.

### R-6 THIS RIDES THE DEVICE BUILD STACK
LEDGER **D-1**: the cumulative device build stack is v60/61 (replenishment) → v62 (UoM) → v63 (cash
rounding) → v66 (Lane C) → v67 (subject to owner ruling **O-22**). LEDGER **D-3**: the SV-11 device
build must deploy fleet-wide **before** the SV-9 migration reaches any `origin/dev` promotion.

**Consequences you must record (not act on):** this fix is device-side and therefore **only reaches a
terminal via a device build**. The handback must state which build number this rides and what the
sequencing obligation is relative to D-1/D-3 — and must **not** assign a build number itself (that is the
device/deploy operator's call, and O-22 is open).

---

## 🏗 MILESTONE STRUCTURE

```
M0  preflight — base pin, defect present in all 3 sites, citation inventory   [setup only]
 │
 └─► M1  🚨 SIGNED-BYTES DECISION MEMO — present both options, then STOP      [fiscal-pos]
      │       (gate REQUIRED; wave halts at blocked_review until it rules)
      │
      └─► M2  the three sale-branch fixes + scale arguments (R-2, R-3)        [fiscal-pos, general]
           │
           └─► M3  real-writer end-to-end fixtures + fixture corrections      [fiscal-pos, general]
                │       (R-4, R-5)
                │
                └─► M4  WHOLE-LANE GATE over the integrated branch            [fiscal-pos, general]
```

### M0 — Preflight (no production code)

1. Base pin + the three-part base check. Worktree + branch created.
2. **Citation inventory**: every `file:line` in this brief re-derived at `BASE_SHA` →
   `brief:line → actual:line` + symbol + semantic anchor. **M0 FAILS if any is unresolved.**
3. **Confirm the third site.** This brief asserts `generateLocalXReport` carries its **own** copy of the
   loop rather than sharing `aggregateReportData`. Verify that independently and record the answer — if
   it turns out to share, the scope shrinks to two sites and you say so.
4. **Declare the regression set**: `apps/pos/src/lib/offline/__tests__/`, and any other suite the three
   files are covered by (re-enumerate — do not trust a list). Tests run **BY PATH**.

### M1 — 🚨 The signed-bytes decision memo (NO production code; ends in a STOP)

Per **R-1**. Produce
`docs/handoff/reviews/z-sale-branch-decomposition/M1-signed-bytes-decision.md` containing:

1. **The exact byte-level consequence**: which fields of which signed events change, for which shifts,
   and what a verifier comparing an old and a new event would see.
2. **Option A** and **Option B**, each with: the files/symbols touched, the consumers that branch on
   `event_version` (device registry, server parser/validator, verification tooling — **enumerate them**),
   and the reversal cost.
3. **The evidence on existing signed history**: does any signed `Z_REPORT`/`X_REPORT` history exist that
   this change would reinterpret? Enumerate what you can verify from the repo (fixtures, seeders,
   migrations, staging-facing docs) and state plainly what you **cannot** verify from here (live
   staging/device data — you have no staging access from this worktree).
4. **The tenant-#1 cheap-window argument**, stated with its limits (R-1).
5. **A recommendation** — you may recommend; you may **not** decide.

Then: run the M1 review with the **fiscal-pos** lens — **this bridge review gates the MEMO'S QUALITY
only** (both options concretely costed, consumers enumerated, history evidence real); it does **not**
and **cannot** emit the A/B ruling (its verdict vocabulary is ACCEPT/CHANGES-REQUIRED only —
2026-08-21 brief-gate finding 1). Once the memo review is ACCEPT, set milestone
`status: blocked_review`, write the question verbatim into `blockers:`, leave the tree clean, and
**END THE RUN**.

**The ruling channel (restructured 2026-08-21):** the PARENT orchestrator dispatches the
`fiscal-pos-reviewer` specialist gate over the committed memo; THAT gate rules Option A vs Option B.
The parent records the ruling in the progress YAML (`owner_gates:` entry `z-signed-bytes-versioning`:
`chosen_option`, `ruled_by`, `record` path) and files it as an owner-review item (same pattern as the
P3-M1 R-4 parent ruling under the owner's standing delegation).

**Resume condition:** that recorded ruling in the YAML. M2 implements **exactly** the ruled option.
Resuming without it is a harness violation.

### M2 — The three sale-branch fixes

Red first, per site — and the red MUST be a **real-writer** red (2026-08-21 brief-gate finding 4: a
red written against a hand-authored `lines` JSON proves nothing, because the existing sale fixtures
author `line_total` as NET and mask the bug — confirmed at
`apps/pos/src/lib/offline/__tests__/zReportService.test.ts:104-124` (line_total `42.00` = subtotal on
a 50.00/8.00 receipt) and `apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts:24-51`
(line_total `8.40` = net). Re-derive both citations at BASE_SHA; enumerate any further masking sale
fixtures you find in the fixture-correction register (R-4)):
1. **Before each fix commit**: at least one failing real-writer assertion per consumer (cart →
   `receiptService` → SQLite row → read back through that consumer), showing the per-rate net inflated
   by the VAT amount. Paste the actual red output. (M3 completes the matrix; the red-first proof lives
   HERE.)
2. Apply the refund pattern (**R-2**) plus explicit scale arguments (**R-3**).
3. Green. **Revert-replay** each fix commit.
4. If the M1 ruling was **Option A**, the version bump lands here too, in the same milestone, with its
   registry/parser/consumer updates — and the "old events keep old semantics" behaviour gets its own
   test.

**The three diffs must be structurally identical** (R-2) — a reviewer will read them side by side.

### M3 — Real-writer end-to-end fixtures + the fixture corrections

Per **R-4** and **R-5**. Deliver:
- The corrected sale fixtures, **each named** with old → new values and the writer expression that
  justifies the change.
- The real-writer end-to-end tests for the sale path across all three consumers, including the
  mixed-rate case and the sale-plus-refund case that must net to zero.
- Rendered/derived-output assertions on values, never on CSS classes or i18n keys (rule 17).

**No user-facing strings are expected to change** in this lane. If one does, en + fr are mandatory on
the device (`apps/pos` has **no `ar` tree** — do not create one; record the gap) — and that is a signal
you have left scope: check.

### M4 — Whole-lane gate

Re-run the full accumulated evidence over the integrated branch with every lens used
(`fiscal-pos`, `general`). Paste `git diff --name-only <BASE_SHA>..HEAD` and read it. Prove the
negatives: no `apps/api/**` change (unless Option A's server-side parser explicitly required it — then
name it), no headline-aggregation change, no refund-branch change beyond the scale argument, no
backfill of signed events. Record the **R-6** device-build sequencing obligation.

---

## 📋 PER-MILESTONE EVIDENCE CONTRACT

| Milestone | Code evidence | Artifact evidence | The evidence that is easy to fake, and its antidote |
|---|---|---|---|
| **M0** | none | base check pasted; citation inventory, **unresolved = 0**; the third-site confirmation | "The line numbers matched." **Antidote:** the reviewer re-derives two of its choosing and confirms the semantic anchor, not the number. |
| **M1** | none | the decision memo with both options fully costed and the consumer enumeration | A memo that argues for one option and sketches the other. **Antidote:** both options must list **concrete files/symbols**; a one-line "Option A would be expensive" fails the gate. |
| **M2** | red output pasted per site, then green; revert-replay per commit | the three diffs shown together | A fix that passes because the test was written against the new code. **Antidote:** the red output is pasted **before** the fix commit, and the expected value is derived from the writer expression, shown arithmetically. |
| **M3** | real-writer end-to-end tests across all three consumers; mixed-rate + net-to-zero cases | **every corrected fixture named**, old → new, with justification | A fixture "corrected" to match the code. **Antidote:** each correction cites `cartStore.recalcLineTotal()` and shows the gross/net/VAT arithmetic; the net-to-zero case is the independent check that the fixture is right. |
| **M4** | full re-run; `git diff --name-only` pasted | the negatives; the D-1/D-3 sequencing note | "Nothing else was touched." **Antidote:** the file list is pasted and read. |

---

## 📏 HOUSE RULES (binding, every milestone)

- **TDD red-first per task**; **revert-replay** every behavioural fix commit. R-4's by-design red
  fixtures are the declared exception — declared, never silent.
- **Tests BY PATH only.** Frontend: `pnpm test` scoped by path; kill vitest zombie worker pools after a
  hang.
- **Rule 19 precision.** No `parseFloat` / `Number(...)` on money; money as strings; explicit currency
  scale on every arithmetic call in the branches you touch (**R-3**).
- **Rule 20** POS cross-layer contracts: SQLite TEXT timestamps via `toSqliteUtc()`; device-authored
  shift fields merged, never replaced.
- **Rule 8 events immutable forever** — the whole point of the M1 gate. No event is renamed or deleted;
  if Option A is ruled, a version is **added**.
- **Rule 11 i18n** if any string surfaces: en + fr on the device; **do not** create `apps/pos/src/locales/ar/`.
- **Rule 17**: assert on rendered/derived output, never on CSS class names.
- **Rule 4 no scope creep** — three sale branches and their scale arguments. Note the rest, continue.
- **No `any` in TypeScript**; `pnpm typecheck` clean, and **`pnpm lint:ratchet` exactly as CI's
  `frontend-lint` job invokes it** (`.github/workflows/ci.yml:1189-1195`) — the warning ratchet
  (`@autoerp/pos` baseline) is the live gate; raw `pnpm lint` green is NOT sufficient evidence
  (2026-08-21 brief-gate finding 6). Zero warning growth.
- Dedicated worktree; **never `git stash`**; **do not push**; **do not merge**.

---

## 🚦 REVIEWER GATES

| Milestone | Lenses | What the gate is really asking |
|---|---|---|
| M0 | *(none — setup only)* | — |
| **M1** | **fiscal-pos (MANDATORY per the ticket)** | Is the byte-level consequence stated precisely? Are **both** options costed with concrete files/symbols? Is the tenant-#1 argument presented with its limits rather than as a conclusion? **The bridge review gates the memo's quality; the A/B RULING comes from the parent-dispatched `fiscal-pos-reviewer` specialist gate and is recorded in the YAML (see M1 ruling channel).** |
| M2 | fiscal-pos, general | Is the refund pattern mirrored exactly, three times identically? Are scale arguments explicit? Was the headline aggregation left alone? Does the implementation match the **ruled** option? |
| M3 | fiscal-pos, general | Are the fixtures corrected to the **real writer**, each named and justified? Do the end-to-end tests cover all three consumers? Does the sale-plus-refund case net to zero? |
| M4 | fiscal-pos, general | Whole-branch: nothing outside the three sites, no signed-event backfill, sequencing obligation recorded. |

Every **P1** finding must be closed or explicitly ruled before the milestone passes; **P2**
close-before-merge; **P3** may ship with a ticket. A review with no parseable `VERDICT:` line is a
**tool error → CHANGES-REQUIRED**, never a pass.

---

## 🔒 OWNER / REVIEWER GATES

| id | Question | Blocks | Status |
|---|---|---|---|
| `z-signed-bytes-versioning` | New `event_version` vs. in-place semantic correction for the corrected Z/X per-rate decomposition. **Ruled by the parent-dispatched `fiscal-pos-reviewer` specialist gate at M1** (memo-informed, tenant-#1 cheap-window argument presented with limits); parent records the ruling in the YAML and files it as an owner-review item. | **M2, M3, M4** | **REQUIRED — the wave STOPS at `blocked_review` until the ruling is recorded in the YAML** |
| `z-device-build-number` | Which device build carries this fix, and its ordering against the v60-67 cumulative stack (LEDGER D-1) and the D-3 SV-11-before-SV-9 obligation. | none | standing constraint — **record**, never assign |

---

## 📦 DELIVERABLE — handback only

**One branch, NOT merged, NOT pushed.**

1. **`codex/z-sale-branch-decomposition-2026-08-18`** in worktree **`.worktrees/z-sale-decomposition`**.
2. **`docs/handoff/progress/z-sale-branch-decomposition.progress.yaml`** complete and truthful —
   including the M1 `blocked_review` state and, after resumption, the recorded ruling.
3. **Review records** under `docs/handoff/reviews/z-sale-branch-decomposition/` — one per milestone per
   round, **plus M1's decision memo**.
4. **A session report** at `docs/sessions/codex-z-sale-branch-decomposition-report.md`: per milestone —
   files touched, tests + commands + **actual output**, decisions, deviations, concerns; plus **the
   fixture-correction register** (R-4), the **ruled option and its source**, the **D-1/D-3 sequencing
   note** (R-6), and the **evidence line for closing LEDGER row C-2** (the parent closes the row at
   merge — do not edit LEDGER.md yourself).

---

## ❓ OPEN AT DISPATCH — ALL RESOLVED 2026-08-21

1. **`base_sha`** = `c6d6308ae` (origin/dev == local dev tip at dispatch).
2. **Commit series prefix** = `z-decomp M<m>.<s>:`.
3. **LEDGER row**: row **C-2** in `docs/handoff/LEDGER.md` now records this defect. The lane closes a
   tracked owe; the parent flips C-2 to CLOSED at merge with the lane's evidence line.
4. **M1 ruling authority**: the parent-dispatched `fiscal-pos-reviewer` specialist gate rules
   autonomously (per the ticket's assignment), recorded in the YAML by the parent and filed as an
   owner-review item under the owner's standing delegation (P3-M1 R-4 precedent).
