# Lane C — WHOLE-BRANCH FINAL REVIEW (feat/v3-refund-chain)

Range `fb3b51608..f6a418720` (62 commits, 168 files, +28784/-340)
Worktree `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain` — read-only review, nothing modified.
Reviewer: fiscal-pos-reviewer (opus). Scope = what scoped/wave gates structurally cannot see:
cross-wave seams, merge/deploy readiness, ledger triage, launch-contract coherence, interim asymmetry.
Per mandate, individual already-gated fixes were NOT re-reviewed.

## VERDICT: MERGE-READY-WITH-CONDITIONS

0 Critical / 4 Important / 4 Minor. No fiscal-chain defect, no wrong-money defect, no
silently-dropped-row defect found at the seams. The four Important items are one latent
immutability widening, one missing rollback lever, and two deploy-record defects — all
closeable in one small commit + a corrected deploy checklist.

### Merge conditions (all four must be closed before promoting to `origin/dev`)
1. C-1 — close the trigger-branch immutability widening (one-line SQL amendment migration or edit of
   `2026_07_31_940000` before it has ever run on staging; it has not).
2. C-2 — publish the rollback procedure (clear BOTH `v4_refund_authoring_enabled` AND
   `v4_refund_authoring_acknowledged_at`); a `--disable` flag on the enable command is the preferred fix.
3. C-3 — replace the ledger's stale deploy line with the enumeration in §2 of this document.
4. C-4 — record the FR/TN account-709 `type` divergence in the deploy notes so the money test
   campaign does not read staging P&L classification as a defect.

---

## 1. FINDINGS

### [IMPORTANT] I-1 — NF525 immutability trigger: the new §6.2 branch drops four guarded columns
`apps/api/database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:102-117`

The branch immediately above it (the FK-cleanup branch, `:72-90`) guards
`customer_name`, `customer_identifier` explicitly. The new branch does not, and it additionally
does not constrain `NEW.fiscal_status`, `is_voided`, `receipt_type`, `original_receipt_id`,
`discount_amount`.

Its entry condition is very broad: `OLD.fiscal_status='fiscalized' AND OLD.sealed_hash_algorithm IS NULL
AND NEW.sealed_hash_algorithm IS NOT NULL`. **Every** `pos_receipts` row that existed before this
feature has `sealed_hash_algorithm IS NULL`, so this branch is reachable for the entire installed base.

Failure scenario: any writer that issues a single `UPDATE pos_receipts SET sealed_hash_algorithm=…,
customer_name=…, is_voided=true WHERE …` on a fiscalized row passes the trigger — the last line of
defense for NF525 inalterability — where today it would raise. The projection/canonical divergence
would be silent (`canonical_bytes` and `fiscal_hash` are guarded, so the row would simply disagree
with its own signed bytes).

Not currently exploited: `BackfillSealedHashAlgorithmCommand.php:225-226` does
`$receipt->sealed_hash_algorithm = …; $receipt->save();`, and Eloquent only sends dirty attributes,
so today's only writer sends exactly one column. This is a widened invariant, not a live bug.

Fix: add to the new branch, verbatim from the branch above —
`AND NEW.customer_name IS NOT DISTINCT FROM OLD.customer_name
 AND NEW.customer_identifier IS NOT DISTINCT FROM OLD.customer_identifier
 AND NEW.partner_id IS NOT DISTINCT FROM OLD.partner_id
 AND NEW.contact_id IS NOT DISTINCT FROM OLD.contact_id
 AND NEW.fiscal_status = OLD.fiscal_status
 AND NEW.is_voided IS NOT DISTINCT FROM OLD.is_voided`.
Cheapest path: edit `940000` in place (never run on staging yet) rather than stack an amendment.

### [IMPORTANT] I-2 — the launch contract has no reversible rollback; the obvious lever bricks refunds
`apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnableV4RefundAuthoringCommand.php:143-144`
`apps/api/app/Modules/POS/Application/Services/V4RefundAuthoringAcknowledgementService.php:37-41`
`apps/api/app/Modules/POS/Application/Services/LegacyCorrectionGuard.php:36`
`apps/api/app/Modules/POS/Domain/Terminal.php:129-137` (casts only; NOT in `$fillable`)
`apps/pos/src/pages/HomePage.tsx:1120-1124`

Verified by grep across `app/` + `database/`: `v4_refund_authoring_acknowledged_at` is written in
exactly one place and **never cleared**; `v4_refund_authoring_enabled` is set `true` in exactly one
place and never set `false`; neither column is mass-assignable, so `PATCH /pos/terminals/{id}`
cannot touch them. Rollback is manual SQL only.

Failure scenario (the launch-night one): something goes wrong with v4 refunds, an operator runs the
intuitive `UPDATE pos_terminals SET v4_refund_authoring_enabled = false`. Next device pull →
`setV4RefundAuthoringEnabled(db, terminalId, false)` (`syncService.ts:1465`) → `HomePage.tsx:1123`
routes to the LEGACY flow → `ReceiptReturnService.php:254` / `ReceiptVoidService.php:90` still see a
non-NULL `acknowledged_at` and return 409 `LEGACY_CORRECTION_RETIRED`. **Neither refund path is
available**, and the cashier-facing copy is "Refunds are temporarily unavailable on this terminal —
contact support" (`bootstrap/app.php:277`), which does not point anyone at the cause.

Fix: add `--disable` to `EnableV4RefundAuthoringCommand` that clears BOTH columns in one statement;
at minimum, put the two-column requirement in the deploy/runbook doc.

