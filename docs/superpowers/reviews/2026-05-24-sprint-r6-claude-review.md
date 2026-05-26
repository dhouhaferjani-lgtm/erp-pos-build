# Sprint v6 — Round 6 Adversarial Review (Claude / Opus 4.7)

**Scope:** T6 Phase 0 spec, migration topology contract (esp. §9 = auth architecture), roadmap auth/effort/migration-gating wording. Confirm T1 acceptance line + pos-coordination-log version label only. T2/T3/T4/T5/T11 substance NOT reviewed this round.

**Method:** Every factual claim verified against actual code (Sanctum v4.3.1, Stancl v3.10.0 — both confirmed in `composer.lock:3008,8099`). File:line citations below are lines I actually read.

---

## Verdict

**APPROVE-WITH-MINOR-EDITS** — the v6 auth architecture is *implementable* and the round-5 findings are genuinely resolved, but two material facts about the *existing* code are missing from the spec that change the effort/risk picture (no request-time tenancy layer exists today; an existing token→tenant binding mechanism is unmentioned). These are P1 "spec is incomplete/misleading," not BLOCKERs — the design still works once corrected. T6 Phase 0 can start with the P1 edits folded in.

---

## Summary

v6's email-first + central-identity-index model is sound and directly dissolves round-5 B-1. The token-bound-tenant approach (§9.4) is **architecturally implementable** on Sanctum v4.3.1: a central `PersonalAccessToken` model + a custom middleware ordered before `auth:sanctum` that reads the token on the central connection and calls `tenancy()->initialize()` will let Sanctum's `Guard::__invoke` resolve the `tokenable` User in tenant context (the morphTo lazy-load at `Guard.php:50` fires *after* tenancy init, so it hits the tenant DB). This is the correct design.

But two things the spec asserts or omits are wrong against the code:

1. **There is NO request-time tenancy initialization anywhere in the app today.** `tenancy()->initialize()` is called in exactly one place — `ResetTenantCommand.php:75` (a console command). No Stancl middleware is wired into the HTTP path. So Phase 0 is not "swap Stancl's request-data middleware for a thin token middleware" — it is *building the entire request-time tenancy layer from zero*, and applying it to **all 11 module route files** (not just `Identity/routes.php`), or every authenticated route breaks after the flip. The spec frames this as a subtraction; it is a large addition.

2. **A token→tenant binding mechanism already exists and is unmentioned.** Tokens already carry a `tenant:<uuid>` ability (`AuthController.php:222,379`) and `EnforceTokenTenantClaim` (`Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`) already validates it — but it sits *after* `auth:sanctum` (`routes.php:43`) and only *rejects mismatches*; it does not *initialize* tenancy. v6's "bind tenant to the token" reads as net-new when half of it exists. The spec must reconcile the two so the implementer doesn't build a parallel mechanism.

Everything peripheral checks out: T1 line ~200 now reads "source company's `InTransitAvailability`" (verified); pos-coordination-log header is labeled v6 (verified); roadmap is v6 / ~70 PD (verified).

---

## Round-5 resolution table

