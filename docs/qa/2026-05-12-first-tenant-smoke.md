# First-Tenant Smoke Protocol — 2026-05-12

> **Status:** DRAFT — finalize against the actual deployment terminal before go-live.
> **Audit reference:** `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md`
> **Plan reference:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md` §M1.6

This protocol is the day-of-launch verification for the first controlled tenant pilot. It is narrower than the full backend test suite (which is run separately as the M1.6 gate, see `2026-05-12-backend-test-exceptions.md`) but covers every path the first tenant operator will exercise.

The smoke is run **after** software install / migration / restore drill, **before** the first real customer transaction.

## Owner

- TBD (release engineer)

## Pre-conditions

- Tenant + company seeded; super-admin / tenant-admin accounts created.
- POS terminal activated; manager PIN configured.
- Currency, tax rates, payment methods configured.
- Receipt printer paired and tested in install runbook.
- Sentry / observability wiring verified by `support.md` checks.

## Smoke Steps

Each step records: command/click path, expected outcome, actual outcome, evidence path (screenshot/log/recording).

### A. Auth + Tenant/Company Selection

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| A.1 | Log in as tenant admin via web | 200 + redirect to dashboard | | |
| A.2 | Switch active company | Company context updates without page reload | | |
| A.3 | Log out, re-log as cashier | 200 + POS UI loads with cashier role | | |
| A.4 | Failed login 5× with bad password | 429 on attempt N (rate limit) | | |

### B. POS Terminal Activation

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| B.1 | Tauri POS app: fresh install, paste activation code | Terminal activates; cashier list loads | | |
| B.2 | Restart POS app | Auto-resumes activated state; no re-prompt | | |
| B.3 | POS app offline → online transition | Backlog (if any) drains; receipts sync | | |

### C. Receipt + Z-Report

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| C.1 | Cashier sale: 2 items, cash payment | Receipt prints; receipt visible in web/admin | | |
| C.2 | Cashier sale: card payment via terminal | Receipt prints; card payment recorded with auth ref | | |
| C.3 | Cashier sale: cash refund | Refund issued; daily refund-cap counter increments | | |
| C.4 | Cashier sale: card refund | Refund issued through correct destination | | |
| C.5 | Discount override (cashier without permission) | Manager PIN prompt appears | | |
| C.6 | Manager PIN entered correctly | Override applied; audit log row recorded | | |
| C.7 | Manager PIN entered wrong 3× | 429 / lockout | | |
| C.8 | Receipt void within window | Reverses receipt; audit log row recorded | | |
| C.9 | Z-report close at end of shift | Server recompute matches POS archive; chain extended | | |
| C.10 | Z-report PDF download | PDF renders with all legal fields filled | | |

### D. Fiscal Hash-Chain Verification

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| D.1 | `php artisan fiscal:verify-chain --vertical=tn` (or active vertical) | Exit 0; all chain links verified | | |
| D.2 | `php artisan fiscal:verify-chain` across every enabled vertical | Exit 0 per vertical | | |
| D.3 | Audit log: most recent privileged events present (role-assign, void, refund, discount-override, Z-report close) | Each event row queryable with tenant/company/actor | | |

### E. Cash Counting + Drawer Close

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| E.1 | Open cash drawer at shift start | Opening balance recorded | | |
| E.2 | Mid-shift cash drop | Drop recorded; counter decrements | | |
| E.3 | End-of-shift cash count | Variance computed; Z-report uses recorded count | | |

### F. Offline Sync + Backlog Drain

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| F.1 | Disconnect POS Wi-Fi | POS continues to take sales offline | | |
| F.2 | Reconnect Wi-Fi after 5 minutes of offline sales | Receipts sync upstream; no duplicates | | |
| F.3 | POS-to-server reconciliation check | Server-side receipt count matches POS local count | | |

### G. Backup + Restore Drill

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| G.1 | Run backup runbook command | Archive created at documented path; checksum recorded | | |
| G.2 | Restore drill on isolated DB clone | Row counts match; checksums match | | |
| G.3 | Verify chain integrity post-restore | Hash chain reconnects without break | | |

### H. Pre-Cutover Final Checks

| # | Action | Expected | Status | Evidence |
|---|---|---|---|---|
| H.1 | Check production CORS not `*` with credentials | CORS allow-list explicit; M1.8 test passes | | |
| H.2 | `composer audit --no-interaction` | "No security vulnerability advisories found" | | |
| H.3 | `pnpm audit --audit-level moderate` | "No known vulnerabilities found" | | |
| H.4 | `APP_ENV=production php artisan list --raw \| grep -c '^sweep:'` | `0` | | |
| H.5 | `APP_DEBUG=false`, HTTPS only, HSTS, secure cookies | All true in production env | | |

## Document-PDF/Email Disposition

For the first-tenant pilot, document-PDF generation and document-email endpoints are NOT in scope (per the M2.2/M2.3 deferred tenant-isolation fixes). They are gated off operator-facing UI; route guards remain `CrossTenantRoute`-annotated and will only ship after M2.2/M2.3 close.

## Sign-Off

| Role | Name | Date | Signature |
|---|---|---|---|
| Release engineer | TBD | TBD | |
| Tenant operator | TBD | TBD | |
| Synerivia observer | TBD | TBD | |
| Tunisia legal reviewer (if applicable) | TBD | TBD | |

The smoke is **PASS** only when every step in A–H has an `expected = actual` match and evidence path. Any failure routes to the in-flight `docs/qa/2026-05-12-backend-test-exceptions.md` for risk acceptance, or blocks first-tenant launch outright.
