# Go-Live Security Audit — AutoERP (`dev`)

**Date:** 2026-06-14
**Branch / worktree:** `dev` @ `9177950d8` (`apps/erp.dev-consolidation`)
**Scope:** Full-application security audit ahead of first-tenant go-live. Backend (`apps/api/`, Laravel 12 / PHP 8.2), web admin (`apps/web/`, React 19), POS (`apps/pos/`, Tauri 2 / React).
**Method:** 6 parallel specialist audit passes (auth/tenancy, authorization/IDOR, injection/RCE, file-upload/media, secrets/config, web/XSS) → false-positive filtering → **direct source verification of every reported finding by the author** before inclusion. Only confirmed, code-verified findings are listed.
**Status:** This is an **audit only**. No code was modified. Findings are listed with file:line, severity, and a fix recommendation — implementation is deliberately not started.

> **Important scoping note:** the launching `/security-review` context was diff-scoped to the `docs/media-subsystem-architecture` docs branch. Per the explicit request this audit instead covers the **whole application on `dev`**, not a single diff.

---

## Executive summary

| # | Severity | Category | Location | Verified |
|---|----------|----------|----------|----------|
| 1 | **HIGH (launch blocker)** | Privilege escalation / broken authorization | `RoleController::assignRole` / `store` / `update` (+ `routes.php`) | ✅ read confirmed |
| 2 | **HIGH** | Privilege escalation (no role-rank check) | `UserController::store` / `update` (`role` field) | ✅ route + pattern confirmed |
| 3 | **HIGH** | Secrets management (leaked secret in git history, rotation still OPEN) | `apps/api/.env.bak` (commit `aee9892cc`) | ✅ recovered from history |
| 4 | **MEDIUM** | Broken object-level authz / data exposure | `PaymentController::index` (missing `company_id` scope) | ✅ read confirmed |
| 5 | **MEDIUM** | Missing request-time tenant-status gate | `ResolveTenancy` + authenticated pipeline | ✅ absence confirmed |
| 6 | **MEDIUM** | Stored XSS (unsafe sink) | `apps/pos/.../TerminalSetupPage.tsx:282` | ✅ read confirmed |
| 7 | **LOW** | Fail-open robustness in tenant DB selector | `ResolveTenancy::tenantFromBearer` | reviewed |
| 8 | **LOW** | Legacy-token grandfathering blind spot | `EnforceTokenTenantClaim` | reviewed |

**Bottom line:** Finding **#1 is a hard launch blocker** — any authenticated tenant user (e.g. a cashier) can self-promote to full tenant admin with two unprotected API calls. Findings #2 and #3 should also close before the first paying tenant. The tenancy/DB-isolation core is otherwise well-defended (database-per-tenant + pre-auth tenant binding + token tenant-claim enforcement), and the injection / file-upload / media-storage surfaces came back **clean**.

---

## Findings

### Finding 1 — HIGH (LAUNCH BLOCKER): Privilege escalation via unprotected role management

