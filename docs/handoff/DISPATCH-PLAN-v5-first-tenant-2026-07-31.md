# Dispatch plan v5 — first-tenant launch program

**Date:** 2026-07-31. **Supersedes:** `DISPATCH-PLAN-v4-first-tenant-2026-07-31.md` (REJECTED round 4 —
`docs/superpowers/reviews/2026-07-31-codex-v4-dispatch-review.md`).
**Status:** AWAITING ROUND-5 ADVERSARIAL REVIEW. Nothing dispatches until APPROVE.

Round-4 state: R1/R4/R5/R6 incorporated; **Lanes A, B, C(spec), D1, F ruled dispatch-safe /
manifest-sufficient / pairwise-disjoint — they are FROZEN as written in v4 §Lane A/B/C/D1/F and are
not restated here.** Every round-4 fix targets the docs lane (D2-a) and the Phase-E gate rows; v5
rewrites exactly those two sections and answers all 7 required fixes.

Owner rulings and the E-7 waiver rule are unchanged from v4 (the `D/2` acceptance blank waives ONLY
refund-rounding delta; E-7 is non-waivable).

---

## Lane D — docs/ops lane: TWO SEQUENTIAL PHASES, ONE MANIFEST (rewritten per round-4 fixes 1, 2, 5)

Worktree `../erp.launch-ops`, branch `docs/launch-ops-runbook` off `origin/dev`.

### Phase D2-a — agent-dispatched authoring (this dispatch wave)

1. **Staging runbook** — consolidate the 7 open checklists (treasury ③/④/⑤a/⑤b, multiloc,
   `RolesAndPermissionsSeeder` + `permission:cache-reset` [Spatie cache TENANT-BLIND], Horizon
   restart, `DemoPharmacySeeder --force`), Task-8 artisan commands only, mark what staging evidence
   shows done, EXECUTE NOTHING. Each step gets an evidence cell (command + expected output) so the
   runbook is executable as gate **E-9**. File: `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md`.
2. **Runbook placeholder burn-down** — fill every repo-derivable placeholder in
   `docs/pos-operations/*.md`; remaining hits map 1:1 to gate-sheet rows (preflight EXPECTED
   non-zero after D2-a; zero is gate E-5).
3. **P0 smoke protocol repair (round-4 fix 1)** — `docs/qa/2026-05-12-first-tenant-smoke.md`:
   - Replace the nonexistent `fiscal:verify-chain` family with the three REAL verifiers, each
     explicitly scoped to the real tenant/company/terminal:
     `fiscal:verify-chains --company=<id>` (`VerifyFiscalChainsCommand.php:23`),
     `pos:verify-chains --company=<id> --terminal=<id> --type=<t>` (`VerifyPosChainCommand.php:35`),
     and `fiscal:verify-event-chain --tenant=<uuid> --terminal=<id> --actor-id=<id>` — the
     **`--actor-id` argument is mandatory and the command fails without it**
     (`VerifyEventChainCommand.php:70-103`; manual: `docs/runbooks/fiscal-verify-all-chains.md:28-43`).
   - Add the P0 handoff's release-version/checksum and clock/timezone checks
     (`2026-05-13-first-tenant-handoff.md:69-80`).
   - Offline segment ≥10 minutes with 5+ receipts.
   - Add an **Actual Outcome** column to every step table (Status alone cannot record
     expected-vs-actual; `2026-05-12-first-tenant-smoke.md:23-34`).
   - Reconcile risk acceptance EXPLICITLY: any failed **P0** step is an unconditional NO-GO; a
     failed non-P0 step may close ONLY via the source's owner risk-acceptance route
     (`2026-05-12-first-tenant-smoke.md:105-114`, `2026-05-13-first-tenant-handoff.md:82-84`),
     recorded with reason + revisit milestone.
   - Owner field filled; EVERY named signatory required before PASS; remove DRAFT once repaired.
4. **Migration-procedure repair (round-4 fix 2)** — `docs/qa/2026-05-12-migration-audit-and-rollback.md`:
   replace its nonexistent singular `fiscal:verify-chain` invocations (`:35-37`, `:83-86`) with the
   real verifiers incl. `--actor-id`; keep the full procedure intact. Structure/commands only — do
   NOT tick any box or fabricate evidence.
5. **Secret-gate rule normalization (round-4 fix 3)** — `docs/security/secret-rotation-2026-05-12.md`:
   the source contradicts itself ("every row revoked" `:53-55` vs High/Medium owner-signed
   acceptance statuses in the formal gate `:235-243`). **Program ruling, declared as an intentional
   override: the STRICTER rule governs — every credential row must reach `revoked`; the
   acceptance statuses are retired for the first-tenant gate.** D2-a edits the gate-rule text to say
   exactly that (citing this plan). Rule text ONLY — status cells, inventory rows, ledger, and
   sign-off structure are untouched and remain Phase-E territory.
