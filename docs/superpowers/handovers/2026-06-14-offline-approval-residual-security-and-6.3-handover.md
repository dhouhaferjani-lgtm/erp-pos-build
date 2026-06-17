# Handover — finish the deferred offline-approval security follow-ups + Phase 6.3

**For:** a fresh session. **Date authored:** 2026-06-14.
**State:** Offline-first shifts **Phase 6.1 + 6.2 + F-3 are DONE and MERGED to local `dev`** (fast-forward; `dev` tip `4bb7a1f96`). **Nothing pushed to origin.** Each phase was Codex-reviewed; reviews in `docs/superpowers/reviews/2026-06-14-pos-*`. This handover covers what was deliberately deferred.

Read first: `docs/superpowers/followups/2026-06-14-offline-approval-residual-security.md` (the authoritative finding write-up) and the spec `docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md` (§7 Phase 6.3, §4.c, Decision 4).

---

## Outstanding items (priority order)

### FU-1 — HIGH — device `operator_pins` cache is never pruned (offline override risk)
**Problem.** F-3 stopped NEW `/pos/auth/pin-data` responses from including suspended members, but the device never deletes `operator_pins` rows omitted from a later pull — `apps/pos/src/lib/db/repositories/operatorPinRepository.ts` upserts only (no DELETE/prune). A manager mirrored BEFORE suspension keeps a valid local PIN + cached `approval_scopes` and can still approve offline overrides (`close_shift_variance`, `discount_limit_override`, `tender_tolerance_override`, `void_or_return_override`, `cash_drawer_control`). The offline approval path (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts` → `approvalVerifier.ts`) verifies client-side bcrypt against the local store and **never calls the server `PinVerifier`**, so the server-side F-3 fix does not cover it.

**Fix (TDD).** Make operator sync authoritative. After a **confirmed full, current-terminal** `/pin-data` pull, delete (or quarantine) `operator_pins` rows not present in the response for that tenant/company/terminal.
- **Footgun:** `/pin-data` is terminal-scoped and a pull can be partial/failed. Only prune on a verified full successful pull for the current terminal — pruning on a partial/empty response would drop legitimately-offline operators and break offline approvals. Consider gating the prune on a non-empty, status-200, current-terminal response and never pruning the actively-logged-in operator.
- **Alternative / complement:** an approval-scope TTL — fail closed when `approval_scope_permissions_fetched_at` is older than a threshold. (`pin-data` already returns `approval_scope_permissions_fetched_at` + `server_time`.)
- **Tests:** suspended-then-omitted operator is removed after a full pull; a partial/failed pull does NOT prune; the active operator is never pruned; an offline approval by a pruned operator now fails closed.
- **Anchors:** `apps/pos/src/lib/sync/syncService.ts` (the `/pin-data` pull + `upsertOperators`, ~line 1024), `operatorPinRepository.ts`, `scopedManagerPin.ts`, `approvalVerifier.ts`.

### FU-2 — HIGH — existence-based company context + open `/pos/auth/sync-pins`
**Problem.** `CompanyContext::userHasAccessToCompany` (`apps/api/app/Modules/Company/Services/CompanyContext.php`) checks membership **existence**, not active status — and this gates **every company-scoped route app-wide**. A suspended/revoked caller retaining a valid token + `pos.operate_terminal` still passes company context and can POST `/pos/auth/sync-pins` (`PosAuthController::syncPins` authorizes only `pos.operate_terminal` and updates `users.pos_pin` by tenant) to rewrite ANY tenant user's `pos_pin` — including an active manager — then approve via that attacker-known PIN (which passes F-3's active filter because the target is active).

**Fix (TDD, sequenced — broad blast radius).**
1. Require an **active** `UserCompanyMembership` in `CompanyContext::userHasAccessToCompany` and `getDefaultCompanyForUser`. **This affects all company-scoped routes** — run a full scoped regression (auth, POS, treasury, documents) and watch for tests that seed memberships without an explicit `status` (DB default is `active`, so most are fine, but verify). Do this on its own branch/PR given the surface.
2. Harden `/pos/auth/sync-pins`: restrict updates to **active** members of the current company, and require server-side proof the update targets the authenticated operator or a trusted device-sync identity (don't let one operator rewrite another's PIN).
- **Tests:** suspended caller is rejected by company context (401/403) before reaching any company-scoped controller; `sync-pins` rejects a cross-operator PIN rewrite and a suspended-member target.
- **Note:** also re-check whether token/session revocation happens on suspension (if suspension revokes tokens, the practical exploit window narrows — but defense-in-depth still wants the active gate). See `App\Services\TenantTokenRevoker` precedent.

### FU-3 — LOW — account-charge manager-PIN is a divergent (fail-closed) path
`/pos/verify-manager-pin` via `AccountChargeConfirmation` (`apps/pos/src/api/managerPinApi.ts` posts only `user_id`+`pin`) is a third manager-PIN flow. It currently **fails closed** — `VerifyManagerPinRequest` requires scoped fields the caller omits → 422 before the controller — so it is NOT a bypass, just tech-debt divergence. Migrate it to `verifyScopedManagerPin` with `credit_limit_override`/`account_status_override`, or send the full scoped request shape. Tech-debt; bundle with FU-2 if touching POS auth anyway.

### Phase 6.3 — operational — live offline shift cycle on the Tunisia demo stack
End-to-end open→sell→close→reopen on the **Tunisia parapharmacy stack** (`docker-compose.demo.yml`, http://localhost:8088, owner@pharmabio.tn/password, POS01 @ Tunis). That compose file lives on `feat/parapharmacy-tunisia-demo`, NOT on `dev` — pull it from there (or run there). **Requires a live Tauri build** (the POS offline layer is Tauri-only; a browser can't run the sale/PIN/Z/sync/reconcile path). Verify: (a) device-minted `shift_number` continues from the server MAX after a fresh install (6.1), and (b) the remote-close advisory banner appears when a shift is closed server-side while open on the device (6.2), and clears on the next reconcile after reopening.

---

## Process + footguns (carry forward)
- **Codex review at the END OF EVERY phase** (owner instruction). The Codex runtime CANNOT write into this worktree sandbox — have it OUTPUT the review verbatim and write the file yourself to `docs/superpowers/reviews/`.
- **`dev` promotion discipline:** work on a worktree off `dev`; merge `dev` INTO your branch first (resolve), verify, THEN `git -C apps/erp.dev-consolidation merge --ff-only <branch>`. The `apps/erp.dev-consolidation` worktree carries an untracked `go-live-security-audit.md` — a ff leaves it untouched; never operate on its uncommitted work.
- **Nothing is pushed to origin.** `dev` (local) is at `4bb7a1f96`. Decide push timing with the owner.
- **NEVER run the full PHPUnit suite** (`php artisan test` no-filter / preflight) — it crashes the laptop. Always `--filter` / a file path.
- **Single-writer:** inside a `withWriteTransaction('fiscal')` job use ONLY the `tx` handle; never call a self-transacting wrapper (deadlock). Sync-path single statements on the pooled `db` are fine; never `BEGIN` through the plugin pool.
- **Migration numbering is a global UNIQUE key** — the next device SQLite migration is **v56** (v54 = shift_number_seed, v55 = local_shifts terminal+number UNIQUE).
- **Pre-existing test failure** (not yours): `syncService > pullProducts > deleted_ids tombstone` is red on `dev` (cross-location-stock merge).
- **Commit messages** end with the Co-Authored-By line; commit only the files for the unit.

## First action for the next session
Start at **FU-1** (most directly continues the offline-approval risk and is contained to the device sync path) on a fresh worktree off `dev`. Then **FU-2** on its own branch (broad blast radius — sequence the CompanyContext change carefully). FU-3 folds into FU-2 if touching POS auth. Phase 6.3 needs the live Tauri/demo stack and can run independently.