| Finding | Status | Evidence |
|---|---|---|
| **B-1** pre-auth flows assumed global users | **FIXED** | §9.1–9.5 give email-first model + `central_identities`; spec §3 work item 8 expands to login/register/verify/reset/check-email. `users` is tenant-scoped (`create_users_table.php:18` tenant_id, `:39` unique(tenant_id,email)). check-email's global lookup confirmed obsolete (`AuthController.php:427` `User::where('email')->exists()`). |
| **P1-1** Stancl request-data middleware wrong | **FIXED (with caveat)** | §9.4 binds tenant to token, removing `InitializeTenancyByRequestData` from auth path. Its constructor IS typed `RequestDataTenantResolver` (`vendor/.../Middleware/InitializeTenancyByRequestData.php:30` — verified), so §9.9's "extends" note is correct. Caveat → New-P1-2 (the existing token mechanism is unmentioned). |
| **P1-2** reference-data seeding phase contradiction | **FIXED** | Spec §3 deliverable 9 + §4 subsection both label it Phase 0; §4 explicitly says "NOT Phase 1A." `TenantInitializationService.php:207-214` no-op-on-empty-countries claim is consistent with the seed-countries-first plan. |
| **P1-3** Tier A migration caveat | **FIXED** | Roadmap §"Tier A" line 230 adds "⚠️ Migration rule" with per-track "migrations branch-dev until Phase 0 merges" on T2/T3/T4/T5/T1/batch lines 232-238. |
| **P2-1** effort arithmetic | **FIXED** | Roadmap line 110 sums 12+12+16+6+9+5+7+3 = 70; T6 spec line 6 = 12 PD Phase 0 / 19 total. Internally consistent. (Realism flagged → New-P2-5.) |
| **P2-2** super_admins "(if exists)" | **FIXED** | `super_admins` verified at `create_super_admins_table.php:14` (schema 14-30); contract §2/§9.6 drop the hedge. |
| **P2-3** reference-data filename citations | **FIXED** | Contract §8 filenames match: `create_countries_table.php` (2025_12_01_192409), `create_country_payment_settings_table.php` (2025_12_10_100000), etc. — all present in `database/migrations/`. |
| **P2-4 (T1 per-company wording)** | **FIXED** | T1 acceptance ~line 200 now "When the source company's `InTransitAvailability=Available`" (verified). |
| **P2-5** login enumeration generic | **FIXED** | §9.7 lists generic failure bodies; unknown tenant_id returns "same body as bad credentials." Reasonable. |
| **S-1 / S-2** version labels / "Tauri deltas" phrase | **FIXED** | Roadmap title "(v6)"; pos-coordination-log header "(v6 — broadened scope)"; file-rename note present (`-tauri-pos-deltas.md` → `-pos-coordination-log.md`). |

---

## New v6 findings

### BLOCKER
None. The auth design is implementable as written; the gaps below are accuracy/completeness, not architectural impossibilities.

### P1

**P1-1 — No request-time tenancy layer exists today; the spec frames a build as a swap.**
`tenancy()->initialize()` is called in exactly one place: `app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:75` (console). A repo-wide grep for `InitializeTenancyBy*` / `tenancy()->initialize` in `app/ bootstrap/ routes/` returns only that command. **No Stancl identification middleware is on the HTTP path.** Consequences the spec must state:
  - The token-bound middleware (§9.4) is net-new infrastructure, and it must be attached to **every** authenticated route group, not just `Identity/routes.php`. There are **11 module `routes.php` files using `auth:sanctum`** and 25 groups referencing `SetPermissionsTeam`/`CompanyContextMiddleware`. After the `PostgreSQLDatabaseManager` flip, any `auth:sanctum` route that loads a tenant-DB User without prior `tenancy()->initialize()` will fail to resolve the user (or resolve against the wrong DB). This is the real blast radius of Phase 0.
  - **Fix:** Spec §3 item 8 and topology §9.4 should add: "No request-time tenancy middleware exists today (`tenancy()->initialize()` only in `ResetTenantCommand.php:75`). Phase 0 builds the token-resolver middleware AND registers it on the global `api` group (and the Identity `web` group) in `bootstrap/app.php`, covering all 11 module route files — verified blast radius, not just `/api/v1/auth/*`."

**P1-2 — Existing `tenant:<uuid>` token ability + `EnforceTokenTenantClaim` is unmentioned; risk of a duplicate/parallel mechanism.**
Tokens are already minted with a `tenant:<uuid>` ability (`AuthController.php:222` login, `:379` register), and `EnforceTokenTenantClaim` (full file at `Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:47-106`) reads `currentAccessToken()->abilities`, extracts the `tenant:` claim, and rejects mismatches with `TOKEN_TENANT_MISMATCH` (401). It is wired *after* `auth:sanctum` at `routes.php:43,54,60`. v6 §9.4 presents "bind tenant to the token" as new design without reconciling against this. The distinction the spec must make explicit:
  - Existing `EnforceTokenTenantClaim` = post-auth *guard* (rejects stale claims); it does NOT initialize tenancy and runs too late to drive DB selection.
  - v6's new middleware = pre-auth *resolver* (reads token on central, initializes tenancy) so `auth:sanctum` can load the User in-tenant.
  - **Fix:** §9.4 should add a sentence: "Note: tokens already carry a `tenant:<uuid>` ability and `EnforceTokenTenantClaim` already validates it post-auth (`EnforceTokenTenantClaim.php`). Phase 0 reuses that ability as the tenant source for the new pre-`auth:sanctum` resolver middleware; it does not introduce a second binding column unless a `tenant_id` column on `personal_access_tokens` proves cleaner than parsing the ability. Decide one source of truth." (The contract §2 mandates a new `tenant_id` *column*; the codebase already encodes tenant in the *ability*. Pick one or the spec ships two mechanisms.)

