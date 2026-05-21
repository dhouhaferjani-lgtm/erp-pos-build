# `fiscal_events` break-glass runbook

> **Audience:** DBA on call. The application service account cannot execute these
> steps — by design. Every step here is privileged.
>
> **Spec reference:** `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` §3.3.
> **Migration:** `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php`.

---

## 1. What the triggers enforce

The `fiscal_events` table is the server-side append-only fiscal ledger. Three
PostgreSQL triggers, all installed by Task 8's migration, enforce immutability:

| Trigger | Event | Behaviour |
|---|---|---|
| `fiscal_events_immutability_update` | `BEFORE UPDATE FOR EACH ROW` | Allows changes **only** to the whitelist `payload`, `payload_parse_status`, `integrity_status`, `integrity_exception_class`, `integrity_exception_reason`, `integrity_resolved_at`, `integrity_resolved_by`. Within that set it enforces named state transitions (see spec §3.3 + §7.5 resume path). Every other diff raises `integrity_constraint_violation`. |
| `fiscal_events_immutability_delete` | `BEFORE DELETE FOR EACH ROW` | Always raises. |
| `fiscal_events_immutability_truncate` | `BEFORE TRUNCATE FOR EACH STATEMENT` | Always raises. |

The application role also has `TRUNCATE ON fiscal_events` **revoked** during
the migration, so even a privilege-bug or SQL-injection path cannot reach the
TRUNCATE codepath at all.

This is deliberately strict. Hash chains are the audit-trail backbone for
NF525 and equivalent regimes; a silent edit anywhere in the chain invalidates
verify-chain output for every event after it.

---

## 2. When break-glass is justified

Break-glass is **not** an operational tool. The expected day-2 operations
(quarantine, resolve, replay) are first-class application paths and do **not**
require this runbook:

- **Quarantine an integrity violation** — `OutboxIngestor` flips
  `integrity_status` `verified → quarantined`; the BEFORE UPDATE trigger
  permits the transition.
- **Resolve a quarantined event** — the resolver path (`fiscal:enqueue-resolved-event-projections`,
  Task 24) flips `quarantined → verified` with `integrity_resolved_at`
  /`integrity_resolved_by` stamped; the trigger permits the transition.
- **Resolve a `canonical_parse_failure`** — same resolver flow performs the
  named `failed → parsed` payload-write resume in **one atomic UPDATE**; the
  trigger permits it (this is the v1-plan-review BLOCKER fix).

Break-glass is reserved for the long tail that the application cannot resolve:

- A regulator or court order requires a specific document be redacted (e.g.
  GDPR right-to-be-forgotten over a `payload` field that is otherwise
  write-once).
- A storage-layer corruption surfaces a row that needs to be excised so the
  chain can be re-anchored at a manual `CHAIN_RESTART` event.
- A schema-evolution backfill, signed off in writing, that the migration
  pipeline cannot perform online.

If your incident does **not** match one of those classes, stop and route
through normal operations. If you are unsure, escalate to the fiscal owner
before opening a session.

---

## 3. Mandatory pre-step — full signed export

**Before any privileged change to `fiscal_events`, take a full export of the
table and have the export signed by the owner-on-record.** No exceptions.

```bash
# 1. Export with sequence + tenant ordering so a future verifier can rebuild
#    the chain bit-for-bit.
psql -d "$DATABASE_URL" -c "\copy (SELECT * FROM fiscal_events ORDER BY tenant_id, terminal_id, sequence_number) TO '/secure/exports/fiscal_events.$(date -u +%Y%m%dT%H%M%SZ).csv' WITH (FORMAT csv, HEADER true, ENCODING 'utf8')"

# 2. SHA-256 the export and sign the digest. Both the digest and the signature
#    must be archived alongside the export — the export alone is not evidence.
shasum -a 256 /secure/exports/fiscal_events.*.csv | tee /secure/exports/fiscal_events.sha256
# (sign /secure/exports/fiscal_events.sha256 with the on-call signing key)
```

Record in the incident ticket: the export path, the SHA-256 digest, the
signer, the timestamp, and the authorising change ticket.

---

## 4. The maintenance window

Take the application out of the path before touching the triggers — every
fiscal event landing during the window will be **unprotected**.

1. **Stop POS sync ingress.** Drain the Horizon queues for fiscal projection
   and stop the ingestion worker. Confirm no in-flight `fiscal_events`
   inserts (`SELECT count(*) FROM fiscal_events WHERE server_received_at > NOW() - INTERVAL '5 seconds'` is and stays 0).
2. **Open the maintenance window** in the change calendar, with the
   originating change ticket linked.
3. **Open a serialized, audited transaction** for the privileged work using
   the explicit, named `ALTER TABLE … DISABLE TRIGGER` sequence in §5 below.

