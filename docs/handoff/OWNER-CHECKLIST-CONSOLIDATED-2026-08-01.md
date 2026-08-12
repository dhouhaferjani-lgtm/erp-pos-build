# CONSOLIDATED OWNER CHECKLIST — first-tenant launch (2026-08-01)

> One pass, in order. Everything the launch still needs from a human, after Lane C
> (v4 refund chain) merged and promoted to origin/dev @ `bf7b7ba90`.
> Machine-side state: docs/superpowers/reviews/2026-08-01-lane-c-final-review.md (MERGE-READY)
> and the Lane C session ledger. This file is Claude-authored; the six Phase-E gate documents
> remain human-only — this checklist points at them, never replaces them.

## A. IMMEDIATELY (staging, after the auto-deploy finishes)
- [ ] **A1. Run on staging api container, in order** (auto-deploy does NOT run these):
      1. `php artisan tenants:run db:seed --class=RolesAndPermissionsSeeder`
      2. `php artisan permission:cache-reset`
      Without both, the new dead-letter/compensation endpoints 403 for every role.
      (Spatie permission cache is tenant-blind — standing rule.)
- [ ] **A2. Confirm tenants:migrate ran clean** in the deploy log (7 new tenant migrations,
      all self-guarding; expect no manual action).
- [ ] **A3. Stacked deploy checklists from the treasury/multiloc lanes** (②③④⑤a⑤b + perm
      reseed/Horizon) — consolidated under launch-program Lane D2; execute if not already done.
      A1's two commands overlap with part of it but do not replace it.

## B. GATE SHEET (docs/handoff/OWNER-manual-launch-gates-2026-07-31.md — initial by hand)
- [ ] **B1. E-10 — INITIAL AS DECIDED**: tenant #1 onboards to PRODUCTION after the staging
      test campaign passes (your ruling 2026-08-01, recorded in the program ledger).
- [ ] **B2. E-1 secrets** — provision per the gate sheet.
- [ ] **B3. E-2 device smoke** — per sheet.
- [ ] **B4. E-3 production migration rehearsal** — per sheet
      (docs/qa/2026-05-12-migration-audit-and-rollback.md:94 lineage).
