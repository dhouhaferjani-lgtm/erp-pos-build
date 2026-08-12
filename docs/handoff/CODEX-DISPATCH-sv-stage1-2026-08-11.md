# Codex A→Z dispatch — Shift-variance dossier, **Stage 1** (SV-1 · SV-9 · SV-10 · SV-11) (2026-08-11)

**Model/effort (owner directive):** Codex SOL 5.6, HIGH effort. Workhorse mode: implement end-to-end,
TDD red-first.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF by
> invoking Opus through the CLI bridge `scripts/adversarial-review.sh` (it calls
> `claude -p --model opus`), read its register, and loop scoped fix rounds until ACCEPT — then move on.
> - **Track state in `docs/handoff/progress/sv-stage1.progress.yaml`** — read it first, update it after
>   every milestone (status, commit SHA, verdict path, fix_rounds). It is the resume point if you crash.
> - The bridge call, once per milestone (lenses come from that milestone's `review_lenses`):
>   ```
>   scripts/adversarial-review.sh \
>     --brief   docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md \
>     --milestone M<n> \
>     --lenses  "<comma list from the milestone's review_lenses>" \
>     --range   <base_sha>..HEAD \
>     --out     docs/handoff/reviews/sv-stage1/M<n>-round<r>.md \
>     --round   <r>
>   ```
>   Exit **0 = ACCEPT** · **2 = CHANGES-REQUIRED** · **3 = tool error → treat as CHANGES-REQUIRED
>   (fail closed; never proceed on a tool error)**. Record the `--out` path in the milestone's
>   `verdict:` and the exit result in `last_verdict:`.
> - **Resume semantics:** a fresh session resumes by reading the YAML + the newest register under
>   `docs/handoff/reviews/sv-stage1/`. Never re-run a milestone whose `status: passed`.
> - **STOP and escalate only at the three harness STOP conditions:** (A) fix rounds exhausted
>   (`max_fix_rounds: 5`) → `blocked_review`; (B) an owner gate — here **only** the SV-9 prerequisite
>   check at M3, and **only if it actually fires** (see the CRITICAL SEQUENCING GUARD: a touch point
>   genuinely requiring G-3 or a Stage-5 policy gate) → `blocked_owner`; (C) an architecture
>   contradiction → `blocked_architecture`. Set the YAML `status` + `blockers` and end your run.
> - **The device-Arabic question at M2 is NOT a stop.** It is an **informational** record: M2 ships
>   `en` + `fr`, files the ticket, and **proceeds**. Nothing in this wave blocks on it.
> - **Gate wiring, deliberate:** no milestone in the YAML carries an `owner_gate:` field. The harness
>   reads that field as an **unconditional** STOP (harness step 4 / condition B), and both gates here are
>   *conditional or informational*. The `owner_gates:` list is an **informational record**
>   (`blocks_milestone: none`); the conditional logic lives in the milestone text — trust the milestone
>   text, and stop only when its stated condition actually fires.
> - **No clock.** Do not call `date`. Use `git rev-parse --short HEAD` as the `updated:` marker.
> - Per-milestone registers go to `docs/handoff/reviews/sv-stage1/`. Branch NOT merged, NOT pushed.

**Revision 2 (2026-08-11).** Brief-gate round 1 fixes, per
`docs/superpowers/reviews/2026-08-11-es-briefs-gate-r1.md` (verdict CHANGES-REQUIRED, 9 findings):
the harness gate wiring (no milestone carries an `owner_gate:` field — the ar-locale gate is
**informational only** and never stops M2; the SV-9 prerequisite stops M3 only if the check actually
fires), `treasury` added to **M5's lens set** (it gated M1), the **device-Arabic ticket path and
minimum contents** pinned, and **SV-11's line-2 Stage-1 rendering contract** stated (cash-sales row
only — no rounding placeholder, no reserved DOM slot).

**Revision 1 (2026-08-11).** First dispatch of this lane. Stage 1 exists precisely because it is
**independent**: it ships on its own, blocks nothing, and must not be delayed by the GL-flag flip
condition — *nor may the flip be used to delay it* (dossier §3, flip condition closing paragraph).

---

## 0. Read before starting (in this order)

| # | Document | Why |
|---|---|---|
| 1 | `docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md` | **The contract.** §1 binding rulings · §2 rows **SV-1 / SV-9 / SV-10 / SV-11 in full** · §3 **Stage 1** and the flip condition · §4 interactions · §5 verification contract (§5.1 rows for SV-1/9/10/11). |
| 2 | `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md` | §4 non-negotiables and **§7 what this session must NOT do** — including the `require_blind_cash_count` clause that the CRITICAL SEQUENCING GUARD below turns into a mechanical check. |
| 3 | `docs/handoff/OWNER-QUESTIONS-es-remediation-2026-08-11.md` | The owner sheet. Nothing in Stage 1 is gated on it *by design* — if you land on an item there, STOP. |
| 4 | `CLAUDE.md` rules 1–21 (esp. **4** no scope creep, **11** no hardcoded strings, **17** test rendered HTML not CSS classes, **18** design tokens, **19** precision, **20** POS cross-layer contracts, **21** branch discipline) + `docs/conventions/` | Non-negotiable house rules. |