> **DO NOT use `SET session_replication_role = 'replica'` on `fiscal_events`.**
> Contrary to a common misconception, that session-mode flag **does** suppress
> default user triggers — including these immutability triggers — and would
> create a much wider trigger-disabled window than this procedure intends
> (every BEFORE/AFTER user trigger on every table touched in the session goes
> dark, not just the three on `fiscal_events`). Use **only** the narrow,
> per-trigger `ALTER TABLE fiscal_events DISABLE TRIGGER
> fiscal_events_immutability_update;` sequence in §5, re-enabled inside the
> **same transaction** before `COMMIT`.

---

## 5. The privileged change

```sql
BEGIN;

-- Restore TRUNCATE only if the change requires it. For row-level edits, leave
-- TRUNCATE revoked and rely on UPDATE/DELETE with the triggers disabled.
-- GRANT TRUNCATE ON fiscal_events TO "<app_role>";

ALTER TABLE fiscal_events DISABLE TRIGGER fiscal_events_immutability_update;
ALTER TABLE fiscal_events DISABLE TRIGGER fiscal_events_immutability_delete;
ALTER TABLE fiscal_events DISABLE TRIGGER fiscal_events_immutability_truncate;

-- ---- The exact change. One UPDATE / DELETE. WHERE clause on `id`. No bulk. ----
-- UPDATE fiscal_events SET payload = NULL WHERE id = '<uuid>';
-- DELETE FROM fiscal_events WHERE id = '<uuid>';

-- ---- Re-enable triggers BEFORE COMMIT. Triggers re-enabled inside the same
-- ---- transaction are atomic with the change.
ALTER TABLE fiscal_events ENABLE TRIGGER fiscal_events_immutability_truncate;
ALTER TABLE fiscal_events ENABLE TRIGGER fiscal_events_immutability_delete;
ALTER TABLE fiscal_events ENABLE TRIGGER fiscal_events_immutability_update;

-- If GRANT TRUNCATE was issued above, REVOKE it back here.
-- REVOKE TRUNCATE ON fiscal_events FROM "<app_role>";

COMMIT;
```

> **Never leave the triggers disabled after `COMMIT`.** If the trigger
> re-enable fails, `ROLLBACK` the transaction and start over — do not leave
> the table unprotected even for a minute.

---

## 6. Post-change verification (in the same window)

1. **Re-run `fiscal:verify-event-chain`** (Task 31) for every `(tenant_id,
   terminal_id)` affected. Any new break is part of the incident.
2. If the change excised a row, **author a `CHAIN_BREAK_DETECTED` event** for
   the affected terminal followed by a `CHAIN_RESTART` (Task 25) so the
   verify-chain command has a recognised re-anchor point.
3. **Re-take a full export** and SHA-256 + sign it. Archive both the
   pre-change and post-change exports — the diff is the evidence trail.
4. **Resume ingress.** Re-enable Horizon queues + ingestion worker. Confirm
   `fiscal_events` inserts resume.
5. **Close the maintenance window.** File the audit packet (change ticket,
   pre-export + signature, post-export + signature, SQL transcript, verify-chain
   output, on-call signer, executing DBA, timestamps).

---

## 7. Fallback — manual `REVOKE TRUNCATE` after replay

The Task 8 migration auto-REVOKEs `TRUNCATE ON fiscal_events` from the
application role recorded in `config('database.connections.pgsql.username')`.
If your environment uses a different application role (separate read-write
role from the role the migration ran as), run the REVOKE manually after the
migration applies:

```sql
REVOKE TRUNCATE ON fiscal_events FROM "<actual_app_role>";
```

Document the executed REVOKE in the deploy log. Verify with:

```sql
SELECT grantee, privilege_type
  FROM information_schema.table_privileges
  WHERE table_name = 'fiscal_events' AND privilege_type = 'TRUNCATE';
```

No row for the application role means the BEFORE TRUNCATE belt is now backed
by the privilege-layer suspenders.

---

## 8. Audit trail — what to file

For every break-glass session, the following artifacts must land in the
fiscal audit packet for the affected period:

- The originating change ticket (with regulatory citation, if any).
- The pre-change export path + SHA-256 digest + owner signature.
- The full SQL transcript of the session (`psql` `\o` capture).
- The list of affected `fiscal_events.id`s and the `(tenant_id, terminal_id,
  sequence_number)` they correspond to.
- The post-change export path + SHA-256 digest + owner signature.
- The `fiscal:verify-event-chain` output before and after.
- The names of the on-call signer and the executing DBA.
- Window-open / window-close timestamps.

If any of the eight items is missing, the change did not happen
operationally — re-open the ticket.
