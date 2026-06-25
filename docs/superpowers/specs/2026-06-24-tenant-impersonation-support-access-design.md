# Tenant Impersonation / Consent-Gated Support Access — Design Spec

- **Date:** 2026-06-24
- **Status:** LOCKED (design approved by owner). Implementation = next cycle, **post-demo / post-launch**.
- **Author context:** Synerivia / AutoERP (Otospex + IziPOS). Multi-tenant, **database-per-tenant** (Stancl `PostgreSQLDatabaseManager`), fiscal/NF525, EU + UK + Tunisia.
- **Supersedes:** the never-written `IMPERSONATION-POLICY.md` referenced in the archived super-admin dashboard roadmap (impersonation was a documented P1 backlog item).
- **Branch note:** this doc was drafted during a research session on an unrelated branch. Move/commit it onto a proper `dev`-based branch before implementation (per dev-branch discipline).

---

## 1. Problem & motivation (why this exists)

Today internal staff **cannot reach a tenant's operational data at all** — and that is currently enforced by accident, not by a designed control:
- Staff authenticate as `SuperAdmin` under a separate guard (`sanctum-admin`); super-admin tokens are *rejected* by tenant routes (`EnforceTokenTenantClaim`).
- Super admins can see tenant **metadata** (plans, user lists, counts) but **not** products, sales, inventory, fiscal documents.
- There is **no "login as" path**.

This is good for the stated goal ("internal team cannot log in as a tenant unattended") but leaves **no sanctioned, audited way to actually help a customer**. The risk of *not* building this is the worse outcome: shared passwords, ad-hoc DB access, or staff quietly reusing admin powers — i.e. exactly the unattended access we want to prevent. A controlled impersonation feature is what *lets us slam that door shut* while still supporting customers.

It is also an emerging **SOC2 / ISO 27001** expectation: privileged access should be named, just-in-time, approved, time-boxed, and logged. This feature converts an uncontrolled risk into an auditable control.

---

## 2. Locked decisions

| # | Decision | Choice | Notes |
|---|----------|--------|-------|
| D1 | Consent model | **Both**: per-incident approval **and** optional tenant-set pre-granted window | Per-incident is the primary path (handles "it's broken, help now"); pre-granted window is opt-in convenience |
| D2 | Access level | **Read-only by default; explicit in-session elevation to write** | Elevation is separately logged with a reason |
| D3 | Second-approver | **Four-eyes for sensitive tenants/actions** | A second internal approver gates flagged tenants and/or write-elevation. Hook designed so the "sensitive" set is configurable |
| D4 | Session TTL | **60 minutes**, auto-expire | Warn ~5 min before expiry; re-request to continue; grant is the real-time kill switch |

**Defaults adopted from research (not separately asked, baked into the design):**
- Separate impersonation token carrying **both identities** (operator + subject) — never lose the real actor.
- **Permission intersection** — operator can never exceed the target user's rights (no privilege escalation).
- **Mandatory reason / ticket reference** on every session.
- **Persistent, non-dismissible banner** during impersonation.
- **Tenant is notified and can see a tenant-facing log** of support access.
- **Append-only, hash-chained audit** recording the real operator on every action (our two-tier hash chain is a genuine differentiator — none of the surveyed vendors do cryptographic tamper-evidence).
- **Equal-or-stricter masking** than the subject sees; reveal-in-full is a separate, individually-logged action.
- **Fiscal/destructive actions gated** (see §5).

---

## 3. What the operator may and may not see

Governing principle (GDPR processor-on-controller-instruction + PCI): **enough to help, never raw sensitive data.** Masking while impersonating must be **equal or stricter** than what the subject user sees — impersonation must never be a masking bypass.

**Visible (scoped to the support purpose):** orders / invoices / documents and their metadata, stock & product data, configuration & settings, error states, payment **status**, card **last-4**, the screens the subject sees.

**Masked / hidden even from an authorized operator:**
- Full payment card PAN → **last-4 only** (PCI DSS 4.0 Req. 3.3/3.4.1). Showing full PAN would pull support tooling into PCI scope.
- Passwords, API keys, tokens, secrets → **never**, to anyone.
- Full national IDs / full IBAN / bank details → masked; reveal-on-need only.
- By default: special-category data and the tenant's **customers'** full PII when not needed for the ticket.