6. **The gate sheet** — materialize the Phase-E table below verbatim into
   `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md` (columns: gate, named owner, evidence,
   evidence location, pass / no-go criteria, status). The gate sheet is the SINGLE evidence sink for
   rows whose source doc has no native evidence table (E-7, E-8, E-10).

**WRITE MANIFEST (phase D2-a, exact):**
`docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` (new),
`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md` (new),
`docs/pos-operations/*.md`,
`docs/qa/2026-05-12-first-tenant-smoke.md`,
`docs/qa/2026-05-12-migration-audit-and-rollback.md` (command/structure repair only),
`docs/security/secret-rotation-2026-05-12.md` (gate-rule text only).
Nothing outside `docs/`. Review gate: one Opus doc-accuracy pass — MUST verify the three verifier
signatures against the command classes (incl. `--actor-id`), the P0/non-P0 no-go split against the
handoff, and that no evidence/status cell was pre-filled. Merge to LOCAL dev, stop.

### Phase E — execution by named humans (sequenced LAST; ownership transfer)

**On D2-a's merge, write-ownership of ALL SIX manifest paths above TRANSFERS to Phase E** (round-4
fix 5/7: the staging runbook now transfers too, since it carries E-9 execution evidence). No agent
lane may touch these files after the transfer; the orchestrator may commit evidence on the humans'
behalf. Phase E's write set = exactly the transferred files.

**HARD RULE: tenant #1 CANNOT onboard until every row is closed with evidence. E-7 is
non-waivable.** Named owner for every row: **Houssam (@otospexsolutions, admin@otospex.com)**
(`secret-rotation-2026-05-12.md:18`); a second human, where required, is named IN the gate sheet
before that row can begin.

