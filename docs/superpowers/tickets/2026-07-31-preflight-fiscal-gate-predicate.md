# Ticket: PreflightFiscalGateCommand trips on any provisioned terminal + JET header note

Found during Lane D1 fiscal review (2026-07-31). Two related pre-existing issues that D1's
provision-at-v3 makes the DEFAULT shape at launch:

1. `apps/api/app/Modules/Fiscal/Infrastructure/Commands/PreflightFiscalGateCommand.php:88-94` —
   `terminalChainStateCount()` counts `last_hash IS NOT NULL OR current_sequence > 0` and FAILS on
   any non-zero total. All three TerminalController creation paths write `current_sequence => 1`
   (DemoPharmacySeeder uses 0 — inconsistent) → the gate fails as soon as one terminal exists.
   Fix shape: correct the predicate (a fresh terminal at sequence 1 with NULL last_hash is a
   legitimate pre-genesis state) or document expected-non-zero in the runbook that invokes it.
2. `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:551-557` — the JET export
   terminal header reads `pos_terminals.current_sequence/last_hash`, which the fiscal-event
   projection never advances → every v3 terminal exports `current_sequence: 1, last_hash: null`
   forever. Nullable DTO, no crash; unrepresentative at first NF525 audit. Needs a compliance note
   or a v3 branch reading the fiscal-event chain head.

Not launch-blocking as code; item 1 must be resolved or documented before E-9/E-10 use any runbook
that invokes the preflight gate.
