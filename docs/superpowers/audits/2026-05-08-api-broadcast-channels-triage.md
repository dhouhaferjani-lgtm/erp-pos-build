---
cluster_id: api.broadcast-channels
date: 2026-05-08
owner: claude (override of required_owner: codex — orchestrator-approved per session-pattern-precedent)
status: triage-approved-execution-in-progress
baseline:
  branch: feat/tenant-isolation-sweep-execution
  head: 47c2251d
  inventory_events: 1603
  inventory_callsites: 326
post_review_dispositions:
  cat_a_count: 0
  cat_b_count: 13  # 5 channel sites + 8 broadcast classes (3 newly discovered added)
  manual_rows_added: 0  # dead-code removal does not warrant a manual row
  tdd_red_anchor: skipped  # no cat-(a) callsites to drive
  cluster_shape: annotation-only (mirrors api.webhooks-incoming)
---

# api.broadcast-channels — Cluster Triage

## Scope

Laravel Echo channel authorization. Trigger is a frontend WebSocket subscribe, not an
HTTP request. Tenant resolution happens via the auth callback per channel
(`Broadcast::channel(...)` closures in `apps/api/routes/channels.php`).

Cluster surface is small:
- 6 channel definitions in `apps/api/routes/channels.php`
- 8 broadcast event classes (orchestrator prompt listed 5; **3 additional were
  discovered**: `OrderLineStatusChangedBroadcast`, `ImportCompletedBroadcast`,
  `ImportProgressBroadcast` — all share the same shape as the listed five and are
  in-scope for the cluster).
- 2 auth helper methods on `User`: `canAccessChannel`, `canAccessCompanyChannel`.

## Bookkeeping notes

- `tenant-isolation-sweep-inventory.yml:449-470` lists this cluster as
  `required_owner: codex`. Orchestrator prompt explicitly dispatched **claude** instead.
  Flagging as a deviation; awaiting orchestrator confirmation before claiming/starting.
- `blocked_by: [api.treasury]` is satisfied (treasury is `fixed`).
- `expected_callsite_count: null` — pending the manual-rows decision below
  (1 cat-(a) callsite proposed).

## Auth helper sanity (load-bearing — verified clean)

`User::canAccessChannel($tenantId, $companyId, $productId)` (`User.php:215-237`):
- Tenant gate: `$this->tenant_id !== $tenantId` → string compare. ✓ (UUIDs).
- Active gate: `$this->isActive()` → `status === UserStatus::Active`. ✓
- Membership gate:
  `$this->companyMemberships()->where('company_id', $companyId)->where('status','active')->exists()`.
  Relationship is `hasMany(UserCompanyMembership::class)` (`User.php:137-140`), so it is
  intrinsically anchored to `$this->id`; the `where company_id` predicate is the
  per-target-company filter. ✓
- `$productId` is **received but ignored**. Not a defect — see channel #2 below.

`User::canAccessCompanyChannel($tenantId, $companyId)` (`User.php:246-263`):
- Same three gates; identical shape. ✓

Neither helper has a tenant-isolation defect. They are load-bearing and SOUND.

## Channel-by-channel triage

### 1. `App.Models.User.{id}` — **DEAD CODE → REMOVE (no manual row, no test)**

> **Disposition shift after orchestrator approval:** clean removal, no manual
> inventory row, no TDD red anchor. Cluster reduces to a pure annotation-only
> cluster mirroring `api.webhooks-incoming`.

```php
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

**Defect:** `User` uses `HasUuids` (`User.php:53`); `$id` is documented as a UUID
string (`@property string $id UUID of the user`, `User.php:26`).
`(int) "<uuid-string>"` truncates to the leading numeric prefix (most UUIDs → 0).
The comparison passes for ANY pair of UUIDs that share the same numeric prefix
(typically both 0). This is a broken auth check.

**Disposition: REMOVE rather than fix.** Justification:
- Frontend audit (search across `apps/web/src`, `apps/pos/src`, `apps/api`):
  **zero subscribers** to `App.Models.User.*`. Only one occurrence in the whole
  repo source — the channel definition itself.
- This is the Laravel framework default scaffolding closure (auth.php boilerplate),
  never wired up.
- Removing it eliminates a broken-auth surface and a future foot-gun (someone
  later wiring up Echo would inherit the broken cast).
- Removal is reversible (one closure, ~3 LOC).

**No manual row.** Per orchestrator instruction: tracking the deletion in the
cluster aggregate history event is sufficient — a manual row describing a
deleted closure is bookkeeping debt, not signal. No regression test is added
either (you cannot test a channel that does not exist).

Exhaustive subscriber re-grep (across `apps/`, `packages/`, `.github/`,
`infra/`, `scripts/`, `apps/api/config/`, plus all Echo config files at
`apps/web/src/lib/echo.ts`, `apps/pos/src/lib/echo.ts` and
`apps/web/src/lib/__tests__/echo.test.ts`) returned **zero matches** for
`App.Models.User`. The only repo references are the channel definition itself
plus the prose of this triage doc.

### 2. `tenant.{tenantId}.company.{companyId}.product.{productId}` — **cat-(b) ANNOTATE**

```php
Broadcast::channel('tenant.{tenantId}.company.{companyId}.product.{productId}',
    function (User $user, string $tenantId, string $companyId, string $productId) {
        return $user->canAccessChannel($tenantId, $companyId, $productId);
    });
