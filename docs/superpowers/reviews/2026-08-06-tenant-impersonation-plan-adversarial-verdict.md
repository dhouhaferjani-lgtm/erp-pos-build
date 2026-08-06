# Tenant Impersonation Plan — Adversarial Security Verdict

**Reviewed:** 2026-08-06  
**Plan:** `docs/superpowers/plans/2026-08-06-tenant-impersonation-support-access-implementation.md`  
**Verdict:** **APPROVE FOR IMPLEMENTATION** after the blocker resolution recorded below.

## Attack surface reviewed

The review treated the operator, subject user, tenant administrator, second approver, route parameters, token abilities, database state, audit storage, and frontend state as independently untrusted. It attacked tenant selection, subject/token binding, permission evaluation paths, revocation races, audit partial failure, chain concurrency/tampering, write classification, and credential transfer.

## Findings and resolutions

### BLOCKER B-1 — Company-less requests could bypass or break the tenant audit mirror

**Attack:** The existing `AuditService::record()` requires a company id and derives tenant context from Auth or `Company::find()`. Impersonated requests such as `/api/v1/auth/me` can be valid before a company is selected. Reusing that method would either invent a company, fail the audit mirror, or tempt an implementation to skip the tenant log. Any of those violates “both logs” and fail-closed behavior.

**Resolution in plan:** Task 7 now defines `TenantImpersonationAuditWriter` plus `ImpersonationAuditMirrorData`. `AuditService` must implement an explicit `recordImpersonationAccess()` that writes tenant id directly and allows `company_id = null`, which the current tenant schema permits. `SessionAuditService` calls this mirror before the controller. **Resolved.**

### BLOCKER B-2 — Subject tokenability alone does not implement the support-scope intersection

**Attack:** Minting the PAT on the subject prevents permissions above the subject, but does not narrow a powerful subject to the configured support scope. Laravel `$user->can()` and direct Spatie `$user->hasPermissionTo()` are separate evaluation paths; protecting only Gate leaves known direct checks in POS and Procurement able to bypass the support allowlist.

**Resolution in plan:** Task 4 stores only `support-scope ∩ subject-current-permissions`, Task 5 recomputes that set on every request, and the current PAT's in-memory abilities are replaced before FormRequest/controller authorization. `User` aliases and wraps both `hasPermissionTo()` and `getAllPermissions()` for impersonation tokens, while ordinary tokens retain existing behavior. Gate callbacks delegate through the wrapped permission method, so `$user->can()`, direct Spatie checks, and `/me` are narrowed. Tests remove a subject permission mid-session and require the next request to lose it. **Resolved.**

### IMPORTANT I-1 — Route-local middleware would leave unprotected tenant modules

**Attack:** The repository has many separately loaded module route files. Adding `ImpersonationContext` and `WriteGuard` only to new SupportAccess routes would let the impersonation token call existing tenant routes without the grant kill switch.

**Resolution:** Tasks 5–8 register context, write guard, audit, and response masking globally on the `api` group with explicit priority after Sanctum authentication. New module routes still follow the repository's exact auth/team/claim route stacks. Tests use existing production routes as probes. **Resolved.**

### IMPORTANT I-2 — Malformed or duplicated token claims could select an attacker-controlled identity

**Attack:** A token with two `tenant:`, `impersonation:`, or `impersonation-session:` abilities could exploit “first match wins” differences among middleware. A session id copied to another PAT could reuse a grant.

**Resolution:** Task 5 requires exactly one of each claim, exact equality among token claim, authenticated subject, central session, central grant, and resolved tenant, plus equality with `personal_access_token_id`. Any malformed impersonation-shaped token returns `IMPERSONATION_ENDED`; it is never treated as an ordinary tenant token. Existing `EnforceTokenTenantClaim` remains in the route stack without an exemption. **Resolved.**

### IMPORTANT I-3 — Revocation and expiry races could preserve one more request

**Attack:** Deleting the PAT is insufficient if the token was already loaded, and checking only session expiry ignores the grant's real-time kill-switch role.

**Resolution:** Context reloads both session and grant centrally on every request. Revocation marks the grant and sessions ended and deletes PATs, but the test deliberately preserves a live token path and proves the next request is rejected by grant status. Central repository transitions use row locks and legal enum transitions. **Resolved.**

### IMPORTANT I-4 — Cross-database audit cannot be atomic

**Attack:** Central chain append, central admin mirror, tenant mirror, and tenant business mutation span databases. If audit were written after `$next`, a committed mutation could have no complete audit. If a mirror fails midway, the chain could contain an unmatched event.

