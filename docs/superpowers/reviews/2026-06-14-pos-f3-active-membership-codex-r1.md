# Codex security review — F-3 active-membership gate for offline approvals

**Date:** 2026-06-14
**Commit reviewed:** `3e671e199` (fix — require ACTIVE company membership for offline approvals)
**Verdict:** REQUEST-CHANGES → F-3-as-scoped is correct; the two residual HIGHs
are **deferred to follow-ups by owner decision** (2026-06-14).

Codex confirmed the two gates F-3 targeted are correctly fixed and that
`MembershipStatus::Active` is the right single allowed state (Pending correctly
excluded; `status` column defaults to `'active'`, non-nullable, so no legit
active manager is wrongly excluded). `PinVerifier::verifyForApproval` is correct
defense-in-depth for server-confirmed approvals; no bypass of it found for
server-side approvals.

The two residual HIGH findings both lie **beyond F-3's scoped two gates** (the
handover scoped F-3 to `PosAuthController::pinData` + `PinVerifier`, mirroring
`AuthorizedManagersController`, and labelled it low-risk on a clean-slate launch
with no suspended managers yet). Verified real, but a broader workstream with
significant blast radius. **Owner decision 2026-06-14: track both as separate
follow-ups, keep F-3 as the scoped fix.** Captured in
`docs/superpowers/followups/2026-06-14-offline-approval-residual-security.md`.

## Finding 1 — HIGH (DEFERRED) — stale `operator_pins` never pruned
The server no longer mirrors suspended members into NEW `/pin-data` responses,
but the device never deletes `operator_pins` rows omitted from a later pull
(`operatorPinRepository` upserts only). A manager mirrored BEFORE suspension
stays offline-valid for approvals. Fix needs device-side authoritative
operator-sync (prune omitted rows for the tenant/company/terminal) or an
approval-scope TTL that fails closed when stale — with care for terminal-scoped
/ partial pulls.

## Finding 2 — HIGH (DEFERRED) — existence-based company context + open syncPins
`CompanyContext::userHasAccessToCompany` checks membership EXISTENCE, not active
status (app-wide, every company-scoped route). A suspended caller retaining a
token + `pos.operate_terminal` still passes company context and can POST
`/pos/auth/sync-pins` to rewrite any tenant user's `pos_pin` (it authorizes only
`pos.operate_terminal` and updates by tenant), then verify against the
attacker-known PIN for an active manager — which passes F-3's active filter. Fix
needs active-membership in `CompanyContext` (broad blast radius — own workstream)
+ `syncPins` authz hardening (restrict to active members / authenticated
operator / trusted device-sync identity).

## Finding 3 — LOW (noted) — account-charge manager-PIN is a divergent path
`/pos/verify-manager-pin` via `AccountChargeConfirmation` (`managerPinApi.ts`
posts only `user_id`+`pin`) is a third manager-PIN flow. It currently
**fails closed** (the `VerifyManagerPinRequest` requires scoped fields the caller
omits), so not a bypass — but it should be migrated to `verifyScopedManagerPin`.
Tech-debt, tracked in the follow-up doc.