```

`canAccessChannel` accepts `$productId` but ignores it. Tenant-anchor + active +
company-membership gates run; product-belongs-to-company validation is **not**
performed.

**Why this is acceptable** (rationale to bake into the PHPDoc):
- The `products` table carries `company_id`. There is no cross-company product
  sharing within a tenant.
- Therefore, any user with active membership in `companyId` is, by design,
  permitted to receive cost-price updates for any product owned by that company.
- The `{productId}` segment serves fan-out (subscribers can scope to one product
  to limit traffic), not access control.

Disposition: PHPDoc above the call documenting the rationale. Suggested form:

```php
/**
 * @cross-tenant-anchored Channel name embeds tenantId + companyId + productId.
 *   Auth callback verifies user.tenant_id === tenantId AND user has active
 *   membership in companyId via User::canAccessChannel. The productId segment
 *   is intentionally not validated against (tenantId, companyId) because
 *   products carry company_id and there is no cross-company product sharing
 *   within a tenant — the company-membership gate is the upstream guard, and
 *   the productId segment is purely a fan-out / subscription-scoping signal.
 */
```

### 3. `tenant.{tenantId}.company.{companyId}.imports` — **cat-(b) ANNOTATE**

```php
Broadcast::channel('tenant.{tenantId}.company.{companyId}.imports',
    function (User $user, string $tenantId, string $companyId) {
        return $user->canAccessCompanyChannel($tenantId, $companyId);
    });
```

Sound. PHPDoc already exists; upgrade with `@cross-tenant-anchored` line so the
arch-test parser can recognize it.

### 4. `tenant.{tenantId}.company.{companyId}.partners` — **cat-(b) ANNOTATE**

Same shape as #3.

### 5. `tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}` — **cat-(b) ANNOTATE**

```php
Broadcast::channel('tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}',
    function (User $user, string $tenantId, string $companyId) {  // ← {terminalId} not in signature
        return $user->canAccessCompanyChannel($tenantId, $companyId);
    });
