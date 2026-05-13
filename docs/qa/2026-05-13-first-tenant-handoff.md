# First-Tenant Pre-Launch Handoff

> **Owner:** Release owner / operations team member.
> **From:** Round 2 closure (2026-05-13).
> **Branch state:** `dev` at `e77dc08e` (Round 2 merged + pushed to `origin/dev`).
> **Engineering side:** complete. All audit-named M1.x and M2.x items closed; tests/typecheck/PHPStan/audits/PHPUnit (5935 tests) all green.
> **Remaining work:** the three items below, plus a smoke pass on the live deployment terminal. None of these require code changes.

---

## What's done (no action needed from you)

The 2026-05-12 dev-go-live audit had two rounds of remediation. Round 2 (2026-05-13) closes the engineering side:

- Five Round 1 P1 follow-ups closed (secret-rotation playbook locked, EnforceTokenTenantClaim verified, Category UI 404→422 audit, seedAuth test isolation, end-to-end rate-limit tests).
- Five tenant-isolation controllers fixed test-first (M2.1 ProductImage, M2.2 DocumentEmail, M2.3 DocumentAdditionalCost, M2.4 RoleController user lookups, M2.5 PurchaseHub cache).
- 97 `CrossTenantRoute` annotations triaged — 0 TBD remaining; first-tenant gate satisfied per the awk checks in the inventory README.
- Route-aware Content-Security-Policy added (strict on `api/*`, permissive on web), plus rate limits for `document-email` (30/hr) and `pos-terminal-activation` (20/min).
- File/PDF endpoint scope review (22 endpoints inventoried) plus AttachmentController tenant+company scope fix.

Full record: `docs/superpowers/reviews/2026-05-13-dev-remediation-round2-adversarial-review.md`.

---

## ITEM 1 — Provider-side secret rotation (CRITICAL, P0)

The `apps/api/.env.bak` file leaked production credentials in git history from 2025-12-27 through 2026-05-13. The file is gone from the working tree, but the values are still recoverable from any clone that fetched the repo in that window. Round 2 chose **Option B (revoke at provider)** over a `git filter-repo` rewrite because two collaborators already have local clones of the bad history.

### Playbook reference

`docs/security/secret-rotation-2026-05-12.md` is the source of truth — it has eight per-credential playbooks with exact commands and verification steps.

### Per-credential checklist

For each credential below, run the playbook, fill the evidence row in `secret-rotation-2026-05-12.md` (date + executor + provider-console screenshot path or redacted verification output), then check the box here.

- [ ] **APP_KEY** (Critical) — Playbook 1. Rotate Laravel encryption key. WARNING: invalidates all active web sessions and any `Crypt::encryptString()` payloads stored at rest. Run `rg -n "encrypt|Crypt::" apps/api/app` first; if any production data uses encryption, plan a re-encryption pass before rotating.
- [ ] **DB_PASSWORD** (Critical) — Playbook 2. PostgreSQL ALTER USER + restart app pool.
- [ ] **REDIS_PASSWORD** (Critical) — Playbook 3. Provider console rotate, then `php artisan config:cache && php artisan queue:restart` on the app server.
- [ ] **MAIL_PASSWORD** (High) — Playbook 4. SMTP/Mailgun/Postmark/SES console.
- [ ] **AWS_ACCESS_KEY_ID + AWS_SECRET_ACCESS_KEY** (Critical) — Playbook 5. IAM console: create new key, swap, verify, DELETE the old key (not just deactivate).
- [ ] **MEILISEARCH_KEY** (High) — Playbook 6. Provider console rotate, may need a Scout re-import.
- [ ] **REVERB_APP_ID + REVERB_APP_KEY + REVERB_APP_SECRET** (High/Medium) — Playbook 7. Rotate the trio together; rebuild the SPA so `VITE_REVERB_APP_KEY` updates.
- [ ] **SENTRY_LARAVEL_DSN** (Medium) — Playbook 8. Sentry → Settings → Projects → [project] → Client Keys (DSN).

### Connection-target review (no rotation, just confirmation)

The `.env.bak` also exposed hostnames/ports for DB, Redis, SMTP, Meilisearch, AWS S3, Reverb, etc. (not credentials, but disclose production topology). One decision required:

- [ ] Confirm these endpoints are public-facing or already-known to attackers (no follow-up); OR
- [ ] Migrate any private hosts to fresh DNS / firewall them off the public internet.

### Sign-off

Once every Critical row is `revoked` and every High row is `revoked` or `accepted-non-prod-with-owner-signoff`, fill the **Final Sign-Off** row at the bottom of `secret-rotation-2026-05-12.md`. That closes the engineering-side gate's last residual.

---

## ITEM 2 — Live-terminal smoke pass (P0)

