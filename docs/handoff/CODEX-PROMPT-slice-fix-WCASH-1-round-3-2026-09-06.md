# Codex prompt — W-CASH-1 fix round 3 (paste into the SAME W-CASH-1 thread). Gate r3 found NO blockers; this round closes the majors so the plan is dispatch-ready.

---

Fix round 3 for docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md. Read docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r3.md in full and revise IN PLACE to rev 4. Read-only on everything else; no code, tests, migrations or git commands; do not ask questions; reply with the path only.

Close every MAJOR with a plan change (or reject with a path:line citation in the round-3 change log):
- M1: state the exact physical shape for every widened column (`payment_repositories.balance numeric(15,3) NOT NULL DEFAULT 0`; `last_reconciled_balance numeric(15,3) NULL`, no default; `journal_lines.debit/credit numeric(15,3) NOT NULL DEFAULT 0` — verify at HEAD). P0 tests and the architecture ratchet assert precision, scale, nullability and normalized column_default before, after and on rerun.
- M2: P0 gains the pre-widen drift census the ticket requires (read-only command listing rows whose ORM scale-3 value would differ from the stored scale-2 value, per tenant, with counts and sample ids) and names its reviewers (treasury-reviewer + stock-gl-interaction-reviewer).
- M3: compatibility-mode execution: give the exact command for the shared-database topology (`php artisan migrate --force --path=database/migrations/tenant/<file>` on the verified connection, with the output marker, exit rule and post-command information_schema queries), alongside the per-tenant `tenants:migrate-rolling --force` path for DB-per-tenant mode; cite RollingTenantMigrationCommand.php:57 and AppServiceProvider.php:283 for why both are needed.
- M4: P0 satisfies convention 09 for its own catalogue-touching change (second company via real registration, second location, rerun with explicit outcome).
- M5: T1 self-guarding migrations get a wrong-shape liveness test (deliberately wrong pre-state → migration refuses with the exact message).
- M6: T4 reversal linkage is carried in the immutable movement audit events (name the event class version per rule 8; new versioned event if payload changes).
- M7: Push 3 must be inert: keep the current scalar endpoint behaviour unchanged during Push 3; the deprecated adapter is replaced atomically at activation (Push 5) before any document exists; after first documented use a false-flag rollback stays fail-closed and never restores the documentless writer. Spell out the packaging steps.
- M8: every typed task contract lists exact production files and full signatures (no "selected by" or "as appropriate").
Close the MINORs: unknown-field red assertions consistent with the Laravel mechanism you cite; refresh the reviewed HEAD declaration to current HEAD; fix the malformed failure token in the deployment census prose.
Keep ≤6 tasks + P0; every code claim cites path:line at current HEAD; add a round-3 change log at the top.