```

The closure signature does not bind `{terminalId}`; Laravel passes only the
parameters declared by the closure. The `{terminalId}` segment is a fan-out
slice, not a security boundary — same rationale as #2 (terminals carry
`company_id`, no cross-company sharing within a tenant).

PHPDoc must spell this out so a future maintainer doesn't read the channel name
and assume terminal-vs-company validation is happening behind the scenes.

### 6. `tenant.{tenantId}.company.{companyId}.pos.kitchen` — **cat-(b) ANNOTATE**

Same shape as #3 / #4.

## Broadcast-event-class triage (8 classes, all cat-(b))

All are `ShouldBroadcastNow` (synchronous; queue-context loss is not a risk).
All construct `PrivateChannel` paths from constructor-provided properties or
properties on a domain-event payload. None read from `auth()` or a global
facade in `broadcastOn()`. None build channel names from request-scoped
state.

| # | Class | Channel construction | Source of tenant/company |
|---|---|---|---|
| 1 | `Product/.../ProductCostPriceUpdatedBroadcast` | `tenant.%s.company.%s.product.%s` | `$this->domainEvent->{tenantId,companyId,productId}` |
| 2 | `Partner/.../PartnerBalanceUpdatedBroadcast` | `tenant.%s.company.%s.partners` | `$this->domainEvent->{tenantId,companyId}` |
| 3 | `POS/.../OrderSentToKitchenBroadcast` | `tenant.{$this->tenantId}.company.{$this->companyId}.pos.kitchen` | constructor `$tenantId, $companyId` |
| 4 | `POS/.../OrderReadyBroadcast` | same kitchen channel | constructor `$tenantId, $companyId` |
| 5 | `POS/.../TerminalActivatedBroadcast` | `tenant.{...}.company.{...}.pos.terminal.{...}` | `$this->event->{tenantId,companyId,terminalId}` |
| 6 | `POS/.../OrderLineStatusChangedBroadcast` (newly discovered) | kitchen channel | constructor `$tenantId, $companyId` |
| 7 | `Import/.../ImportCompletedBroadcast` (newly discovered) | imports channel | `$this->event->{tenantId,companyId}` |
| 8 | `Import/.../ImportProgressBroadcast` (newly discovered) | imports channel | `$this->event->{tenantId,companyId}` |

Disposition: each class gets a class-level PHPDoc `@cross-tenant-anchored` block
documenting that the broadcast channel name is derived from event-property
tenantId/companyId set at construction time. Frontend-facing payloads are
already minimal (no cross-tenant data leakage in `broadcastWith()` — verified).

## Cluster summary (post-orchestrator-approval shape)

| Category | Count | Action |
|---|---|---|
| cat-(a) (per-callsite manual row) | **0** | — (the dead-code removal is tracked in the cluster aggregate history event, not as a callsite) |
| cat-(b) (annotate) | 5 channels + 8 broadcast classes = **13 sites** | PHPDoc `@cross-tenant-anchored` |
| Removals | **1** | Delete `App.Models.User.{id}` Laravel-default closure (dead; broken `(int)` cast on UUIDs; zero subscribers) |

**Architecture test approach** (Step 6 — preview, not yet implemented):

Approach **(a)** — static analysis with PhpParser, mirroring
`WebhookControllerTenantContextTest`/`ConsoleCommandTenantContextTest` style.

- Test 1 (`BroadcastChannelTenantContextTest`): parse
  `routes/channels.php`, find every `Broadcast::channel(...)` call, and assert
  each:
  - Name pattern includes `{tenantId}` segment, AND
  - The closure body either calls `$user->canAccessChannel(...)` or
    `$user->canAccessCompanyChannel(...)` (allowlist), OR has a preceding
    PHPDoc with `@cross-tenant-anchored` plus a non-empty justification, OR
    is listed in `broadcast-channel-deferrals.json` (likely empty).
- Test 2 (`BroadcastEventTenantContextTest`): scan `app/Modules/**/Broadcasting/*Broadcast.php`
  classes implementing `ShouldBroadcast`/`ShouldBroadcastNow`. Assert
  `broadcastOn()` constructs `PrivateChannel`/`PresenceChannel` from properties
  whose names match `tenantId|tenant_id|companyId|company_id` (or are nested
  under `$this->event->...` / `$this->domainEvent->...`). Reject calls to
  `auth()`, `Auth::*`, or `app(CompanyContext::class)` inside `broadcastOn()`.

Deferral fixture: `apps/api/tests/Architecture/fixtures/broadcast-channel-deferrals.json`
— created empty.

## Risk surface raised by this triage

1. **Same-tenant cross-company subscribe-then-eavesdrop:** if a user has membership
   in company A and someone (a privileged operator) accidentally crafts a channel
   name with company B's UUID, the auth callback rejects it. ✓ Verified by reading
   the membership predicate.
2. **Broken `(int)` cast on `App.Models.User.{id}`:** real, but inert (zero
   subscribers). The remediation (remove) eliminates it permanently.
3. **`{productId}` / `{terminalId}` segments not validated against company:**
   acceptable per company-scoping rationale; documented in PHPDoc to prevent
   future maintainers from drifting toward "this looks like it's checked, why
   isn't it?".
4. **Queue-context tenant loss:** N/A — every broadcast is `ShouldBroadcastNow`
   (verified). If any class is later promoted to async `ShouldBroadcast`, the
   architecture test (Step 6) will pick up the case-by-case event-property
   sourcing pattern and continue to enforce it.

## Stop conditions check

- [x] No channel found with active subscribers AND missing tenant gate.
- [x] `canAccessChannel` / `canAccessCompanyChannel` are not defective.
- [x] No POS code-change diff anticipated; only annotation-only PHPDoc edits to
      POS broadcast classes (`OrderSentToKitchenBroadcast`, `OrderReadyBroadcast`,
      `TerminalActivatedBroadcast`, `OrderLineStatusChangedBroadcast`).
      `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`
      will be non-empty after Step 5, but the diff content will be PHPDoc-only.
      Will surface to orchestrator BEFORE pushing if any non-annotation POS edit
      becomes necessary.
- [ ] Required-owner mismatch (cluster YAML says `codex`, orchestrator
      dispatched claude) — **awaiting orchestrator decision** before claim/start.

## Approved execution plan (collapsed)

1. **Step 2** — claim + start cluster (no manual rows). Single commit:
   `chore(broadcast-channels): claim + start cluster (no manual rows; cluster is annotation-only)`.
2. **Step 3** — SKIP (no cat-(a) callsites; arch test in Step 6 carries the
   regression load).
3. **Steps 4 + 5 + 6 collapse into one commit:**
   `fix(broadcast-channels): @cross-tenant-anchored annotations on 5 channel definitions + 8 broadcast classes; remove dead App.Models.User.{id} default scaffold; architecture test enforces classification`.
4. **Step 7** — verify gates (phpstan / pint / phpunit + POS surface diff).
5. **Step 8** — Codex headless review. **STOP and report verdict** before lock.
6. **Step 9** — lock cluster, with the owner-override line in the cluster
   aggregate history event note and the final commit message.

## Orchestrator decisions logged here

1. **Owner override approved** (claude implements, codex reviews — same shape
   as `api.console-commands` / `api.scheduled-jobs` / `api.webhooks-incoming`
   earlier this session). Documented at lock time per orchestrator instruction.
2. **Scope expansion approved** — 3 newly discovered broadcast classes are
   in-scope. Total: 8 broadcast classes annotated.
3. **Clean removal** of `App.Models.User.{id}` — no commented-out remnant, no
   manual row, no migration note.
4. **POS surface diff acceptable** if PHPDoc-only on the 4 POS broadcast
   classes. Hard escalation if any non-PHPDoc edit becomes necessary.