Per the master plan §M1.7 Step 5, the first-tenant deployment requires a smoke pass on the actual deployment terminal (not just CI).

### Smoke protocol reference

`docs/qa/2026-05-12-first-tenant-smoke.md` (the protocol) and `docs/pos-operations/walkthrough-rehearsal.md` (the rehearsal script).

### Checklist

Use the release artifact + real device profile:

- [ ] App version + SHA-256 checksum match the runbook.
- [ ] Clock + timezone check passes.
- [ ] Terminal activation works.
- [ ] Cashier sale: ring up an item, take cash, confirm receipt prints.
- [ ] Refund: refund a previous receipt, confirm refund receipt + cash drawer balance.
- [ ] Discount override: trigger a manager-PIN flow, confirm rejection on bad PIN, confirm acceptance on right PIN.
- [ ] Z-Report close: close a shift, confirm Z-Report PDF, confirm fiscal-chain entry.
- [ ] Restore drill: backup, simulate failure, restore from backup, confirm row counts match.
- [ ] Wi-Fi backlog drain: take the terminal offline for ≥10 minutes, ring up 5+ receipts, bring back online, confirm sync drains within the documented window.
- [ ] Receipt printing: confirm thermal printer wakes from sleep, confirm reprint on demand, confirm printer-disconnect handling.

### Failure handling

Any failure blocks the first tenant unless **you** (release owner) record explicit risk acceptance in `walkthrough-rehearsal.md` with reason + mitigation + revisit date.

---

## ITEM 3 — Tunisia legal-pack sign-off (P0)

Per master plan §M1.7 Step 4, the Tunisia legal pack needs accountant or legal-reviewer sign-off (or written risk acceptance) before first tenant.

### What needs sign-off

- [ ] VAT rates confirmed for the target activity.
- [ ] Receipt legal-field list confirmed (legal name, tax ID, fiscal sequence, etc.).
- [ ] Cash-register certification scope confirmed (which terminals require it, what the certification covers).
- [ ] Daily fiscal close (Z-Report) format confirmed.
- [ ] Storage retention period confirmed.

### Where to record

Append to `docs/pos-operations/walkthrough-rehearsal.md` (Tunisia section) with:
- accountant or legal reviewer name
- date of sign-off
- list of items reviewed
- explicit "approved" or "approved with caveats: [list]" flag

If no sign-off available before launch, you (release owner) record explicit risk acceptance with date + revisit milestone.

---

## ITEM 4 — Pre-deploy TBD scan (engineering will land this; you run it)

The remaining `pos-operations/` runbooks may still contain `TBD` placeholders the team forgot to fill. We're adding a `scripts/preflight-runbooks.sh` that scans for `TBD/TODO/PLACEHOLDER` and exits non-zero if any are found, then wiring it into the deploy CI gate. Watch for the commit on `dev`; once it lands, your job is to run it before deploy and resolve any hits it surfaces.

---

## What I (the engineering side) am doing in parallel

So you don't duplicate effort, here's what's still moving on the engineering branch (`dev`):

1. Pre-deploy TBD scan script (Item 4 above).
2. POS receipt/Z-report PDF endpoints — adding defense-in-depth `tenant_id` predicates alongside the existing `company_id` scope.
3. AuthController `checkEmail` mitigation — neutralizing the email-existence disclosure.
4. Sampling spot-check of the 68 auto-promoted `legitimate-platform` CSV rows.
5. Smaller P2 follow-ups: Scramble consumer audit, pnpm overrides doc comment, audit-log dispatch verification, classifier pytest.

If you want to add or re-prioritize, ping me and I'll re-shuffle.

---

## Quick links

| Doc | Purpose |
| --- | --- |
| `docs/security/secret-rotation-2026-05-12.md` | Item 1 playbooks |
| `docs/qa/2026-05-12-first-tenant-smoke.md` | Item 2 smoke protocol |
| `docs/pos-operations/walkthrough-rehearsal.md` | Item 2 + 3 rehearsal + legal sign-off recording |
| `docs/superpowers/reviews/2026-05-13-dev-remediation-round2-adversarial-review.md` | Round 2 verdict + residuals |
| `docs/security/cross-tenant-route-inventory-2026-05-12.md` | CrossTenantRoute triage rationale |
| `docs/security/file-endpoint-scope-review-2026-05-12.md` | File/PDF endpoint audit |

## Sign-off

| Field | Value |
| --- | --- |
| Handoff prepared | 2026-05-13 |
| Branch state | `dev` @ `e77dc08e` |
| Operational items 1–3 | OPEN |
| Engineering item 4 | scheduled (next commit on `dev`) |
| Released to first tenant | gated on items 1–3 + 4 + the in-flight P2 follow-ups |