**Do NOT read** `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/` or
`docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/` as the contract — they are untracked working records
(`.gitignore:58`) and are cited by the dossier for provenance. The dossier is the contract; if you need
a number from a research file, the dossier already carries it.

---

## ⛔ HARD PREREQUISITES

### 1. VERIFY THE CONTRACT

The sibling ES program's contract-of-record is a hash-verified snapshot, and this dossier cites it as a
sibling. Prove your tree is the gated tree before writing code — run, at the pinned base:

```
git show <BASE_SHA>:docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md | tail -n +63 | shasum -a 256
# expected: 04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540
```

Mismatch ⇒ `status: blocked_precondition`, observed digest into `blockers:`, STOP. Never edit the
snapshot; corrections live only in `ES-REGISTER-CORRECTIONS-2026-08-11.md`.

### 2. PIN THE BASE SHA

```
git -C <repo> rev-parse --verify dev            # → BASE_SHA
git -C <repo> log -1 --format='%H %ci %s' dev   # → paste verbatim
git -C <repo> status --porcelain                # → your worktree MUST be clean
```

`BASE_SHA` goes into the YAML, M0's report header, and the session report.

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

Dedicated worktree **`.worktrees/sv-stage1`**, branch **`codex/sv-stage1`**, created from `BASE_SHA` off
**local `dev`**. **Never `git stash`** (repo-global stack). Do not push; do not merge; do not commit in a
shared `dev` worktree.

---

## 🎯 SCOPE — four rows, and exactly four

| Row | What Stage 1 ships | Milestone |
|---|---|---|
| **SV-1** | Bury the takings-only formula; correct the **three** artefacts that cite it as policy | **M1** |
| **SV-11** | Count-screen copy — the exact strings the dossier specifies | **M2** |
| **SV-9** | Blind counting **ON everywhere** — 6 touch points + the data migration | **M3** |
| **SV-10** | Blind-mode leak audit (read-only audit; narrow in-scope fixes only) | **M4** |

**Order note.** The dossier's Stage-1 list puts SV-11 first and SV-1 third, but its own SV-1 text says
*"Do this **early** so nobody in the lane re-derives the dead premise mid-flight."* This brief therefore
runs **SV-1 first** — the two statements are reconciled in favour of the explicit instruction, and the
rows are independent, so nothing else moves.

### 🚫 NOT IN SCOPE — do not touch

| Area | Why |
|---|---|
| **SV-2 … SV-8, SV-12 … SV-18** (Stages 2–5) | Different stages, different prerequisites, different reviewers. Stage 1 is the independent slice. |
| **`TREASURY_SHIFT_VARIANCE_GL_ENABLED`** | Handover §7 forbids flipping it. Stage 5 item 16 owns it, behind the six-item flip condition. |
| **Lane A0 / A1 and any event-sourcing work** | The sibling wave `codex/es-wave-a0` owns `app/Modules/Fiscal/**` and `app/Modules/POS/Commands/**`. Emitting, retiring or rewiring **any** event is out of scope here. |
| `docs/sessions/`, `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md`, `docs/handoff/FINDINGS-other-problems-2026-08-11.md`, `scripts/dev-scan-stack.sh` | Owned elsewhere / audit records. |
| Any **GL posting shape** change | Dossier §4.4: *"unchanged by the ruling — do not redesign"* (accounts, direction, per-shift granularity all stay). |

---

## ✅ BINDING RULINGS (cite; do not re-open)

Dossier §1 — these are **decided**; you implement them, you do not re-litigate them.

| # | Ruling | Consequence for this wave |
|---|---|---|
| 1 | **Whole-drawer counting — CONFIRMED** (OD:70). *"Takings-only formula is dead code born of a missing float join; bury it and correct the config/docblock comments that describe it as policy."* | **M1's whole mandate.** Operationally (R15 §D): *"The cashier counts everything physically in the drawer. Variance = counted total − (opening float + net cash movements). Takings are derived by subtraction and displayed, never counted."* |
| 2 | **Blind counting — RULED ON everywhere** (OD:64, reaffirmed OD:70) — *everywhere* = **both verticals**, overriding the current automotive-only vertical default. | **M3's mandate** — subject to the CRITICAL SEQUENCING GUARD. |
| 4 | **Takings-only artefacts must be CORRECTED, not merely left in place.** | M1 is not "delete the function"; it is delete/annotate **and** rewrite the artefacts. |

