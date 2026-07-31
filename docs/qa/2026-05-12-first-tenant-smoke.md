# First-Tenant Smoke Protocol — 2026-05-12

> **Status:** REPAIRED (v5.1 Lane D2-a, 2026-07-31) per `docs/handoff/DISPATCH-PLAN-v5-first-tenant-2026-07-31.md`
> §Lane D2-a task 3 — verifier commands corrected to the three real target-scoped chain verifiers (the
> previous `fiscal:verify-chain` family does not exist), Gate class and Actual Outcome columns added to
> every step in every table, a release-artifact/clock preflight section and printer-resilience steps
> added, the offline segment tightened to ≥10 minutes / 5+ receipts, and the risk-acceptance rule
> reconciled explicitly (see below). This document is executable but **has not been run** — every
> Status/Actual Outcome/Evidence cell below is empty and stays empty until real execution on the
> deployment terminal (gate **E-2**).
> **Audit reference:** `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md`
> **Plan reference:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md` §M1.6
> **Gate sheet:** `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md` (gate **E-2**)

This protocol is the day-of-launch verification for the first controlled tenant pilot. It is narrower than the full backend test suite (which is run separately as the M1.6 gate, see `2026-05-12-backend-test-exceptions.md`) but covers every path the first tenant operator will exercise.

The smoke is run **after** software install / migration / restore drill, **before** the first real customer transaction.

## Owner

- Houssam (@otospexsolutions, admin@otospex.com) — release engineer / release owner
  (`docs/security/secret-rotation-2026-05-12.md:18`).

## Pre-conditions

- Tenant + company seeded; super-admin / tenant-admin accounts created.
- POS terminal activated; manager PIN configured.
- Currency, tax rates, payment methods configured.
- Receipt printer paired and tested in install runbook.
- Sentry / observability wiring verified by `support.md` checks.
- The exact `COMPANY_UUID` / `TENANT_UUID` / `TERMINAL_UUID` / `SYSTEM_USER_UUID` values used by the
  Section D verifiers below are recorded in the Evidence cell of the step that first captures them (D.1).

## Gate Class Rule (◆FIX-1)

Every step in every table below (A–H) carries a **Gate class**: `P0` or `non-P0`.

- **Default is P0.** A step is classified `non-P0` **only** when its failure, by itself, cannot affect
  fiscal integrity, money movement, or data durability (the dispatch plan's example: cosmetic print
  layout). The doc-accuracy review gate verifies every step that touches fiscal state, money movement, or
  data durability is classified `P0`.
- Gate **E-2** operates on these enumerated per-step Gate classes, not on a separate list.

## Risk Acceptance Rule (◆FIX-1 — intentional program override)

This explicitly overrides the general owner-acceptance language elsewhere in this document (§Sign-Off)
and in `2026-05-13-first-tenant-handoff.md:82-84` ("Any failure blocks the first tenant unless you record
explicit risk acceptance..."):

- **Any failed `P0` step is an unconditional NO-GO.** There is no risk-acceptance path for a failed P0
  step under any circumstance.
- **A failed `non-P0` step may close only via a recorded owner risk-acceptance entry** stating the reason
  and a revisit milestone, logged against gate **E-2** in
  `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`. A non-P0 failure must never be silently marked
  PASS.

## Smoke Steps

Each step records: command/click path, expected outcome, Gate class, actual outcome, and evidence path
(screenshot/log/recording). **All Status / Actual Outcome / Evidence cells below are intentionally empty**
— this document is the executable protocol, not a completed report.

### A0. Release Artifact & Clock/Timezone Preflight (◆FIX-1 new — closes the P0 handoff's release-version/checksum and clock/timezone checks, `2026-05-13-first-tenant-handoff.md:69-80`)

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| A0.1 | Compute the installed release's version + SHA-256 (`docs/pos-operations/install.md` §Verify The Release Artifact) and compare against the recorded release record | Version and SHA-256 match the release record exactly; signing status matches the record | P0 | | | |
| A0.2 | Run the Windows Time/timezone checks (`docs/pos-operations/install.md` §Windows Time And Timezone: `w32tm /query /status`, `w32tm /query /source`, `Get-TimeZone`) | Windows Time service running; NTP source is the approved server; timezone is Tunisia `(UTC+01:00)`, no DST | P0 | | | |

### A. Auth + Tenant/Company Selection

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| A.1 | Log in as tenant admin via web | 200 + redirect to dashboard | P0 | | | |
| A.2 | Switch active company | Company context updates without page reload | P0 | | | |
| A.3 | Log out, re-log as cashier | 200 + POS UI loads with cashier role | P0 | | | |
| A.4 | Failed login 5× with bad password | 429 on attempt N (rate limit) | non-P0 — auth brute-force mitigation; a missed rate-limit does not, by itself, alter fiscal state, move money, or destroy data | | | |

### B. POS Terminal Activation

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| B.1 | Tauri POS app: fresh install, paste activation code | Terminal activates; cashier list loads | P0 | | | |
| B.2 | Restart POS app | Auto-resumes activated state; no re-prompt | non-P0 — re-prompting for activation is an operational inconvenience; it does not corrupt or lose any already-fiscalized receipt | | | |
| B.3 | POS app offline → online transition | Backlog (if any) drains; receipts sync | P0 | | | |

### C. Receipt + Z-Report

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| C.1 | Cashier sale: 2 items, cash payment | Receipt prints; receipt visible in web/admin | P0 | | | |
| C.2 | Cashier sale: card payment via terminal | Receipt prints; card payment recorded with auth ref | P0 | | | |
| C.3 | Cashier sale: cash refund | Refund issued; daily refund-cap counter increments | P0 | | | |
| C.4 | Cashier sale: card refund | Refund issued through correct destination | P0 | | | |
| C.5 | Discount override (cashier without permission) | Manager PIN prompt appears | P0 | | | |
| C.6 | Manager PIN entered correctly | Override applied; audit log row recorded | P0 | | | |
| C.7 | Manager PIN entered wrong 3× | 429 / lockout | P0 — prevents unauthorized discount/fiscal manipulation | | | |
| C.8 | Receipt void within window | Reverses receipt; audit log row recorded | P0 | | | |
| C.9 | Z-report close at end of shift | Server recompute matches POS archive; chain extended | P0 | | | |
| C.10 | Z-report PDF download | PDF renders with all legal fields filled | P0 — legal/fiscal field content | | | |
| C.11 | Put the thermal printer to sleep, then trigger a print | Printer wakes from sleep and completes the print without operator intervention beyond the trigger | P0 — the fiscal receipt must be deliverable to the customer | | | |
| C.12 | Reprint a previous receipt on demand | Exact reprint with correct fiscal data (no new chain entry, no duplicate fiscal sequence) | P0 | | | |
| C.13 | Disconnect the printer mid-operation, then reconnect | POS surfaces a clear disconnect error, recovers cleanly on reconnect; no lost sale, no fiscal-chain corruption | P0 | | | |

### D. Fiscal Hash-Chain Verification (◆repaired round-4 fix 1 — real target-scoped verifiers only)

> The previous D.1/D.2 referenced a nonexistent `fiscal:verify-chain` (singular) command family. Repaired
> per v5.1 Lane D2-a using the three real verifiers below, each explicitly scoped to the deployed
> tenant/company/terminal. Signatures verified against
> `apps/api/app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php`,
> `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php`,
> `apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php`, and
> `docs/runbooks/fiscal-verify-all-chains.md`.

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| D.1 | `php artisan fiscal:verify-chains --company=<COMPANY_UUID>` — document (invoice/credit-note) fiscal hash chains (`VerifyFiscalChainsCommand.php:23`) | Exit 0; output ends "Status: ALL CHAINS VALID ✓" | P0 | | | |
| D.2 | `php artisan pos:verify-chains --company=<COMPANY_UUID> --terminal=<TERMINAL_UUID> --type=all` — legacy POS receipt chain **and** `pos_z_reports` Z-chain mirror (`VerifyPosChainCommand.php:35`) | Exit 0; output ends "All chains verified successfully." | P0 | | | |
| D.3 | `php artisan fiscal:verify-event-chain --tenant=<TENANT_UUID> --terminal=<TERMINAL_UUID> --chain-context=operational --actor-id=<SYSTEM_USER_UUID>` — canonical fiscal-events chain (v3/v4 device-authority). **`--actor-id` is mandatory; the command fails without it** (`VerifyEventChainCommand.php:70-103`, permission gate `fiscal.events.verify_chain`) | Exit 0; output "chain verified — ... no quarantine incidents." | P0 | | | |
| D.4 | Repeat D.3 once per additional active terminal on the tenant, if more than one | Exit 0 for every terminal | P0 | | | |
| D.5 | Audit log: most recent privileged events present (role-assign, void, refund, discount-override, Z-report close) | Each event row queryable with tenant/company/actor | P0 | | | |

Never pass `--fix` to `fiscal:verify-chains` unattended during this smoke (it rewrites chain links).

### E. Cash Counting + Drawer Close

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| E.1 | Open cash drawer at shift start | Opening balance recorded | P0 | | | |
| E.2 | Mid-shift cash drop | Drop recorded; counter decrements | P0 | | | |
| E.3 | End-of-shift cash count | Variance computed; Z-report uses recorded count | P0 | | | |

### F. Offline Sync + Backlog Drain (◆repaired round-4 fix 1 — ≥10 minutes / 5+ receipts)

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| F.1 | Disconnect POS Wi-Fi | POS continues to take sales offline | P0 | | | |
| F.2 | While offline, keep the terminal offline for **at least 10 minutes** and ring up **at least 5 receipts** | All 5+ receipts recorded locally with an unbroken local hash-chain sequence | P0 | | | |
| F.3 | Reconnect Wi-Fi after the ≥10-minute offline segment | Receipts sync upstream; no duplicates | P0 | | | |
| F.4 | POS-to-server reconciliation check | Server-side receipt count matches POS local count (includes all 5+ offline receipts) | P0 | | | |

### G. Backup + Restore Drill

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| G.1 | Run backup runbook command (`docs/pos-operations/backup.md`) | Archive created at documented path; checksum recorded | P0 | | | |
| G.2 | Restore drill on isolated DB clone (`docs/pos-operations/restore.md`) | Row counts match; checksums match | P0 | | | |
| G.3 | Verify chain integrity post-restore (re-run the Section D verifiers against the restored terminal) | Hash chain reconnects without break | P0 | | | |

### H. Pre-Cutover Final Checks

| # | Action | Expected | Gate | Status | Actual Outcome | Evidence |
|---|---|---|---|---|---|---|
| H.1 | Check production CORS not `*` with credentials | CORS allow-list explicit; M1.8 test passes | P0 | | | |
| H.2 | `composer audit --no-interaction` | "No security vulnerability advisories found" | non-P0 — dependency-hygiene check; a known advisory does not by itself alter fiscal state, move money, or destroy data (closes via owner risk acceptance if a real advisory is found) | | | |
| H.3 | `pnpm audit --audit-level moderate` | "No known vulnerabilities found" | non-P0 — same rationale as H.2 | | | |
| H.4 | `APP_ENV=production php artisan list --raw \| grep -c '^sweep:'` | `0` | P0 — an exposed destructive sweep command in production is a data-durability risk | | | |
| H.5 | `APP_DEBUG=false`, HTTPS only, HSTS, secure cookies | All true in production env | P0 — protects fiscal/auth data in transit | | | |

## Document-PDF/Email Disposition

For the first-tenant pilot, document-PDF generation and document-email endpoints are NOT in scope (per the M2.2/M2.3 deferred tenant-isolation fixes). They are gated off operator-facing UI; route guards remain `CrossTenantRoute`-annotated and will only ship after M2.2/M2.3 close.

## Sign-Off

PASS requires **every** listed role to sign — none is optional or conditional on "if applicable." The
Tunisia legal reviewer's row may be satisfied either by their signature here or by gate **E-4**'s recorded
owner risk-acceptance path (reason + revisit milestone); it may not be silently left blank.

| Role | Name | Date | Signature |
|---|---|---|---|
| Release engineer | | | |
| Tenant operator | | | |
| Synerivia observer | | | |
| Tunisia legal reviewer | | | |

The smoke is **PASS** only when every step in A0 and A–H has a Gate class recorded, a Status, an
`Expected = Actual Outcome` match, and an evidence path, AND every signatory above has signed (or, for the
Tunisia legal reviewer, gate E-4's risk-acceptance path is recorded instead). Per the Risk Acceptance Rule
above: any failed **P0** step is an unconditional NO-GO — no acceptance path exists. A failed **non-P0**
step routes to a recorded owner risk-acceptance entry (reason + revisit milestone) logged against gate
**E-2** in `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`; it must never be silently marked PASS.