**Reveal-in-full** is a distinct action requiring a reason and is **individually logged** (own `reveal_event`).

> Implementation note: the cross-cutting masking layer is the largest net-new surface. It is therefore **phased** (see §10) — but the design treats "operator sees masked-or-stricter" as a hard requirement, not optional.

---

## 4. Architecture

### 4.1 Identity & token model (the spine)

Issue a **short-lived Sanctum personal access token whose tokenable is the target tenant `User`**, so all existing tenant-scoped code "just works" (`auth()->user()` is the subject; `SetPermissionsTeam` scopes correctly; `ResolveTenancy` opens the right DB). The token additionally carries the **operator's real identity** so audit records actor ≠ subject.

Token abilities (example):
```
['tenant:<tenant_uuid>', 'impersonation:<operator_super_admin_id>', 'support:readonly']
```
- `tenant:<uuid>` — consumed by existing `ResolveTenancy` → initializes the tenant DB (db-per-tenant). **No new DB-context machinery needed.**
- `impersonation:<operator_id>` — marks the token as an impersonation token and preserves the real actor (the AWS `sourceIdentity` / RFC 8693 `act`-claim pattern).
- `support:readonly` vs `support:write` — reflects current elevation state (D2).
- **TTL:** 60 min (D4). Token expiry is a backstop; the **grant** is the authoritative kill switch (checked per request).

Tokens live in the **central** DB (`CentralPersonalAccessToken`), so the operator (authenticated as `SuperAdmin` under `sanctum-admin`) can mint one for a target user via a controlled admin endpoint after a valid grant exists.

### 4.2 Middleware changes

- **`ResolveTenancy`** — unchanged; already extracts `tenant:<uuid>` and initializes the tenant DB.
- **`EnforceTokenTenantClaim`** — the impersonation token's `tenant:` claim **matches the subject user's `tenant_id`** (because tokenable IS the subject user), so the existing claim check passes. No exemption hack required. (Contrast: a super-admin token would NOT match — which is why we mint a subject-scoped token instead of reusing the super-admin token.)
- **NEW `ImpersonationContext` middleware** (runs after `auth:sanctum`):
  1. Detect `impersonation:<operator_id>` ability.
  2. Load the backing **grant + session**; **fail closed** if expired / revoked / out of window → 401 `IMPERSONATION_ENDED` (this is the precise "get kicked out" behavior).
  3. Populate a request-scoped **ImpersonationContext** (`operator_id`, `session_id`, `subject_user_id`, `tenant_id`, `readonly|write`, `reason/ticket`).
  4. Expose context to the audit subsystem and to `/me` (for the banner).
- **NEW `ImpersonationWriteGuard` middleware** — when context is read-only, reject non-safe HTTP methods (POST/PUT/PATCH/DELETE) with 403 `IMPERSONATION_READ_ONLY`, except a narrow safelist. Writes allowed only when the session is write-elevated (D2 + four-eyes per D3).

### 4.3 Consent model (D1 — both)

Two ways a grant comes into existence; both produce the same `impersonation_grants` row that gates token issuance:
- **Per-incident:** operator submits a request (reason + ticket + requested duration). Tenant admin gets an in-app prompt + notification and **approves** → grant becomes active. (Shopify/Okta handshake.)
- **Pre-granted window:** tenant admin toggles "allow support access" for a window from their settings; operator may enter while active. (Salesforce/Atlassian.)

A grant is **time-boxed**, tied to a **subject user (or scope)**, carries the **reason/ticket**, and is **tenant-revocable at any time**.

### 4.4 Permission intersection (no escalation)

Effective permissions during a session = **operator's allowed support scope ∩ subject user's permissions**. The session can never do more than the subject could. Inherited automatically by minting the token on the subject user; the support scope further narrows it.

### 4.5 Write elevation + four-eyes (D2 + D3)

