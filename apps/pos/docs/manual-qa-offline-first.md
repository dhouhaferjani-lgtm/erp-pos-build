# Manual QA Checklist — POS Offline-First

**Audience:** QA tester running through the offline-first P0 flow on a real terminal before dev → main graduation.
**Prereqs:** macOS, Linux, or Windows laptop with FileVault / LUKS / BitLocker enabled (see Section 0).
**Estimated time:** 60–90 min.

If any `[ ]` step fails, stop and file a report with: the step number, what happened, a screenshot, and the contents of the SQLite DB at that point (see Appendix A for how to open it).

---

## Section 0 — Environment sanity

- [ ] Working on branch `feat/pos-offline-first-p0` (or whatever the merge target is) with a clean `git status`.
- [ ] Laptop full-disk encryption is **on**. Required interim mitigation until P1 SQLCipher lands.
  - macOS: System Settings → Privacy & Security → FileVault → On.
  - Windows: Settings → Privacy & Security → Device encryption → On.
  - Linux: LUKS on the root volume.
- [ ] Postgres + the rest of the API dependencies are running (`docker compose up -d` from repo root).
- [ ] You have a clean test tenant. If unsure, run `php artisan migrate:fresh --seed` in `apps/api`.

---

## Section 1 — Launch

Run these in three separate terminals, left to right:

```bash
# Terminal 1: API server (Laravel)
cd apps/api
php artisan serve --port=8002
# Wait for: "Server running on [http://127.0.0.1:8002]"
```

```bash
# Terminal 2: POS desktop app (Tauri)
cd apps/pos
pnpm tauri dev
# Wait for the IziPOS window to open. First launch takes 30–60s (Rust compile).
```

```bash
# Terminal 3: SQLite inspector — keep this handy for every step
# See Appendix A for location and commands
```

- [ ] API server is reachable: `curl http://127.0.0.1:8002/up` returns `200`.
- [ ] POS app window opens to the login screen.
- [ ] Log in as the seeded admin user (or whichever user has POS access on your test tenant).
- [ ] Pick a company, then activate a terminal. Use the "Available terminals" tab and claim one. The banner "Terminal setup required" should appear briefly, then disappear once activation completes and the `terminal_state` row is seeded.

---

## Section 2 — Happy-path sales while online

All three flows should feel instant (<500ms perceived from button tap to success modal).

### 2.1 Cash sale

- [ ] Add 2 items to the cart. Total should display correctly.
- [ ] Tap **Pay Cash**.
- [ ] Enter a tendered amount higher than the total (e.g. total 15.00 EUR → tender 20.00).
- [ ] Confirm.
- [ ] Success modal opens showing receipt number (format: `MAIN-T<num>-<year>-<8-digit-seq>`), total, and change due (5.00).
- [ ] If a thermal printer is attached and `autoPrint` is on, the receipt prints. If not, the preview in the modal is non-empty.
- [ ] Close the modal. Cart is empty.

**Expected SQLite state** (run `SELECT id, receipt_number, status, server_receipt_id, payments_json FROM offline_receipts ORDER BY created_at DESC LIMIT 1`):
- `status`: `'synced'` (the sync tick should fire within ~10s)
- `server_receipt_id`: not null — a UUID like `9c1e...`
- `payments_json`: a JSON array with one entry, `{ "payment_method_id": "...", "amount": "15.00", "card_last_four": null, "transaction_reference": null }`

### 2.2 Card sale

- [ ] New cart with 1 item, total 30.00 EUR.
- [ ] Tap **More...** → pick **Card** (or use the card-specific payment method configured in your test tenant).
- [ ] Enter card details: last 4 = `4242`, auth reference = `AUTH-TEST-1`.
- [ ] Confirm.
- [ ] Success modal opens. Change due should be **0** (card has no change).
- [ ] **Expected SQLite**: `payments_json[0].card_last_four === "4242"`, `transaction_reference === "AUTH-TEST-1"`.

### 2.3 Split sale (the critical path)

- [ ] New cart, total 50.00 EUR.
- [ ] Tap **Advanced payments** (or equivalent — the UI element that lets you add multiple payment lines).
- [ ] Add two lines: 20 EUR cash + 30 EUR card (last 4 = `1234`, ref = `AUTH-SPLIT`).
- [ ] Total allocated must equal 50.00 before the confirm button enables.
- [ ] Confirm.
- [ ] Success modal shows total 50.00.
- [ ] **Expected SQLite** for this receipt:
  - `payments_json` is a JSON array of length **2**.
  - First entry: `{"amount": "20.00", "card_last_four": null, ...}`.
  - Second entry: `{"amount": "30.00", "card_last_four": "1234", "transaction_reference": "AUTH-SPLIT"}`.
- [ ] **Expected on the server side** (`docker compose exec pgsql psql -U autoerp -d autoerp -c "SELECT pos_receipt_id, amount, card_last_four FROM pos_receipt_payments ORDER BY created_at DESC LIMIT 2;"`): two rows for the latest receipt, one per payment line. **This is the prior silent-data-loss fix — verify it by eye.**