### P2

**P2-1 — Web SPA cookie login does NOT carry a Sanctum PAT, so token-bound tenant resolution can't cover it.**
`AuthController::login` uses `Auth::attempt()` + `$request->session()->regenerate()` (`AuthController.php:186,196`) — i.e. stateful SPA cookie auth. For cookie auth, `Guard::__invoke` returns the session user with a **`TransientToken`** (`vendor/laravel/sanctum/src/Guard.php:32-38`), and `EnforceTokenTenantClaim` itself documents this exemption ("Non-PAT auth (session cookie / TransientToken) → pass", `EnforceTokenTenantClaim.php:34`). So a web user authenticated by cookie has **no `personal_access_tokens` row** for the §9.4 middleware to read a tenant from. §9.4's "the tenant travels with the token" silently assumes bearer-token auth on every authenticated request, which is not how the web back-office works today. **Fix:** §9.4 must state how the web SPA cookie path resolves its tenant post-login (e.g. tenant stamped in the session at login and a session-tenant resolver branch in the same middleware), or explicitly require web to switch to bearer tokens. This is the single most likely place an implementer ships a half-working auth layer.

**P2-2 — `password_reset_tokens` does NOT FK or relate to `users`; §9.5's reclassification rationale is factually wrong.**
Contract §2 and §9.5 say `password_reset_tokens` "FK/relate to tenant `users`." Actual migration `2025_11_30_160000_create_password_reset_tokens_table.php` keys the table by `email` (string primary key), with `token` + `created_at` — **no `user_id`, no FK** (verified, full file read). (By contrast `email_verification_tokens` *does* FK users at `2025_12_21_125019_*:23-26` — that half is correct.) The tenant-side reclassification is still right (reset runs in tenant context), but the stated reason is false and could mislead the implementer into looking for a non-existent FK. **Fix:** §9.5 row for forgot/reset: "`password_reset_tokens` is email-keyed (no FK); reclassify tenant-side because the reset must run under tenant context, and the Laravel `Password` broker resolves the user via the tenant `users` provider."

**P2-3 — Password broker reconfig (§9.5) is under-specified for the multi-DB model.**
`config/auth.php:103-110` defines one broker `users` → table `password_reset_tokens` → provider `users` (Eloquent on default connection). After the flip, the broker's user provider and its token repository must both operate against the *current tenant* connection. Laravel's `DatabaseTokenRepository` uses the default DB connection unless `connection` is set in the broker config; `tenancy()->initialize()` swaps the default connection, so this likely "just works" *if* tenancy is initialized before `Password::sendResetLink`/`reset` is called — which loops back to P1-1 (no resolver exists pre-auth, and forgot/reset are pre-auth). **Fix:** §9.5 should state the ordering explicitly: "forgot/reset resolve tenant via `central_identities` and call `tenancy()->initialize()` *before* invoking the `Password` broker, so the broker's `users` provider + token table both bind to the tenant connection. No custom broker class is needed *if* tenancy is initialized first; otherwise a per-tenant broker repository binding is required." Validate during Phase 0 whether the default-connection swap is sufficient.

**P2-4 — Duplicate central tables not flagged for Phase 0 classification.**
Two `tenant_subscriptions` migrations exist (`2025_12_01_193759` AND `2025_12_16_100001`) and two plan tables (`plans` 2025_12_01_193629 AND `subscription_plans` 2025_12_16_100000). Contract §2 cites only `2025_12_01_193759_create_tenant_subscriptions_table.php:14` as the canonical central table. Phase 0's "walk every migration and classify central vs tenant" (spec §3 acceptance) will trip over the duplicate. **Fix:** add a line to contract §2 / spec §3: "Note: duplicate `tenant_subscriptions` (193759 + 100001) and `plans`/`subscription_plans` migrations exist; Phase 0 must confirm which is live and classify both as central (or consolidate)."

