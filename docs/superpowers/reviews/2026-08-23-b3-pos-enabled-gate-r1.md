# Adversarial merge gate — round 1 — `fix/b3-pos-enabled-claim-enforcement`

- **Lane tip reviewed:** `7b01949f9` (single commit, 12 files, +993/−22)
- **Base:** `dbfa347e5` (merge-base with `dev`)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b3-pos-enabled` (read-only review; restored to a clean tip after every probe — `git status --porcelain` empty)
- **Class-resolution proof (worktree, not main tree):** `ReflectionClass` resolved
  `App\Modules\POS\Presentation\Controllers\TerminalController` →
  `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b3-pos-enabled/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php`
  and `App\Modules\Company\Domain\Location` → the worktree copy. All runs below are worktree code.

---

## 1. What I verified green (evidence first)

| Check | Result |
|---|---|
| `TerminalLocationPosEnabledTest` (sqlite) | **OK 11/11, 26 assertions** |
| `BackfillLocationPosEnabledB3MigrationTest` (sqlite) | **OK 15/15** (1 skipped — the PG-only savepoint case) |
| `BackfillLocationPosEnabledB3MigrationTest` (**PostgreSQL**, `autoerp_b3gate_test` @5433) | **OK 15/15, 46 assertions** — savepoint-containment case ran, not skipped |
| `TerminalLocationPosEnabledTest` + `TenantLaunchContractTest` (**PostgreSQL**) | **OK 13/13** |
| `TenantProvisioningServiceTest` (**PostgreSQL**, real tenant DB created + dropped) | **OK 1/1, 18 assertions** — the rewritten A2 pin executes end-to-end through `tenants:migrate` |
| `TerminalCreationFiscalSchemaVersionTest` + `TenantLaunchContractTest` (sqlite) | OK 8/8 |
| `PosStabilizationTenantIsolationTest` (sqlite) | OK 49/49 (8 pre-existing skips) |
| `LocationTest` (factory-default pin) | green; `LocationTest.php:164` still asserts `pos_enabled` **false** at the column default |
| Pint (`--test`, 7 changed files) | `{"result":"pass"}` |
| PHPStan (TerminalController + migration) | `[OK] No errors` |
| deptrac | **182 violations — identical with the lane controller and with the base controller** (probe run both ways). No new boundary debt; matches the B-9-acked 182 |
| `php tools/feature-lane-manifest-check.php` | **OK** — 1367 Feature classes / 74 groups, 1136 parked classes; `find tests/Feature/POS -name '*Test.php' | wc -l` = **145**, matches the raised ceiling |

### Red-first, reproduced by my own method (not taken on trust)

- **Controller probe.** `git checkout dbfa347e5 -- .../TerminalController.php`, re-ran the new class:
  **`Tests: 11, Assertions: 17, Failures: 6`** — exactly the claimed 6F/5P signature (claim-refuse, request-refuse, store-refuse, web-refuse, web-existing-refuse, available-exclusion). Restored.
- **Neutralized-`up()` probe.** Injected `if (true) { return; }` at the head of the migration's `up()`
  (`2026_08_23_120000_...php:102`), re-ran the migration class: **`Errors: 2, Failures: 7`**. The committed `up()` is
  live, not a decorative body. Restored from the commit.
- **Fixture-revert probe (the "8 originally-broken tests" claim).** Reverted the three fixture files to base with the
  new controller in place: `TerminalCreationFiscalSchemaVersionTest` + `TenantLaunchContractTest` → **7 failures**;
  `PosStabilizationTenantIsolationTest` → **1 failure** (`:1329`, the same-tenant web-terminal control).
  **7 + 1 = 8. The claim is exact.** Restored.

### Coverage enumeration — terminal-acquisition surfaces (item 2)

Enumerated from `apps/api/app/Modules/POS/routes.php:54-74` and by grepping every terminal-minting and
`hardware_identifier`-binding site in `app/`:

- `Terminal::create` exists at exactly four sites: `TerminalController.php:117` (store), `:419` (request), `:492` (web),
  and `VirtualAdminTerminalResolver.php:38`.
- `hardware_identifier => …` is written at exactly two sites: `TerminalController.php:392` (claim) and `:432` (request).
- `findByDevice` (`:531`, route `:62`) is a **lookup of an existing binding**, mints nothing, binds nothing.
- Sync controllers (`SyncController`, `ZReportSyncController`, `AuditEventSyncController`, …) mint no terminal.
- `PATCH /pos/terminals/{id}` can move `location_id` (`UpdateTerminalRequest`), but that is **not** an acquisition
  bypass: after a move, `available()` hides the terminal and `claim()` refuses it, so the flag still wins.

**Conclusion:** the five gated surfaces are the complete set of *device/admin acquisition* paths.
`VirtualAdminTerminalResolver` is correctly **not** gated (server-authored fiscal events for back-office deposits /
account-status changes have no shop floor) — but it poisons the backfill predicate; see **P1-1**.

### The refusal helper, attacked (item 1)

- `locationHasPosEnabled()` (`TerminalController.php:646-653`) re-applies `where('company_id', …)` **and** `whereKey()`.
  On `claim()` the company id is `$terminal->company_id`, and the terminal was already fetched through
  `Terminal::forCompany(requireCompanyId())` (`:353`), so it is the caller's company — no widening.
- A terminal whose `location_id` points at a **foreign company's** location (legacy/inconsistent row) fails closed →
  422 `LOCATION_POS_DISABLED`, no existence signal. Correct.
- **PG uuid-cast hazard is closed:** all three body-driven paths validate `'uuid'` *before* `ScopedExists`
  (`CreateTerminalRequest.php:36`, `RequestTerminalRequest.php:34`, inline `TerminalController.php:458`), and `claim()`
  passes a column value. No unvalidated string reaches a uuid comparison.
- **The "soft-deleted default location" edge does not exist.** `Location` (`app/Modules/Company/Domain/Location.php:50-55`)
  uses `HasFactory` + `HasUuids` only — **no `SoftDeletes`**, and `locations` has no `deleted_at` column
  (`2025_11_30_105000_create_locations_table.php`). Locations are hard-deleted, and the default location cannot be
  deleted at all (`LocationController.php:290`, `CANNOT_DELETE_DEFAULT_LOCATION`). Fail-closed cannot strand a
  legitimate web-terminal setup this way.
- **Cross-company probing is unchanged:** `ScopedExists::company` fails in the validator first, returning the
  `errors.location_id` 422 shape — the B-3 refusal never becomes an oracle.
- `available()`'s `whereHas` does not change the response shape: `->get()` into `TerminalResource::collection`
  (`:335-339`), no pagination, no `meta`. The device picker (`apps/pos/src/stores/terminalStore.ts:741`,
  `apiGet<Terminal[]>`) is unaffected.

### The `getOrCreateWebTerminal` refuse-before-lookup judgment call (item 3)

Verified by repo-wide grep: **`POST /pos/terminals/web` has no live client.** The only reference outside tests is the
helper `apps/web/src/features/pos/api/terminalApi.ts:123-125`, which **nothing calls** (`grep -rn
"getOrCreateWebTerminal" apps/` → definition only; `grep -rn "terminals/web" apps/ packages/` → that line only).
So the "web POS reconnect after transient logout" regression is theoretical today, and the strict reading is the safe one.

Coherence against the deferred half: existing **device** terminals keep selling (no re-acquisition — `findByDevice`
returns the stored binding, shift-open is deferred) while existing **web** terminals are blocked at next acquisition.
That asymmetry is real but **temporary and safe-direction**, and it costs nothing today because no client calls the web
path. I do **not** ask for a change here — but the residual must be written down (**P3-10**), not left in a commit message.

### Provisioning flip (item 4)

- Three flips verified: `TenantProvisioningService.php:176`, `AuthController.php:441`, `CompanyController.php:131` —
  each with a citing comment. Repo-wide grep for `pos_enabled` in `app/` shows no fourth production writer besides
  `LocationController.php:205`.
- `LocationController.php:205` `'pos_enabled' => $validated['pos_enabled'] ?? false` is **untouched** — explicit user
  choice still wins, as ruled. `UpdateLocationRequest` keeps `sometimes|boolean` and `update()` persists it
  (`LocationController.php:251`), and the Settings UI has a real editable toggle
  (`apps/web/src/features/settings/LocationsPage.tsx:519`, `AddLocationModal.tsx:402-409`) — so the refusal message's
  remediation advice ("enable it in Settings") is actionable.
- **Rule 7 (`typescript:transform`) is genuinely not needed**: no PHP DTO/`Data` class is in the diff; `LocationResource`
  is a Resource (not transformed) and is unchanged; the FE `pos_enabled` types are hand-written
  (`apps/web/src/features/locations/api/locations.ts:24`) and untouched. There is **no committed OpenAPI artifact** in
  this repo (`find -iname '*openapi*'` returns only docs/reviews), so no spec surface goes stale.
- The `TenantLaunchContractTest` fixture flip matters more than it looks: that class **is** in the `backend-pgsql`
  `--filter` allowlist (`.github/workflows/ci.yml:935`), i.e. it runs on every CI event. It is green on PG (above).

### Backfill predicate, re-derived (item 5)

- Branch (b) SQL (`migration:127-138`): `type = 'shop' AND (is_default = true OR company_id IN (SELECT company_id FROM
  locations GROUP BY company_id HAVING count(*) = 1))`. The "only location" half **is** evaluated **per company** —
  `GROUP BY company_id` — so a multi-company tenant DB is handled correctly. A company whose only location is a
  warehouse stays false (the `type = 'shop'` predicate is outside the OR). Verified by reading, and by
  `test_a_warehouse_without_terminals_is_left_alone` / `test_a_secondary_shop_without_terminals_is_left_alone`.
- Soft-delete handling: **not applicable** on `locations` (no `deleted_at`, above). On `pos_terminals` the absence of a
  `deleted_at` filter in branch (a) is deliberate and justified (`migration:31-37`) and I agree with it — *except* for
  terminal **type**, see P1-1.
- Idempotency proven three ways: `where('pos_enabled', false)` scope; `test_a_second_run_is_a_no_op` compares the full
  `updated_at` map byte-for-byte (`:181-191`) — I re-ran it on **PostgreSQL**, green; and
  `assertTenantMigrationIsIrreversibleNoOp` (`tests/Traits/ProvesTenantMigrationRoundTrip.php:171-213`) is a real
  harness (up → up → down → up, asserting the shape each time), not a tautology.
- Savepoint containment: `test_a_failing_backfill_does_not_poison_the_enclosing_migration_transaction` **ran on real PG**
  (it is `markTestSkipped` off pgsql, and my PG run shows 15/15 with zero skips). It drops `is_default` to force the
  failure inside the savepoint and asserts both survival and the `status=FAILED` gate line. Honest.
- **Ordering:** `2026_08_23_120000` sorts after O-27 (`2026_08_21_140000_backfill_chart_required_purposes_o27.php`) and
  after every dpa-v8 migration (`2026_08_18_*`) riding the same promotion. Both self-guards
  (`locations` absent → `status=skipped`; `pos_terminals` absent → branch (b) only) are pinned by rename-based tests.

### Manifest raise (item 6)

`gated_ceiling` 1134→1136 and `POS.classes` 143→145 are the **only** manifest edits (`git show` on that file: exactly two
hunks). Disk count is 145. The note names both classes, cites the ruling, and states the by-path sqlite+PG evidence —
the deliberate-raise contract is satisfied and the checker is green on the tip. One deviation from the *sibling*
precedent recorded in the same file — see **P2-5**.

### Scope / diff-stat audit (item 10)

All 12 files are under `apps/api/` (4 controllers/services, 1 tenant migration, 6 tests, 1 manifest). **No** `.github/`,
**no** `apps/web`, **no** `apps/pos`, **no** shift-open surface (`ShiftController`, `TerminalResource`, `terminalStore`),
**no** ratchet/G-5 file. Scope respected.

### Inherited red (not attributable to this lane)

`Tests\Feature\Seeders\DemoPharmacySeederTest::test_seeds_gl_consistent_partner_balances` fails at `:291`
(`SUPP-PAYABLE-01 payable_balance < 0`). **Attribution probe:** reverted `apps/api/app` and `apps/api/database` to
`dbfa347e5` and re-ran that single method — **fails identically**. Pre-existing red; record it, do not charge it to B-3.

---

## 2. Findings

### P1-1 — The backfill counts server-minted `virtual_admin` terminals as "this location sells", and the mistake is one-shot

`migration:141-147` filters branch (a) on **nothing but `location_id`**:

```php
$query->orWhereIn('id', function (Builder $withTerminal): void {
    $withTerminal->from('pos_terminals')->select('location_id')->whereNotNull('location_id');
});
```

But `pos_terminals` also holds **server-authored** rows. `VirtualAdminTerminalResolver::resolve()`
(`app/Modules/POS/Application/Services/VirtualAdminTerminalResolver.php:29-41`) mints a `type = virtual_admin`
terminal at **whatever location is oldest**:

```php
$locationId = Location::query()->where('company_id', $companyId)->orderBy('created_at')->value('id');
```

no `type` filter, no `pos_enabled` filter — and it is created by ordinary back-office actions
(`RecordCustomerDepositService.php:42`, `CustomerAccountStatusService.php:17`). For any tenant whose oldest location is a
warehouse — which is the *canonical* shape in this codebase: `DemoPharmacySeeder.php:215` creates `WH-01` **before** the
four shops at `:300` — the backfill therefore flips a **warehouse** to `pos_enabled = true`.

That directly contradicts the migration's own stated contract (`migration:49-59`: *"A warehouse is never enabled by (b) —
the ruling is explicit about that"* — true of (b), silently false via (a)) and the ruling behind it. The guard the
migration cites as protection, `DemoPharmacySeederTest.php:142` / `:154` (*"Warehouse must NOT be POS-enabled"*),
**cannot catch it**: under `RefreshDatabase` the migration runs against an empty schema and the seeder runs afterwards,
so the production ordering (terminals exist → migration runs) is never exercised.

Why P1 and not P2: the migration is **scoped `where('pos_enabled', false)` and never writes false**. Once it
over-enables a location, a corrected re-run *cannot* undo it — the repair is manual, per location, per tenant, and
invisible until someone audits. This is the one part of the lane that is not re-runnable.

**Fix (minimal):** restrict branch (a) to terminals that are actual tills, and pin it.

```php
$withTerminal->from('pos_terminals')
    ->select('location_id')
    ->whereNotNull('location_id')
    ->whereIn('type', ['physical', 'web']);   // TerminalType::Physical / ::Web
