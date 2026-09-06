# Team checklist — staging facts and hardening items (Khalil, Dhouha) — 2026-09-07

Tick a box when done and add a comment line under the item (evidence: value seen, screenshot path, command output). Commit this file on `dev` path-scoped (`git commit -m "checklist: <item>" -- docs/handoff/TEAM-CHECKLIST-staging-facts-and-hardening-2026-09-07.md`). Ask the orchestrator session before changing anything outside this file.

## 1. Dokploy / staging facts (Khalil)

- [ ] **U-2** `TENANCY_DB_PER_TENANT` is set to `true` in the staging **API** application environment (Dokploy → application → Environment). Expected: true (we go live DB-per-tenant). Comment: value + screenshot path.
- [ ] **U-1** Staging topology: is the ERP staging one compose stack (`docker-compose.staging.yml`) or separate Dokploy applications (api, worker, web)? Comment: app names.
- [ ] **U-3** Staging API application id (Dokploy URL segment). Comment: id.
- [ ] **U-6** `SYNC_PERMISSIONS_ON_BOOT` present in the staging API environment? Value? Comment.
- [ ] **U-7** Confirm the API application has `autoDeploy` enabled on `dev` (push → build). Comment.
- [ ] **Web app** id `mY6P_PHb4pw-2LdG1Y7Ml` still current, `autoDeploy=false` confirmed. Comment.
- [ ] **D4** Staging Dokploy reachable and API `/health` green today. Comment: timestamp.
- [ ] **D6** Redis reachable from api AND worker containers (`php artisan tinker --execute="Cache::store('redis')->put('probe',1,10); echo Cache::store('redis')->get('probe');"` in each container). Comment.
- [ ] **D7** Web is served over HTTPS on the staging origin (so `crypto.randomUUID` works). Comment: URL.
- [ ] **Backups** Host-side `pg_dump` path/credentials for staging PG (`157.180.71.252:5434`, creds in Dokploy `postgres-one`) verified with one dry run to a local file (do not restore). Comment: file size.

## 2. Staging retests (Dhouha)

- [ ] **D5a** Tester login for a **second company** import on staging works; `units_not_seeded` 422 no longer appears. Comment.
- [ ] **D5b** F-BUG-1 retest (company switching keeps the working location). Comment.
- [ ] **G-14** Second-balance-file import skip: still reproduces? Comment.
- [ ] **DEV-QA registry**: add the registry file to the repo under `docs/qa/DEV-QA-registry.md` (or share the link here). Comment.

## 3. Real-device POS campaign (Dhouha + Khalil, Windows Tauri build)

Build to test: current staging device build (record the version shown in the POS about screen). Two terminals if possible.
- [ ] Open shift with float; 5 cash sales incl. one with a 3-decimal VAT split; one refund; one voucher if configured. Comment: receipt numbers.
- [ ] Offline: disconnect network, 3 sales, reconnect, verify sync + no duplicates in web receipts. Comment.
- [ ] Crash: kill the app mid-sale after payment, relaunch, verify the sale is either complete or absent (never half). Comment.
- [ ] Printer: receipt prints; check totals and VAT lines match the screen. Comment.
- [ ] Shift close / Z: counted vs expected, note the difference shown; compare with the web shift page. Comment.
- [ ] Two terminals on one drawer (if available): note what each shows for opening float and expected cash. Comment.
- Log every defect as `docs/superpowers/tickets/2026-09-07-device-<short>.md` with steps, build version, screenshots.

## 4. Mobile (erp-mobile)

- [ ] The 4 physical/manual items from the Codex mobile lane (see `project_session_n` handover in `docs/handoff/`) — mark each. Comment.
- [ ] Production census re-run after the mobile merge. Comment.

## 5. Questions for the client's accountant (owner)

- [ ] Establishment numbering: one sequential series per establishment with a prefix — confirm what the tax office expects on receipts and invoices for établissements secondaires (000/001/002).
- [ ] Item tax classification review, stamp on credit notes (optional, non-recoverable), configured books.