- [ ] **B5. E-4 TN legal confirmation** — per sheet.
- [ ] **B6. E-9 staging runbook execution** (docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md)
      — ADD to the runbook while executing (carry-overs ticket #12):
      • daily check: dead-lettered `fiscal_event_projections` + non-null `refund_policy_alerts`
        (the server refund cap fails as a silent ledger hole, not a blocked refund);
      • emergency lever: `pos:disable-v4-refund-authoring` HALTS ALL REFUNDS on a
        v3-from-birth terminal (tenant #1) until re-enable — that is its designed semantic.
- [ ] **B7. E-7 (NON-WAIVABLE)** — evidence now includes: §1.1 acceptance test with Z-close leg
      (both topologies, both chain verifiers), payout cash-bound analysis
      (worktree docs/sessions/LANE-C-wave3-payout-cash-bound-analysis.md, refreshed), M1+M2+M3
      implemented. REMAINING for sign-off: the staging test campaign (§D) + final fiscal re-run
      (§E). Interim NO-REFUNDS prohibition stays until you sign E-7.

## C. AWARENESS ITEMS (no action, don't let them surprise you)
- [ ] **C1. Live pre-existing defect (ticketed URGENT, own fiscal gate):** device Z/X reports
      decompose net/VAT wrong on taxed SALES today (totals right, split wrong) —
      docs/superpowers/tickets/2026-08-01-device-z-sale-branch-gross-as-net.md. Post-Lane-C, a
      fully-refunded taxed sale shows a small VAT residue in Z buckets (refund side correct).
- [ ] **C2. Staging vs production P&L difference (by design):** FR/TN account 709 stays
      revenue-typed on the pre-existing staging chart; fresh tenant #1 gets expense-typed.
      Amounts identical; P&L placement differs. Campaign asserts amounts only.
- [ ] **C3. Launch refund scope:** cash-payout-only, same-device v3 originals, single terminal;
      whole-receipt-discounted originals refused (all refunds); per-line-discounted PARTIAL
      refunds refused (full allowed); card/mixed-paid originals refused; training refused.
- [ ] **C4. M2/M3 seeded defaults (tenant-tunable in company_fraud_settings, revisit with pilot
      data):** max 5 refunds AND 300.000 TND per shift while device unsynced; single refund
      > 100.000 TND offline refused AND requires server-verified manager PIN.
- [ ] **C5. Magnitude-semantics release note:** refunds_amount / cumulative_refunds are
      magnitude-based from this release; terminals with pre-release legacy returns show a
      one-time counter discontinuity; no production tenant affected (wave-4 report §E4c).

## D. TEST CAMPAIGN (docs/qa/2026-08-01-money-test-plan.md @ 4a8814d54)
- [ ] **D1. Playwright web campaign on staging** — Tier T1 = 192 P0 cases is the go-live gate
      (427 total). Claude runs this (dedicated session/agents) once §A is done — say go.
- [ ] **D2. POS desktop campaign — Codex Desktop (computer use), LAST**: §Z of the plan,
      64 items. BLOCKING dependency for refund coverage (v4 flow is 100% device-side) and it
      authors the SHIFT-1/SHIFT-2 fixtures §F's fiscal figures need. Needs: new POS build with
      device migrations v65/v66/v67 (plus older owed v60/61/62 from prior lanes) installed on
      the test device.
- [ ] **D3. Defects triage** — campaign FAILs come back through Claude for fix lanes; the 5
      pre-registered known-defect FAILs don't re-file.

## E. FINAL PRE-PRODUCTION GATE (your directive: "one last go before we go live")
- [ ] **E1. Final fiscal re-run on staging tenant** after ALL testing:
      `fiscal:verify-event-chain` + `pos:verify-chains` green on every terminal; X/Z
      decomposition vs known seeded figures; Z-close over a mixed sale+refund shift.
- [ ] **E2. Enablement sequence (ONLY after E-7 signed), in order:**
      1. `php artisan accounting:backfill-refund-compensation-accounts --dry-run` → real
      2. `php artisan fiscal:backfill-sealed-hash-algorithm` (no-op for v3-from-birth; stamps completion)
      3. `php artisan fiscal:enable-v4-refund-authoring --tenant= --company= --dry-run` → real
         (preflight: exactly 1 active Physical terminal, 0 legacy-sealed rows, both accounts)
- [ ] **E3. Production onboarding of tenant #1** per E-10 ruling + runbook.

## F. PARKED FOR POST-LAUNCH (committed tickets, no action now)
Sale-branch Z decomposition fix (own fiscal gate) · legacy-return sequence on v3-from-birth
terminals (chain-numbering lane) · Lane C minor carry-overs (12 items) · positive-refund ticket
CLOSED by wave 4 · M2/M3 defaults revision with pilot data.

## G. COUNTRY DEFAULTS PHASE A — TWO-RELEASE ACTIVATION
- [ ] **G1. Release 1:** deploy the additive central schema, draft bootstrap import, admin API/UI,
      and provisioning code with both `COUNTRY_DEFAULTS_EXTERNAL_EDITORS_ENABLED=false` and
      `COUNTRY_DEFAULTS_PROVISIONING_ENABLED=false`.
- [ ] **G2. Authenticated certification:** a logged-in active `super_admin` reviews and publishes
      the TN, FR, and Generic chart templates through the HTTP surface, then assigns `TN`, `FR`,
      and `*`. No CLI/synthetic certification is permitted.
- [ ] **G3. Verification:** run `php artisan country-defaults:verify` to zero exit on staging and
      again on production before any reader switchover.
- [ ] **G4. Release 2:** set `COUNTRY_DEFAULTS_PROVISIONING_ENABLED=true`, rebuild `config:cache`,
      restart application/queue workers, run `php artisan horizon:terminate`, and verify the value
      from the running release. Keep external editors disabled pending the central-admin MFA lane.
- [ ] **G5. Rollback:** restore `COUNTRY_DEFAULTS_PROVISIONING_ENABLED=false`, rebuild config cache,
      restart application/queue workers, terminate Horizon, and verify the running value. Release 1
      has one behaviorally active expense-category loud-failure boundary; see the M5 P3 ticket.

Phase A limitation (S-5): **Phase A does not wait for, and does not implement, `country_code`
immutability** (a separate settings-guards lane owns it). Phase A's certification claims cover
**unconditional template-layer timbre invariants only**. The tenant-side stamp capability check is
a **usability guard, never an authorization control**, until that lane closes.
