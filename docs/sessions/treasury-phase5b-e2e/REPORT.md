# Treasury Phase ⑤b live E2E report

Date: 2026-07-20

Branch: `feat/treasury-phase5`

Final statement: `019f8136-6986-71af-8d7d-78314df271aa`

Repository: `BANK-02` (`e2236b2a-97ef-4134-a936-ea705a3d3f84`)

## Driven flow

The live Playwright smoke `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts` ran against the db-per-tenant demo stack and passed all six serial steps in 28.8 seconds using the committed `playwright.smoke.config.ts`.

1. Logged in as `owner@pharmabio.tn`, selected an unreconciled GL-linked bank repository, created a saved CSV profile, and configured a dedicated 1.50% card method to route to that repository.
2. Created a real +15.000 repository adjustment, issued a real -37.125 outbound expense cheque, issued a second pending cheque for the checkpoint rejection, authored and ingested a real +100.000 device fiscal card receipt, and imported a one-line duplicate primer.
3. Uploaded the main CSV in the browser. Preview reported 5 accepted lines, 1 already imported fingerprint, 1 zero row dropped, and 1 invalid-date row requiring attention.
4. Confirmed the Tier 1 adjustment, Tier 3 outbound instrument clear, and Tier 4 card batch. The Tier 4 action allocated the gross fiscal movement and created the 1.500 acquirer-fee movement, reconciling the bank's +98.500 net.
5. Created and settled the -2.500 bank-agio expense from its statement line, then ignored the -1.250 informational line with a required explanation.
6. Acknowledged the signed ignored total and completed the statement. The UI displayed `Reconciled`, `5/5`, and `0.000 TND` remaining. The public repository balance endpoint showed a 2026-07-20 checkpoint; `last_reconciled_balance` equals the statement closing balance, while the live balance correctly excludes the ignored metadata-only outflow. A same-date repository adjustment and a same-date outbound cheque clear both returned 422; the second cheque remained `received`.

The duplicate primer was voided after completion. No legacy bank-reconciliation endpoint was used.

## Evidence

- `01-import-preview-reports.png` — accepted/duplicate/zero/unparseable report and five-line preview.
- `02-tier-matches-confirmed.png` — Tier 1, Tier 3, and Tier 4 lines matched.
- `03-created-and-ignored.png` — agio line `Created and matched`; informational line documented as `Ignored`.
- `04-reconciled-checkpoint.png` — final `Reconciled`, `5/5`, and zero remaining amount.

## Out-of-band audit

After the final browser run:

```text
Tenant: 019f2313-4ff7-73aa-99fd-fc6fbbedcce4
treasury:reconcile — checked 7 repository(ies); froze 0 on cash drift; found 0 portfolio drift(s); found 0 statement alert(s); 0 error(s).
```

The demo database initially lacked the seeder-owned `403`/`4035` instrument-purpose accounts. The supported deploy command was run through tenant context before the successful drive:

```text
Payable instrument account backfill: 2 account(s) created; 0 promoted; 0 invalid account(s).
```

Fiscal projection was run with `QUEUE_CONNECTION=sync` on the phase-worktree API so the job could not be consumed by the concurrently running main-worktree worker. The normal Vite proxy configuration was restored after the run.