**Resolution:** The middleware writes an “authorized request” chain event and both mirrors before `$next`; a mirror failure returns 503 and the controller is never called. A failed mirror can leave a central evidence row, but cannot leave an unaudited business action. The verifier treats missing mirrors as a failure, so partial audit is visible rather than silently accepted. Domain-event enrichment records actual business outcomes as defense-in-depth. **Accepted residual:** there is no distributed transaction; the chosen failure ordering favors denying the action and retaining excess evidence, which is the only fail-safe ordering available. **Resolved for implementation.**

### IMPORTANT I-5 — Concurrent requests could fork the hash chain

**Attack:** Two requests reading the same head and appending independently produce duplicate sequences or two successors to one hash.

**Resolution:** Task 7 locks the central session row in a transaction, increments the sequence, derives the previous hash from the locked head, inserts under unique `(session_id, sequence)`, and advances the head before releasing the lock. The verifier checks sequence, previous hash, recomputed hash, and stored head. **Resolved.**

### IMPORTANT I-6 — Existing row hashes are not a session chain

**Attack:** `audit_events.event_hash` hashes one row and has no predecessor. Reusing it would not detect row deletion/reordering and would not satisfy the tamper gate.

**Resolution:** The plan introduces authoritative session events with canonical serialization and predecessor hashes, then mirrors the event id/sequence/previous/hash into both audit stores. The required tamper test mutates a snapshot and expects verification failure; database triggers independently reject update/delete of authoritative rows. **Resolved.**

### IMPORTANT I-7 — Write elevation could become a generic fiscal bypass

**Attack:** Treating `support:write` as permission to invoke every unsafe route would expose invoice posting/cancellation, receipt void/refund, fiscal ingestion, password reset, or future tenant deletion paths.

**Resolution:** The global classifier applies hard blocks after context and before audit/controller execution. It classifies route names, URIs, and controller namespaces, and tests current production routes plus future deprovision/secret-rotation patterns. Unknown mutating Fiscal-module controllers are hard-blocked. All remaining unsafe methods require a partner-approved elevation reason. **Resolved.**

### IMPORTANT I-8 — Four-eyes could fail open when configuration is empty

**Attack:** An empty approver list, a missing seeded partner, self-approval, or a deactivated partner could be interpreted as “approval not required.”

**Resolution:** Four-eyes defaults enabled; missing/invalid configuration denies approval. Approval requires a different active SuperAdmin whose normalized email is in the configured set. The partner seeder uses environment-backed name/email/password and never embeds a credential. Sensitive grant entry and every elevation remain pending until a valid approval. **Resolved.**

### IMPORTANT I-9 — Frontend credential crossover could expose the operator token

**Attack:** Writing the admin token to the tenant store or persistent storage would collapse the two identities. Replacing the admin store during impersonation would also prevent a safe exit.

**Resolution:** The admin token stays only in the existing memory-only admin store. Session start writes only the subject-scoped impersonation token to the tenant auth store. Exit clears tenant/query state and returns to the admin page while the in-memory admin token remains. Neither token is included in audit details, translations, URLs, or persisted history. **Resolved.**

### MODERATE M-1 — Generic reveal could expose the wrong resource

**Attack:** A cross-module “reveal arbitrary field by resource id” endpoint cannot securely resolve every domain's tenant/company/resource rules and would become a new data-exfiltration surface.

**Resolution:** The schema retains append-only reveal-event capability, but no generic reveal endpoint ships. Response masking is enforced and reveal-in-full is disabled, which is stricter than the Phase-3 optional capability. A future reveal must be resource-specific and separately reviewed. **Accepted and documented for handback.**

## Required implementation review checkpoints

The following evidence is mandatory before the verdict can remain valid:

1. grant revocation denies the next request even if the PAT row is artificially retained;
2. current subject permission loss narrows the very next request;
3. matching `EnforceTokenTenantClaim` remains in the real route stack;
4. central or tenant audit mirror failure prevents controller execution;
5. concurrent appends cannot fork the chain;
6. a mutated chain snapshot fails verification;
7. elevated sessions still fail every hard-blocked route;
8. tenant history contains no operator email, network metadata, hashes, or internal notes;
9. the browser never persists the admin bearer token.

## Verdict

The revised plan has no unresolved BLOCKER. Its residual cross-database audit limitation is handled in the fail-safe direction, and generic reveal is deliberately disabled rather than implemented as an unsafe abstraction. Implementation may begin under strict TDD and the milestone verdict gates.
