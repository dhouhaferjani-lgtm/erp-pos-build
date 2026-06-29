# Final whole-branch review — POS Loyalty Phase 1 (online balance + tier + earn estimate)

**Scope reviewed:** commits `84b07f4d0..cda9671a5` (6 commits) on `feat/loyalty-pos-online`
(worktree `/Users/houssamr/Projects/syneriva/apps/erp.loyalty-pos`).
**Reviewer:** final whole-branch reviewer (merge gate).
**Date:** 2026-06-28.

---

## VERDICT: ready-after-fixes

The branch is coherent end-to-end, both-layer gated, cross-module-clean, and the persisted/credited
path stays float-free and behavior-unchanged except for the extracted resolver. All six whole-branch
checks pass as designed. There is **one NEW Important finding** (a stale-pointer / earn-miss edge in
the phone-match branch) that should be either fixed or consciously accepted-and-documented before
merge — it does not trigger in the dominant demo flow but silently breaks the core "auto-enroll ⇒
points accrue on next sale" promise when it does. Everything else is clean or deferrable.

If the owner accepts the Important finding as a documented Phase-2 edge (reasonable, given the
parapharmacy demo uses fresh per-customer phones), this is **ready** as-is.

---

## NEW findings

### Important — phone-match branch leaves a stale loyaltyable, so the earn path never credits the current partner (and can show another customer's balance)

`PosLoyaltyBalanceService::findOrCreateMember`
(`apps/api/app/Modules/Loyalty/Application/Services/PosLoyaltyBalanceService.php:184-192`).

The flow is: resolve by contact/partner (`MemberResolver::resolveByContactOrPartner`) → if null and a
phone is present → `findOrCreateMember`, which dedupes by `(tenant, phone)` via `withTrashed()` and
returns the existing member **without re-pointing its `customer_id` / `loyaltyable_*` to the attached
partner.**

Key observation: the phone-match branch is only reached *after* `resolveByContactOrPartner($contactId,
$partnerId)` returned null. That resolver matches on `loyaltyable_id == partnerId` **OR**
`customer_id == partnerId` (`MemberResolver.php:61-71`). Therefore any member found in the phone branch
is, by construction, one whose `loyaltyable`/`customer_id` do **not** match the currently attached
partner. The endpoint then enrolls/returns that member with `enrolled:true` and **its** balance/tier.

Consequences when a phone collision exists across two customer records in a tenant (or a contact-based
member shares a phone with an attached partner):
1. **Earn-miss (breaks the headline promise):** at sale time `SaleEarningService` resolves the buyer by
   `contactId`→`partnerId` (`SaleEarningService.php:258-260` → same `MemberResolver`). The enrolled
   member still points at the *other* partner, so `resolveByContactOrPartner($attachedPartnerId)`
   returns null again and **no points are credited** for this customer's sales — even though the POS
   showed `enrolled:true` at attach.
2. **Cross-record balance display (within-tenant):** the attached customer is shown the phone-twin's
   balance/tier. Defensible under "phone is the loyalty identity," but combined with (1) it is
   confusing: shows a balance that this partner's sales will never add to.

Likelihood: **narrow, not dominant.** A brand-new customer with a never-seen phone always falls through
to fresh `create()` (`customer_id = partnerId`, `loyaltyable = partner/partnerId`) → earn resolves
correctly. The soft-deleted-same-partner case is also fine (the resolver skips trashed, withTrashed
restores the *same* partner's member). Only a genuine cross-record phone collision triggers it.

**Fix (small):** in the phone-match branch, when the found member's `customer_id` (and/or
`loyaltyable_id`) does not equal the attached `partnerId`/`contactId`, re-point it before returning —
e.g. set `customer_id = $partnerId` (and optionally `loyaltyable_type/id`) and save. This makes the
earn-path resolution and the displayed balance consistent with the attached customer. Add a resolver
test for the `customer_id` fallback branch (see carried Minor T1) and a balance-service test for the
phone-collision case.

**Alternative (accept):** document this as a known Phase-2 edge in the spec's out-of-scope/known-gaps
section. The demo flow does not hit it.

---

## Triage of carried Minors

- **T1 — `customer_id` fallback branch in `MemberResolver` untested.** **Fix-before-merge (light).**
  This branch (`orWhere('customer_id', $partnerId)`) is exactly the resolution seam the Important
  finding above turns on, so it should not stay uncovered. A one-case test (member created with
  `customer_id` set but `loyaltyable_id` different, resolved by partnerId) closes the gap cheaply.
  Independent of whether the Important finding is fixed or accepted.

- **T4 — `react-hooks/set-state-in-effect` warning on `setBalance(null)` reset; no isolated
  `useLoyaltyBalance` hook test.** **Defer.** Matches the existing `TableSelector`/`CashPaymentScreen`
  pattern, is functionally correct (resets stale chrome on customer change before refetch), and the
  hook's behavior is exercised through the component test's three states. Not a merge blocker.