### 2.4 Print flow

For the last cash sale above (Section 2.1), with the success modal still open:
- [ ] If you have a thermal printer, the printed receipt shows the correct payment method **name** (e.g. "Cash") — **not a UUID**.
- [ ] The printed receipt shows VAT breakdown rows (server-assembled receipt).
- [ ] If the sync completes *while* the modal is still open, the preview content may upgrade from SQLite-assembled to server-assembled. Not user-visible unless you're watching closely — no failure here.

---

## Section 3 — Offline path (the core of this PR)

The goal: a Wi-Fi outage must not lose a single sale.

**Disable Wi-Fi:**
- macOS: Control Center → Wi-Fi → Off.
- Windows: Action Center → toggle Wi-Fi Off.
- Linux: `nmcli radio wifi off`.

Leave the POS app running. Do NOT restart it.

- [ ] A connectivity indicator somewhere in the UI should flip to "offline" within a few seconds.
- [ ] Complete **5 cash sales**, each 10 EUR, back-to-back. Each one should land on the success modal in <500ms.
- [ ] Complete **2 card sales**, each 25 EUR with `last_four: 4242, reference: OFFLINE-C<n>`.
- [ ] Complete **1 split sale**, 40 EUR = 15 cash + 25 card (`last_four: 5555, ref: OFFLINE-S1`).
- [ ] Complete a **Z-report** (close shift). The Z-report should open and show totals matching the 8 sales just completed.

**Expected SQLite state** (`SELECT receipt_number, total, status, server_receipt_id, payments_json FROM offline_receipts ORDER BY created_at DESC LIMIT 8`):
- All 8 rows have `status = 'pending'`.
- All 8 rows have `server_receipt_id IS NULL`.
- Receipt numbers are sequential and share the same terminal/year prefix.
- `payments_json` is correct per the sale type (1 entry for cash/card, 2 for split).

---

## Section 4 — Reconnect + sync

- [ ] Re-enable Wi-Fi (reverse of the step above).
- [ ] Wait ≤ 60 seconds. The connectivity indicator flips to "online". The sync scheduler ticks.

**Expected SQLite** (same query as Section 3):
- All 8 rows now have `status = 'synced'`.
- All 8 rows have `server_receipt_id` set to a UUID.
- Re-running the query a second time shows no change (idempotent).

**Expected server side** (run in a new shell):
```sql
-- From apps/api:
php artisan tinker
>>> \App\Modules\POS\Domain\Receipt::orderBy('created_at', 'desc')->limit(10)->get(['id','receipt_number','fiscal_status']);
```
- 8 receipts present, matching the `receipt_number` values from SQLite.
- All have `fiscal_status` of `'posted'` (not `'pending'` or `'failed'`).
- The split sale's `ReceiptPayment` rows are **both present** (prior bug regression check):
  ```sql
  SELECT receipt_id, COUNT(*) FROM pos_receipt_payments GROUP BY receipt_id HAVING COUNT(*) > 1;
  ```
  Should include the split receipt with count 2.

---

## Section 5 — Chain-break detection (NF525 tamper evidence)

**Why this test exists:** NF525 requires the POS to surface a tamper-evident signal when the fiscal hash chain breaks. The chain is a linked sequence of SHA-256 hashes across receipts; if any receipt's hash is altered, every subsequent receipt fails verification.

### 5.1 Simulate tampering

- [ ] In SQLite (shift is open, no active sale):
  ```sql
  -- Replace one character of a synced receipt's fiscal_hash to simulate tamper:
  UPDATE offline_receipts
  SET fiscal_hash = 'TAMPERED' || SUBSTR(fiscal_hash, 9)
  WHERE status = 'synced'
  ORDER BY hash_sequence DESC
  LIMIT 1;
  ```
  (Use the last synced row; note its `receipt_number` for later.)

- [ ] Complete **one more cash sale**. It writes to SQLite with `status='pending'` as usual — local offline-first does not verify the chain, only sync does.

- [ ] Wait for the next sync tick (≤ 60 s).

### 5.2 Verify the alert

- [ ] A **red banner** appears in the UI with title "Fiscal receipt chain broken", showing the `receipt_number` of the last successfully-synced receipt (the one *before* the tampered sale).
- [ ] The banner does NOT auto-dismiss.
- [ ] Click the "I understand" button.
  - The banner **does not disappear** — it collapses to a compact strip showing title + receipt number + "Acknowledged at HH:MM — alert remains until resolved".
  - This is intentional. Tamper evidence is persistent.

- [ ] **Expected SQLite state**:
  - The tampered receipt's `status` is `'failed'` with `sync_error` mentioning chain_broken.
  - No receipts after the tamper point were synced.

### 5.3 Recovery