### [IMPORTANT] I-3 — the ledger's deploy record understates the branch
`docs/sessions/TASK-LOG-lane-c-code-phase.md:130` ("DEPLOY: device migration v66 required; no server
migration") and `:255-257` ("release notes: v66+v67 device migrations, … permission cache-reset if
perms added").

Both are stale. Actual content of the range (enumerated in §2): **7** server tenant migrations,
**3** device migrations (v65/v66/v67), **1** new permission that IS added, and **2** artisan commands
that are hard prerequisites for enablement. `origin/dev` auto-runs `tenants:migrate` on staging but
does NOT run seeders, does NOT run `permission:cache-reset`, and does NOT run either command.
Merging on the current record risks a staging state where `EnableV4RefundAuthoringCommand` refuses
(missing accounts) and `fiscal.refunds.manage_dead_letters` 403s for every role.

### [IMPORTANT] I-4 — FR/TN account 709 `type` divergence between staging and a fresh tenant
`apps/api/database/seeders/FranceChartOfAccountsSeeder.php:287-296`,
`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:282-291` flip 709 from
`'revenue'` to `'expense'` and add `system_purpose = sales_return`.
But `FranceChartOfAccountsSeeder.php:38-57` never rewrites an existing row (it promotes `is_system`
only), and `BackfillRefundCompensationAccountsCommand.php:24-35` deliberately patches ONLY
`system_purpose`, never `type`, to avoid re-signing historical journal lines.

Consequence: on staging (and on any pre-existing FR/TN chart) a `valid_unbooked` compensation debits
a **revenue**-typed 709; on a fresh production tenant #1 it debits an **expense**-typed 709. There is
no runtime type assertion (`GeneralLedgerService::getAccountByPurpose:4451` →
`Account::findByPurposeOrFail`, no `expectedAccountType()` check), so nothing errors — the P&L
classification simply differs. This is the accepted §5.3 divergence, but it is NOT in the deploy
notes, and the §F money-test campaign will read the staging figure.

### [MINOR] M-1 — one migration is not self-guarding
`apps/api/database/migrations/tenant/2026_07_31_950000_create_fiscal_refund_compensations_table.php:25`
uses a bare `Schema::create` where the other six new migrations all open with a
`Schema::hasTable`/`hasColumn` no-op guard. A partially-applied batch re-run on a staging tenant
throws "relation already exists" instead of no-opping. Add `if (Schema::hasTable('fiscal_refund_compensations')) return;`.

### [MINOR] M-2 — one permanently-failed fiscal event arms M2 forever
`apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:92-103` — `sync_status != 'synced'`.
The device's status domain is `pending|syncing|synced|failed` (`migrations.ts:969`). A poison event
that the server rejects settles at `'failed'` and never leaves it, so
`evaluateOfflineRefundVelocityCeiling` (`refundCheckoutStore.ts:1491`) is armed on every subsequent
shift: 5 refunds / 300.0000 TND per shift, permanently, with no operator-visible cause. Fail-closed
and therefore acceptable for launch, but it belongs in the E-9 runbook next to the dead-letter check.

### [MINOR] M-3 — the §5.1/§5.2 operator surface is API-only
The branch touches **zero** `apps/web` files. `DeadLetteredProjectionsController` and
`RefundCompensationController` (`apps/api/app/Modules/Fiscal/routes.php:36-50`) have no UI. The E-9
runbook's daily "dead-lettered projections + non-null `refund_policy_alerts`" check therefore has to
be a hand-issued API call. Already ticketed as a minor; just confirm the runbook carries the exact curl.

### [MINOR] M-4 — device migration v66 has no dedicated test
`migrations.v65.test.ts` and `migrations.v67.test.ts` exist; v66
(`terminal_state.v4_refund_authoring_ack_error`, `migrations.ts:2131-2145`) has none. Low risk (single
idempotent `ADD COLUMN`), noted for symmetry.

---

## 2. DEPLOY-READINESS ENUMERATION (this section is the deploy checklist)

### 2.1 Server tenant migrations — 7, all new, run automatically by `tenants:migrate` on push to `origin/dev`
Ordering is by filename and is correct; `910000` must precede `940000` (the trigger references the
column it adds) and does.

| # | File (`apps/api/database/migrations/tenant/`) | Effect | Self-guarding | Idempotent re-run |
|---|---|---|---|---|
| 1 | `2026_07_31_910000_add_sealed_hash_algorithm_to_pos_receipts.php` | nullable `varchar(32)` on `pos_receipts` | yes (`:29`) | yes |
| 2 | `2026_07_31_915000_add_sealed_hash_algorithm_backfill_completed_at_to_pos_terminals.php` | nullable timestamp on `pos_terminals` | yes (`:30`) | yes |
| 3 | `2026_07_31_920000_add_refund_policy_alerts_to_pos_receipts.php` | nullable `jsonb` on `pos_receipts` | yes (`:26`) | yes |
| 4 | `2026_07_31_930000_add_v4_refund_authoring_capability_to_pos_terminals.php` | `boolean default false` + nullable timestamp | yes (`:31`) | yes |
| 5 | `2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php` | `CREATE OR REPLACE FUNCTION prevent_receipt_modification()` | pgsql-only guard (`:41`); `CREATE OR REPLACE` is inherently idempotent | yes | 
| 6 | `2026_07_31_950000_create_fiscal_refund_compensations_table.php` | new table + unique index on `fiscal_event_id` | **no** (see M-1) | no |
| 7 | `2026_08_01_000001_add_refund_exposure_settings_to_company_fraud_settings.php` | 3 columns + CHECK + backfill on `company_fraud_settings` | yes (`:35`, `:42/47/52`, drop-if-exists CHECK) | yes |

Trigger-redefinition safety confirmed: `grep -rln prevent_receipt_modification database/migrations/`
returns 8 files; the newest prior *definition* is `2026_05_26_100001_allow_pos_receipt_fk_cleanup.php`
(`2026_07_28_100200` only mentions it in a comment), and migration 5 copies that definition verbatim
into both `up()` and `down()`, adding exactly one branch. No later migration will clobber it.

Column-order note: migrations 2 and 4 both use `->after('fiscal_schema_version')`. Harmless — Laravel's
`after()` is a MySQL-only hint and is ignored on PostgreSQL.

### 2.2 Device (SQLite) migrations — 3, applied at app startup
| v | Name (`apps/pos/src/lib/db/migrations.ts`) | Effect | Idempotent |
|---|---|---|---|
| 65 | `refund_intents_and_v4_refund_capability` (`:2008`) | `CREATE TABLE IF NOT EXISTS refund_intents` + 2 indexes + partial unique index; `offline_receipts.receipt_kind`; `terminal_state.v4_refund_authoring_enabled` / `_acknowledged_at` | yes (`IF NOT EXISTS` + `isDuplicateColumnError` catch) |
| 66 | `add_v4_refund_authoring_ack_error_to_terminal_state` (`:2131`) | `terminal_state.v4_refund_authoring_ack_error` | yes |
| 67 | `add_refund_exposure_policies_to_company_fraud_settings_cache` (`:2160`) | 3 columns on `company_fraud_settings_cache`, defaults byte-identical to the server's `CompanyFraudSettings::DEFAULT_*` | yes |

SQLite time contract (rule 20) re-verified for the new tables: `refund_intents.created_at/updated_at`
use `DEFAULT (datetime('now'))` (SPACE separator). The only JS-supplied boundary compared against a
`datetime('now')` column in the new code is
`refundCheckoutStore.ts:1508` → `toSqliteUtc(shift.opened_at)`, matching
`zReportService.ts:194` and `endOfDayPreview.ts:181`. No raw `.toISOString()` reaches a SQLite time
comparison in the new repositories (grep across `refundIntentRepository`, `offlineReceiptRepository`,
`terminalStateRepository`, `localRefundRecordRepository`, `companyFraudSettingsCacheRepository`,
`fiscalEventRepository`, `refundCheckoutStore`, `refundReceiptService` — the only hits are
`setSyncMetadata`/`markZReportSynced` value writes, not comparisons).

### 2.3 Queues
**No new queue names.** `git diff … | grep onQueue` over the whole range returns nothing.
`apps/api/config/horizon.php` is untouched and `HorizonQueueCoverageTest` remains satisfied.
`ApplyFiscalEventProjectionJob` gained a non-retryable branch (`:395-412`) but no queue change.

### 2.4 Permissions — 1 new, REQUIRES a post-deploy step
`fiscal.refunds.manage_dead_letters` (`RolesAndPermissionsSeeder.php:426`), granted to the role at
`:537`. Guards 3 routes (`Fiscal/routes.php:37,45,48`).
Post-deploy, in order: `tenants:run db:seed --class=RolesAndPermissionsSeeder` then
`permission:cache-reset` (the Spatie permission cache is tenant-blind — see
`project_spatie_permission_cache_tenant_blind.md`). Without both, every call to the dead-letter and
compensation surfaces 403s.

### 2.5 Artisan commands — hard prerequisites for enablement
1. `php artisan accounting:backfill-refund-compensation-accounts` (`--dry-run` first) — provisions
   `refund_write_off` + patches `sales_return`'s `system_purpose`. **Required**: without it
   `EnableV4RefundAuthoringCommand`'s §5.3 precheck (`:120-135`) refuses.
2. `php artisan fiscal:backfill-sealed-hash-algorithm` — only needed for terminals carrying legacy
   (`fiscal_event_id IS NULL`) fiscalized rows. Tenant #1 is v3-from-birth, so expect a no-op; run
   it anyway so `sealed_hash_algorithm_backfill_completed_at` is stamped.
3. `php artisan fiscal:enable-v4-refund-authoring --tenant= --company= --dry-run` then for real —
   LAST, and only after the E-7 evidence gate. Preflight: exactly 1 active Physical terminal, zero
   legacy-sealed fiscalized receipts, both accounts present.
4. Chart-of-accounts seeder re-run is NOT sufficient on its own for existing tenants — see I-4.

### 2.6 Generated types
`packages/shared/types/generated.d.ts` — 4 hunks, all consistent with transform output, no hand edits:
`SystemAccountPurpose` gains `'refund_write_off'`; `POS` gains `SealedHashAlgorithm`; `FraudSettingsData`
gains the 3 M2/M3 fields; plus 2 unrelated stale pickups (`ReplayPreviewMode`,
`TerminalSyncHealthState`) that the transform simply had not emitted before. All three PHP sources
(`SystemAccountPurpose.php:63`, `SealedHashAlgorithm.php`, `FraudSettingsDTO.php:22-33`) match.

### 2.7 CI
`.github/workflows/ci.yml:555` — the PG-only filter gains 19 new test classes covering the
PostgreSQL-specific surfaces (trigger, `FOR UPDATE` cap, concurrent redelivery, mixed-legacy).
This is the right mitigation for the "SQLite masks PG aggregate bugs" trap.

---

## 3. CROSS-WAVE SEAM AUDIT (the mandate's item 1)

All seams below were traced end-to-end in code at HEAD. **All compose.**

| Seam | Producer | Consumer | Verdict |
|---|---|---|---|
| ACK endpoint exists and persists | `POS/routes.php:82-85` → `V4RefundAuthoringAcknowledgementController:52` → `…Service:41` (`forceFill(['v4_refund_authoring_acknowledged_at' => now()])->save()`) | device `syncService.ts:1390` `apiPost('/pos/terminals/{id}/acknowledge-v4-refund-authoring')`, 3 attempts w/ backoff | OK — path, method and payload match |
| ACK authorization | `Gate::any(['pos.manage_terminals','pos.operate_terminal'])` (`…Controller:47`) | identical gate on `TerminalController::show:75`, which the device's pull already calls successfully | OK — cannot 403 where the pull succeeds |
| Capability flag delivery | `TerminalResource.php:134` `'v4_refund_authoring_enabled'` | `syncService.ts:1414` `apiGet<TerminalStateResponse>('/pos/terminals/{id}')`, read at `:1444` with `?? false` stale-server default | OK |
| ACK persistence ↔ M2 predicate | ACK txn writes `fiscal_events.sync_status='synced'` (`syncService.ts:375`) | `countUnsyncedFiscalEvents` reads `sync_status != 'synced'` (`fiscalEventRepository.ts:99`) | OK — same column, same value (caveat M-2) |
| Device payload ↔ server key set | `RefundReceiptV4Payload.ts:483-494` = v3 skeleton spread + `original_line_references` + `refund_destination` + `settlement_allocation` (33 keys) | `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V4` (33 keys, `:369-402`), selected by `payloadKeysFor():429` | OK — plus golden fixture + `CanonicalByteHashV4ParityTest` + `RefundReceiptV4Payload.parity.test.ts` on both sides |
| Version fan-out | `FiscalEventPayloadRegistry.ts:213-233` (REFUND→4, VOID→throw, unknown→throw) | `FiscalEventPayloadRegistry.php:120` `[1,2,3,4]` + validator `:866-881` (v4 ⇒ must be REFUND) | OK — VOID fail-closed on both sides |
| Projection shape ↔ wave-4 aggregates | `PosCoreReceiptProjection:349` writes `receipt_type` from `resolveReceiptType():568-575` (REFUND/VOID + resolved original ⇒ `Return`); `total` is the payload's POSITIVE magnitude (`:363`) | every wave-4 site keys on `receipt_type='return'` + `-ABS(col)` per row (`PosAnalyticsService:439/458`, `ReportGenerationService:528`, `GrandtotalService:172/207`, `OwnerSalesSummaryService:112`) | OK — assumption matches what wave 2 actually emits, both sign eras |
| Payment legs exist for returns | `writePayments()` called unconditionally at `PosCoreReceiptProjection:435` | `ReportGenerationService::buildExpectedPerMethod:528` nets return legs; `calculateShiftTotals:1023-1037` nets them into `payment_methods` | OK — the return arm has rows to net |
| Stock direction | `applyStockMovementForLines:1530` → `restockForLines:1822` (Receipt/POSReturn), disposition-aware: `scrap`/`not_received` skip (`:1841-1844`), `restock` + `RestockPolicy::Never` skips with a warning (`:1846-1855`) | device sends `disposition` per line in `original_line_references` | OK — restock, not decrement; exactly-once via the `fiscal_event_id` anchor |
| Refund rows can't double-submit | refund `offline_receipts` row is inserted `status:'pending'` (`refundReceiptService.ts:281`) | `getPendingReceiptsForSync` (`offlineReceiptRepository.ts:330`) has **no live caller** (grep across `apps/pos/src`, tests excluded) — v3 sync pushes `fiscal_events` only | OK — no legacy receipt-sync double-post |
| Post-ACK flips atomicity | `applyRefundAckLocalFlips` (`syncService.ts:368-401`) — one `withWriteTransaction`, each of the 3 flips asserts exactly 1 row | resolves the receipt via `idempotency_key = refund_intents.id` (`updateReceiptStatusByIdempotencyKey:305`) | OK |
| M2/M3 settings channel | `CompanyFraudSettings::DEFAULT_*` → `FraudSettingsResolver:48-58` → `FraudSettingsDTO:22-33` → `fraudSettingsApi.ts:47-52` → `companyFraudSettingsCacheRepository` → `readRefundExposurePolicy` (`refundCheckoutStore.ts:1422`) | device fallback constants byte-identical to server defaults (`migrations.ts:2164-2168`) | OK — precedence (row > fallback; unreadable ⇒ refuse) is coherent end-to-end |
| Residual bare `SUM(total)` consumers | swept `app/` for `SUM(total)`, `SUM(pos_receipts.total)`, `SUM(subtotal)`, `SUM(tax_amount)`, `->sum('total'…)` | only `SalesReportService.php:51` (already `receipt_type = sale` filtered at `:42`) and `DashboardController:43/49` (sums `documents`, not `pos_receipts`) | OK — the 5-aggregate pre-enable ticket is genuinely closed |
| Rule 20 in tests | all 8 new `PosCoreReceiptProjection*` tests bind context in `setUp` for fixtures and call `app(CompanyContext::class)->clear()` inside the single `apply()` helper (1:1 apply:clear ratio in each file) | — | OK |
| i18n | `apps/pos/src/locales/en/pos.json` vs `fr/pos.json` added-key sets | `diff` of extracted keys | OK — identical |

---

## 4. LAUNCH-CONTRACT WALKTHROUGH (the mandate's item 4)

One terminal, full lifecycle, traced through branch code.

| # | State | Device routing | Server `/return` + `/void` | Both? Neither? |
|---|---|---|---|---|
| S0 | provisioned v3; `enabled=false`, `ack=NULL`; local flag 0 | LEGACY (`HomePage.tsx:1123`, `getV4RefundAuthoringEnabled` returns false on missing row / pre-v65 column) | OPEN (`LegacyCorrectionGuard:36` inert) | legacy only ✔ |
| S1 | sales authored (v3 fiscal events, `fiscal_event_id` NOT NULL) | LEGACY | OPEN | legacy only ✔ |
| S2 | `fiscal:enable-v4-refund-authoring` run → `enabled=true`, `ack=NULL`; device has not pulled | LEGACY (local flag still 0) | OPEN | legacy only ✔ — preflight at `EnableV4…:103-117` passes precisely because S1's sales are v3 |
| S3 | device pull lands: `setV4RefundAuthoringEnabled(…, true)` at `syncService.ts:1465`, ACK POST in flight | V4 | OPEN until the ACK returns | **BOTH nominally open** — but only for the milliseconds of the POST, and the device itself cannot reach the legacy path (single boolean at `HomePage.tsx:1123`). Enable-time single-Physical-terminal preflight means no second client exists. Accepted. |
| S3' | ACK fails all 3 attempts (`syncService.ts:1364-1385`) | V4 (v4 refunds still project fine — ingress does not consult the capability) | OPEN indefinitely | **BOTH**, durably. Recorded: `terminal_state.v4_refund_authoring_ack_error` (v66) + `logSyncOperation` row + `console.error`. Self-heals on the next successful pull (the ACK is re-sent every pull while `enabled`). Acceptable and observable. |
| S4 | ACK succeeds → `ack` stamped | V4 | 409 `LEGACY_CORRECTION_RETIRED` | v4 only ✔ |
| S5 | v4 refund → Z → sync | V4 | 409 | ✔ — the §1.1 Z leg is covered by `ReceiptReturnRefactorV3Test` |
| **S6** | **naive rollback: `enabled=false` only** | LEGACY (next pull writes 0) | **still 409** (`ack` never cleared) | **NEITHER — see I-2.** Reachable by the single most likely emergency action; recoverable only by SQL. |
| S7 | device DB reset / reinstall after S4 | LEGACY until the first pull writes the flag | 409 | NEITHER, transiently. Low impact: the legacy path is an online-only server call anyway, and the first pull is a startup precondition. |

**Unreachable / irreversible-by-accident:** S6 is the only irreversible-by-accident state and it is the
merge condition C-2. No state is unreachable. `acknowledged_at` is a genuine one-way door by design
(`V4RefundAuthoringAcknowledgementService:19-23`) — that is correct for the audit trail, but the
*pair* of flags needs a documented joint reset.

---

## 5. INTERIM VAT ASYMMETRY (the mandate's item 5) — CONFIRMED NOT WORSE

Ticketed behaviour: refund-side VAT decomposition correct, sale-side gross-as-net, leaving a
`+2/+2` residue on a fully-refunded 12.00-gross / 2.00-VAT line.

Code at HEAD:
- SALE branch, **unchanged by this branch**: `apps/pos/src/lib/offline/zReportService.ts:906-914` —
  `const lineNet = line.line_total ?? '0'; const lineGross = bcadd(lineNet, lineVat);` i.e. it treats
  the gross TTC `line_total` as net and adds VAT on top. Pre-existing, live on staging today for any
  taxed sale.
- REFUND branch, **new and correct**: `zReportService.ts:866-877` —
  `lineNet = bcsub(bcabs(line_total), bcabs(tax_amount))`, all three accumulators subtracted.

Net effect for a taxed sale fully refunded in the same shift: sale contributes net 12 / vat 2 /
gross 14; refund subtracts net 10 / vat 2 / gross 12 ⇒ residue net +2, vat 0, gross +2. **Exactly what
the ticket describes.** Nothing in the range touches the sale branch.

Strictly-better check: before this branch a *legacy* refund contributed nothing to `vatByRate` at all
(`local_refund_records` carries no line detail), so a fully-refunded taxed sale left the FULL sale
(net 12 / vat 2 / gross 14) in the Z's VAT block. The residue therefore **shrinks** from the full sale
to +2/+2. The branch does not make the asymmetry worse by any measure.

Also confirmed unmoved: `refunds_amount` stays a positive magnitude and is never folded into
gross/net sales on either side (`zReportService.ts:841-848` device, `ReportGenerationService:1019-1021`
server), so the ticketed defect stays contained to the VAT block.

---

## 6. LEDGER TRIAGE (the mandate's item 3)

Source: `docs/sessions/TASK-LOG-lane-c-code-phase.md`. One challenge to a parked ruling is raised, with
evidence (row 5).

| # | Ledger item (line) | Verdict | Basis |
|---|---|---|---|
| 1 | ⚖️ Q-1 — device-local cumulative-quantity backstop RETAINED; spec owes a §4.4 addendum (`:70-76`) | **OK-TO-MERGE-AS-IS** | Ruling re-issued on the merits; the §4.4 erratum landed in round 2 item G. Doc-only residue. |
| 2 | ⚖️ Ruling 1 — SALE-branch gross-as-net Z/EOD/X decomposition is a PRE-EXISTING LIVE DEFECT, out of wave, URGENT ticket owed (`:121-124`) | **OK-TO-MERGE-AS-IS** | Independently verified at §5 above: sale branch byte-unchanged, refund branch correct, residue shrinks. Fixing it changes signed `Z_REPORT` bytes and correctly needs its own fiscal gate. |
| 3 | ⚖️ Ruling 2 — one exact `Math.abs` at the number-typed `CartItem` boundary PARKED (`:125-126`) | **OK-TO-MERGE-AS-IS** | Sign flip on an already-`number` field is exact in IEEE-754; the canonical quantity string is derived once via `bcformat(line.quantity, FROZEN_QUANTITY_SCALE)` and then **asserted** byte-identical to the signed line (`RefundReceiptV4Payload.ts:468-473`, `RefundQuantityAlignmentError`). No float reaches money or the signed bytes. |
| 4 | ⚖️ Ruling 4 — per-line-discounted PARTIAL refunds refused pre-PIN; launch scope narrowed (`:128-130`) | **OK-TO-MERGE-AS-IS** | Deliberate, fail-closed, owner-visible. Server mirrors it (`FiscalPayloadConstraintValidator:987-991`, v4 requires zero `transaction_discount_amount`). Test-plan correction is already listed as owed. |
| 5 | "DEPLOY: device migration v66 required; no server migration" (`:130`) and "release notes: v66+v67 device migrations" (`:256`) | **MUST-FIX-BEFORE-MERGE** | **Challenged with evidence.** The range ships 7 tenant migrations, device v65+v66+v67, a new permission, and 2 prerequisite artisan commands (§2). Both ledger lines are factually wrong for the whole branch and would produce a broken staging enablement. = condition C-3. |
| 6 | ⚖️ NEW-3 — `cumulative_refunds` fix-forward, NO backfill (`:195-197`) | **OK-TO-MERGE-AS-IS** | `computeGrandTotals` is proven derived-not-sealed (`ReportGenerationService:867-887` docblock + `ZReportHashService::serializeForHashing` hashes only `z_number\|terminal_id\|generated_at\|report_data_json`), and this method is refused entirely at `fiscal_schema_version >= 3`. Tenant #1 is fresh. Release note is mandatory — folds into C-3. |
| 7 | W4 residual (a) — `period_totals` refund keys COUNT VOIDS (signed-payload mislabel) (`:167`) | **OK-TO-MERGE-AS-IS** | Correctly NOT renamed: the keys are inside hash-chained bytes (`GrandtotalService:128-142` docblock). Pinned by test + docblock. Re-pointing needs its own ruling. |
| 8 | W4 residual (b) — `calculatePeriodTotals` windows on `created_at` vs `posted_at` elsewhere (`:168`) | **OK-TO-MERGE-AS-IS** | Pre-existing skew, unchanged by this branch, does not interact with refund sign. Ticket. |
| 9 | W4 residual (c) — newly generated Zs over legacy returns now report magnitude refunds (`:168-169`) | **OK-TO-MERGE-AS-IS** | Accepted semantic; sealed rows untouched; verify suites green. Belongs in the release note (C-3). |
| 10 | W4 residual (d) — `PosAnalyticsService` training-flag gap; `PosAnalyticsService:77` AVG-float; `CashierComparisonChart.tsx:64` `Number()` on money (`:169-170`, `:187-188`) | **OK-TO-MERGE-AS-IS** | All pre-existing, all on lines this branch did not touch (the touched AVG was cleaned at `c6e8b6eb4`). Minors ticket. |
| 11 | Pre-existing PG failures: `ShiftCloseRequiresZReportTest`, `ZReportGrandTotalsPopulated`, `SalesReportServicePaymentBreakdown`, `StrictCanonicalParserTest` (`:107-108`, `:158-159`, `:132-133`) | **OK-TO-MERGE-AS-IS** | Each verified pre-existing on an unmodified baseline (ledger records the stash-verification for `StrictCanonicalParserTest`). Carry-over ticket. |
| 12 | Refund disposition UI follow-up — restock-default ruling (`0aa810761`) | **OK-TO-MERGE-AS-IS** | Device sends `restock` for every line; projector honours all three dispositions (`PosCoreReceiptProjection:1839-1855`), so the UI can be added later without a payload change. |
| 13 | M2/M3 defaults anchored to 2026 TN SMIG; "REVISIT WITH PILOT DATA" (`:218-221`) | **OK-TO-MERGE-AS-IS** | They are tenant settings, editable without a release. Residual by construction. |
| 14 | M2/M3 gate: 5 minors deferred (`:237-238`) | **OK-TO-MERGE-AS-IS** | 0 Critical / 3 Important all closed at `f6a418720` (I-1 TOCTOU, I-2 `reset()` scope, I-3 empty-company fail-closed). |
| 15 | 8 fiscal minors deferred from the wave-2 gate + NEW-M5/M6 + finding-9 UI surface (`:88`, `:147-148`, `:204`) | **OK-TO-MERGE-AS-IS** | Three of the eight were taken in round 2 item G; the rest are cosmetic/observability. Finding-9's UI absence is M-3 above. |
| 16 | "React Doctor staged regressions" open item (`:48-49`) | **CLOSED** | Resolved at `f6a418720` as a pre-existing whole-repo 1464-warning baseline; no `.tsx` touched by that commit. |
| 17 | W3 analysis-doc citation refresh; test-plan M2/M3 + discount corrections (`:134-135`, `:232-236`, `:252-253`) | **OK-TO-MERGE-AS-IS (doc debt)** | Does not gate merge; gates the E-7 packet and the money campaign. |
| 18 | 🎫 5 `SUM(pos_receipts.total)` aggregates = HARD pre-enable gate | **CLOSED — verified independently** | Full sweep of `app/` found no residual bare sum over `pos_receipts` money columns (§3, last row). |

---

## 7. WHAT I COULD NOT VERIFY
- No test suites were executed (read-only review). All green/red claims here are code-structural,
  not run-based; the implementer/gate records carry the execution evidence.
- Behaviour on a real staging tenant DB (migration ordering interactions with tenant DBs that are
  mid-batch) is reasoned from the guards in the files, not observed.
- Whether `docs/qa/2026-08-01-money-test-plan.md` corrections have landed — out of this range.

---
_Reviewer: fiscal-pos-reviewer (opus). This is a gate, not a merge. A human merges._

---

# ADDENDUM — SCOPED RE-VERIFY of the conditions fix

Range `f6a418720..4a70f4015` (2 commits, 8 files, +956/-1). Read-only; no tests executed.
Implementer report: `docs/sessions/LANE-C-final-conditions-report.md`.
Scope: strictly this diff — C-1, C-2, M-1, M-4, the two judgement calls I was asked for, and new
breakage introduced here.

## REVISED VERDICT: MERGE-READY-WITH-CONDITIONS — ONE NEW CONDITION (C-5)

C-1, C-2, M-1, M-4 are all ADDRESSED and well done. But the C-2 lever makes reachable a
pre-existing latent defect that was previously unreachable by construction, and it is a
double-payout path. That is finding N-1 below and it must be closed before the rollback lever
ships, because the lever is worthless if using it can double-refund a customer.

## Per-item verdicts

### C-1 — trigger immutability widening: **ADDRESSED**
`2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:132-137` — edited IN PLACE
(correct; the migration has never run on staging, so no amendment migration is owed). All six
clauses I named are present and in the right form: `customer_name`, `customer_identifier`,
`partner_id`, `contact_id` via `IS NOT DISTINCT FROM` (NULL-safe, matching the branch above),
`fiscal_status` via `=` (never NULL on a fiscalized row), `is_voided` via `IS NOT DISTINCT FROM`.
The new branch now guards a strict superset of the FK-cleanup branch at `:78-96`.
`down()` is untouched and still restores `2026_05_26_100001`'s definition verbatim — verified by
diffing the hunk list: only `up()` and the docblock changed.

Non-vacuity is run-proven, which is the part that matters: `PosReceiptImmutabilityTriggerSealedHashAlgorithmTest`
gains six negative tests (`test_transition_combined_with_a_{customer_name,customer_identifier,partner_id,contact_id,fiscal_status,is_voided}_change_is_rejected`),
each asserting `QueryException` matching `/fiscally sealed and cannot be modified/`, plus a positive
control (`test_the_permitted_transition_still_passes_on_a_pre_feature_row`) proving the legitimate
NULL→`canonical_json_v3` transition still succeeds. Per the implementer report all six previously
passed through the unfixed trigger. The `is_voided` test correctly holds `fiscal_status` at
`'fiscalized'` so the void branch cannot match and absorb it. Registered in the PG-only CI filter.

### C-2 — rollback lever: **ADDRESSED** (with N-1 attached)
`DisableV4RefundAuthoringCommand.php` — better than the minimum I asked for. Verified:
- both columns cleared in ONE `update()` inside `DB::transaction` (`:157-162`), so the half-state is
  not reachable through the supported lever;
- target set is `enabled = true OR acknowledged_at IS NOT NULL` (`:99-104`) — this **repairs** a
  terminal an operator already bricked by hand, which is the S6 state itself. Good call;
- `--dry-run` writes nothing (`:143-147`); declining the prompt writes nothing (`:149-153`);
  `--force` for non-interactive deploys; `Log::warning` audit line with the exact terminal ids (`:164`);
- `--terminal` that matches nothing FAILS rather than reporting a misleading success (`:118-129`);
- company scoping is explicit (`:100`), so a foreign terminal cannot be touched;
- `protected $aliases` is a genuine Laravel property (`Illuminate/Console/Command.php:85,114-115`), so
  `fiscal:disable-v4-refund-authoring` really resolves;
- registered in `FiscalServiceProvider:69`, test registered in the PG-only CI filter.

Test coverage is proportionate (10 tests), and `test_acknowledgement_is_refused_after_a_disable` closes
a hole I had not asked about: a late in-flight device ACK cannot silently re-retire the legacy path,
because `V4RefundAuthoringAcknowledgementService:33` requires `enabled = true` and the command cleared it.

S6 re-check: **resolved.** With both columns cleared, `LegacyCorrectionGuard:36` is inert and
`test_disable_reopens_the_legacy_correction_path_end_to_end:161-172` proves a legacy return is
genuinely re-authored through the real `ReceiptReturnService` (fiscalized, hashed, chained) — not
merely waved past the guard. The lifecycle is now reversible in both directions: re-enabling later
re-stamps `acknowledged_at` on the next device ACK.

### M-1 — `950000` self-guard: **ADDRESSED**
`2026_07_31_950000_create_fiscal_refund_compensations_table.php:25-33` — `if (Schema::hasTable(...)) return;`
before `Schema::create`, matching the other six migrations in the range. A partially-applied batch
re-run now no-ops. Note the guard also correctly skips the `CREATE UNIQUE INDEX` that follows, so a
re-run cannot throw "index already exists" either.

### M-4 — v66 migration test: **ADDRESSED**
`apps/pos/src/lib/db/__tests__/migrations.v66.test.ts` — 6 real-SQLite tests, and better than a bare
column-presence check: it asserts the column is absent before v66 and nullable TEXT after, that a
pre-v66 row reads back a null marker, that re-running v66 is idempotent AND preserves a recorded
failure, that success stamps the acknowledgement **exactly once** (`second.acknowledgedAt ===
first.acknowledgedAt`, pinning the `COALESCE` at `terminalStateRepository.ts:264`), that a later
failure leaves an existing stamp alone, and that a pre-v66 schema reads fail-closed. Symmetric with
the v65/v67 tests.

## The two judgement calls I was asked for

### Transient-window answer: **ACCEPTED**
Between the server-side disable and the device's next terminal-state pull, the device still routes v4
while the server's legacy path is open — both paths accept work. I judge this benign, and it is the
same class as the already-accepted S3' window:
- a v4 refund authored in that window is fully valid — fiscal-event ingress never consults the
  capability flag, so it signs, syncs and projects normally; nothing is stranded or dead-lettered;
- the device physically cannot use the legacy path while its local flag is 1 (`HomePage.tsx:1123` is a
  single boolean), and the enable-time preflight guarantees exactly one active Physical terminal, so
  no second client exists to race it;
- the window is bounded by the next pull, and the command prints both the forcing action (sync or
  restart — the pull is a startup step) and a concrete verification query
  (`SELECT v4_refund_authoring_enabled FROM terminal_state …` must read 0). Printing a *verification*
  step, not just a *do this* step, is the right shape.

### Diagnostics-only claim: **VERIFIED TRUE**
Grepped `apps/pos/src` for `getV4RefundAuthoringAckState`: the only occurrences are its own definition
(`terminalStateRepository.ts:283`), the new v66 test, and a `vi.fn()` module mock in
`syncService.test.ts:201`. **No production caller.** Likewise the device column
`terminal_state.v4_refund_authoring_acknowledged_at` is written only by
`setV4RefundAuthoringAckFailure` (`:255-266`) and read only by that same diagnostics function. A stale
value there changes no behaviour, so leaving it as the rollout audit trail is correct.

## NEW BREAKAGE INTRODUCED BY THIS DIFF

### [CRITICAL] N-1 — after a rollback, the legacy return cap CREDITS EXTRA HEADROOM for an already-v4-refunded line (double payout)
`apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1168`

```php
$absQuantity = bcmul((string) $returnLine->quantity, '-1', 4);
```

This assumes every return line stores a NEGATIVE quantity — true for the legacy era only. A **v4**
refund line is stored as a POSITIVE magnitude: `PosCoreReceiptProjection::writeLines:1170` writes
`$line->quantity` verbatim from the canonical view, and `:1511-1515` states the invariant explicitly
("The canonical `line_items[].quantity` is a POSITIVE MAGNITUDE for every invoice type — the fiscal
payload validator's quantity regex forbids a leading `-`"). It is the same sign-era split wave 4
normalised with `-ABS(...)` in six other consumers; this one was missed because it is a PHP loop, not
a SQL aggregate.

Chain, all verified at HEAD:
1. the projector sets `original_receipt_id` on a v4 refund (`PosCoreReceiptProjection:379`) and
   `original_line_id` on each line (`writeLines:1121-1130`);
2. `Receipt::returnReceipts()` is `hasMany(Receipt::class, 'original_receipt_id')` (`Receipt.php:369-372`),
   so a v4 refund **is** enumerated by `calculateAlreadyReturnedQuantities:1158`;
3. `bcmul(POSITIVE, '-1')` yields a NEGATIVE `$absQuantity`, which is `bcadd`-ed into `$returned[$key]`
   at `:1171`, making the already-returned tally negative;
4. `:1090` computes `remainingReturnable = bcsub(original.quantity, alreadyReturned, 4)` — subtracting
   a negative, so the allowance grows;
5. `:1092`'s cap therefore passes.

Failure scenario: enable v4 → cashier v4-refunds 1 of 1 unit → operator runs
`pos:disable-v4-refund-authoring` → device reverts to legacy → cashier refunds the SAME line again via
legacy `/return` → cap computes `remaining = 1.0000 − (−1.0000) = 2.0000` and ALLOWS it. Customer paid
twice, stock restocked twice. Not merely "the v4 refund isn't counted" — the cap actively hands out
double the original quantity.

**Why this is new breakage in this diff, not a pre-existing defect I should have caught earlier:**
before `db1d42fae` a terminal that had acknowledged v4 could not return to the legacy path at all — my
S6 finding was that it was *bricked* (409 on every legacy return). The era mix was unreachable by
construction. The disable command deliberately makes it reachable, and it is the first thing an
operator does in an incident. The fix for C-2 is correct; it just needs this guarded before it ships.
`ReceiptVoidService` should be checked for the same assumption.

Note the implementer's own lifecycle test does not contradict this: `:161` builds a FRESH sale with no
prior v4 refund, so the era mix is never exercised.

**Fix (recommended, one line, matches this branch's own established pattern):** in
`calculateAlreadyReturnedQuantities`, take the MAGNITUDE regardless of stored sign rather than flipping
it — the exact `-ABS`/`magnitude()` normalisation wave 4 applied at `PosAnalyticsService:439/458`,
`ReportGenerationService:499-503`, `GrandtotalService:46-51`. That makes the legacy cap correct in both
eras permanently, rollback or not. Add a regression test: original → v4 refund of the full quantity →
disable → legacy return of the same line must be REFUSED.
A weaker stop-gap (refuse `pos:disable-v4-refund-authoring` when the terminal has any projected v4
refund) is not recommended: it blocks the incident lever exactly when it is needed.

## Merge-condition status after this round
| # | Condition | Status |
|---|---|---|
| C-1 | trigger immutability widening | **CLOSED** @ `db1d42fae` |
| C-2 | rollback lever | **CLOSED** @ `db1d42fae` (see C-5) |
| C-3 | deploy enumeration lifted into the ledger | reported CLOSED by orchestrator — not re-verified here (outside this range) |
| C-4 | FR/TN 709 divergence recorded as campaign instruction | reported CLOSED by orchestrator — not re-verified here (outside this range) |
| M-1 | `950000` self-guard | **CLOSED** @ `db1d42fae` |
| M-4 | v66 migration test | **CLOSED** @ `4a70f4015` |
| M-2, M-3 | runbook items (poison-event M2 arming; API-only dead-letter surface) | still owed by the E-9 runbook, not merge-blocking |
| **C-5** | **N-1 — legacy return cap must take the magnitude, not flip the sign** | **NEW, OPEN, merge-blocking** |

No other regression found in this diff. Nothing else in `apps/pos` production code changed; no new
queue, no new permission, no schema change beyond the two migration edits above.

---

# ADDENDUM 2 — FINAL SCOPED RE-VERIFY (C-5 + C-6)

Range `4a70f4015..4fe070e78` (2 commits, 5 files, +505/-8). Read-only; no tests executed.

## FINAL VERDICT: **MERGE-READY**

All merge conditions are closed. 0 open blocking findings. Remaining items are runbook entries and
post-launch follow-ups, enumerated at the end.

## C-5 (N-1, double payout) — **ADDRESSED**

`ReceiptReturnService.php:1176-1181` introduces `quantityMagnitude()` — `bccomp/bcsub/bcadd` at scale 4,
no float, no `bcmul(-1)`. Applied at `:1200`. The implementer's sweep found a **second instance I had
missed**: `ReceiptController::calculateReturnedQuantities:632`, the cashier-facing receipt view that
advertises how much of a line is still returnable — the same sign-flip, same negating effect. That one
is real and I should have caught it; good catch.

Legacy-era behaviour is provably unchanged: for a negative stored quantity `quantityMagnitude` returns
`bcsub('0',$v,4)`, byte-identical to the old `bcmul($v,'-1',4)`; for zero both yield `'0.0000'`. So the
v2 path cannot regress, and `test_v2_terminal_legacy_return_flow_is_unaffected:1133` still guards it.

Regression coverage is exactly what I asked for and then some, red-proven by revert per the report:
- `test_legacy_return_after_a_rollback_cannot_double_refund_a_fully_v4_refunded_line:1226` — asserts the
  v4 refund line projects `'1.0000'` POSITIVE (pinning the premise of the whole finding), then expects
  `/Maximum returnable: 0\.0000/`. Pre-fix this read 2.0000;
- `test_legacy_return_after_a_rollback_is_capped_at_the_v4_remainder:1268` — sale 2, v4 refund 1 ⇒
  `Maximum returnable: 1.0000`, and the legitimate remainder return still succeeds and reaches
  `FiscalStatus::Fiscalized`. This is the important half: the fix bounds without over-blocking;
- `test_receipt_show_reports_a_v4_refunded_quantity_as_a_positive_magnitude:1319` — covers the
  controller instance.

`ReceiptVoidService` audited CLEAN with evidence — consistent with my own read (it does not compute an
already-returned tally; the void path has no per-line remaining-quantity cap).

Minor, non-blocking: `quantityMagnitude()` is duplicated verbatim in a Service and a Controller. The
duplication mirrors a loop that was already duplicated, so this is not new drift, but the controller
arguably should not be recomputing return tallies at all — a shared read model is the eventual fix.
Follow-up ticket, not merge-blocking.

## C-6 handling — **ADDRESSED**, and the fail-closed ruling holds

The disclosure is correct and well-placed: `V3_FROM_BIRTH_WARNING` (`:96`) is printed BEFORE the prompt
(`:175`) **and** after the write (`:206`), so the `--force` path — which never sees a prompt — still
gets it; the consent is inside the confirmation question itself (`:98`), not buried in an epilogue, and
both confirmation tests were updated to the new wording. The success line was also corrected from "the
legacy /return + /void path is open again" to "the LegacyCorrectionGuard is inert again server-side",
which no longer overclaims. The post-write counterpoint (`:207`) correctly states that a terminal WITH
legacy history does get a working legacy return back.

**Correction to my own Addendum 1.** I wrote that
`test_disable_reopens_the_legacy_correction_path_end_to_end` "proves a legacy return is genuinely
re-authored post-disable". That is true *for that fixture* — whose terminal has a coherent
`current_sequence` — but it does **not** generalise to the launch configuration. C-6 is precisely the
gap between those two shapes, and `v4EnabledTerminal():1461-1487` documents it honestly in-line
(`'current_sequence' => 3` is deliberately seeded past the authored sequence numbers "so the legacy
authoring leg can run at all"). My C-2 "S6 resolved" verdict should be read as: the *brick* is resolved
(the guard is inert, the cap is era-correct, the state is reversible), not that working legacy refunds
return on a v3-from-birth terminal.

### Is the fail-closed claim sound as tested?
**Yes.** `test_legacy_return_on_a_v3_from_birth_terminal_after_a_rollback_fails_closed:1373` builds the
real launch shape (`current_sequence => 0`), drives the REAL ingestion pipeline for the v3 sale + v4
refund, runs the real disable command, then hits the real HTTP endpoint.

The delta methodology is **sound, and the right choice**:
- baselines are captured AFTER the approval scaffolding (`:1418`), so the legitimate
  `OPERATOR_APPROVAL_GRANTED` / override events it writes cannot mask a leak;
- the return-receipt assertion is a before/after **delta** scoped to `original_receipt_id` +
  `receipt_type='return'`, with an in-line comment explaining why an absolute `== 0` would be wrong —
  the legitimate v4 refund is itself such a row. An absolute assertion here would have been a false
  green; this is the subtle thing the test gets right;
- asserting `!$response->isSuccessful()` rather than a specific status is correct, since the failure is
  a DB constraint surfacing through a generic handler.

### Side-effect tables: any missed?
I checked the ones the coordinator named plus the rest of the write set.

- **Stock movements — not asserted, but rollback is structurally guaranteed.** `processReturn` opens
  ONE `DB::transaction` at `ReceiptReturnService.php:189`; `restoreStock()` runs inside it at `:441`
  (writing `StockMovement` at `:1277` and mutating `stock_levels`), and
  `finalizationService->finalize()` — where the `chain_sequence` allocation fails — runs later at
  `:478`, still inside. A throw at `:478` rolls back `:441`. Same for `pos_receipt_lines`,
  `pos_receipt_payments`, `pos_receipt_vat_details`, `journal_entries` and any Treasury payment leg:
  all inside that one transaction, and nothing fires on `afterCommit` because there is no commit.
  So the untested tables are protected by the DB, not by an assertion. **Recommended (1 line, not
  blocking):** add a `stock_movements` delta to the test — it is the side-effect a future reader will
  most want pinned, and phantom restock is the failure that would hurt most quietly.
- **"Intent rows" — nothing missed; there is no such server table.** `refund_intents` exists only in
  device SQLite (device migration v65); `grep -rn refund_intents apps/api/database/migrations/` returns
  nothing. The only new server table is `fiscal_refund_compensations`, which the legacy return path
  never touches.

### Is accepting C-6 the right call?
Yes. The alternative postures are worse: leaving the S6 brick with no lever and no disclosure, or
blocking launch on a fiscal-chain-numbering change that genuinely deserves its own gated lane. The
lever's honest semantic on the launch configuration is "halt refunds until re-enable" — which is the
pre-Lane-C interim no-refunds prohibition, a posture this program has already accepted — and it now
fails closed with no cash movement and no fiscal event, is consented to explicitly, and is pinned by a
test that will flip green the day numbering is fixed. That last property is what makes this an accepted
limitation rather than accumulating debt.

Two things this obliges, both non-blocking:
1. **E-9 runbook must say it plainly:** on a v3-from-birth terminal, `pos:disable-v4-refund-authoring`
   HALTS refunds; the recovery is `fiscal:enable-v4-refund-authoring`, not "fall back to legacy". An
   operator must not be discovering this at 2am from console output alone.
2. **Follow-up:** the command already has the exact query the Enable command uses to detect
   v3-from-birth (count of `fiscal_event_id IS NULL AND fiscal_status = 'fiscalized'` receipts). Running
   it per terminal would turn today's blanket warning into an accurate per-terminal statement. Today it
   over-warns, which is the safe direction and correct for the launch config.

## New breakage in this range
**None.** The two `quantityMagnitude` helpers are behaviour-preserving in the legacy era (shown above);
the command changes are output/consent text plus two updated confirmation-string assertions; no schema
change, no queue, no permission, no `apps/pos` production code, no signed-byte surface touched.

## FINAL CONDITION LEDGER
| # | Condition | Status |
|---|---|---|
| C-1 | trigger immutability widening | CLOSED @ `db1d42fae` |
| C-2 | rollback lever (S6 brick) | CLOSED @ `db1d42fae`, scope clarified by C-6 |
| C-3 | deploy enumeration in the ledger | CLOSED by orchestrator (outside my ranges; not re-verified) |
| C-4 | FR/TN 709 divergence as campaign instruction | CLOSED by orchestrator (outside my ranges; not re-verified) |
| C-5 | legacy return cap magnitude (double payout) | **CLOSED @ `4bc8a7414`**, incl. a second site I missed |
| C-6 | v3-from-birth disable halts refunds | **CLOSED @ `4fe070e78`** as ACCEPTED FAIL-CLOSED, disclosed + consented + pinned |
| M-1, M-4 | migration self-guard, v66 test | CLOSED |
| M-2, M-3 | poison-event M2 arming; API-only dead-letter surface | runbook, non-blocking |
| new | `stock_movements` delta in the C-6 test; per-terminal v3-from-birth detection; `quantityMagnitude` duplication | follow-ups, non-blocking |

**BRANCH VERDICT: MERGE-READY.** Fiscal chain, money correctness, projection idempotency, exactly-once
stock, precision contract, SQLite time contract, queue coverage and rule-20 test discipline all verified
across the four review rounds. Merge to local `dev`, then promote as a clean fast-forward.