**P2-5 — Phase 0 effort (12 PD) is optimistic given P1-1.**
The spec's own §3 item 8 estimates the Identity rewrite at 5-7 PD *in isolation*, on top of ~2 PD FK rewrites + migration moves + central connection + reference seeding + Spatie placement + PG CI strategy + flip test + fiscal coordination. With P1-1 (building the request-time tenancy layer from scratch and re-wiring all 11 route files + the web cookie path of P2-1), 12 PD is tight. round-5's independent range was 12-15 PD; the realistic number after P1-1/P2-1 is the **top of that range or above**. **Fix:** widen Phase 0 to "~12-15 PD" and call P1-1 out as the dominant cost driver.

**P2-6 — `central_identities` sync has an orphan/race surface the spec only half-covers.**
§9.1 says the index is "kept in sync by register / invite / delete + a reconcile command." But `register` (`AuthController.php:264-389`) does tenant+user creation in one `DB::transaction` on the *default* connection; the new flow must write the central `central_identities` row and the tenant-DB `users` row across **two different databases**, which cannot be one atomic transaction post-flip. If the tenant-DB write fails after the central index write (or vice-versa), the index orphans. The reconcile command mitigates but doesn't prevent. **Fix:** §9.1/§9.5 register row should specify the ordering + compensation (e.g. write central `tenants`+`central_identities` first, then tenant-DB user; on tenant-DB failure, roll back/mark the central rows; reconcile sweeps stragglers) — and note that cross-DB atomicity is impossible so this is eventual-consistency by design.

### SUGGESTION

- **S-1 — `check-email` removal is more than "drop the call."** `AccountStep.tsx` has the mutation (`:23-29`), an `onSuccess` handler setting `emailCheckError` (`:30+`), the `emailCheckError` state (`:22`), a debounce ref (`:23`), and the `.mutate()` call + dep array (`:52,:56`). Spec §3 item 8 / topology §9.2 say "drop the call" — accurate but understated; the cleanup is the whole debounce-validation block. Confirmed only caller (no other `check-email`/`checkEmail` refs in `apps/web/src` or `apps/pos/src`).
- **S-2 — `backend-test-pgsql` is a *job inside `ci.yml`* (line 275), not a standalone workflow.** Spec §3.6 calls it "the existing backend-test-pgsql workflow." Its gating `if: ... github.base_ref == 'main' ...` (ci.yml line ~283) confirms PR→main-only — so the substantive claim (reconfigure to include PR→dev) is correct; just rename "workflow" → "job in ci.yml" for precision.
- **S-3 — tenancy.php duplicate `pgsql` key line numbers are slightly off.** Spec §3.5 says "lines 67-85"; actual keys are `pgsql => PostgreSQLDatabaseManager` at `config/tenancy.php:72` and `pgsql => PostgreSQLSchemaManager` at `:84` (last wins → Schema is effective today, consistent with contract §11). Tighten to "lines 72 and 84."
- **S-4 — `central_connection` already defaults to `central`.** `config/tenancy.php:51` is `'central_connection' => env('DB_CONNECTION', 'central')`. So Stancl already *expects* a connection named `central` when `DB_CONNECTION` is unset. The contract §4 plan to add a `central` connection to `database.php` should note this interplay: set `DB_CONNECTION=central` (or add the `central` connection AND keep it as the Stancl `central_connection`) so Stancl's central matches the app's central. Minor but avoids a "which connection is central" mix-up at wiring time.

---

## Ready-to-start verdict for T6 Phase 0

**Yes, with the two P1 edits folded into the spec before kickoff.** The v6 auth architecture is implementable on the installed Sanctum v4.3.1 / Stancl v3.10.0 stack, and round-5's findings are genuinely resolved. The blocking risks are not architectural — they are that the spec (a) understates that the request-time tenancy layer must be built from scratch and applied to all 11 authenticated route files (P1-1), and (b) doesn't reconcile the new token-tenant resolver against the existing `tenant:<uuid>` ability + `EnforceTokenTenantClaim` (P1-2). Add those, plus the web-cookie tenant-resolution answer (P2-1, the most likely half-finished surface) and the password-broker ordering (P2-3), and Phase 0 can start. Treat 12 PD as the floor (P2-5).
