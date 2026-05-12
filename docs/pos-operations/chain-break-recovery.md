# IziPOS Chain-Break Recovery Runbook

This runbook is for operators. Hash-chain reconciliation is Synerivia-only in deploy-phase-1.

## Symptoms

- Red banner: `Chaine fiscale rompue.`
- Cashier sees intermittent payment failure.
- Receipts do not appear in `/pos/receipts` server-side.
- `offline_receipts` rows remain failed after retries.

## Immediate Rule

Do not run any `UPDATE`, `DELETE`, or manual edit against `offline_receipts` or `terminal_state`.

Operator responsibility is backup, capture, restart for known retry-cap cases, and escalation.

## Diagnosis Steps

Run in order.

1. Check connectivity:
   - POS has internet.
   - API URL is reachable.
   - Reverb/websocket connection is not required for receipt fiscal integrity, but note if it is down.

2. Inspect recent sync errors:

   ```sql
   SELECT *
   FROM sync_log
   WHERE created_at > datetime('now', '-1 hour')
   ORDER BY created_at DESC;
   ```

3. Inspect failed receipts:

   ```sql
   SELECT id, receipt_number, hash_sequence, status, retry_count, sync_error
   FROM offline_receipts
   WHERE status = 'failed'
   ORDER BY hash_sequence;
   ```

4. Capture current terminal state:

   ```sql
   SELECT terminal_id, hash_sequence, last_hash, updated_at
   FROM terminal_state;
   ```

## Recovery Decision Tree

### A. Retry-Cap Saturation Only

Condition:

```sql
sync_error LIKE '%database is locked%'
```

Action:

1. Restart IziPOS.
2. Wait for sync.
3. Re-run the failed receipt query.
4. Verify rows moved from `failed` to `pending` or synced state.

Reason: the app has an auto-recovery hook for SQLite lock retry-cap saturation on launch.

### B. Hash-Chain Mismatch

Condition:

```sql
lower(sync_error) LIKE '%hash chain%'
OR lower(sync_error) LIKE '%hash mismatch%'
OR lower(sync_error) LIKE '%chain break%'
OR lower(sync_error) LIKE '%chain broken%'
OR lower(sync_error) LIKE '%chain_broken%'
```

Action:

1. Stop trading on this terminal.
2. Run `backup.md`.
3. Run the support bundle command in `support.md`.
4. Capture exact failed receipt IDs and `hash_sequence` values.
5. Escalate to Synerivia.

Do not execute any SQL update. Fixing this requires reconciling local `terminal_state.last_hash`, `terminal_state.hash_sequence`, subsequent receipts' `previous_hash` values, and local Z-report totals. That is not safe as an operator procedure.

### C. Unknown Error Class

Action:

1. Run `backup.md`.
2. Run the support bundle command in `support.md`.
3. Escalate to Synerivia with the query captures below.

## Pre-Escalation Capture

Always capture:

```sql
SELECT id, receipt_number, hash_sequence, status, retry_count, sync_error
FROM offline_receipts
WHERE status = 'failed'
ORDER BY hash_sequence;

SELECT terminal_id, hash_sequence, last_hash, updated_at
FROM terminal_state;

SELECT *
FROM sync_log
ORDER BY created_at DESC
LIMIT 10;
```

Email the support bundle and captures to `support@otospex.com` or the configured support address with subject:

```text
CHAIN_BREAK <terminal_code> <YYYY-MM-DD>
```

## Recovery Log

Record:

```text
Date/time:
Operator:
Terminal code:
Company ID:
Symptom:
Failed receipt IDs:
Hash sequences:
Backup path:
Support bundle path:
Synerivia contact:
Decision tree branch:
Action taken:
Outcome:
```

## Explicitly Forbidden

- No manual `UPDATE offline_receipts`.
- No manual `UPDATE terminal_state`.
- No reordering receipts.
- No deleting failed receipts.
- No Z-report regeneration from edited local state.

Hash-mismatch recovery may become in-app deploy-phase-2 UX. Until then, Synerivia performs reconciliation out-of-band.