---

## Verified-correct (whole-branch checks)

1. **End-to-end coherence / type alignment.** Backend response is `{enrolled:bool, balance:string,
   tier:string|null, rate:string|null}` (`PosLoyaltyBalanceService.php:155-161`, also the not-enrolled
   shapes at `:127,:131`). POS `LoyaltyBalance` interface matches exactly
   (`apps/pos/src/lib/loyalty/loyaltyApi.ts:4-9`). `apiPost` → `request()` returns `json.data`
   (`apps/pos/src/lib/api.ts:184-187`), and the controller wraps `['data' => $result]`
   (`LoyaltyPOSController.php:388`), so the hook receives the inner object (no double-unwrap). The
   `MemberResolver` body is identical (verbatim) to the old private `SaleEarningService::resolveMember`
   and is now called by both `SaleEarningService.php:258-260` and `PosLoyaltyBalanceService.php:133`.

2. **Auto-enroll idempotency.** Member dedupe by `(tenant, phone)` with `withTrashed()` + restore
   (`PosLoyaltyBalanceService.php:184-192`) avoids the unique-violation 500; enrollment guarded by
   `findByMemberAndProgram` before `enroll()` (which throws on duplicate)
   (`PosLoyaltyBalanceService.php:142-147`). `PosLoyaltyBalanceTest::test_repeat_call_does_not_
   duplicate_member_or_enrollment` asserts exactly 1 member + 1 enrollment across two calls. For the
   normal (fresh) path the created member has `customer_id = partnerId` and `loyaltyable = partner/
   partnerId`, so `SaleEarningService` resolves and credits it on the next sale. (Exception: the
   phone-collision edge in the Important finding.)

3. **`customer_id` set on POS-created members.** `LoyaltyMember::create([... 'customer_id' =>
   $partnerId ...])` (`PosLoyaltyBalanceService.php:200`); column + fillable confirmed
   (`LoyaltyMember.php:22,56`; migration `tenant/2026_01_10_100001_create_loyalty_members_table.php`).
   `PosLoyaltyBalanceTest:587` asserts `customer_id => $partnerId` persisted. `findByCustomerId` /
   partner→member reporting therefore continues to work for the fresh-create path.

4. **Online-only + both-layer gating.** Endpoint sits inside the route group
   `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim,'module:Loyalty']`
   (`routes.php:26`) and the route adds `can:pos.operate_terminal` (`routes.php:451-453`) — rule 12
   satisfied; `test_403_when_loyalty_module_disabled` isolates and proves the module layer. FE chrome
   gated on `useHasModule('Loyalty')` (`useLoyaltyBalance.ts:7`) AND `customer_sync_status === 'synced'`
   AND a successful call; any failure/offline/module-off/walk-in/unsynced ⇒ `null` ⇒ no chrome
   (`useLoyaltyBalance.ts:11-18`, `CustomerLoyaltyBadge.tsx:13`). Component test covers the null case.

5. **No cross-module model import; earn path unchanged.** `PosLoyaltyBalanceService` and
   `MemberResolver` import no Partner model (phone supplied by POS); POS imports no Loyalty backend
   model. The only `SaleEarningService` change is swapping the private method for the injected resolver
   (diff `:257-260`, removed `:283-309`) — earn logic, guards, and TTC basis untouched. (The Partner
   import in `PosLoyaltyBalanceTest` is test-only seeding — acceptable.)

6. **No float on the credited path; FE `Number()` display-only.** Server credit uses bcmath at currency
   scale throughout (`EarningProcessingService.php:102-160`, `PointEarningService::calculateSpendPoints`
   `bcmul(amount, reward_value, scale+4)` at `:277-283`). Rate semantics match the FE estimate
   (`points = total × reward_value`), so `floor(total × Number(rate))`
   (`CustomerLoyaltyBadge.tsx:18`) is a faithful display approximation, never persisted. `rate` is a
   string `'2.0000'` (decimal:4 cast, `EarningRule.php:73`) matching the test assertion.

### Minor / cosmetic (non-blocking, optional)
- When the Loyalty module is **on but no active program** exists, the call succeeds with
  `enrolled:false, rate:null`, so the chrome renders "Joins on purchase" with no estimate
  (`CustomerLoyaltyBadge.tsx:32-34`) — slightly misleading (there is no program to join). Harmless for
  the demo (a program is configured); could suppress the note when `rate === null`.

---

## Bottom line

Merge-ready pending: (a) decide the Important phone-collision finding — fix (re-point `customer_id` on
phone-match) or document-and-accept; and (b) add the light `MemberResolver` `customer_id`-branch test
(carried T1). The T4 set-state warning and the no-program cosmetic note are safe to defer.
</content>
</invoke>