- Sessions start **read-only**. To write, operator requests **elevation** (reason).
- For **sensitive tenants** (configurable flag) and/or **write-elevation**, a **second internal approver** (different `SuperAdmin`) must approve before `support:write` is granted.
- **Fiscal/destructive gating (§5)** applies on top of elevation.

### 4.6 Visibility to the subject/tenant

- **Persistent non-dismissible banner** while impersonating: subject identity, purpose/ticket, **remaining time**, and an **Exit** control (exit → end session + revoke grant + delete token).
- **Tenant notification** on grant + on session start.
- **Tenant-facing, sanitized log** in tenant settings: who accessed, when, why, read/write, duration.

### 4.7 Audit (the differentiator)

Every audited event during a session records **both** identities:
- Extend `admin_audit_logs` (central) and `audit_events` (tenant) with `impersonator_id` (operator) and `impersonation_session_id`. Never collapse operator into the subject's `user_id`.
- Thread the `ImpersonationContext` into `DomainEventSubscriber::persistEvent()` and `AdminAuditService::log()` so attribution is automatic on every domain event/write.
- **Session log is hash-chained** (reuse the audit hash-chain approach) and append-only; anchor chain snapshots outside the impersonator's control (operators are privileged users who could otherwise rewrite logs).
- **Reveal-in-full events** logged individually.
- **Retention:** the longer of NF525 fiscal (6–7 yr) and DPA commitments.

---

## 5. Fiscal / destructive action restrictions (NF525)

- Fiscal records are **legally immutable** — no one, operator included, may edit/delete invoices, receipts, or fiscal documents. Corrections are only ever **new compensating entries** (void/refund/credit-note as new signed, hash-chained transactions). An impersonating operator gets **no path** ordinary users lack.
- **Financial/destructive writes under impersonation** (refunds, voids, deleting customers, bulk changes, payment-config changes) require **write-elevation + four-eyes + reason**, and appear in **both** the impersonation log and the fiscal chain, tagged actor=operator / subject=user.
- **Hard-blocked from impersonation entirely:** tenant deletion / deprovisioning / password reset / secret rotation — routed through a separate controlled admin path.

---

## 6. Mapping to existing building blocks (what we reuse)

| Need | Already exists | File / mechanism |
|------|----------------|------------------|
| Separate internal identity | ✅ | `super_admins` table, `sanctum-admin` guard, `EnsureSuperAdmin` |
| Enter a tenant's DB | ✅ | `ResolveTenancy` + `tenancy()->initialize()` / `Tenant::run()` |
| Scoped, expiring tokens | ✅ | Sanctum abilities + `expiresAt`; central `CentralPersonalAccessToken` |
| Tenant-claim enforcement | ✅ (passes for subject-scoped token) | `EnforceTokenTenantClaim` |
| Mark intentional cross-tenant ops | ✅ | `#[CrossTenantRoute]` attribute + middleware |
| Admin audit log + viewer | ✅ | `admin_audit_logs`, `AdminAuditService`, `AuditLogsPage.tsx` |
| Automatic business-event audit | ✅ | `audit_events`, `DomainEventSubscriber`, `AuditService` |
| Hash-chain tamper-evidence | ✅ (fiscal) | `CompanyHashChain`; extend approach to session log |
| Stancl `UserImpersonation` feature | ⚠️ present, disabled, **partial fit** | `config/tenancy.php:175` — built for *domain/session* login, not our token-based SPA+Tauri. **Do not rely on it**; mint a scoped Sanctum token instead |

---

## 7. New schema

- `impersonation_grants` (central): id, tenant_id, subject_user_id (nullable for scope), operator_id, type (`per_incident`|`pre_granted_window`), reason, ticket_ref, status, requested_at, approved_by, approved_at, expires_at, revoked_at, revoked_by.
- `impersonation_sessions` (central): id, grant_id, operator_id, subject_user_id, tenant_id, started_at, ended_at, end_reason, readonly|write, write_elevated_by, prev_hash, hash.
- `impersonation_reveal_events` (central or tenant): session_id, field, resource, reason, operator_id, created_at, hash.
- Columns added to `admin_audit_logs` + `audit_events`: `impersonator_id`, `impersonation_session_id`.
- Tenant settings: `allow_support_access` window fields; `is_sensitive` flag on tenant (for four-eyes).