| # | Gate | Evidence → location | Pass / NO-GO |
|---|---|---|---|
| E-1 | Secret rotation | Every credential row in the inventory (`:53-67` — 9 rows / 12 variables; enumerate, don't count) flipped to `revoked`; connection-target decision (`:69-80`); one Verification-Evidence ledger entry per rotation (`:245+`); Final Sign-Off filled (`:260+`) | PASS only when every inventory row is `revoked` + ledger + sign-off. **Intentional program override of the source's High/Medium acceptance statuses** (normalized by D2-a task 5): no acceptance path for ANY row. |
| E-2 | P0 real-device smoke | The REPAIRED `2026-05-12-first-tenant-smoke.md` executed on the real Windows terminal: every step's Status + Actual Outcome + Evidence cell filled; all three chain verifiers (incl. `--actor-id`) exit 0 target-scoped; release checksum + clock/timezone checks pass; offline ≥10 min / 5+ receipts; printer sleep/reprint/disconnect; every named signatory signed | Any failed **P0** step = NO-GO, no acceptance. Failed non-P0 steps close only via the recorded owner risk-acceptance route (reason + revisit milestone). |
| E-3 | Production migration rehearsal | The FULL repaired source procedure recorded in `2026-05-12-migration-audit-and-rollback.md`: live backup + checksum + five fiscal-table row counts (`:20-30`); pretend-run output + irreversible-operation review + schema diff; ACTUAL staging-clone migration with timing/downtime estimate; `migrate:status`; all applicable chain verifiers (real commands, `--actor-id`); staging happy-path smoke (`:31-37`, `:67-86`) | NO-GO on ANY of: failed dry-run step, unverifiable/mismatched backup checksum, fiscal row-count mismatch, unreviewed irreversible operation, failed verifier, failed staging smoke, exceeded downtime budget |
| E-4 | TN accountant/legal sign-off | Review evidence for ALL FIVE subjects — VAT rates, receipt legal fields, certification scope, Z format, retention — plus reviewer name, date, reviewed-item list, and explicit approved / approved-with-caveats flag, appended to `walkthrough-rehearsal.md` per `2026-05-13-first-tenant-handoff.md:88-108` | Approved (or approved-with-caveats, caveats itemized) — OR a dated owner risk acceptance with reason + revisit milestone |
| E-5 | Runbook preflight ZERO | `preflight-runbooks.sh` exit 0; full output pasted into the gate sheet | Script green, output persisted |
| E-6 | Walkthrough rehearsal | `walkthrough-rehearsal.md` record complete on the release build; second human named in the gate sheet | EVERY explain-back row = YES, `Approved for go-live docs? = YES`, every gap closed or explicitly accepted (`:15,:35`) |
| E-7 | **v3 correction-chain closure (NON-WAIVABLE)** | Lane C spec APPROVED + code phase landed through its review gates; green integration evidence: device v3 sale → online return → next device sale → Z at schema 3 (test path named in gate sheet); chosen VOID implementation or compensating control landed | The `D/2` acceptance waives ONLY refund-rounding delta. NOTHING waives E-7. |
| E-8 | **Target-device rollout (EXPANDED per round-4 fix 6 — full deploy-checklist §`:224-263`)** | For the real tenant #1, recorded in the gate sheet: main-location POS disposition; real terminal created/claimed at schema 3 (`TerminalResource` policy pull); **target-scoped `pos:configure-cash-rounding` DRY-RUN then real enable with explicit `--tenants=<uuid>`** (after E-7 or the authorized rounding exception); device/API policy pull enabled + `0.050`; **manager briefing held (non-optional)**; v64/v3 release build installed; device SQLite migration 64 verified by query; device policy-cache and CASH-method (`is_cash_tender`) checks; a REAL rounded cash sale with correct screen/ticket amounts + tender edge cases; EOD/Z succeeds; server projection populated; expected GL source rows present; NO policy-mismatch or change-exceeds-cash audit events | Every item evidenced for the actual tenant/device before its first live customer transaction |
| E-9 | **Staging-runbook execution (NEW, round-4 fix 5)** | Owner executes `STAGING-RUNBOOK-first-tenant-2026-07-31.md` top to bottom; every still-applicable step's evidence cell filled with command output in the runbook file itself | Every applicable step closed or explicitly marked already-done-with-evidence; no step skipped silently |
| E-10 | **Production release/cutover (NEW, round-4 fix 5)** | Recorded in the gate sheet: (1) the OWNER'S ENVIRONMENT DECISION — does tenant #1 run on the staging deployment or a separate production deployment? (this is an open owner decision; the gate cannot close without it); (2) the exact release revision/artifact promoted to that environment; (3) the ACTUAL migration run + verification per the repaired migration doc (`:31-37`) on that environment; (4) `RolesAndPermissionsSeeder` + `permission:cache-reset` (tenant-blind cache) + required backfills/config per the staging runbook's production-applicable rows; (5) Horizon/process restarts; (6) final release evidence (versions, checksums, verifier outputs). **A push to `origin/dev` deploys STAGING and is NOT production clearance** (`HANDOVER…:8-11`). | All six items evidenced for the environment tenant #1 actually runs on |

## Lanes A, B, C(spec), D1, F — FROZEN per v4 (round 4: dispatch-safe, sufficient, disjoint)

As specified in `DISPATCH-PLAN-v4-first-tenant-2026-07-31.md` §Lane A / §Lane B / §Lane C /
§Lane D1 / §Lane F, verbatim, including their write manifests and review gates. No changes in v5.

## Fencing summary (updated for the expanded D2-a manifest)

Concurrent wave: A, B, C-spec, D1, D2-a (F after A+D1). Disjointness vs v4 changes only in D2-a's
two added docs paths (`qa/2026-05-12-migration-audit-and-rollback.md`,
`security/secret-rotation-2026-05-12.md`) — no other lane writes ANY `docs/qa`, `docs/security`,
`docs/handoff`, or `docs/pos-operations` path (A/B/D1/F write no docs at all except F's ticket under
`docs/superpowers/tickets/`; C-spec writes only under `docs/superpowers/specs/`). Phase E overlaps
D2-a BY DESIGN as a strictly sequential ownership transfer of all six manifest paths after D2-a
merges; never concurrent.

## Execution order

A, B, C(spec), D1, D2-a dispatch in parallel on round-5 APPROVE. Merges to LOCAL dev per review
gates; F after A + D1; ONE batched ff promotion of A+B+D1+D2-a+F (D1's migration self-guarding;
push auto-runs STAGING `tenants:migrate` — staging only, per E-10). C's code phase = its own later
batch behind its spec gate. Phase E rows execute LAST; E-7/E-8/E-9/E-10 immediately before
onboarding.

## What round 5 must answer

1. Exact-path fence re-audit including the two docs paths added to D2-a and the six-path E transfer
   (round-4 fix 7): any concurrent writer anywhere?
2. Do E-1…E-10 now match (or explicitly and coherently override) their repository sources, with
   named owners, complete pass/NO-GO rules, and persisted evidence locations for every row —
   including the two NEW gates E-9/E-10?
3. Is D2-a's authoring brief acceptance-safe (verifier signatures incl. `--actor-id`, P0/non-P0
   split, Actual Outcome recording, no pre-filled evidence)?
4. Any remaining blocker for tenant #1 that no lane or gate row covers?
