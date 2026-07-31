# Owner Manual Launch Gates — First Tenant — 2026-07-31

> Materialized verbatim from `docs/handoff/DISPATCH-PLAN-v5-first-tenant-2026-07-31.md` §Phase E (v5.1,
> Lane D2-a task 6) by Lane D2-a. This is the **single evidence sink** for gates whose source document has
> no native evidence table (E-7, E-8, E-10) and the execution-tracking sheet for every other gate. It does
> not add, remove, or reinterpret any gate — it is a faithful materialization plus the two additional
> columns the dispatch plan requires (**named owner**, **status**), both empty/owner-assigned as specified.

**Do not dispatch, execute, or write evidence into this file from any agent lane.** On D2-a's merge,
write-ownership of this file (and the other five Lane D2-a manifest paths) transfers to Phase E — named
humans only; the orchestrator may commit evidence on their behalf.

**erp-mobile is out of scope for this gate sheet** — it was already pushed to `main` at `5a90341` and
carries no first-tenant launch gate here.

---

## Hard rule (verbatim)

**Tenant #1 CANNOT onboard until every row below is closed with evidence. E-7 is non-waivable.**

Named owner for every row: **Houssam (@otospexsolutions, admin@otospex.com)**
(`docs/security/secret-rotation-2026-05-12.md:18`). A second human, where required, is named in the
**Named owner** column of that row **before the row can begin** — an unnamed required second human blocks
the row from starting, not just from closing.

Owner rulings and the E-7 waiver rule are unchanged from v4: the `D/2` acceptance blank (in
`docs/handoff/cash-rounding-phase2-deploy-checklist.md:226`) waives **only** the refund-rounding delta.
**E-7 is non-waivable** by any acceptance blank, anywhere.

---

## Phase E gate table

