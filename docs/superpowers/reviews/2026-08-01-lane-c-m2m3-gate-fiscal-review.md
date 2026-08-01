# Lane C M2/M3 refund-exposure policies — scoped fiscal/POS gate review

- **Reviewer:** fiscal-pos-reviewer (adversarial, code-grounded)
- **Date:** 2026-08-01
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain` (branch `feat/v3-refund-chain`), read-only
- **Range:** `2dc158c32..81a984e12` (2 commits: `e80630bb7` api, `81a984e12` pos)
- **Inputs:** `docs/sessions/LANE-C-m2-m3-brief.md` (contract), `docs/sessions/LANE-C-m2-m3-report.md` (claims), `docs/sessions/LANE-C-m2m3-gate-package.diff`
- **Accepted rulings (verified as implemented, not re-litigated):** value limb `already + this > ceiling`; both policies skipped on a genuinely recovered approval; precedence cached-setting > fallback constants, absent row = fallback, present-but-unreadable = fail closed.

## VERDICT: spec ✅ + quality **APPROVE-WITH-FIXES**

The two policies do what the brief specifies, are placed pre-PIN/pre-intent, use decimal strings end to end, ride the existing fraud-settings channel unchanged, and are covered by real-SQLite tests for exactly the parts that only SQLite can falsify. I re-ran the two scoped POS suites: **80/80 green** (74 `refundCheckoutStore.test.ts` + 6 `migrations.v67.test.ts`), matching the report.

No Critical. Three Important and five Minor findings below. None of them regress an existing behaviour; I-1 and I-2 are gaps between a control's stated purpose and what it actually enforces, and I-3 is an unclassified path in the fail-closed audit the brief asked for.

---

## Findings by severity

### Important

**[IMPORTANT] `apps/pos/src/stores/refundCheckoutStore.ts:1483-1489` (+ `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:55-59, 247-305`) — M3 is a TOCTOU check; passing it does NOT guarantee the manager PIN is server-verified.**

`evaluateLargeRefundOnlineRequirement()` reads `useConnectivityStore.getState().isOnline` once, at `begin()`. The PIN is collected and verified much later, in `approveAndSubmitV4()` → `authorRefundReturnApprovalV3()` (`apps/pos/src/lib/refundFlow/refundApprovalV3.ts:66`) → `verifyScopedManagerPin()`. That function explicitly permits the **device-local PIN fallback** whenever `isGenuineOfflineFailure()` is true — which is true for a `FetchTimeoutError` *or* for `isOnline === false` at that later moment (`scopedManagerPin.ts:55-59`), returning a local-only approval at `scopedManagerPin.ts:300-304`.

*Why it matters (failure scenario):* cashier starts a 500 TND refund while the terminal believes it is online (M3 passes — note `isOnline` is a poll refreshed at most every 30 s while online, `apps/pos/src/stores/connectivityStore.ts:4`, so it can already be stale by up to 30 s against a server that went down without a browser `offline` event). During PIN entry the link drops. `apiPost('/pos/verify-manager-pin')` throws a transport error, `isGenuineOfflineFailure()` returns true, and the >threshold payout is approved against the **device-local PIN cache** — precisely the downgrade the control's own docblock says it exists to prevent ("so the manager PIN is verified against the SERVER … rather than against the device-local PIN cache").

*Suggested fix:* thread a `requireServerVerifiedPin: boolean` (set when `thisRefundTotal > onlineRequiredThreshold`) from `beginV4()` through `authorRefundReturnApprovalV3()` into `verifyScopedManagerPin()`, and make `isGenuineOfflineFailure()` return `false` when that flag is set. This fails **before** `authorPosOverride()` signs anything (`refundApprovalV3.ts:66` runs before `:75`), so it does not reintroduce the finding-10 stranded-approval hazard. Re-asserting the M3 gate *after* the approval is authored would, and must not be the fix.

**[IMPORTANT] `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:168-171` — the admin "reset to defaults" endpoint silently widens the invisible refund-exposure ceilings.**

`reset()` does `CompanyFraudSettings::updateOrCreate(['company_id' => …], CompanyFraudSettings::getDefaults())`, and `getDefaults()` now carries the three new columns (`CompanyFraudSettings.php:182-184`). The `update()` path at `:120-123` is correctly unaffected (it writes only `$validated`, and the new fields are not validated), so this is specific to `reset()`.

*Why it matters (failure scenario):* per the owner ruling there is **no admin UI** for these three settings — the only way to tighten them is a direct DB write. A tenant sets `online_required_refund_threshold = 40`. An operator later clicks "reset fraud settings to defaults" (a button that visibly concerns cash-variance thresholds and shows nothing about refunds), and the offline single-refund ceiling silently jumps back to 100 and the shift ceiling to 300 — no UI trace, no audit of the widening, and the device picks it up on the next fraud-settings refresh.

*Suggested fix:* until the admin UI + rule-19 regex validation land (report residual #1), exclude the three refund-exposure columns from `reset()`'s payload (`Arr::except(CompanyFraudSettings::getDefaults(), [...])`), or add them to `CompanyFraudSettingsData` so the surface is at least visible before it can be reset.

**[IMPORTANT] `apps/pos/src/stores/refundCheckoutStore.ts:1500-1502` + `:1102-1106` — an empty `companyId` degrades the policy to the fallback constants instead of failing closed (unclassified path in the fail-closed audit).**

`companyIdForResume()` returns `''` when `useAuthStore.getState().companyId` is null. `readRefundExposurePolicy()` then calls `getCompanyFraudSettings(db, '')`, which matches no row, returns `null`, and is treated as `fromFallback: true` — enforcing the **seeded 5 / 300 / 100** instead of the tenant's own (possibly far tighter) values. This exact condition is treated as fail-closed elsewhere in the very same file: `resumeReusedIntent()` refuses with `errorInternal` when `companyId === ''` (`refundCheckoutStore.ts:1587-1589`).

*Why it matters:* the "absent row ⇒ fallback" exception was ruled for *rollout availability* (never-synced terminal / un-migrated tenant). An empty company id is a different fact — the device has a cached policy but cannot address it — and silently resolves to the **more permissive** of the two. In the same state `currencyForBound()` also returns `'EUR'` (`:1496`), so every comparison would additionally run at scale 2 instead of TND's 3.

*Suggested fix:* refuse (`errorInternal`) when `companyIdForResume() === ''` before reading the policy, mirroring `resumeReusedIntent()`; add the row to the report's precedence table either way.

### Minor

**[MINOR] `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:80,93,106` vs `apps/pos/src/lib/refundFlow/refundExposureDefaults.ts:29,32,35` vs `apps/pos/src/lib/db/migrations.ts:2168-2170` — the "ONE source, byte-identical" claim is unguarded across the PHP↔TS boundary.**

The PHP side genuinely is one source (the migration derives its column defaults from `getDefaults()`), and `migrations.v67.test.ts:94-108` binds the SQLite column defaults to the TS constants. Nothing binds the TS constants to the PHP constants. Changing `DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD` on the server alone would silently give never-synced terminals a different policy from freshly-seeded ones — the exact equivalence the whole precedence rule rests on. *Fix:* assert the three literal values in `FraudSettingsPosControllerContractTest` (or a dedicated defaults-parity test) so both sides fail together.

**[MINOR] `apps/pos/src/stores/refundCheckoutStore.ts:1326-1334` (`readCeilingAmount` → `bcformat`, `apps/pos/src/lib/decimal.ts:14,78-80`) — narrowing a scale-4 ceiling to the currency scale rounds HALF-UP, i.e. can widen the ceiling.**

`Big.RM = 1` (ROUND_HALF_UP). A tenant ceiling of `100.9990` becomes `101.00` at EUR scale 2; `0.0005` becomes `0.001` at TND scale 3. Sub-cent magnitude, but a ceiling should never round *outward*. *Fix:* use round-down when narrowing a ceiling (`new Big(raw).round(scale, Big.roundDown).toFixed(scale)`).

**[MINOR] `apps/pos/src/stores/refundCheckoutStore.ts:1461-1464` (`bcabs` → `safeBig`, `apps/pos/src/lib/decimal.ts:16-19`) — an EMPTY prior refund total reads as `0` instead of refusing.**

`safeBig('')` returns `Big(0)`, so an empty `offline_receipts.total` would silently contribute nothing to `alreadyRefunded` — an UNDERCOUNT, contradicting the report's "a prior refund total unreadable ⇒ Refuse" row. Non-empty garbage does throw (Big.js) and is correctly caught at `:1125-1135`. Practically unreachable — `total` is `TEXT NOT NULL` (`migrations.ts:113`) and is only ever written from `bcmul`/`bcadd` output (`refundReceiptService.ts:168-171, 207`) — so the guarantee currently rests on the writers, not on the check. *Fix:* validate each total against `NON_NEGATIVE_DECIMAL`-style canonical form (allowing the leading `-`) before `bcabs`, same discipline as `readCeilingAmount`.

**[MINOR] `apps/api/database/migrations/tenant/2026_04_25_100001_seed_company_fraud_settings_for_existing_companies.php:40-43` — an older tenant migration now inserts columns that a later migration adds.**

That backfill calls `CompanyFraudSettings::create(CompanyFraudSettings::defaultsForVertical(...))`, and `defaultsForVertical()` → `getDefaults()` now includes the three new keys, all of which are `$fillable`. It runs at ordinal `2026_04_25`, *before* `2026_08_01_000001` creates the columns. Unreachable on a fresh tenant DB (the `companies` table is empty at that point, so the chunk loop never inserts) and on any tenant already past 2026-04-25 (the migration is recorded as run). It **is** reachable on a dormant/restored tenant DB that has companies but has never run the 2026-04-25 backfill: `tenants:migrate` would abort with `column "offline_refund_count_ceiling" does not exist`. The same shape applies to `CompanyFraudSettingsService::ensureForCompany()` (`CompanyFraudSettingsService.php:29-32`) firing on a `CompanyCreated` event during the deploy window before that tenant's `tenants:migrate` finishes. *Fix:* either state the "all live tenants are past 2026-04-25" assumption explicitly in the deploy note, or make the 2026-04-25 migration insert only the columns that exist.

**[MINOR] `apps/pos/src/api/fraudSettingsApi.ts:9-21` — `FraudSettingsResponse` is hand-written rather than imported from `packages/shared/types/generated.d.ts` (`App.Modules.POS.Application.DTOs.FraudSettingsData`), against rule 7.**

Pre-existing pattern; the three new fields inherit it. Risk is contained by `FraudSettingsPosControllerContractTest`, which pins the camelCase key names on the server side.

### Noted, not a finding (record it so nobody "fixes" it later)

M2 measures **only v4 refunds** — legacy `/return` refunds never write an `offline_receipts` row at all (`apps/pos/src/lib/offline/endOfDayPreview.ts:412-421`), so they are invisible to `getShiftRefundReceiptTotals()`. This is **correct**, not a gap: legacy refunds are HTTP-mediated and therefore already visible to the §12 server-side cap, which is exactly the authority M2 exists to compensate for while it is blind. Worth one line in the report so a future reader does not add `local_refund_records` to the M2 window and double-count.

---

## Fail-closed audit — every read in the two check paths, classified

Entry point: `refundCheckoutStore.ts:1096-1146`, the whole block wrapped in `try/catch` → `errorInternal` (fail closed) at `:1125-1135`.

| # | Read | Site | On error | On "empty"/absent | Classification |
|---|---|---|---|---|---|
| 1 | `useConnectivityStore.getState().isOnline` | `:1487` | n/a (no throw) | stale `true` up to ~30 s (`connectivityStore.ts:4`) | **fail OPEN** for that window — see I-1 |
| 2 | `getCompanyFraudSettings(db, companyId)` | `:1367` | throws → `errorInternal` | `null` → fallback constants (`:1370-1377`) | fail closed / **ruled fallback** |
| 3 | `companyIdForResume()` | `:1104` | n/a | `''` → no row → fallback constants | **unclassified fail-open-ish** — see I-3 |
| 4 | `readCeilingCount(offline_refund_count_ceiling)` | `:1336-1342` | non-number / non-integer / negative → throws | — | fail closed ✅ |
| 5 | `readCeilingAmount(offline_refund_value_ceiling)` | `:1326-1334` | non-string / non-canonical-decimal → throws | `''` fails the regex → throws | fail closed ✅ |
| 6 | `readCeilingAmount(online_required_refund_threshold)` | `:1326-1334` | idem | idem | fail closed ✅ |
| 7 | `countUnsyncedFiscalEvents(db, terminalId)` | `:1433` | throws → `errorInternal` | `0` → ceiling stands down (**by design**, `fiscalEventRepository.ts:78-95`); `queryOne` undefined → `0`, unreachable for `COUNT(*)` | fail closed ✅ / designed stand-down |
| 8 | `useTerminalStore.getState().shift` | `:1436` | — | `null` → explicit `throw` (`:1438-1441`) | fail closed ✅ |
| 9 | `getShiftReceiptAnchor(db, shift.id)` | `:1447` | throws → `errorInternal` | `null` → wall-clock `openedAt` window (legacy shift) | fail closed ✅ / documented fallback |
| 10 | `toSqliteUtc(shift.opened_at)` | `:1450` | unparseable → throws (`sqliteTime.ts:28-30`) | — | fail closed ✅ |
| 11 | `getShiftRefundReceiptTotals(...)` | `:1453` | throws → `errorInternal` | `[]` → count 0, value 0 | fail closed ✅ |
| 12 | `bcabs(total, scale)` per prior total | `:1462` | non-empty garbage → Big.js throws → `errorInternal` | `''` → silently `0` (`decimal.ts:16-19`) | fail closed ✅ **except** the empty case — see M-3 |
| 13 | `getCurrencyDecimals(currencyForBound())` | `:1097` | — | missing company → `'EUR'` scale 2 (`:1496`) | pre-existing; compounds I-3 |

No unclassified path remains other than rows 1, 3 and the empty-string corner of 12, all raised above.

---

## Verified clean

**(a) M2 arithmetic**
- Count limb `totals.length + 1 > policy.countCeiling` (`:1455-1457`) and value limb `bccomp(bcadd(alreadyRefunded, thisRefundTotal), policy.valueCeiling) > 0` (`:1461-1470`) are both `already + this > ceiling` — the accepted strictly-stronger ruling, implemented on both limbs.
- All arithmetic is Big.js decimal strings: `bcadd` / `bcabs` / `bccomp` / `bcformat`. **No SQL `SUM()`** on `offline_receipts.total` (raw strings returned, `offlineReceiptRepository.ts` `getShiftRefundReceiptTotals`), and a grep of the entire diff for `parseFloat|Number\(|\(float\)|toFixed\(|number_format|SUM\(` on added lines returns **only comments**.
- Scale: every comparison runs at `getCurrencyDecimals(currencyForBound())` — TND ⇒ 3. Server ships scale-4 strings; they are narrowed exactly once, at read time, in `readCeilingAmount`.
- `thisRefundTotal` uses the newly extracted `sumRefundLineTotals()` (`:1281-1286`), byte-identical to the receipt's own `total` derivation (`refundReceiptService.ts:168-171`, negated at `:207`). Refund receipts hard-set `cash_rounding_adjustment: '0'` (`refundReceiptService.ts:301`), so unlike the §M1 legacy bound there is no rounded-vs-exact mismatch to reconcile — the M2 aggregate compares like with like.
- **The unsynced predicate is real.** `fiscal_events.sync_status != 'synced'`, on a column declared `TEXT NOT NULL DEFAULT 'pending' CHECK (sync_status IN ('pending','syncing','synced','failed'))` (`migrations.ts:954, 969`) — so no NULL three-valued-logic hole. `'synced'` is written **only** on a positive server ACK (`syncService.ts:464-472`); every rejection path (`sequence_conflict`, chain break, generic exception) writes `'failed'` (`syncService.ts:487, 495, 521`) and therefore **still counts as unsynced**. A crash-stranded `'syncing'` row counts too. Proven against real SQLite at `migrations.v67.test.ts:210-235` (pending+syncing+failed = 3, synced excluded, other terminal isolated). **A synced-but-server-rejected event cannot evade the predicate.**
- Shift window mirrors `zReportService.generateZReport()` exactly (`offlineReceiptRepository.ts` anchor/openedAt branches vs `zReportService.ts:177-195`): rollback-immune `hash_sequence > opening_hash_sequence` preferred, wall-clock only for pre-anchor legacy shifts, `is_training = 0` and `receipt_kind = 'refund'` filters, per-terminal. Refund receipts do carry `hash_sequence = appended.sequence_number` (`refundReceiptService.ts:269`) in the same sequence space the anchor is taken in (`shiftReceiptAnchorRepository.ts:38-42`), so the anchor window cannot silently exclude them.
- **Rule 20 (`' ' < 'T'`) respected:** the wall-clock boundary goes through `toSqliteUtc(shift.opened_at)` (`:1450`), and the same-day inclusion is pinned by a real-SQLite test (`migrations.v67.test.ts:189-208`).

**(b) M3**
- Boundary is exact at scale: `bccomp(thisRefundTotal, policy.onlineRequiredThreshold) <= 0 → allow` (`:1488`) — equal-to-threshold is authorable offline, as ruled. Tests: over (`:1154-1166`), equal (`:1168-1177`), online bypass with a `0.0000` threshold (`:1179-1187`).
- Connectivity source is the real store (`useConnectivityStore`), not a cached flag on the shift or a `navigator.onLine` read. **But** see I-1 for the staleness window and the missing server-verification guarantee.
- Compares the cash payout (Σ|line_total|, gross), which is the right quantity for a cash ceiling. No per-line TTC-vs-HT assertion is introduced anywhere in this diff (rule 19 `unit_price` trap avoided).

**(c) Placement and refusal family**
- The block sits at `refundCheckoutStore.ts:1096-1146` — after the quantity cap, the §M1 legacy value bound and the discounted-partial refusal, and **before** `createOrReuseActiveRefundIntent()` (`:1148`) and before `step: 'confirm'` (`:1162`). The PIN is only collected in `approveAndSubmitV4()`. Nothing is signed at refusal; tests assert both `createOrReuseActiveRefundIntent` and `authorRefundReturnApprovalV3` were never called.
- The recovered-approval skip **cannot be reached on a fresh intent**: `recoveredApproval` is initialised `null` (`:911`) and only set from `resumeReusedIntent()`'s `{outcome:'proceed', approval}` on the `state === 'approval_authored'` branch, and only after `recoverRefundApprovalEvidenceLocally()` proves the pair on the local chain mirror (`:1586-1607`). The `'drafted'` branch returns `approval: null` (`:1609-1610`), and no existing intent leaves it `null`. Test at `:1224-1243` asserts the gate never even measures the shift on a resume.
- Three typed keys added to the `RefundCheckoutErrorKey` union (`:229-249`), each with an en **and** fr entry at the same `refundFlow.*` depth (`locales/en/pos.json:131-133`, `locales/fr/pos.json:131-133`) — **key parity confirmed**. Rendered generically via `t(error.key)` (`components/pos/RefundCheckoutFlow.tsx:63`), so no dispatch table needed updating. Copy names only causes the device can prove.

**(d) Settings channel**
- No new channel: `company_fraud_settings` → `FraudSettingsResolver::forCompany()` → `FraudSettingsDTO` (`#[TypeScript]`) → `FraudSettingsPosController::show()` → `fraudSettingsApi.fetchFraudSettings()` → `upsertCompanyFraudSettings()` → `getCompanyFraudSettings()` at `beginV4()`. Verified end to end.
- **Both** device write paths updated — `refreshFraudSettingsCache()` (`fraudSettingsApi.ts:46-52`, reached from `terminalStore.ts:528` preWarm) and the EOD inline upsert (`Header.tsx:156-165`). A repo-wide grep finds no third writer. Omitted-server-field fallback covered by a test (`fraudSettingsApi.test.ts` "falls back to the seeded device defaults…").
- "Enum-backed keys" is vacuous on this channel, as the report states and I confirmed: it is column-typed, not key/value — there are no setting-key strings to enumerate. New columns are `unsignedSmallInteger` / `decimal(12,4)` server-side and `INTEGER` / `TEXT` device-side, surfaced through a typed DTO and a typed row interface.
- Generated types match the DTO: `generated.d.ts:1345-1347` (`offlineRefundCountCeiling: number; offlineRefundValueCeiling: string; onlineRequiredRefundThreshold: string`) ↔ `FraudSettingsDTO.php`. The **four unrelated additions are genuine transform output, not hand edits** — each corresponds to a PHP definition that pre-dates this range: `SystemAccountPurpose::RefundWriteOff = 'refund_write_off'` (`Accounting/Domain/Enums/SystemAccountPurpose.php:64`), `Inventory/Domain/Enums/ReplayPreviewMode.php`, `Inventory/Domain/Enums/TerminalSyncHealthState.php`, `POS/Domain/Enums/SealedHashAlgorithm.php` (added by `7e21a68dc`).
- Server-side fallback for a pre-migration row (`FraudSettingsResolver.php:53-58`) is safe: Laravel's `decimal`/`integer` casts are primitive casts and pass `null` straight through, so `$row->offline_refund_count_ceiling ?? DEFAULT_…` genuinely coalesces on a tenant whose migration has not run — the POS `GET /fraud-settings` endpoint does **not** 500 during the migration window.

**(e) Migrations**
- Tenant migration `2026_08_01_000001` is self-guarding as claimed: `hasTable` early return, per-column `hasColumn` guards inside the `Schema::table` closure, idempotent NULL backfill, `DROP CONSTRAINT IF EXISTS` before `ADD CONSTRAINT`, pgsql-guarded, symmetric `down()`. Ordering is safe — `create_company_fraud_settings_table` is `2025_12_23_160000`, so the `hasTable` guard can never mark the migration run while silently skipping the columns.
- Device migration **v67** is additive and crash-safe: three separate `ALTER TABLE … ADD COLUMN` statements, each individually wrapped in `isDuplicateColumnError`-guarded `try/catch` (`migrations.ts:2164-2179`), so a crash between columns resumes correctly. Idempotency and default-preservation proven at `migrations.v67.test.ts:130-144`; column type / NOT NULL / default asserted against `PRAGMA table_info` at `:88-109`.
- No new `onQueue(...)` anywhere in the diff ⇒ no `apps/api/config/horizon.php` coverage owed. No projection or queued-job code touched ⇒ no `CompanyContext` / no-arg `getScale()` exposure introduced.

**(f)** See the fail-closed enumeration table above.

**(g) Defaults**
- All three sites carry byte-identical values `5` / `'300.0000'` / `'100.0000'`: PHP `CompanyFraudSettings.php:80,93,106`; device fallback `refundExposureDefaults.ts:29,32,35`; SQLite v67 `migrations.ts:2168-2170`.
- The PHP side is genuinely one source — `$attributes`, `getDefaults()`, `defaultsForVertical()` and the tenant migration's column defaults all resolve to the `DEFAULT_*` constants (the migration reads `CompanyFraudSettings::getDefaults()` rather than re-literalling).
- `migrations.v67.test.ts:94-108` binds the SQLite defaults to the TS constants. The PHP↔TS hop is the only unguarded edge — M-1.

**Tests**
- Re-run by me: `refundCheckoutStore.test.ts` 74 passed, `migrations.v67.test.ts` 6 passed, **80/80**, matching the report's counts. Vitest workers killed after.
- Right split of concerns: the store suite mocks the repository layer (so it tests the *policy*), and the SQL that only real SQLite can falsify — column defaults, the `' ' < 'T'` boundary, the `receipt_kind`/`is_training`/terminal filters, the anchor window, `'syncing'`-counts-as-unsynced — is exercised against real SQLite. No `assertTrue(true)`, no mocking of the thing under test, no fake API payloads.
- Existing v4 tests were kept meaningful: the shared `beforeEach` seeds a drained, online terminal with the seeded policy, so both bounds are inert and every pre-existing assertion keeps its original meaning.
- API side: `FraudSettingsResolverTest` uses `RefreshDatabase` + real `Tenant`/`Company` models and asserts against the constants, not literals; `FraudSettingsPosControllerContractTest` pins camelCase and rejects snake_case for all three new keys. (I did not re-execute the PHP suites — **cannot verify** the report's PHPUnit/PHPStan/Pint counts firsthand.)

---

## What to fix before merge

Close I-1 (pass a `requireServerVerifiedPin` flag into `verifyScopedManagerPin` so an above-threshold refund cannot fall back to the local PIN — the fix must land *before* `authorPosOverride` signs), I-2 (exclude the three refund-exposure columns from `FraudSettingsController::reset()` while they have no UI), and I-3 (refuse on an empty `companyId` instead of silently using the fallback constants); the five Minors can ride a follow-up.

---

# Re-verify round — `81a984e12..f6a418720` (commit `f6a418720`, "close I-1/I-2/I-3")

Scope: this diff only. Verdict per Important, plus any new breakage introduced here.

## RE-VERIFY VERDICT: all three **ADDRESSED**. No new findings. **APPROVE.**

Evidence re-run by me in this worktree:
- `apps/pos`: `refundCheckoutStore.test.ts` + `scopedManagerPin.test.ts` + `scopedManagerPin.audit.test.ts` + `refundApprovalV3.test.ts` + `migrations.v67.test.ts` → **128 passed / 5 files**; workers killed after.
- `apps/pos` `tsc --noEmit` → **exit 0**.
- `apps/api` `FraudSettingsControllerContractTest` → **5 passed, 81 assertions**.
- `apps/api` PHPStan L8 on `FraudSettingsController.php` → **No errors**.

---

## I-1 — M3 TOCTOU / no server-verified PIN → **ADDRESSED**

**The deviation is accepted, and it is strictly better than what I prescribed.** I asked for `isGenuineOfflineFailure()` to return `false` under the flag; that would have routed a genuine offline into the trailing generic `service_unavailable` 503 (`scopedManagerPin.ts:375-394`) — mislabelling a link drop as a server error, both in the audit trail and in the operator copy. The implemented shape audits `denied_kind: 'server_verification_required'` and throws a typed `ServerVerifiedPinRequiredError` (`scopedManagerPin.ts:67-75, 299-316`), which the store maps to the existing `refundFlow.largeRefundRequiresOnline` copy (`refundCheckoutStore.ts:1782-1786`). Same security effect, correct attribution, no new i18n owed. **Intent preserved completely.**

Both of the coordinator's specific questions check out against the code:

**(1) Is the new branch reachable ONLY under the flag?** Yes. `if (input.requireServerVerifiedPin === true)` (`scopedManagerPin.ts:299`) — strict identity against `true`, on an optional field (`:126`). Omitted ⇒ `undefined` ⇒ never taken. The six other production callers (`paymentStore.ts:1454`, `Header.tsx:242`, `AccountChargeConfirmation.tsx:169`, `CashDrawerModal.tsx:54`, `DiscountModal.tsx:152`, `LineDiscountModal.tsx:153`) and the legacy refund path (`refundApproval.ts:68`) are untouched by this diff and pass nothing. The `refundApprovalV3` path now always passes an explicit `false` when not required (`refundApprovalV3.ts:85`), which the exact-args (not `objectContaining`) assertion at `refundApprovalV3.test.ts:97-104` pins as byte-identical, and `=== true` makes behaviourally identical.

**(2) Does every path under the flag refuse rather than fall through?** Yes — I enumerated every exit of `verifyScopedManagerPin`. There are exactly **two** `return` statements that yield an approval: the server-confirmed one (`:227-231`) and the offline local fallback (`:364-368`). The new branch is the **first** statement inside `if (isGenuineOfflineFailure(error))` (`:293`), ahead of both the FU-1b stale-cache check and that only local `return`, so under the flag `:364` is unreachable. The remaining exits are all throws: `manager_pin_scope_mismatch` (`:186`), the 403 denial rethrow (`:284`), the retries-exhausted 503 (`:258`), the unexpected-error 503 (`:390`). **Under the flag the function can only return a server-confirmed approval or throw.** (Skipping the FU-1b stale-cache check under the flag is harmless: an unconditional refusal is strictly stronger than a freshness test.)

Placement is preserved: `verifyScopedManagerPin()` runs at `refundApprovalV3.ts:78`, **before** `authorPosOverride()` at `:91` — so a refusal leaves nothing signed and cannot strand an approval+override pair (the finding-10 hazard my own prescription warned about).

Wiring is derived from the **amount**, not from a connectivity re-read: `requireServerVerifiedPin = bccomp(thisRefundTotal, policy.onlineRequiredThreshold) > 0` (`refundCheckoutStore.ts:1170-1171`), computed inside the same `try` as the policy read, so a policy that cannot be read refuses via the existing catch rather than falling through with `false`. It is carried on the store (`:467-479, 1221`), consumed at `:1774`, and survives a retry (the error branch sets `step:'approval'` without clearing it). On the resumed-approval path the flag stays `false` and is never consumed — `approveAndSubmitV4` short-circuits `authorRefundReturnApprovalV3` entirely when `state.approval !== null`. The exact TOCTOU scenario (online at `begin()`, link drops during PIN entry) is pinned by a test asserting `createRefundReceipt` not called, `approval` null, `refundIntent` still present, `step === 'approval'` (`refundCheckoutStore.test.ts:1279-1306`), plus a companion asserting a non-M3 approval failure still yields `errorApproval`.

## I-2 — `reset()` widened the invisible ceilings → **ADDRESSED**

`reset()` now passes `Arr::except(CompanyFraudSettings::getDefaults(), self::REFUND_EXPOSURE_KEYS)` (`FraudSettingsController.php:199-202`), with the excluded keys as a documented `private const` (`:62-66`).

- **`update()` intact:** untouched by this diff — it still writes only `$validated` (`:120-123` pre-diff, now `:146-149`), and the three columns have no validation rules, so they were never in `$validated`. The diff adds no rule and removes none. ✅
- **Rowless companies still get model defaults:** `updateOrCreate` on a missing row constructs the model, whose `$attributes` (`CompanyFraudSettings.php:61-63`) already carry `DEFAULT_OFFLINE_REFUND_COUNT_CEILING` / `_VALUE_CEILING` / `_ONLINE_REQUIRED_REFUND_THRESHOLD`, so the INSERT includes them — excluding them from the payload cannot produce a `0`/NULL ceiling. ✅ Directly asserted by `test_reset_on_a_company_with_no_row_still_seeds_the_refund_exposure_defaults`, and the preserve case by `test_reset_preserves_the_refund_exposure_policies` (which also asserts the cash-control default WAS reset, so the exclusion is not over-broad). Both green.

## I-3 — empty `companyId` degraded to fallback constants → **ADDRESSED**

The guard is the **first** statement inside `if (recoveredApproval === null)` (`refundCheckoutStore.ts:1114-1132`), ahead of `getCurrencyDecimals(currencyForBound())` (`:1134`), ahead of `sumRefundLineTotals()` (`:1135`), and ahead of `readRefundExposurePolicy()` (`:1140`). **The refusal therefore happens before any policy read AND before any scale read** — so the EUR-scale-2-instead-of-TND-3 compounding I flagged is closed too. It refuses with `errorInternal`, mirroring `resumeReusedIntent()`'s posture for the identical condition (`:1645-1647`). Test at `:1236-1245` asserts `getCompanyFraudSettings` was **not called** and no intent was created. The resumed path skips the guard, which is sound: `recoveredApproval` can only be non-null via the `approval_authored` branch, which itself already refuses on `companyId === ''`.

## New breakage in this diff — none found

- **No signature break:** `requireServerVerifiedPin` is optional on both `ScopedManagerPinApprovalInput` and `AuthorRefundReturnApprovalV3Input`; `tsc --noEmit` clean; the pre-existing `scopedManagerPin` suite (17) and its audit companion (11) both green, including the unchanged offline-fallback, retry-exhaustion, server-rejection and fail-closed-on-unexpected-error cases.
- **No import cycle:** `refundCheckoutStore` → `scopedManagerPin` is one-directional (`scopedManagerPin` does not import the store).
- **New store field is safe:** `requireServerVerifiedPin: boolean` is initialised in `initialState` (`:558`) and cleared in `reset()` (`:815`); no other construction site exists.
- **Error-copy reuse is accurate:** `refundFlow.largeRefundRequiresOnline` already reads "…Reconnect the terminal and try again, so a manager's approval can be checked with the server" — correct for the PIN-time refusal as well as the begin()-time one, in both en and fr. No new key, so key parity is unchanged.
- **No precision, chain, projection, queue or timestamp surface touched** by this diff: no money math added (`bccomp` on two already-scaled canonical strings), no event class altered, no `onQueue`, no SQLite time boundary.

**Observation (not a finding):** `denied_kind: 'server_verification_required'` is a new free-string value in an untyped audit payload (`recordAuditEvent` takes `payload?: Record<string, unknown>`), exactly like the pre-existing `no_local_match` / `server_rejected` / `service_unavailable` / `stale_offline_cache`. A repo-wide grep finds **no** consumer in `apps/web` or `apps/api` that switches on `denied_kind`, so nothing breaks; worth registering in the fraud taxonomy whenever one is built.

**Carried forward unchanged:** the five Minors from the first round (M-1 PHP↔TS defaults parity unguarded, M-2 half-up ceiling narrowing, M-3 `bcabs('')` reads as 0, M-4 the 2026-04-25 seed migration vs the new columns, M-5 hand-written `FraudSettingsResponse`). None are touched by this diff and none block.