```

(`pos_terminals.type` is `string(20)`, `2026_02_19_000002_add_type_to_pos_terminals.php:16`; `virtual_admin` added
`2026_05_22_101000`.) Add a case to `BackfillLocationPosEnabledB3MigrationTest`: *a warehouse whose only terminal is
`virtual_admin` stays disabled* (and, for contrast, that a `web` terminal still counts). Amend the `(a)` docblock at
`migration:31-37` to say which types count and why.

### P1-2 — The new `store()` refusal is invisible in its only admin client: unfiltered dropdown + swallowed error

The lane correctly reasoned "never OFFER what claim() would refuse" for the device picker (`TerminalController.php:322-328`).
The **admin** create-terminal path got the refusal without the matching offer-side fix:

- `apps/web/src/pages/POS/Terminals.tsx:46-52` loads **all** locations (`getLocations`, no `posEnabled` filter) and
  passes them straight into the create form at `:255`.
- The mutation's error handler `apps/web/src/pages/POS/Terminals.tsx:93-98` does
  `catch (_err) { toast.error(t('common:common.errorCreating', …)) }` — the error object is **discarded**. The server's
  actionable message ("POS is not enabled at this location. Enable POS for the location in Settings…",
  `TerminalController.php:666`) never reaches the operator.

Launch consequence, on the exact flow the launch tenant will run: tenant #1 registers (MAIN is now POS-enabled ✔), then
adds a **second shop** through the UI — where `AddLocationModal.tsx:57` and `LocationController.php:205` both default
`pos_enabled` to **false** by deliberate design — then tries to create a till there and gets a **generic
"Error creating terminal"** with no cause and no hint. That is the "silent refusal on a prod path" failure mode this
gate exists to stop, and `apps/web` is *not* inside the lane's scope bar (which names shift-open, device code, `.github/`
and ratchets — not the web admin page).

**Fix (minimal, pick both if cheap):**
1. Filter the dropdown: pass `locations.filter(l => l.posEnabled)` at `Terminals.tsx:255` (the transform already maps
   `pos_enabled` → `posEnabled`, `apps/web/src/features/locations/api/locations.ts:65`), with an empty-state hint
   pointing at Settings (rule 11: `t()` key, no hardcoded string).
2. Surface the cause: map `error.code === 'LOCATION_POS_DISABLED'` to a dedicated i18n key in the `catch` at `:93`.
   Add a Vitest case asserting the disabled location is not offered / the specific message renders.

### P2-3 — Two seeder writers still mint a POS-dead `MAIN` shop; the backfill cannot save them

Seeders run **after** `tenants:migrate`, so anything they create is born at the column default and the B-3 backfill has
already passed:

- `database/seeders/DatabaseSeeder.php:269` — `'pos_enabled' => false` on a `type=shop`, `is_default=true`, `code=MAIN`
  location. This is the fifth writer the parent pre-authorised.
- **`database/seeders/DemoTenantSeeder.php:1209-1220`** — `Location::firstOrCreate([... 'code' => 'MAIN'], ['type' =>
  'shop', 'is_default' => true, 'is_active' => true])` with **no `pos_enabled` key at all**, so it inherits `false`.
  This one was missed by the lane *and* by the sizing scout, and it is the worse of the two because the omission is
  invisible at the call site. `DemoTenantSeeder` is a real demo-tenant path.

**Fix:** `'pos_enabled' => true` at both, with the same "Owner ruling B-3 / A2, 2026-08-23 …" comment convention used at
`TenantProvisioningService.php:170-176`.
**Deliberately leave alone** (verified correct): `StockLevelSeeder.php:69` (fallback **warehouse**),
`TaxRecoverabilityTestDataSeeder.php:89`/`:103` (both **warehouses**), `DemoPharmacySeeder.php:223` (warehouse false,
`:308` shops true), `CoffeeShopSeeder.php:355`, `TunisianParapharmacySeeder.php:204`, `ParapharmacySeeder.php:604` (all
already true). The legacy data migration `2025_11_30_133000_migrate_tenant_data_to_companies.php:72` creates a
shop/default location without the key, but it is a no-op on fresh DBs and branch (b) covers it on old ones — no change needed.

### P2-4 — The device's own location picker still offers POS-disabled locations (residual, out of scope to fix here)

`apps/pos/src/pages/TerminalSetupPage.tsx:228` fetches `apiGet<Location[]>('/locations')` unfiltered and renders every
one in the location `<select>` (`:339`); `handleRequest` (`:247`) then calls `requestTerminal`, which now 422s, and the
raw server message is shown via `getErrorMessage(err)` (`:253`). Same class of defect the lane fixed for `available()`.
Device code is barred by the lane's scope bar, so **do not fix it here** — but it must be **recorded** as a named
residual on the promotion checklist and added to the D-1 device brief (`Location` already carries `pos_enabled` on the
device type, `apps/pos/src/stores/terminalStore.ts:38`, so the fix is a one-line filter).

### P2-5 — The lane's only guards run on **no** CI event, and the manifest's own precedent says otherwise

The POS lane is parked behind `vars.SELF_HOSTED_RUNNER_READY`, so `TerminalLocationPosEnabledTest` and
`BackfillLocationPosEnabledB3MigrationTest` execute nowhere in CI. The same manifest file records two precedents where a
parked-lane class guarding a **live** surface was additionally named in the `backend-pgsql` job's `--filter` allowlist —
r2f4's `CorrectingEntryEndpointTest` (`.github/workflows/ci.yml:885`) and dpa-v8's two classes (`:914`, listed at `:935`)
— with dpa-v8's own note arguing the point ("*while this lane is parked they would otherwise execute on no event*"), and
that was for a surface with **zero callers**. B-3's surface is live, launch-critical, and refuses in production.

`.github/` is barred by the lane's scope bar, so this is a **parent decision**, not a lane defect: either lift the bar for
these two allowlist entries, or record on the promotion checklist that B-3's enforcement ships with by-path-only evidence
and no standing CI guard. Do not let it pass unrecorded.

### P3-6 — The helper's security-flavoured docblock is unexercised

`TerminalController.php:636-645` claims the predicate "FAILS CLOSED … so every acquisition path answers with the same
422 instead of leaking a 404/500 difference between 'disabled' and 'not yours'". No test in
`TerminalLocationPosEnabledTest` passes an unresolvable or foreign-company location id. Add one case: a terminal in
company A whose `location_id` points at company B's **POS-enabled** location → `claim()` must 422
`LOCATION_POS_DISABLED`, not 200. Cheap, and it converts a comment into a contract.

### P3-7 — Two of the three A2 flips are unpinned

Only `TenantProvisioningService` is pinned (`TenantProvisioningServiceTest.php:149`). `AuthController.php:441` and
`CompanyController.php:131` would flip back silently. `tests/Feature/Company/CreateCompanyTest.php:135`
(`test_company_creation_creates_default_location`) already asserts `is_default`/`is_active` — add
`$this->assertTrue($location->pos_enabled);` there, and the equivalent in whichever register test covers the
shared-DB-compat path.

### P3-8 — Citation collision on "ruling A2"

The commit body and `TenantProvisioningServiceTest.php:130-132` cite *"Owner ruling B-3 / delegated ruling A2, 2026-08-23
(docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md, row B-3)"*. That file has **no A2 row**; its row **A-2**
(line 13) is the `adversarial-review.sh` verdict-parse hardening. A future reader following the citation lands on an
unrelated ruling. Cite the delegation unambiguously (e.g. "parent-delegated sub-ruling under B-3, session ledger
2026-08-23") in the commit and in the test comment.

### P3-9 — Migration docblock overclaims on the first run

`migration:71-75`: *"nothing here ever sets `pos_enabled` back to false, so a location an operator deliberately switched
OFF between deploys is not re-enabled by a re-run."* True for a **re-run**; the **first** run *will* enable a location an
operator had unticked, if it matches (a) or (b). That is defensible — the flag was decorative before this deploy, so
pre-deploy intent is not reliable — but the docblock should say so in one sentence instead of leaving the stronger
reading standing. (`test_a_location_switched_off_by_hand_is_not_re_enabled_when_it_has_no_evidence` only covers a
no-evidence location, which is a weaker claim than the prose.)

### P3-10 — B-3 is half-delivered against the owner's words; record the residual

The owner's ruling is *"terminal **claim/open** must respect it"* (`docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md:21`).
Only the claim side ships; shift-open is parent-deferred to D-1 — legitimately — but the consequence is that **an already-claimed
device terminal keeps selling at a POS-disabled location indefinitely**. Today that residual lives only in the commit
message. It belongs in the owner ledger/promotion checklist, and B-3 must not be closed until the D-1 half lands.

---

## 3. What to fix before merge

Two blockers, both small: **(P1-1)** exclude `virtual_admin` from the backfill's evidence branch + pin it — the
over-enable is permanent and unrepeatable; **(P1-2)** stop the admin terminal form from offering POS-disabled locations
and stop it swallowing the refusal. Then fold in **P2-3** (flip `DatabaseSeeder.php:269` **and** `DemoTenantSeeder.php:1209`)
and record **P2-4 / P2-5 / P3-10** as named residuals on the promotion checklist. P3-6/7/8/9 are cheap and should ride
the same fix round.

VERDICT: CHANGES-REQUIRED