**Category:** `broken_authorization` / `privilege_escalation`
**Files:**
- `apps/api/app/Modules/Identity/routes.php:59-63, 78-80`
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:114` (`store`), `:154` (`update`), `:273` (`assignRole`), `:315` (`removeRole`)

**Description.** The role-management and role-assignment endpoints carry **no authorization enforcement**. The route group applies only `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` — there is **no `permission:` middleware** — and the controller methods take a bare `Request` (not a FormRequest with `authorize()`) and contain **no `can()` / `authorize()` / Gate check**. The route comments ("requires `roles.manage` permission", "requires `users.assign-roles` permission") are aspirational; the permissions exist in `RolesAndPermissionsSeeder` but are never checked.

Verified bodies:
- `store` (line 114): validates `name` + `permissions.*` against the global catalog, then `Role::create(...)` + `$role->syncPermissions($validated['permissions'])`. No authz.
- `assignRole` (line 273): validates `role` exists, resolves the tenant user, then `$user->assignRole($roleName)` with no permission check and **no role-rank check**. (It does emit a `RoleAssigned` audit event — but auditing is not authorization.)
- `update` (line 154): blocks *renaming* system roles, but still allows `syncPermissions()` of arbitrary permissions onto any non-system role.

**Exploit scenario.** A seeded `cashier` (no `users.*`, no `roles.*` permissions) logs in normally, then:
1. `POST /api/v1/users/{their-own-id}/roles` with `{"role":"admin"}` → instantly becomes tenant admin (the `admin` role is seeded with `Permission::all()`); **or**
2. `POST /api/v1/roles` with `{"name":"pwn","permissions":["users.assign-roles","invoices.post","reports.financial"]}` then self-assign it — gaining arbitrary high-privilege permissions (fiscal posting, treasury, user management) without ever touching the `admin` role.

Both give full control over fiscal/financial/customer data within the tenant.

**Confidence:** 9/10 (route absence of `permission:` middleware and controller absence of any authz both confirmed by direct read).

**Fix.**
- Add `->middleware('permission:roles.manage')` to `roles.store/update/destroy`, and `->middleware('permission:users.assign-roles')` to the user-role assign/remove routes.
- Add an in-controller **rank guard**: reject granting `super-admin`/`admin`/`owner` (or syncing those permissions into a role) unless the actor themselves holds them, and restrict assignable `permissions[]` to a subset of the actor's own grants. This prevents an actor with `roles.manage` from escalating beyond their own level.

---

### Finding 2 — HIGH: User create/update accepts any role with no rank check

**Category:** `privilege_escalation`
**Files:** `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php` (`store` ~:166, `update` ~:242); `CreateUserRequest` / `UpdateUserRequest`.

**Description.** `users.store`/`users.update` are correctly gated by `users.create`/`users.update`, but the `role` field is validated only as `['required','string','exists:roles,name']` — **any** role name is accepted, including `admin` (which carries `Permission::all()`). A user holding only `users.create`/`users.update` (e.g. a custom "HR" role) can create or promote an account into `admin`, escalating above their own privilege level.

**Exploit scenario.** An "HR" user with `users.create` calls `POST /api/v1/users` with `{"name":"x","email":"x@e.com","role":"admin"}`, or `PATCH /api/v1/users/{victim}` with `{"role":"admin"}`, minting/elevating an admin account they control.

**Confidence:** 8/10 (route gating and free-text `role` validation pattern confirmed; consistent with Finding 1).

**Fix.** In the FormRequests, validate `role` against a **caller-scoped** allowed-role list (only roles the actor holds or outranks), not the full `roles` table. Reuse the same rank guard as Finding 1.

---

### Finding 3 — HIGH: Production-style secrets recoverable from git history; rotation still OPEN

**Category:** `secrets_management`
**Evidence:** `git show aee9892cc:apps/api/.env.bak` (added `aee9892cc` 2025-12-27, file removed in `7ca37bfaf` 2026-05-13 but **bytes never purged from history**). Rotation tracker `docs/security/secret-rotation-2026-05-12.md` is **`Status: OPEN — pending provider-side revocation`**, every row `pending-provider-rotation`.

**Description.** A committed `.env.bak` exposes secret material that is still recoverable from any clone:
- `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=` (Laravel encryption / session/cookie signing — **Critical**)
- `REVERB_APP_SECRET=qdza0mqp72k3weogs4ws` (+ `REVERB_APP_KEY`) — websocket auth
- `SENTRY_LARAVEL_DSN`, plus dev-style `DB_PASSWORD` / `REDIS_PASSWORD` / `MEILISEARCH_KEY` / `AWS_*` MinIO creds.
- The same file also has `APP_DEBUG=true` (a backup artifact, not the deployed config).

**Exploit scenario.** Anyone with a repo clone (collaborator, leaked mirror, CI cache) runs `git show aee9892cc:apps/api/.env.bak`. If the **production** `APP_KEY` is unrotated, an attacker can forge/decrypt Laravel-encrypted cookies and session payloads and tamper with `Crypt::`-protected data; the Reverb secret allows forging websocket auth.

**Confidence:** 8/10 (secret concretely recoverable today; the team's own rotation record confirms provider-side revocation is **not yet complete**). Real exploitability depends on whether the deployed prod `APP_KEY` equals the leaked value — that must be confirmed, not assumed.

**Fix.** Close every row of `docs/security/secret-rotation-2026-05-12.md` to `status = revoked` **before the first-tenant gate** (priority: `APP_KEY`, `REVERB_APP_SECRET`, `SENTRY_LARAVEL_DSN`). Once rotated, the leaked values are inert; additionally `git filter-repo --path apps/api/.env.bak --invert-paths` + force-push so the bytes stop propagating to new clones.

---

### Finding 4 — MEDIUM: `PaymentController::index` leaks payments across companies in a tenant

**Category:** `broken_object_level_authorization` / `data_exposure`
**File:** `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` (`index` ~:46).

**Description.** `index()` scopes the query by `tenant_id` only. The sibling `show()` (same controller) scopes by **both** `tenant_id` and `company_id` ("Treasury is company-scoped — api.treasury.075"). In a multi-company tenant (`UserCompanyMembership` + `X-Company-Id`), a user who is a member of company A can `GET /api/v1/payments` and receive **every company's** payment ledger in the tenant — partner names, amounts, references, allocations — data they have no membership for. The inconsistency with `show()` makes this a clear omission rather than an intentional design.

**Exploit scenario.** A tenant operates two legal companies. A company-A-only user calls `GET /api/v1/payments` and reads company B's full payment history.

**Confidence:** 7/10 (requires a multi-company tenant; the divergence from the controller's own `show()` scoping is confirmed by direct read).

**Fix.** Add `->where('company_id', $companyId)` to the `index()` query, matching `show()`. Audit other Treasury/Document list endpoints for the same tenant-only-no-company omission.

---

### Finding 5 — MEDIUM: No request-time tenant-status gate (suspended tenant retains access via session)

**Category:** `authorization` / `session_management`
**Files:** `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php`; absence in `app/Http/Middleware/` and module middleware.

**Description.** Tenant suspension/archival is enforced **only at `AuthController::login`**. No middleware in the authenticated pipeline re-checks `tenant.status` per request. For **session-cookie (web SPA) auth**, suspension does not terminate access: `TenantObserver::revokeAllUserTokens` deletes `personal_access_tokens` rows only — session cookies are untouched — and `ResolveTenancy` re-binds the tenant from `session('tenant_id')` on every request with no status revalidation. (Token-based clients are partially covered because their PATs are deleted.)

**Exploit scenario.** A super-admin suspends a tenant (non-payment / fraud / off-boarding). A back-office user already logged into the SPA continues to read and mutate tenant data indefinitely — invoices, customer/partner exports, POS sales — because the only suspension check is the login gate they already passed.

**Confidence:** 7/10 (login-only enforcement and absence of a request-time gate confirmed; token revocation present but does not cover cookie sessions).

**Fix.** Add a request-time gate (extend `ResolveTenancy` after tenant resolution, or a new middleware on the authenticated `api`/`web` groups) returning 403 `ORGANIZATION_UNAVAILABLE` when `tenant.status ∈ {Suspended, Archived}`. On suspend, also invalidate sessions (per-tenant session epoch, or move the SPA to short-lived tokens).

---

### Finding 6 — MEDIUM: Stored XSS in POS terminal-setup via `dangerouslySetInnerHTML`

**Category:** `xss` (DOM, unsafe sink)
**File:** `apps/pos/src/pages/TerminalSetupPage.tsx:282-290`; template `apps/pos/src/locales/{en,fr}/pos.json` (`terminal.terminalRequested`); source field validated in `CreateTerminalRequest` / `RequestTerminalRequest` (free-text `name`, no HTML restriction).

**Description.** The "pending activation" banner renders an i18next string with `interpolation: { escapeValue: false }` through `dangerouslySetInnerHTML`, injecting `pendingTerminal.name` into the DOM as HTML. `name` is server-accepted free text (`string|max:…`) with no character/HTML sanitization (the sibling `code` field *is* regex-restricted, `name` is not). Aggravating: `apps/pos/src-tauri/tauri.conf.json` sets `"csp": null`, and the webview holds broad capabilities (`sql:allow-execute`, `fs` read/write to `$APPDATA/images`, wide `http` allowlist) — injected script could exfiltrate the local offline-SQLite receipt store or call the backend with the operator's credentials.

**Exploit scenario.** A tenant user with terminal-request rights creates a terminal named `<img src=x onerror=...>`. When an operator opens the terminal-setup screen with that terminal pending, the payload runs in the no-CSP Tauri webview. Intra-tenant stored XSS (attacker and victim share a tenant) → capped at MEDIUM.

**Confidence:** 8/10 (sink, `escapeValue:false`, and unvalidated source all confirmed by read).

**Fix.** Replace `dangerouslySetInnerHTML` with the `<Trans>` component (or split the string so `name` is a plain text node and `<strong>` comes from the component tree); drop `interpolation.escapeValue:false`. Defense-in-depth: set a restrictive `csp` in `tauri.conf.json`, and add server-side length/character sanitization on terminal `name`.

---

### Finding 7 — LOW: `ResolveTenancy::tenantFromBearer` uses the bare Sanctum token model

**Category:** `tenant_isolation` (robustness / fail-open)
**File:** `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php` (~:131).

**Description.** The security-critical tenant DB selector looks the bearer token up with `Laravel\Sanctum\PersonalAccessToken::findToken()` rather than the central-pinned `CentralPersonalAccessToken` the app registered. It works today because the default connection is still central this early in the request, but it is connection-fragile: if any earlier bootstrapper swaps the default connection (this middleware is also on the `web` group), the lookup hits the wrong DB, returns null, and the tenant is not bound — a fail-open rather than fail-closed posture. Blast radius is bounded by `EnforceTokenTenantClaim` downstream.

**Confidence:** 6/10. **Fix.** Resolve via `CentralPersonalAccessToken::findToken()` (or `Sanctum::$personalAccessTokenModel`) so tenant selection always reads the central token table regardless of current default-connection state.

---

### Finding 8 — LOW: Legacy-token grandfathering blind spot

**Category:** `authentication`
**File:** `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`.

**Description.** `EnforceTokenTenantClaim` passes any PAT carrying no `tenant:` ability ("grandfathering" pre-PR tokens). A claim-less token causes `ResolveTenancy` to bind no tenant DB, and the post-auth guard also waves it through — both layers share the blind spot. No cross-tenant read on its own; self-healing as tokens rotate (30-day TTL).

**Confidence:** 6/10. **Fix.** Before go-live, purge `personal_access_tokens` (force a global rotation), then flip grandfathering to **reject** claim-less PATs (fail-closed).

---

## Areas audited and found sound (no findings)

These surfaces were examined in depth and are notably well-defended; no concrete >70%-confidence vulnerabilities were found:

- **Multi-tenant DB isolation (core).** Pre-auth `ResolveTenancy` binds the tenant DB from the token's `tenant:<uuid>` ability before `auth:sanctum`; `CentralPersonalAccessToken` pins token lookup to central while keeping the tokenable on the tenant connection; `EnforceTokenTenantClaim` is wired on essentially every authenticated route group; queue jobs bind tenant via `QueueTenancyBootstrapper`. Cross-*tenant* IDOR is structurally mitigated by database-per-tenant. Admin guard is separated (`auth:sanctum-admin` + `EnsureSuperAdmin`, distinct `super_admins` provider). Login is tenant-scoped via `central_identities` (no cross-tenant credential acceptance). `TenantLinkSigner` (AES-256 + MAC) fails closed.
- **SQL injection.** All `whereRaw`/`orderByRaw`/`selectRaw`/`DB::select`/`DB::statement` sites use bound `?` parameters; dynamic SQL *expressions* come from `match()`/enum branches with hardcoded column names; the generic sort/filter trait whitelists columns (`in_array(..., true)`), validates direction, and escapes LIKE wildcards. No request-key-driven column/table names reach SQL.
- **Command injection / RCE.** Only one process surface (`TenantBackupService` → `pg_dump`/`pg_restore`) uses the array form of Symfony `Process` (no shell), with a system-resolved DB name. No `exec`/`shell_exec`/`proc_open`/`system`/backticks on user input. No `unserialize()`/`yaml_parse()`/`eval()` on untrusted input. POS Rust side spawns no commands.
- **File upload / media / imports.** Uploads target the non-web-served `local`/`s3` (private ACL) disks — never the public disk or `storePublicly()`; bytes are streamed through authenticated controllers, so a disguised `.php` upload cannot execute. MIME validation is content-based (`mimetypes:` / finfo / `mime_content_type`), storage filenames are server-generated UUIDs (client filename stored as display field only — no path traversal/overwrite). Image processing is pure GD (no ImageMagick/`policy.xml`, no shell-out). ZIP import defends against zip-slip (`basename()` + `realpath` containment) and zip-bombs. XLSX via PhpSpreadsheet (XXE disabled internally). Product-media controllers scope by `tenant_id` + `company_id` + `owner_id`; the intentionally-public catalog endpoint gates on `is_active_for_ecommerce` and still scopes to the resolved product's tenant.
- **CORS / debug / crypto.** `config/cors.php` only wildcards origins when `CORS_ALLOWED_ORIGINS=*` is explicitly set (env-controlled); `APP_DEBUG=false` in both committed `.env.example` and `.env.production.example`; Horizon gate requires an allowlisted email and denies null user; no Telescope. Security tokens use `Str::random`/`Password::createToken`/Sanctum; no md5/sha1 for passwords; all MAC/signature checks use HMAC-SHA256 + `hash_equals`; webhook secrets fail closed when unset. `$hidden` is set on `User` (password, `pos_pin`, remember_token), `SuperAdmin`, `TenantSigningKey` (key_material).
- **Web/POS data exposure & XSS (besides Finding 6).** Receipt Blade `{!! $qrSvg !!}` renders library-generated SVG from a server-side fiscal token (not user text); customer names/notes use escaped `{{ }}`. API responses use explicit field mapping / Resources — no raw whole-model JSON, no PIN/token/secret fields surfaced. The two web `window.location.href` assignments use static literals (no open redirect). Tauri exposes no `shell` allowlist and no Rust command taking a JS-supplied path/command.

---

## Recommended go-live gate

1. **BLOCKER — fix Finding 1** before any tenant onboards (two-call cashier→admin escalation).
2. **Before first paying tenant — Findings 2 & 3** (role-rank check on user create/update; complete and verify the OPEN secret rotation, confirm the deployed prod `APP_KEY` ≠ leaked value).
3. **Pre-launch hardening — Findings 4, 5, 6** (company-scope payment list; tenant-status request gate + session invalidation on suspend; POS XSS sink + Tauri CSP).
4. **Cleanup — Findings 7 & 8** (central token model in the resolver; purge legacy tokens + fail-closed grandfathering).

*No remediation was performed as part of this audit (read-only, as requested). Each finding above has a concrete file:line and fix to drive a separate implementation pass.*
