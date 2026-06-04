# Desktop QA — Manual Test Protocols

> Tester-facing manual test protocols for the **IziPOS / Otospex desktop application** (`apps/pos/`, Tauri 2).
> This folder is the home for step-by-step protocols a non-developer tester can follow to validate features end-to-end on a real desktop terminal.

**Maintainer:** dev team · **Audience:** QA testers, owner/operators doing acceptance testing
**Created:** 2026-06-05

---

## Why this folder exists

We ship to `dev` continuously and promote to `main` (client-facing) when ready. A lot of features landed in the last two weeks and more are in flight. Automated tests (PHPUnit / Vitest) cover the logic, but they **mock the Tauri runtime** — they do not prove the feature works on a real desktop terminal with a real local SQLite database, real sync, and a real cashier clicking buttons.

These protocols close that gap. Each one is a self-contained script: setup → preconditions → numbered steps → expected results → a fill-in scenario table → how to report issues.

> Sibling folders: `docs/qa/` holds dated release smoke protocols (broad, per-release). `docs/testing/` holds older feature-specific manual tests. **This folder is per-feature, desktop-focused, and meant to be reused every time a feature changes** — not tied to one release date.

---

## The order — protocol catalog

Run protocols **top to bottom** within a session: each group builds on the setup of the one above it (you need a working terminal + shift before you can test a deposit, etc.). Status legend: ✅ ready to run · 🟡 draft / needs review · ⬜ outstanding (not written yet).

### Group A — Foundation (must pass before anything else)
| # | Protocol | Status | Source feature(s) |
|---|----------|--------|-------------------|
| A1 | Sign-in, terminal selection, open/close shift | ⬜ outstanding | `feat/t6-*`, `feat/pos-multitenant-login` (in flight) |
| A2 | Offline-first sale + sync recovery (sell, go offline, sync) | ⬜ outstanding | POS go-live cluster |

### Group B — Customer accounts (fiscal)
| # | Protocol | Status | Source feature(s) |
|---|----------|--------|-------------------|
| **B1** | **[Customer-account deposit (money-in)](01-customer-account-deposit.md)** | ✅ **ready** | `feat/pos-customer-accounts-phase2` |
| B2 | Charge-to-account (credit sale / money-out) | ⬜ outstanding | `feat/fiscal-phase-3-charge-to-account` |
| B3 | Account status overrides + manager approval | ⬜ outstanding | `feat/fiscal-phase-4-account-status-overrides-approval` |
| B4 | Per-country tax-number validation on customer | ⬜ outstanding | `feat/fiscal-phase-1-5-2-per-country-tax-validation` |
| B5 | Parse-failure resolution UX | ⬜ outstanding | `feat/fiscal-phase-1-5-3-parse-failure-resolution-ux` |

### Group C — Inventory & catalog
| # | Protocol | Status | Source feature(s) |
|---|----------|--------|-------------------|
| C1 | Branch-to-branch stock transfer (+ WAC cost on receive) | ⬜ outstanding | `feat/inventory-transfer`, `feat/wac-divisor-basis` |
| C2 | Batch / lot management | ⬜ outstanding | `feat/batch-management-completion` |
| C3 | Monetary precision on receipts (EUR €0.02 vs TND 0.020) | ⬜ outstanding | `feat/precision-*` cluster |

### Group D — Reporting & end-of-day
| # | Protocol | Status | Source feature(s) |
|---|----------|--------|-------------------|
| D1 | Z-report (cash-count + tolerance summary) | ⬜ outstanding | `feat/fiscal-z-report-chain-clean-rebuild` |
| D2 | Owner reporting dashboard (web-admin) | ⬜ outstanding | `feat/t5-owner-reporting` |

### Group E — Multi-branch / multi-tenant
| # | Protocol | Status | Source feature(s) |
|---|----------|--------|-------------------|
| E1 | Signup → DB-per-tenant provisioning → first login | ⬜ outstanding | `feat/t6-signup-db-provisioning`, `feat/t6-tenant-db-lifecycle` |
| E2 | Multi-branch demo (parapharmacy) end-to-end | ⬜ outstanding | `feat/parapharmacy-enrichment-erp`, `feat/demo-multibranch-seed` |

> **Outstanding ≠ untested.** Outstanding means *no tester-facing protocol document exists yet*. Most of these features have automated coverage on `dev`. Writing the ⬜ protocols is the backlog for this folder — see [Contributing](#contributing-a-new-protocol).

---

## Shared environment setup

Every protocol assumes this baseline. Do it once per test session.

### What you need
- A desktop terminal running the **IziPOS / Otospex desktop app** (Tauri build), OR a dev build (`pnpm tauri dev` from `apps/pos/`).
- Network access to the backend the app points at (ask the dev team for the test server URL, or run the Laravel backend locally on `:8002`).
- Test credentials for a tenant with at least one **company**, one **terminal**, and one **cash register**.

> **Why a real desktop build (not a browser):** the POS reads its local database and connectivity through the Tauri runtime. Opening the Vite URL in a plain web browser stops at a "No Internet Connection" screen and **cannot reach the POS or record a deposit** — the database/IPC layer only exists inside the desktop shell. Always test on the packaged app or `pnpm tauri dev`.

### Baseline preconditions (the "ready to sell" state)
1. **Sign in** with your test operator. Pick the company/branch under test.
2. **Select a terminal** and **open a shift** (enter the opening cash float). Most fiscal actions — including deposits — are rejected without an **open shift**.
3. Confirm at least one **cash payment method** and one **active cash register** are configured for the company.
4. Note the company's **currency** (EUR shows 2 decimals, TND shows 3) — you'll check decimal formatting against it.

If any baseline step fails, **stop and escalate** — downstream protocols will not work.

---

## Reporting conventions (all protocols)

For each test row, record:

- **Status:** `PASS` / `FAIL` / `BLOCKED` / `SKIP` / `N/A`
- **Actual behavior:** what happened, especially if different from expected. Screenshots help — paste into the sheet or attach to the row.
- **Severity** (only if `FAIL`): `CRITICAL` / `HIGH` / `MEDIUM` / `LOW` / `INFO`
  - **CRITICAL** — data loss, fiscal-chain corruption, or a crash that blocks all work
  - **HIGH** — feature is broken (e.g., a deposit can't be recorded)
  - **MEDIUM** — works but degraded (wrong label, slow, confusing)
  - **LOW** — cosmetic (typo, color, alignment)
  - **INFO** — observation worth flagging, not actionable
- **Tester / Date.**

**Escalate immediately** for any `CRITICAL` or `HIGH` — message the dev team with the Test ID; don't wait for the end of the session. `MEDIUM` and below: hand back the filled sheet.

### Putting the table in a spreadsheet
Each protocol ends with a markdown scenario table. To track results in Excel / Google Sheets: paste the markdown into Google Sheets (it auto-converts), or use a "markdown table to CSV" converter. The `docs/qa/` Q2 smoke protocol ships a `.csv` companion if you prefer a ready-made sheet to copy from.

---

## Contributing a new protocol

1. Copy [`TEMPLATE.md`](TEMPLATE.md) to `NN-short-name.md` (use the next number within the right group).
2. Fill every section. Keep steps **observable** — each step is something the tester clicks/types and something they can see.
3. Add at least one ⚠️ **edge / negative** probe (e.g., zero amount, offline, stale data) — happy-path-only protocols miss the bugs.
4. Flip the catalog row above from ⬜ to ✅ and link the file.
5. Open a PR to `dev`.
