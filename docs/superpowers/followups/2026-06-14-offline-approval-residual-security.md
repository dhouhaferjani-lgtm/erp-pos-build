# Follow-up — residual offline-approval security (post F-3)

**Created:** 2026-06-14. **Owner decision:** track as follow-ups (do NOT fold into
the offline-first-shifts branch). Source: Codex security review of `3e671e199`
(F-3). Both findings are **verified real** but lie beyond F-3's scoped two gates.
Low urgency on a **clean-slate launch** (no suspended managers exist yet), but
must close before suspension/revocation is exercised in production.

---

## FU-1 (HIGH) — device `operator_pins` cache is not pruned on sync
**Risk:** F-3 stops NEW `/pos/auth/pin-data` responses from including suspended
members, but the device never deletes `operator_pins` rows omitted from a later
pull (`apps/pos/src/lib/db/repositories/operatorPinRepository.ts` upserts only;
no DELETE/prune). A manager mirrored BEFORE suspension keeps a valid local PIN +
cached `approval_scopes` and can approve offline overrides
(`close_shift_variance`, `discount_limit_override`, `tender_tolerance_override`,
`void_or_return_override`, `cash_drawer_control`) via
`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts` /
`approvalVerifier.ts` (client-side bcrypt against local store; does NOT call
server `PinVerifier`).

**Fix options:**
- Authoritative operator sync: after a successful full `/pin-data` pull, delete
  (or quarantine) local `operator_pins` rows not present in the response for the
  current tenant/company/terminal. **Care:** `/pin-data` is terminal-scoped and
  pulls can be partial — only prune on a confirmed full, current-terminal pull,
  or the prune could drop a legitimately-offline operator.
- OR approval-scope TTL: fail closed when `approval_scope_permissions_fetched_at`
  is older than a threshold.

**Anchors:** `apps/pos/src/lib/sync/syncService.ts` (pull + `upsertOperators`),
`operatorPinRepository.ts`, `scopedManagerPin.ts`, `approvalVerifier.ts`.

---

## FU-2 (HIGH) — existence-based company context + open `/pos/auth/sync-pins`
**Risk:** `CompanyContext::userHasAccessToCompany`
(`apps/api/app/Modules/Company/Services/CompanyContext.php`) checks membership
EXISTENCE, not active status — app-wide, every company-scoped route. A
suspended/revoked caller retaining a token + `pos.operate_terminal` still passes
company context and can POST `/pos/auth/sync-pins`
(`PosAuthController::syncPins`, authorizes only `pos.operate_terminal`, updates
`users.pos_pin` by tenant) to rewrite ANY tenant user's `pos_pin` — including an
active manager — then approve via that attacker-known PIN (which passes F-3's
active filter because the target is active).

**Fix:**
- Require ACTIVE membership in `CompanyContext::userHasAccessToCompany` and
  `getDefaultCompanyForUser`. **Broad blast radius — own workstream** (gates all
  company-scoped routes; needs a full regression pass).
- Harden `/pos/auth/sync-pins`: restrict updates to active members in the current
  company; require server-side proof the update is for the authenticated
  operator or a trusted device-sync identity.

---

## FU-3 (LOW) — account-charge manager-PIN is a divergent (but fail-closed) path
`/pos/verify-manager-pin` via `AccountChargeConfirmation`
(`apps/pos/src/api/managerPinApi.ts` posts only `user_id`+`pin`) is a third
manager-PIN flow. It currently **fails closed** — `VerifyManagerPinRequest`
requires scoped fields (`company_id`, `terminal_id`, `approval_scope`,
`target_event_type`, `target_reference_id`, `reason`) the caller omits, so it
422s before the controller. Not a bypass; tech-debt. Migrate to
`verifyScopedManagerPin` with `credit_limit_override`/`account_status_override`,
or send the full scoped request shape.