| # | Gate | Named owner | Evidence | Evidence location | Pass / NO-GO criteria | Status |
|---|---|---|---|---|---|---|
| E-1 | Secret rotation | Houssam (release owner — sole executor per source; no second human required — every playbook in the source document is scoped to the Release owner) | Every credential row in the inventory (`:53-67` — 9 rows / 12 variables; enumerate, don't count) flipped to `revoked`; connection-target decision (`:69-80`); one Verification-Evidence ledger entry per rotation (`:245+`); Final Sign-Off filled (`:260+`) | `docs/security/secret-rotation-2026-05-12.md` (`:53-67` inventory, `:69-80` connection-target decision, `:245+` Verification-Evidence ledger, `:260+` Final Sign-Off) | PASS only when every inventory row is `revoked` + ledger + sign-off. **Intentional program override of the source's High/Medium acceptance statuses** (normalized by D2-a task 5): no acceptance path for ANY row. | |
| E-2 | P0 real-device smoke | Houssam (release engineer) — **second humans required, named here before the row begins; blank blocks the row:** Tenant operator, Synerivia observer, Tunisia legal reviewer (all four roles must sign per the smoke doc's Sign-Off table) | The REPAIRED `2026-05-12-first-tenant-smoke.md` executed on the real Windows terminal: every step's Status + Actual Outcome + Evidence cell filled; all three chain verifiers (incl. `--actor-id`) exit 0 target-scoped; release checksum + clock/timezone checks pass; offline ≥10 min / 5+ receipts; printer sleep/reprint/disconnect; every named signatory signed | `docs/qa/2026-05-12-first-tenant-smoke.md` (sections A0, A–H, §Sign-Off) | ◆FIX-1: gate classes are the ENUMERATED per-step P0/non-P0 labels D2-a added to the protocol tables. Any failed **P0** step = NO-GO, no acceptance — intentional program override of the sources' general acceptance language. Failed non-P0 steps close only via the recorded owner risk-acceptance route (reason + revisit milestone). | |
| E-3 | Production migration rehearsal | Houssam (release engineering) — **second human required if the DBA/database steward or Synerivia ops observer role is not Houssam, named here before the row begins; blank blocks the row** (source doc lists these as separate owner roles, `2026-05-12-migration-audit-and-rollback.md:88-92`) | The FULL repaired source procedure recorded in `2026-05-12-migration-audit-and-rollback.md`: live backup + checksum + five fiscal-table row counts (`:20-30`); pretend-run output + irreversible-operation review + schema diff; ACTUAL staging-clone migration with timing/downtime estimate; `migrate:status`; all applicable chain verifiers (real commands, `--actor-id`); staging happy-path smoke (`:31-37`, `:67-86`) | `docs/qa/2026-05-12-migration-audit-and-rollback.md` (`:20-30`, `:31-37`, `:67-86`, `:88-92` owners) | NO-GO on ANY of: failed dry-run step, unverifiable/mismatched backup checksum, fiscal row-count mismatch, unreviewed irreversible operation, ◆FIX-3 unexpected schema diff (drops/renames not accounted for), any migration not reported `Ran` in `migrate:status`, failed verifier, failed staging smoke, exceeded downtime budget | |
| E-4 | TN accountant/legal sign-off | Houssam (coordinates) — **second human required: a Tunisia accountant or legal reviewer, named here before the row begins; blank blocks the row** | Review evidence for ALL FIVE subjects — VAT rates, receipt legal fields, certification scope, Z format, retention — plus reviewer name, date, reviewed-item list, and explicit approved / approved-with-caveats flag, appended to `walkthrough-rehearsal.md` per `2026-05-13-first-tenant-handoff.md:88-108` | `docs/pos-operations/walkthrough-rehearsal.md` (Tunisia sign-off section); source checklist `docs/qa/2026-05-13-first-tenant-handoff.md:88-108` | Approved (or approved-with-caveats, caveats itemized) — OR a dated owner risk acceptance with reason + revisit milestone | |
| E-5 | Runbook preflight ZERO | Houssam — single-owner, script-driven; no second human required | `preflight-runbooks.sh` exit 0; full output pasted into the gate sheet | This gate sheet (Status cell of this row) + `scripts/preflight-runbooks.sh` output | Script green, output persisted | |
| E-6 | Walkthrough rehearsal | Houssam (facilitator) — **second human required: the Reader role from `walkthrough-rehearsal.md`, named here before the row begins; blank blocks the row** | `walkthrough-rehearsal.md` record complete on the release build; second human named in the gate sheet | `docs/pos-operations/walkthrough-rehearsal.md` | EVERY explain-back row = YES, `Approved for go-live docs? = YES`, every gap closed or explicitly accepted (`:15,:35`) | |
| E-7 | **v3 correction-chain closure (NON-WAIVABLE)** | Houssam — tracks Lane C landing through its own engineering review gates; no second human required for this row (Lane C's own reviewers are tracked in Lane C's records, not here) | Lane C spec APPROVED + code phase landed through its review gates; green integration evidence: device v3 sale → online return → next device sale → Z at schema 3 (test path named in gate sheet); chosen VOID implementation or compensating control landed | Lane C spec/review records (path named in this row's Status cell once Lane C lands) | The `D/2` acceptance waives ONLY refund-rounding delta. NOTHING waives E-7. | |
| E-8 | **Target-device rollout (EXPANDED per round-4 fix 6 — full deploy-checklist §`:224-263`)** | Houssam (release owner / on-site coordinator) — **second human required: the branch/location manager attending the mandatory manager briefing, named here before the row begins; blank blocks the row** | For the real tenant #1, recorded in the gate sheet: main-location POS disposition; real terminal created/claimed at schema 3 (`TerminalResource` policy pull); **target-scoped `pos:configure-cash-rounding` DRY-RUN then real enable with explicit `--tenants=<uuid>`** (after E-7 or the authorized rounding exception); device/API policy pull enabled + `0.050`; **manager briefing held (non-optional)**; v64/v3 release build installed; device SQLite migration 64 verified by query; device policy-cache and CASH-method (`is_cash_tender`) checks; a REAL rounded cash sale with correct screen/ticket amounts + tender edge cases; EOD/Z succeeds; server projection populated; expected GL source rows present; NO policy-mismatch or change-exceeds-cash audit events | This gate sheet (Status cell); cross-referenced `docs/pos-operations/install.md`, `docs/handoff/cash-rounding-phase2-deploy-checklist.md:224-263` | Every item evidenced for the actual tenant/device before its first live customer transaction | |
| E-9 | **Staging-runbook execution (NEW, round-4 fix 5)** | Houssam — single-owner execution; no second human required | Owner executes `STAGING-RUNBOOK-first-tenant-2026-07-31.md` top to bottom ON THE FINAL RELEASE CANDIDATE; every still-applicable step's evidence cell filled with command output in the runbook file itself | `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` (evidence lives in that file, not here) | ◆FIX-4: PASS only when every applicable command exits as expected AND Actual Outcome matches the authored Expected Outcome; any failed or missing result = NO-GO. N/A / already-done requires owner, reason, revision/environment, and evidence — never a silent skip. `RERUN-ON-FINAL-CANDIDATE` steps must be re-run regardless of history. | |
| E-10 | **Production release/cutover (NEW, round-4 fix 5)** | Houssam — owner's environment decision is non-delegable; second human optional (e.g., DBA/ops observer per the E-3 pattern) but not source-mandated for this row | Recorded in the gate sheet: (1) the OWNER'S ENVIRONMENT DECISION — does tenant #1 run on the staging deployment or a separate production deployment? (this is an open owner decision; the gate cannot close without it); (2) the exact release revision/artifact promoted to that environment; (3) the ACTUAL migration run + verification per the repaired migration doc (`:31-37`) on that environment; (4) `RolesAndPermissionsSeeder` + `permission:cache-reset` (tenant-blind cache) + required backfills/config per the staging runbook's production-applicable rows; (5) Horizon/process restarts; (6) final release evidence (versions, checksums, verifier outputs); ◆FIX-3 (7) a FRESH backup on the owner-selected ACTUAL environment immediately before its migration — checksum + five fiscal-table pre-counts + post-run verification and rollback-point record (`2026-05-12-migration-audit-and-rollback.md:18-30`); E-3's earlier rehearsal backup can NEVER satisfy this. **A push to `origin/dev` deploys STAGING and is NOT production clearance** (`HANDOVER…:8-11`). | This gate sheet (Status cell); cross-referenced `docs/qa/2026-05-12-migration-audit-and-rollback.md:18-30,31-37`, `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` | ◆FIX-4: PASS only when the environment decision is recorded AND every migration / verifier / reseed / backfill / config / restart / version / checksum result SUCCEEDS (expected = actual); any failed, mismatched, or missing result = NO-GO | |

All **Status** cells above are intentionally empty. They are filled only by the named human(s) executing
that row, with evidence, at execution time — never prospectively by any agent lane.

---

## Phase-E dependency chain (◆FIX-5, verbatim)

Evidence must be collected against the FINAL release candidate in the owner-selected environment — earlier-revision evidence cannot close later gates:

1. E-1 / E-4 / E-5 / E-6 may run any time (E-6 on the release build).
2. E-3 (rehearsal) PRECEDES cutover.
3. E-7 must be IN the exact release candidate.
4. E-9 executes on that final candidate in STAGING.
5. E-10 then records the environment decision and prepares the actual tenant environment
   (incl. its fresh just-in-time backup).
6. E-8 / E-2 execute on that same promoted artifact — E-8's manager briefing BEFORE device
   install; E-2 (smoke) AFTER install/migration/restore, BEFORE the first real transaction.

Writes to `walkthrough-rehearsal.md` (E-4 + E-6) are SERIALIZED through the orchestrator (single
committer); Phase-E rows sharing a file never edit concurrently.
