# Ticket — add `channels:reconcile` as a post-migrate step to the staging/production runbook

**Opened:** 2026-08-05
**Source:** cat-(b) cross-tenant conversion wave 1, adversarial review finding **B3**
(`docs/superpowers/reviews/2026-08-05-cat-b-wave1-review.md`).
**Owner:** release owner (Phase-E runbook execution — gate **E-9**).
**Status:** open — code side shipped; the runbook line is owed.

---

## What is owed

One step, in the post-migrate section of
`docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` (and whatever production
cutover document gate **E-10** produces):

```bash
php artisan channels:reconcile        # backfills channel_webhook_directory
```

## Why it cannot be skipped

The migration `2026_08_05_000001_create_channel_webhook_directory_table.php` creates
`channel_webhook_directory` **empty**. That table is now the ONLY way the unauthenticated
`POST /api/v1/webhooks/channels/{channelId}` route can resolve which tenant owns a channel —
`channels` is a tenant table, so the callback cannot read it without already knowing the tenant.

Only two things ever write a pointer:

- the `Channel` model observer (`ChannelServiceProvider.php`), which covers channels created
  **from now on**;
- the nightly `channels:reconcile` self-heal at **03:30**, which covers everything else.

A central migration cannot backfill across tenant databases, so between deploy and the first
03:30 sweep every **pre-existing** channel's webhook returns a fail-closed 404. Not a regression
(those callbacks were 500ing on 42P01 before the fix) — but a window that one command closes.

## How to run it

- **Do NOT wrap it in `tenants:run`.** It is a `TenantScopedCommand` and iterates the central
  tenant directory itself; wrapping it would run a full fleet iteration once per tenant.
- **Gate on the exit code, not on output.** Non-zero means one or more tenants failed (missing
  database, mis-migrated `channels` table). This is one of the few commands where the exit code is
  trustworthy — `tenants:run` always exits 0, which is the second reason not to wrap it.
- **Idempotent.** `updateOrCreate` per channel; safe to re-run, expected on a re-run of the whole
  runbook.
- **It also prunes** (since 2026-08-05, review finding R2): pointers whose channel no longer exists
  in the owning tenant, and pointers whose tenant has left the central directory. A healthy fleet
  reports `Pruned 0 stale webhook directory pointer(s).` A non-zero prune count on the FIRST run
  after this deploy would be surprising (the table starts empty) and is worth investigating.

## Verification

```sql
-- CENTRAL
SELECT count(*) FROM channel_webhook_directory;
```

must equal the sum of `SELECT count(*) FROM channels` across every tenant database. Then, per
tenant, spot-check one channel end to end: `POST /api/v1/webhooks/channels/<real channel id>` with
a fresh `X-Channel-Timestamp` must reach signature verification (403 on a bad signature), **not**
404. A 404 means the pointer is missing.

## Why this ticket exists instead of the edit

`STAGING-RUNBOOK-first-tenant-2026-07-31.md` is an owner-executed Phase-E document (gate E-9,
`RERUN-ON-FINAL-CANDIDATE: YES` on every step) under a separate ownership transfer, and
`STAGING-DEPLOY-RUNBOOK-2026-07-28.md` is explicitly superseded historical record ("do not execute
it separately"). The session that shipped the code fix does not edit either. The step is also
recorded, with the same detail, in the wave-1 section of
`docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md`.