- [ ] (Supervisor step.) Restore the original hash manually via SQL (or use a support-provided repair script).
- [ ] Complete a new sale. Sync should eventually resume; the ChainBreakAlert stays until a sync run returns with `chainBreak: false` (which, today, still requires manual `setChainBreak(false)` — flagged as post-P0 polish).

---

## Section 6 — Cold-start terminal bootstrap

**Why this test exists:** A fresh terminal that was shipped, powered on, but never connected to the internet would otherwise crash with a cryptic "Terminal hash chain not initialized" error on first sale.

### 6.1 Simulate cold start

- [ ] Close the POS app.
- [ ] In SQLite, delete the terminal_state row:
  ```sql
  DELETE FROM terminal_state;
  ```
- [ ] Re-open the POS app.

### 6.2 Verify the banner

- [ ] An **amber banner** appears with the title "Terminal not activated" and a "Try activation now" button.
- [ ] The **Pay Cash** / **Pay Card** / **Advanced payments** buttons are all disabled (greyed out, clicks do nothing).
- [ ] Network is still on from Section 4.
- [ ] Click **Try activation now**.
- [ ] Within ~10 s:
  - The banner disappears.
  - Checkout buttons re-enable.
  - The `terminal_state` row is re-seeded in SQLite (confirm: `SELECT * FROM terminal_state;`).
- [ ] Complete one cash sale to confirm the terminal is fully operational again.

### 6.3 Offline cold start (negative)

- [ ] Repeat 6.1 but keep Wi-Fi **off**.
- [ ] App reopens, banner appears, buttons are disabled.
- [ ] Click "Try activation now". It silently fails (no crash, but banner stays because `pullTerminalState` couldn't reach the server).
- [ ] Turn Wi-Fi back on. Click "Try activation now" again. Banner disappears as in 6.2.

---

## Section 7 — Full cycle sanity

- [ ] After all the above, open the POS app fresh, log in, open a new shift.
- [ ] Complete 1 more sale (any type).
- [ ] Close the shift (Z-report).
- [ ] All operations complete without errors.

---

## Appendix A — Finding and inspecting the SQLite DB

The POS app stores its database in Tauri's per-user app data directory:

| OS | Path |
|---|---|
| macOS | `~/Library/Application Support/com.syneriva.izipos/pos.sqlite` (path may vary by tenant — look for a `*.sqlite` file in that dir) |
| Windows | `%APPDATA%\com.syneriva.izipos\pos.sqlite` |
| Linux | `~/.local/share/com.syneriva.izipos/pos.sqlite` |

Open it with the CLI:

```bash
sqlite3 "~/Library/Application Support/com.syneriva.izipos/pos.sqlite"
```

Or a GUI like DB Browser for SQLite.

Useful queries:

```sql
-- Sync status summary
SELECT status, COUNT(*) FROM offline_receipts GROUP BY status;

-- Recent receipts
SELECT receipt_number, total, status, server_receipt_id, SUBSTR(fiscal_hash, 1, 10) || '...' as hash
FROM offline_receipts ORDER BY created_at DESC LIMIT 10;

-- Payments breakdown for the latest receipt
SELECT receipt_number, payments_json FROM offline_receipts ORDER BY created_at DESC LIMIT 1;

-- Terminal state
SELECT * FROM terminal_state;

-- Migration history (current schema version)
SELECT MAX(version) FROM _migrations;
-- Expected: 16 (offline-first P0 added migration v16)
```

---

## Appendix B — Pass/fail summary to report back

After completing all sections, file a summary like:

```
Manual QA — feat/pos-offline-first-p0

Section 1 Launch:          PASS
Section 2 Happy path:      PASS (2.1, 2.2, 2.3, 2.4 all pass)
Section 3 Offline path:    PASS
Section 4 Reconnect sync:  PASS
Section 5 Chain break:     PASS (5.1, 5.2, 5.3)
Section 6 Cold start:      PASS (6.1, 6.2, 6.3)
Section 7 Full cycle:      PASS

Environment:
- OS: macOS 14.5 / Windows 11 / Ubuntu 22.04
- POS app version: <from package.json>
- Branch: feat/pos-offline-first-p0 @ <SHA>
- Server: Laravel 12 @ php artisan serve port 8002

Issues found: none / <list>
Screenshots attached: <paths or links>
```

---

## Appendix C — If something breaks

1. **Stop the test.** Don't try to "work around" it.
2. Capture the full SQLite `offline_receipts` table as-is:
   ```bash
   sqlite3 <db-path> ".dump offline_receipts" > /tmp/offline-receipts-dump.sql
   ```
3. Copy the console output from the Tauri dev window (right-click → Inspect → Console).
4. Note exactly which step failed.
5. File an issue with the above attached.

The programmatic integration test at `apps/pos/src/__tests__/integration/offlineFirstFlow.test.ts`
covers the non-UI state transitions — if a failure here reproduces there too, it's a code bug,
not an environment issue.