**Not ruled (owner-owed, and NOT Stage 1's to answer):** float modelling shape (E-2/D-4), DEPOSIT/PAYOUT
GL typing (E-3/D-3), negative-till behaviour (E-4/D-5), `SAFE_DROP`-vs-`CASH_OUT` (SV-16/D-17),
Toast-style two-stage deposit reconciliation (E-5/D-7), TND thresholds + alert severity (E-8/D-6).
**If you find yourself needing any of these, you have left Stage 1.** STOP and report.

---

## 🧷 RIDERS — eight binding items

### R-1 THE GATE IS REAL; ITS STATED REASON IS NOT — that is the whole of SV-1
`ReportGenerationService::buildExpectedPerMethod()` computes expected cash as **takings only** —
`Σ pos_receipt_payments.amount − Σ change_due` per method, with **no `pos_shifts.opening_cash` and no
`pos_cash_drawer_operations` term**. Twenty lines up **in the same method**, the report's own
`expected_cash` comes from the **whole-drawer** `CashDrawerService::calculateExpectedCash()`. Two
formulas in one function, disagreeing by exactly the float, nothing reconciling them.
On 2026-08-08 a reviewer read the dead function, correctly observed the omission, and wrote the
"whole-drawer would post the float to 658/758 on every close" premise into **three artefacts**.
**The float-pollution risk is genuine but originates in SV-3 (the float is unbooked in Treasury), not in
this formula.** M1 corrects the *stated reason* in all three places; it does **not** remove the gate.

### R-2 SV-1's ARTEFACT LIST IS **THREE**, not two — the dossier's list is the list
| # | Artefact | Semantic anchor |
|---|---|---|
| 1 | `apps/api/config/treasury.php` | the `shift_variance_gl_enabled` comment block — the sentences naming `buildExpectedPerMethod()` and "sums receipt payments ONLY" as the pre-enable rationale |
| 2 | `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php` | the class docblock's **"SHIPS DISABLED"** section, which repeats the same premise |
| 3 | `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md` **§G-1** | the same premise, in the deploy notes |

*(Line citations in the dossier — `config/treasury.php:19-25`, listener `:44-52`, ticket `:53-58` — are
evidence, not addresses. **Re-derive them at `BASE_SHA` and cite the semantic anchor**; the config block
in particular does not start where the dossier's range implies.)*
**Each rewrite must state the real gate: the float and drawer ops are unbooked in Treasury (SV-3/SV-4),
and *that* is why the flag stays off.** Do not write "the formula was wrong" — the formula is dead; the
premise about *whole-drawer* was wrong.

### R-3 SV-1's RETIREMENT SHAPE IS A CHOICE WITH A PROOF OBLIGATION
The dossier offers **(a)** delete `buildExpectedPerMethod()` **with its branch**, **or** **(b)** annotate
it `@deprecated` with *"this is takings-only and has no client; production is whole-drawer via the
device"*. Either is acceptable. **The choice must be justified by an ENUMERATION, not a reading**
(dead-code-retirement contract, §5 class of the sibling program: **grep-proof + removal test**):
- **Grep-proof of consumers.** The archaeology's finding to re-verify, not to trust: the branch runs only
  when `cash_counts` is non-null (`ReportGenerationService.php:233-241`), `cash_counts` is nullable in
  `GenerateZReportRequest.php:61-68`, **both** clients send `terminal_id` only (web
  `apps/web/src/features/pos/api/shiftApi.ts:68-70`; device `apps/pos/src/api/reportApi.ts:246`, itself
  `@deprecated`), and the path is **doubly** unreachable since the v3 chokepoint —
  `generateZReport()` throws `ServerFiscalAuthoringRetiredException` for `fiscal_schema_version >= 3`
  (`ReportGenerationService.php:84-89`, guarded by `ServerReportAuthoringUnreachabilityTest` /
  `ZReportServerAuthoringChokepointTest`). **Its only exercisers are PHPUnit tests.**
  *Enumerate; never read.* Quote `--include='*.php'`, use `grep -E` for alternation, and read the line
  **after** each match — an empty grep is not proof until the syntax is verified.
- **Removal test.** If you delete: a test asserting the surface is gone and **nothing regressed** — and
  the tests that exercised it are part of the same diff, with the two chokepoint guards still green.
  If you annotate: a test that pins the annotation's claim (no production caller) so a fifth reader
  cannot re-derive the dead premise.
- **The historical note must SURVIVE either path** (dossier, R16 §1.3): the omission traces to one
  parenthesis in `apps/api/tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php:79-81` —
  *"opening cash is shift-level, not per-tender"* — accurate about the **schema**, false about the
  **business meaning**. The defect is a missing join, not a wrong doctrine. *"This is the fourth reader
  to reach it; the annotation exists to stop a fifth."* If the code that would carry the note is deleted,
  the note lands in artefact 3 (the ticket).
- **Scope fence:** ONLY what the dossier row names. The neighbouring live formula
  (`CashDrawerService::calculateExpectedCash()`) is **production and whole-drawer — do not touch it.**

### R-4 SV-11 IS **PRESENTATION ONLY** — every input already exists on the device
Dossier §2 SV-11: *"Every input to line 4 already exists on the device
(`apps/pos/src/lib/offline/endOfDayPreview.ts` computes each term) — **this is presentation only**."*
Do **not** compute, re-derive or re-aggregate a money figure in the UI. Rule 19 is in force on the
device too: **no `parseFloat` / `Number(...)` on money**; render through the POS money/quantity
formatters (`apps/pos/src/lib/quantity` / the existing currency formatter), and interpolate the float
**amount** as an already-formatted string.

### R-5 🚨 SV-11's ARABIC HALF — the device app HAS NO `ar` LOCALE
The dossier's §5.1 SV-11 row asks for **"en+fr+ar keys present"**, and the standing owner direction is
*"en/fr/ar run in parallel for cheap work (translation files etc.)"* (OD:76). **But the count screen is
the device app**, and at `BASE_SHA` `apps/pos` ships **only** `en` and `fr`: `apps/pos/src/locales/`
contains `en/` and `fr/` only, and `apps/pos/src/lib/i18n.ts` registers exactly those two languages
(`lng: 'en'`, `fallbackLng: 'en'`, four namespaces). Standing up an Arabic tree for the device is a new
locale + RTL surface — **not "cheap translation-file work", and not SV-11's scope.**
- **Ship `en` + `fr` in full** (the exact strings below), in parallel, through `t()` (rule 11 — no
  hardcoded strings).
- **Do NOT create `apps/pos/src/locales/ar/`** or register a new language in this wave.
- **Record the gap** as an owner gate (`sv11-arabic-device-locale`) **and** a ticket at
  **`docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md`**, and name it in the report.
  **Minimum ticket contents:** (i) there is **no `ar` tree** under `apps/pos/src/locales/` at
  `BASE_SHA` — only `en/` and `fr/`; (ii) `apps/pos/src/lib/i18n.ts` registers **exactly those two**
  languages (`lng: 'en'`, `fallbackLng: 'en'`, four namespaces); (iii) the **RTL implications** of
  standing up device Arabic (layout direction, the POS touch surfaces, numeric/currency rendering) —
  i.e. why this is a new surface rather than translation-file work; (iv) an explicit reference to the
  owner gate `sv11-arabic-device-locale` as the decision that opens or closes a device-Arabic lane.
- **THE ARABIC GATE NEVER STOPS THIS WAVE.** It is informational: ship `en` + `fr`, file the ticket,
  proceed. If a milestone finding tries to make Arabic a merge condition, that finding is **out of
  scope for Stage 1** — record it against the gate and continue; do **not** set `blocked_owner`.
- If the wave touches an **`apps/web`** string surface (M3 does: `locales/{en,fr}/compliance.json`),
  **`apps/web` DOES have `ar`** — there, en/fr/ar run in parallel as normal.

### R-6 🚨 SV-9's SEQUENCING — the dossier says Stage 1, the handover says "not without the owner's gate"
Two sources, both authoritative, and you must **check** rather than choose:
- **Dossier §3 Stage 1 item 2:** *"SV-9 + SV-10 — blind count ON everywhere + leak audit"*, in the stage
  that *"Ships independently, in parallel, blocks nothing"*; and the flip condition's closing line:
  *"Stage-1 items (SV-1, SV-9, SV-10, SV-11) are **NOT** preconditions for the flip and must not be used
  to delay it — nor may the flip be used to delay them."*
- **Handover §7 (must-not):** *"Enable `TREASURY_SHIFT_VARIANCE_GL_ENABLED`, **or flip
  `require_blind_cash_count` defaults**, without the owner's pre-enable gate sequence (backfill command
  first)."*

**The ruling "ON everywhere" and the Stage-1 placement are the dossier's — they stand.** What you must
NOT do is *assume* the row therefore has no prerequisites. **At M3, before the first code commit:**
1. Walk **each** of SV-9's six touch points and its data migration.
2. Ask, per touch point: does this genuinely require **G-3 (the backfill command)** or another
   **Stage-5 policy gate** as a prerequisite? Blind counting is a *count-screen behaviour* setting, not
   the GL flag — the honest expectation is **no**, and the check is what makes that honest.
3. If **any** touch point genuinely requires it: **STOP, `status: blocked_owner`**, name the touch point
   and the prerequisite in `blockers:` — do **not** proceed, do **not** work around, do **not** flip a
   default "narrowly".
4. Record the outcome of the check in M3's report **either way**. A silent pass is not a check.
**Under no circumstances does this wave touch `TREASURY_SHIFT_VARIANCE_GL_ENABLED`.**

### R-7 SV-9's DATA MIGRATION IS A STAGING AUTO-DEPLOY — self-guarding or nothing
`origin/dev` auto-deploys staging **including `tenants:migrate`**, so the migration will run unattended,
per tenant, on the first promotion after merge (handover §4.5; CLAUDE rule 21's companion). Therefore:
- **Idempotent and self-guarding**: `Schema::hasTable` / `hasColumn` guards, safe to re-run, no
  assumption that any seeder ran.
- **A `catch (Throwable)` inside `tenants:migrate` hits SQLSTATE 25P02 on PG** (aborted transaction ⇒
  connection unusable ⇒ the bookkeeping write fails anyway). If you need per-tenant tolerance, use a
  **connection-bound savepoint wrapper**, not a bare try/catch.
- **Silence ≠ success.** Emit a distinct completion token at **warning** level (production
  `LOG_LEVEL=warning` swallows `Log::info`), and print the per-tenant counts.
- **Scope it to what the row names:** rows already persisted `false` by the one-off 2026-04-25 seed
  (`database/migrations/tenant/2026_04_25_100001_seed_company_fraud_settings_for_existing_companies.php`).
  It is a **settings** migration — it must not touch shift, receipt or fiscal rows.

### R-8 SV-10 IS AN AUDIT FIRST — findings to a file, fixes only where the row reaches
Dossier §2 SV-10: the obvious leak **is already defended** —
`apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:239-262` suppresses the legacy expected-cash card
when the cash-count section is active, with an explicit SECURITY comment. **Not audited:** Toast
additionally suppresses its over/short **threshold warning** under blind mode *"as it contains cash
balance information"*, while AutoERP shows severity/reason prompts derived from the variance
(`CashReconciliationSection.tsx:137`, `:165-180`) — **none checked for magnitude disclosure pre-commit.**
- Deliver a **read-only audit** of the enumerated surfaces, findings written to a file under the wave's
  review directory (`docs/handoff/reviews/sv-stage1/`).
- **Fix only leaks that are in-scope per the dossier row** — i.e. a surface that discloses expected or
  variance **magnitude before Commit Counts** under blind mode. The §5.1 acceptance is exactly that.
- **Anything larger → a ticket + the report.** A redesign of the reconciliation section is not SV-10.
- **Do not weaken the existing defence** at `EndOfDayPreviewModal.tsx:239-262` while "tidying".

---

## 🏗 MILESTONE STRUCTURE

```
M0  preflight — contract verify, rulings read, no production code      [fiscal-pos]
 │
 └─► M1  SV-1  — bury the formula + correct the 3 artefacts            [fiscal-pos, treasury]
      │
      └─► M2  SV-11 — count-screen copy (en+fr, exact strings)         [fiscal-pos]
           │
           └─► M3  SV-9  — blind counting ON everywhere + migration    [fiscal-pos, frontend-conventions, tenancy-authz]
                │
                └─► M4  SV-10 — blind-mode leak audit (+ narrow fixes) [fiscal-pos, frontend-conventions]
                     │
                     └─► M5  WHOLE-LANE GATE over the integrated branch
                             [fiscal-pos, treasury, frontend-conventions, tenancy-authz]
```

### M0 — Preflight (no production code)

1. **Contract digest** (HARD PREREQUISITES §1); **the M0 base check** (HARD PREREQUISITES §2 —
   ancestor + digest + admin-only delta, NOT strict `HEAD == BASE_SHA` equality); clean tree;
   worktree + branch created.
2. **Read and record**: dossier **§1 binding rulings** (whole-drawer CONFIRMED · blind counting RULED ON
   everywhere · takings formula = dead code) and **§2 rows SV-1 / SV-9 / SV-10 / SV-11 in full**, plus
   §3 Stage 1 and §5.1's four rows. The artefact is a short citation inventory: for every `file:line`
   this brief and the dossier give for those four rows, `old:line → new:line` + **the symbol** + a
   **semantic anchor**. **M0 FAILS if any in-scope citation is unresolved.**
3. **Record the R-5 Arabic finding** (device locale set at `BASE_SHA`) and the **R-6 guard plan** —
   the six touch points listed, with the prerequisite question stated per point, to be *answered* at M3.
4. **Declare the regression set**: every suite directory the wave will touch (api Feature/Unit POS +
   Compliance + Treasury; `apps/pos` vitest; `apps/web` vitest). Tests run **by path**, always.

### M1 — SV-1: bury the takings-only formula, correct the artefacts

- The retirement itself, per **R-3** (delete-with-branch or `@deprecated` annotation), justified by the
  **grep-proof**, covered by the **removal test**, with the historical note preserved.
- The **three** artefact rewrites, per **R-2**, each stating the **real** gate (float + drawer ops
  unbooked — SV-3/SV-4), never the refuted whole-drawer premise.
- **Scope fence:** `CashDrawerService::calculateExpectedCash()` and every Stage-2+ row are untouched.
- Evidence: the grep-proof output (actual output, not a summary), the removal/annotation test red-first
  where behavioural, and the two chokepoint guards (`ServerReportAuthoringUnreachabilityTest`,
  `ZReportServerAuthoringChokepointTest`) still green **by path**.

### M2 — SV-11: the count-screen copy, exactly as specified

Four additions, in the dossier's priority order, all through `t()` (rule 11), **en + fr in parallel**
(Arabic per **R-5**):

1. **A one-line instruction above the count, always visible, blind or not** — *"the single highest-value
   change in this document"*:
   - **EN:** *"Count **all** the cash in the drawer, including the opening float of {{amount}}."*
   - **FR (the exact line):** *"Comptez **tout** l'argent présent dans le tiroir, **y compris le fonds de
     caisse** de {{amount}}."*
2. **Float-disclosure line on the expected figure:** *"Expected includes the opening float."* /
   *"Le montant attendu inclut le fonds de caisse."* **Under blind mode this renders AFTER Commit
   Counts, alongside the reveal** — it must not appear before (that is an SV-10 leak by construction).
3. **Name the zero case and decompose the reveal.** Replace a bare `0.000` with **"No difference"** /
   *"Aucun écart"*. The post-commit summary uses this split:

   | line | EN | FR |
   |---|---|---|
   | 1 | Opening float | Fonds de caisse |
   | 2 | Cash sales (net of change) | Ventes en espèces (net rendu monnaie) |
   | 3 | Paid in / paid out | Entrées / sorties d'espèces |
   | 4 | **Expected in drawer** | **Attendu en caisse** |
   | 5 | **Counted** | **Compté** |
   | 6 | **Over / Short / No difference** | **Excédent / Manquant / Aucun écart** |

   **Line 2 — the Stage-1 rendering contract, stated so nobody ships dead DOM:** render **ONLY the
   cash-sales row**. **No rounding placeholder, no reserved DOM slot, no empty container, no
   `display:none` sibling.** SV-12's rounding decomposition will eventually land on this line, but SV-12
   is **Stage 5 and explicitly out of scope**: the structural extension is **deferred to SV-12**, and
   Stage 1 ships the single row exactly as specified. Do not compute or display a rounding component
   here, and do not pre-build the shape that would hold one.
4. **Wording:** keep **"Écart"** over "Différence" — the register must match the figure that triggers a
   mandatory written reason.

**Surface:** the device count screen (`apps/pos` — `cash_count.*` in `locales/{en,fr}/pos.json`, and the
components the dossier names). **Presentation only (R-4).** Evidence per §5.1: **rendered-HTML
assertions (rule 17), keys present in both locales, no hardcoded strings, and the float amount
interpolates.**

### M3 — SV-9: blind counting ON everywhere

**First, execute the CRITICAL SEQUENCING GUARD (R-6) and record its outcome.** Then implement the
dossier's six touch points plus the data migration:

| # | Touch point (dossier §2 SV-9) |
|---|---|
| 1 | `CompanyFraudSettings.php` defaults — the `require_blind_cash_count` entry in the defaults array **and** in `getDefaults()` |
| 2 | `defaultsForVertical()` — `$defaults['require_blind_cash_count'] = $isAutomotive;` becomes dead or inverted. **`tests/Unit/Compliance/CompanyFraudSettingsVerticalDefaultsTest.php` (asserting `false` for non-automotive) goes RED BY DESIGN and is rewritten** — say so explicitly; a red-by-design test rewritten silently is indistinguishable from a test weakened to fit |
| 3 | **The data migration** for rows already persisted `false` (the 2026-04-25 seed ran once) — per **R-7** |
| 4 | Optionally the column default (`…2026_04_25_000004_add_cash_variance_settings_to_company_fraud_settings.php`) |
| 5 | The FE `false` initial/fallback (`apps/web/.../FraudSettingsPage.tsx:55`, submit `:396`) — **which would silently re-disable it on a form round-trip for a row-less company.** This is the one that bites in production |
| 6 | The device cache `DEFAULT 0` (`apps/pos/src/lib/db/migrations.ts:518`) — governs **pre-first-sync behaviour only**; state the reasoning, do not over-fix |

Also in the row's surface: the resolver's no-row fallback (`FraudSettingsResolver.php:30`, persisted row
`:45`), the admin API + DTO, and the web toggle + `locales/{en,fr}/compliance.json` (**`apps/web` has
`ar` — do it in parallel there**).

**⚠️ Do not conflate with inventory blind counting** — the prior blind ruling in memory concerns
`inventory_countings` on the mobile app. Different setting; directionally consistent; **out of scope**.

**Acceptance (§5.1 SV-9), all three:** a fresh company in **both** verticals resolves
`require_blind_cash_count = true`; a pre-existing row persisted `false` **is migrated**; and a **FE form
round-trip on a row-less company does not re-disable it**.

### M4 — SV-10: the blind-mode leak audit

- **Read-only audit** of the enumerated surfaces: the defended one
  (`EndOfDayPreviewModal.tsx:239-262`), the un-audited severity/reason prompts
  (`CashReconciliationSection.tsx:137`, `:165-180`), the reveal gate
  (`organisms/CashCountTable.tsx:64-65`), and any sibling surface that renders a variance-derived value
  before Commit Counts. **Enumerate the render paths; do not reason from component names.**
- **Findings to a file** under `docs/handoff/reviews/sv-stage1/` (e.g. `M4-sv10-leak-audit.md`): per
  surface — what renders, whether it discloses **magnitude**, and the verdict with `file:line`.
- **Fix only the in-scope leaks** (magnitude disclosed before Commit Counts under blind mode), each with
  a rendered-output test. **Anything larger → ticket + report** (R-8).
- **Acceptance (§5.1 SV-10):** under blind mode, **no** surface — including severity/threshold prompts —
  renders expected or variance **magnitude** before Commit Counts.

### M5 — Whole-lane gate

Re-run the **full accumulated evidence** over the integrated branch and apply **every lens used in the
wave** — `fiscal-pos`, **`treasury`** (it rides M1, whose diff is Treasury's config and listener
docblock), `frontend-conventions`, `tenancy-authz`. Per the harness this is the last gate and
is not a formality: it re-reads M1–M4 as one diff, checks that no Stage-2+ row was touched, that
`TREASURY_SHIFT_VARIANCE_GL_ENABLED` is untouched, and that the deploy obligations are recorded.

---

## 📋 PER-MILESTONE EVIDENCE CONTRACT

| Milestone | Code evidence | Artifact / non-code evidence | The evidence that is easy to fake, and its antidote |
|---|---|---|---|
| **M0** | none (no production code) | digest matched + pasted; `HEAD == BASE_SHA`; citation inventory with **unresolved = 0**; the Arabic finding; the six-point prerequisite question list | "I read the dossier." **Antidote:** the reviewer re-derives **two citations of its choosing** and confirms symbol + anchor. |
| **M1** | grep-proof **actual output**; removal/annotation test; both chokepoint guards green by path | the three artefact rewrites, each naming the **real** gate; the historical note preserved somewhere durable | Deleting the function and calling the artefacts "already fine". **Antidote:** the diff must show **all three** artefacts changed, and a grep for the refuted premise ("sums receipt payments only" / "float … 658/758 on every close") returns **zero** policy assertions outside a historical note. |
| **M2** | rendered-HTML assertions (rule 17) per string; en + fr keys present; interpolation proven with a real amount | the exact-string table checked line by line against the dossier | Asserting on i18n **keys** instead of rendered text, or on CSS classes. **Antidote:** assert the rendered French line **verbatim**, including "y compris le fonds de caisse"; and a test that the float-disclosure line is **absent before** Commit Counts under blind mode. |
| **M3** | red-by-design test rewritten **and named as such**; migration idempotent (run twice, second run changes nothing); the three §5.1 assertions | the **R-6 guard outcome, recorded either way**; the deploy note (unattended `tenants:migrate`, per-tenant counts) | A migration that "worked" because it silently no-op'd. **Antidote:** assert the **row count actually changed** on a seeded `false` row, and assert the second run changes nothing — both in the same test. Plus the FE round-trip test, which is the failure nobody sees in CI. |
| **M4** | fixes only where the row reaches, each with a rendered-output test | the audit file: every enumerated surface, verdict + `file:line`, including the ones found clean | An audit that only lists the surfaces that were already defended. **Antidote:** the audit enumerates **render paths**, states what was checked and **what was found clean**, and the reviewer spot-checks one "clean" verdict against code. |
| **M5** | full accumulated evidence re-run; `git diff --name-only <BASE_SHA>..HEAD` pasted | proof of the negative: no Stage-2+ file, no `TREASURY_SHIFT_VARIANCE_GL_ENABLED`, no event added/retired | "Nothing else was touched." **Antidote:** the file list is **pasted and read**, not asserted. |

---

## 📏 HOUSE RULES (binding, every milestone)

- **TDD red-first per task**; **revert-replay** every fix commit (revert, prove the covering test goes
  red, restore). M3's red-by-design test is the exception that must be **declared**, not the pattern.
- **Tests BY PATH only. The full PHPUnit suite is FORBIDDEN — it crashes the machine.** The declared
  regression set must include **every suite directory the diff touches**. Frontend: `pnpm test` scoped by
  path; watch for **vitest zombie worker pools** (`ps aux | grep 'node (vitest'`) after a hang.
- **A real PostgreSQL run before ANY green claim** for backend work. Local PG on 5432 (Docker 5433 is
  broken). `[PG]` tests skip **loudly**.
- **Rule 19 precision.** No float touches money or quantity — device included (**R-4**). `MoneyInput` /
  `formatCurrency` on the web; the POS formatters on the device; payloads as strings.
- **Rule 11 i18n**: every user-facing string through `t()`; **en + fr** on the device (**R-5**), **en +
  fr + ar** on `apps/web`. **Rule 18**: design tokens for any Tailwind colour in code you touch.
- **Rule 17 testing**: valid UUIDs for FK columns; check the actual schema before writing
  seeders/fixtures; **test rendered HTML output, not CSS class names**.
- **TanStack keys**: any tenant-data query key you touch uses `tenantScopedKey([...])` (enforced by
  `apps/web/tools/audit-tanstack-keys.mjs` in lint/preflight/CI).
- **Migrations idempotent, self-guarding, unattended-safe** (**R-7**).
- **Strict types**; constructor injection with `private readonly`; **no `app()` helper** in production;
  no `any` in TypeScript.
- **Rule 8 events immutable forever** — this wave adds, renames and retires **no** event. **Rule 6**
  module boundaries via `Shared/Contracts/`, events, or a module's public service class.
- **No scope creep (rule 4)** — Stage 1 only; note adjacent findings and continue.
- `pint` + `phpstan` level 8 clean on touched files; web `pnpm lint` + `pnpm typecheck` clean; `deptrac`
  must not regress its baseline.
- Dedicated worktree; **never `git stash`**; do not commit to a shared `dev` worktree; **do not push**.

---

## 🚦 REVIEWER GATES

| Milestone | Lenses | What the gate is really asking |
|---|---|---|
| M0 | fiscal-pos | Is the contract verified? Are the citations real? Is the R-6 question list complete? |
| M1 | fiscal-pos, treasury | Is the retirement justified by an **enumeration**? Do all three artefacts now state the real gate? Was the live whole-drawer formula left alone? |
| M2 | fiscal-pos | Are the strings **exactly** the dossier's? Is it presentation-only? Does the float-disclosure line stay hidden pre-commit under blind mode? |
| M3 | fiscal-pos, frontend-conventions, tenancy-authz | Was the prerequisite check executed and recorded? Is the migration idempotent and self-guarding? Does the FE round-trip stop re-disabling it? Is the settings surface tenant-scoped and permission-gated as before? |
| M4 | fiscal-pos, frontend-conventions | Does the audit enumerate render paths? Are the fixes inside the row? Was the existing defence left intact? |
| M5 | fiscal-pos, **treasury**, frontend-conventions, tenancy-authz | Whole-branch: nothing from Stages 2–5, the flag untouched, evidence complete. (`treasury` is re-applied because it gated M1.) |

*(The dossier's own reviewer profile for SV-9/SV-10 is `frontend-conventions-reviewer` +
`tenancy-authz-reviewer`; `fiscal-pos` is carried across the wave because every surface here is POS
shift-close semantics. `treasury` rides M1 because the artefacts it rewrites are Treasury's config and
listener docblock.)*

Per the harness: every **P1** finding must be closed or explicitly ruled by an owner gate before the
milestone passes; **P2** close-before-merge; **P3** may ship with a ticket recorded in the tree. A review
with no parseable `VERDICT:` line is a **tool error → CHANGES-REQUIRED**, never a pass.

---

## 📦 DELIVERABLE — handback only

**One branch, NOT merged, NOT pushed.** The orchestrator merges to local `dev` after the wave completes
and the whole-lane register is read.

1. **`codex/sv-stage1`** in worktree **`.worktrees/sv-stage1`**, created from `BASE_SHA` off local `dev`.
2. **`docs/handoff/progress/sv-stage1.progress.yaml`** complete — every milestone `status`, `commit`,
   `verdict`, `last_verdict`, `fix_rounds`; wave `status` and `blockers` reflecting reality.
3. **Review records** under `docs/handoff/reviews/sv-stage1/` — one file per milestone per round
   (`M<n>-round<r>.md`), **plus M4's leak-audit findings file**.
4. **A session report** at `docs/sessions/codex-sv-stage1-report.md`: per milestone — files touched,
   tests + commands + **actual output**, decisions taken, deviations with rationale, concerns; plus the
   **R-6 guard outcome**, the **deploy obligations** (the SV-9 data migration runs unattended on the next
   `origin/dev` promotion — state what it changes and how to verify it per tenant), and every ticket
   raised (at minimum: the device-Arabic gap at
   `docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md`, and any SV-10 leak larger than
   the row).

**Owner sheet for anything gated:** `docs/handoff/OWNER-QUESTIONS-es-remediation-2026-08-11.md`. Stage 1
is designed to need **none** of it — if you land on **D-3/D-4/D-5/D-6/D-7/D-17**, you have left Stage 1:
STOP and name the item ID in `blockers:`.

---

## ❓ OPEN AT DISPATCH — one item

**The base SHA.** Everything else is disposed: the rulings are the dossier's §1, the row texts its §2,
the stage boundary its §3, the acceptance its §5.1. The two escalations are recorded in the YAML's
`owner_gates:` list as **informational entries** (`blocks_milestone: none`) precisely so the harness does
not read them as unconditional stops: **R-6** (SV-9 prerequisites) is answered *by the implementer's
check* at M3 and stops the wave **only if the check actually fires**; **R-5** (device Arabic) **never**
stops the wave — M2 ships `en` + `fr`, files the ticket, and proceeds.