---

## 8. API surface (indicative)

- `POST /api/v1/admin/impersonation/requests` — operator requests access (reason, ticket, subject, duration).
- `POST /api/v1/admin/impersonation/requests/{id}/approve` — second approver (four-eyes).
- Tenant-side: `POST /api/v1/support-access/grants` (pre-granted window), `POST /api/v1/support-access/requests/{id}/approve|reject`, `GET /api/v1/support-access/log`, `POST /api/v1/support-access/grants/{id}/revoke`.
- `POST /api/v1/admin/impersonation/sessions` — mint token from an active grant → returns short-lived impersonation token.
- `POST /api/v1/admin/impersonation/sessions/{id}/elevate` — request write (reason → four-eyes if required).
- `POST /api/v1/admin/impersonation/sessions/{id}/end` — exit (revoke + delete token).
- `GET /api/v1/me` — surfaces impersonation context for the banner.

---

## 9. Frontend

- **Admin (super-admin) panel:** request access, see approvals, start/end sessions, active-session list.
- **Tenant settings:** "Support access" — pre-grant window toggle, approve/reject incoming requests, live active-session indicator, revoke, sanitized history log.
- **Global banner:** persistent, non-dismissible, shows subject + purpose + remaining time + Exit; rendered whenever `/me` reports impersonation context. (None of `apps/web` has impersonation UI today — all net-new.)

---

## 10. Phasing (implementation, not design — all decisions above stand)

- **Phase 1 (MVP):** per-incident grant + approval, subject-scoped token model, `ImpersonationContext` + read-only `WriteGuard`, audit attribution (operator on every event), 60-min TTL + grant kill-switch, banner, tenant notification + tenant-facing log.
- **Phase 2:** pre-granted window; write-elevation + four-eyes; fiscal/destructive gating beyond what's already immutable.
- **Phase 3:** cross-cutting masking + reveal-on-need; hash-chain/WORM tamper-evidence on the session log; (optional) session recording.

---

## 11. Compliance mapping

- **GDPR:** vendor = processor; basis = controller's documented instruction (Art. 28) → consent-gated entry (D1). Data minimization (Art. 5) → read-only + purpose-scoped + masking. Confidentiality (Art. 28(3)(b)) for operators. Record in Art. 30 ROPA + DPA; keep a DPIA/LIA on file. EDPB necessity test → impersonation only when a lesser view won't do.
- **PCI DSS 4.0:** PAN masked to last-4 (Req. 3.3/3.4.1); never expose full PAN/CVV/secrets.
- **SOC2 / ISO 27001:** named privileged role, JIT + approval, time-box, privileged-session logging, periodic dated access reviews.
- **NF525:** fiscal immutability + 6–7 yr retention (see §5).

---

## 12. Open questions / risks

- **Tunisia (Law 2004-63 / INPDP):** consent-leaning, stricter cross-border transfer than GDPR — validate specifics with local counsel before relying on TN-specific assumptions.
- **Break-glass** (emergency access when consent can't be obtained) is intentionally **out of scope** here; if needed, design as a separate, alert-on-use, rotate-after-use, post-incident-reviewed path.
- Interaction with the **deferred go-live privesc fix** (RoleController authz) — impersonation must not become a new escalation vector; sequence after that fix.
- Masking surface (Phase 3) is large; scope carefully against actual support workflows to avoid over-building.

---

## 13. References (selected)

Industry patterns: WorkOS AuthKit impersonation; Okta/Atlassian support access; Salesforce "Grant Login Access"; Shopify collaborator handshake; AWS STS `sourceIdentity`; GitLab/Zendesk (negative lessons — silent, non-expiring, actor lost). Compliance: GDPR Art. 5/28/30; EDPB Legitimate-Interest Guidelines 1/2024; PCI DSS 4.0 Req. 3.3/3.4.1; SOC2 CC6/CC7; ISO 27001 A.8.2; NF525 (Infocert/Fiskaly). Full URLs captured in the 2026-06-24 research session transcript.
