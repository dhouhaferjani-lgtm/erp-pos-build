# GATE 3 — Wave 3 (CSV `placement_path`)

Reviewed `plc-gate-2..HEAD` (one commit, `e4f705093`) against spec §6/§3.1/§10 and the brief's Wave 3 scope. The CSV integrity core is sound — write-free dry-run, plan-driven commit through the Phase-1 partial-unique service (`createNode`/`assignProduct`, no raw inserts), per-row transactional rollback (proven by the drift test), tenant/company scoping, code-first with deterministic name-ambiguity errors, sync/async commit parity by construction, full en/fr/ar + design-token compliance, generated types (no hand-edits). But one reachable UI crash blocks the gate:

## Findings

1. **HIGH — `_placement_plan` leaks through `errors()` and crashes the validation grid.** `ImportController::errors()` returns `$row->data` unfiltered (`ImportController.php:335`), unlike the three sibling surfaces that strip `_`-prefixed keys (`preview()` :277-281, `FailedRowsExportService` :104-108, `ResultWorkbookService` :100). `prepareJob` persists the plan object under `data['_placement_plan']` while preserving other validation errors — so a row with a resolvable `placement_path` but a bad other column (e.g. price) is `is_valid=false` and carries the plan. `ValidationGrid` builds columns from `Object.keys(row.data)` and renders `value || '-'` (`ValidationGrid.tsx:41,161`); an object child makes React throw "Objects are not valid as a React child", breaking the failed-rows panel. TS misses it because `ImportRow.data` is typed `Record<string,string>`. Fix: filter `_`-keys at `:335`; add a regression test. (Not a bulk-write-integrity/divergence defect → no Fable escalation; stays Opus-tier.)
2. **LOW — strict-mode dry-run↔commit divergence.** `commitRow` recreates a planned-existing node via `createNode` whenever id+code lookups both miss, regardless of mode (`ProductPlacementImportService.php:135-149`); a node soft-deleted between preview and commit gets silently recreated in `strict` mode, violating "existing only." Gate `createNode` on `auto_create`.
3. **LOW — no async/queued-path placement-commit test** despite the brief's sync/async-parity emphasis (parity holds structurally, but is untested).
4. **LOW cosmetic — inverted default depth→type map** (`bin` above `shelf`) at `ImportWizardPage.tsx:42`.

Note: the reviewer could not execute `ProductPlacementImportTest.php` in-session because its sandbox blocked `artisan test`; findings rest on code reading, and the drift/rollback and dry-run write-freedom behaviors were verified statically rather than through its own green bar.

VERDICT: CHANGES-REQUIRED
