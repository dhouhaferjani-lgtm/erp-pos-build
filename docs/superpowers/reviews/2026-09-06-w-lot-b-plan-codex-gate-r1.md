# Codex plan gate r1 — W-LOT-B rev 4 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: W-LOT-B at local dev b3623823d. Verbatim.

---
Reviewed read-only at local `dev` HEAD `b3623823d19de289d45678e75a1f88e6884500e4`. No files were edited, no tests were run, and no Git state was modified. Source code is unchanged from the plan’s `a4044924…` baseline; intervening commits are documentation-only. The declared HEAD is nevertheless stale.

References: `P` = [W-LOT-B plan](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:1), `A` = [W-LOT-A plan](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1), `R4` = [previous gate](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-plan-codex-gate-r4.md:1), `S4` = [spec v4](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:1), `OR` = [owner rulings](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:1).

## Previous-gate closure table

### Carried items

| Previous item | Status now | Evidence |
|---|---|---|
| R1-1 Mechanical dispatch gate | **OPEN** | Missing DTOs/command registration, incomplete per-task red packets and non-exact reviewer invocation; `P:559-569,603-677,1145-1271`. |
| R1-2 Recall escalation and roles | **REJECTED from B scope, prerequisite blocked** | Correctly assigned to A at `P:99-108`, but A encodes OPEN Q10; `A:156-162,265-322`. |
| R1-3 POS core versus lot arm | **OPEN** | Parent-plus-N is present, but evidence/projection races and late-evidence-after-refund are unresolved; `P:534-537,1126-1130,1233-1242`. |
| R1-4 Lock census | **OPEN** | Ingress claims no inventory lock while also reopening applied obligations; `P:534,537,1130`. |
| R1-5 Deployment order | **OPEN** | See NEW-B8. |
| R1-6 Freeze before replacement | **REJECTED from B scope** | L2/L9 belongs to A under `S4:457`; excluded at `P:101-104`. |
| R1-7 Float retirement | **OPEN** | B touches the live POS cart/availability path but leaves quantity as JS `number`; `P:14,904-1012`; [cart.ts:36](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/cart.ts:36). |
| R1-8 L9 correction | **REJECTED from B scope** | `P:101-104`; `S4:457`. |
| R1-9 Flat-row count model | **REJECTED from B scope** | `P:104`; `S4:457`. |
| R1-10 Provenance and health | **OPEN for B** | Labels exist, but correction, refund cascade and operator visibility are incomplete; `P:1233-1245`. |
| R1-11 Refresh before POS open | **CLOSED narrowly** | Both open branches and refusal semantics are explicit at `P:850-902`. |
| R1-12 Durable outbox | **CLOSED narrowly** | Same SQLite transaction and independent outbox are explicit at `P:1008-1026,1039-1079`; DTO defects remain separately blocking. |
| R1-13 L8/L9 completion | **OPEN** | L8 cannot complete with the blockers below; B correctly makes no L9 claim at `P:19`. |
| R1-14 Citation hygiene | **OPEN — MINOR** | `P:6` declares `a4044924…`; reviewed HEAD is `b3623823…`. |
| R1-15 Task size | **CLOSED** | Ten bounded tasks at `P:571-1319`. |
| R2-B1 Latest owner register | **CLOSED inside B; A violates it** | B reproduces Q10–Q13 and encodes no branch at `P:69-85`; A does at `A:156-162`. |
| R2-B2 Aggregate movement link | **CLOSED narrowly** | Non-null aggregate link and child movement verification at `P:383-456`. |
| R2-B3 Canonical line key | **CLOSED narrowly** | `INTEGER`, six-digit index, maximum 999999 and 64-byte storage at `P:145-201`. |
| R2-B4 Writer/lock census | **OPEN** | Evidence arrival versus projection/recovery ordering is incomplete; `P:529-555,1126-1130`. |
| R2-B5 Deployment preflight | **OPEN** | Preflight lands in Push 5 but is invoked by Push 3; `P:1274-1319,1676-1685,1779-1788`. |
| R2-B6 Dispatch packets | **OPEN** | Convention-09 subcases lack exact cases/assertions/commands, reviewer filenames are implicit, and several production types are omitted. |
| R2-M1 L9 seed scope | **REJECTED from B scope** | `P:101-104`; `S4:457`. |
| R2-M2 Reservation consumers | **REJECTED as A-owned interface** | B consumes A’s canonical predicate at `P:118-124,828-833`; device overlay is scoped at `P:945-952`. |
| R2-M3 Provenance producer seams | **REJECTED generally; OPEN for captured POS** | General producer census is A-owned, but B’s captured correction/refund lifecycle is incomplete. |
| R2-M4 Renderer census | **OPEN** | Active `BarcodeChooserModal` is omitted from T5; compare `S4:372` with `P:906-923`. |
| R2-M5 Health schedule | **REJECTED from B scope** | General 03:20 census belongs to A; B owns only obligation retry/preflight. |
| R2-M6 Vocabulary ambiguity | **OPEN** | B creates a second name/table for the glossary’s existing Projection lot obligation; `P:128-143`; [glossary:58](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:58). |
| R2-m1 Reproducible baseline | **OPEN — MINOR** | Stale HEAD at `P:6`; source citations remain materially stable because the intervening diff is documentation-only. |

### Prior blocker, major and minor findings

| Previous finding | Status now | Evidence |
|---|---|---|
| Multi-lot cardinality | **CLOSED narrowly** | Parent obligation plus N immutable effects at `P:419-456`. |
| Canonical index `SMALLINT` | **CLOSED** | Line/allocation indices are `INTEGER`; `P:289-299,365-376,423-432`. |
| Recall authority/bypass | **REJECTED from B scope, A blocked** | `P:99-108`; Q10 violation in `A:156-162,265-322`. |
| Recall retry after transition | **REJECTED from B scope** | A-owned. |
| Receipt evidence atomicity | **CLOSED narrowly** | `P:1008-1035`. |
| Company entitlement boundary | **REJECTED as B-owned work** | B correctly requires A’s fence at `P:111-126`; dispatch remains contingent on that implementation. |
| Five-push manifest | **OPEN** | See NEW-B8. |
| Dispatch and CI | **OPEN** | Early pushes reference future files; task/reviewer packets remain incomplete. |
| GL ownership/order inversion | **OPEN for B integration** | Buffer ordering is stated, but evidence/projection concurrency is not closed; `P:512-555`. |
| Float retirement surfaces | **OPEN** | Current cart and cart-ingress quantities remain `number`; see NEW-B4. |
| Count as-of marker | **REJECTED from B scope** | A-owned; B correctly forbids UUID ordering at `P:835`. |
| Used-lot freeze | **REJECTED from B scope** | `P:101-103`. |
| L1 authorization census | **REJECTED from B scope** | Recall permissions belong to A. |
| Active cart/pre-open owners | **CLOSED** | `P:850-902,904-968`. |
| Generated enums/types | **OPEN** | Missing `WLotBRolloutFlagsData` and `PosLotRecoveryResultData`; see NEW-B3. |
| Stale verified HEAD | **OPEN — MINOR** | `P:6`. |
| NEW-B1 Q10 encoded | **REJECTED inside B, REOPENED at split boundary** | B is neutral; its mandatory A prerequisite is not. See NEW-B1. |
| NEW-B2 Signed reconciliation | **CLOSED narrowly** | Uses `quantity_after - quantity_before`; `P:448-456,509`. |
| NEW-B3 Entitlement fence | **REJECTED as B-owned work** | `P:111-126`; A implementation must precede B. |
| NEW-B4 Branch hold eligibility | **REJECTED as B-owned work, A blocked** | `P:118-126`; A cannot dispatch its current Q10 schema. |
| NEW-B5 Count watermark | **REJECTED from B scope** | `P:31,104`. |
| NEW-B6 Either-arrival-order evidence | **CLOSED narrowly** | No event FK, durable 202 and both directions at `P:333-355,460-474,1120-1129`. Concurrency is a new blocker. |
| NEW-B7 Staging manifest | **OPEN** | See NEW-B8. |
| NEW-B8 Convention 10 | **OPEN** | Table format is not the required verbatim skeleton; see NEW-B9. |
| Batch deactivation disposition guard | **REJECTED from B scope** | A-owned server lot lifecycle. |
| GM role exceeds ruling | **REJECTED from B scope** | A-owned role delta. |
| Convention-09 per task | **OPEN** | Tasks provide prose bullets, not exact test case/assertion/command packets. |
| Convention-11/glossary contract | **OPEN** | Existing canonical nouns conflict with the proposed names/table; see NEW-M1. |
| Entitlement state vocabulary | **CLOSED** | Exact `entitled`, `not_entitled`, `entitlement_unresolved` vocabulary at `P:218,630,829`. |
| Schema checks delegated to prose | **OPEN** | Transition legality, immutability and timestamp relations remain unenforced; see NEW-B7/NEW-M5. |
| Task packets mechanically undispatchable | **OPEN** | See NEW-B3, NEW-B8 and NEW-M4. |
| Wrong oversell citation | **REJECTED from B scope** | B does not implement or decide oversell; A cites `S4:376`. |
| Stale HEAD label | **OPEN — MINOR** | `P:6`. |

## NEW BLOCKERS

### NEW-B1 — The A/B split still encodes OPEN Q10

- Plan: B makes A’s requested-local-hold predicate mandatory before T3–T10 at `P:113-126`.
- Source: A explicitly chooses `requested → recalled`, excludes release/reject, creates a two-state schema and transition constraint at `A:156-162,265-322,645-654`. Q10 remains OPEN at [owner rulings:136-143](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:136).
- Failure scenario: dispatching the prerequisite permanently holds a branch lot unless the GM recalls it. That is precisely one Q10 branch, even though B itself adds no recall column.
- Minimum correction: do not dispatch the A recall schema/task/push until Q10 is ruled. B may retain a neutral eligibility interface, but T3–T10 cannot claim an executable prerequisite until A is revised after that ruling.

### NEW-B2 — No authoritative snapshot revision or historical validation source exists

- Plan: T3 promises an opaque monotonic revision/watermark at `P:828-836`, while T8 validates the submitted cache revision at `P:1122-1126`.
- Source: current FEFO reads live batch state directly and has no snapshot authority at [FEFOInventoryService.php:81-114](/Users/houssamr/Projects/syneriva/apps/erp/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:81); spec v4 requires cache revision/coverage and sidecar validation at `S4:366-380`.
- Failure scenario: after expiry, recall, reservation or stock mutation, the server cannot prove what snapshot the device saw, whether its revision was genuine, or whether the captured batch was eligible at sale time. A counter generated from only stock movements also misses hold/recall/expiry changes.
- Minimum correction: define one durable server snapshot issuance contract—stored immutable snapshot manifest or authenticated signed snapshot token—with a monotonic scope revision updated by every eligibility mutation. T3 must own its schema/writer; T8 must validate against that exact historical artifact.

### NEW-B3 — Required transport and recovery types are absent from task ownership

- Plan: SQLite requires `snapshot_id` at `P:270-283`, but `LotEvidenceSubmissionData` omits it at `P:655-675`. `PosLotEligibilitySnapshotData` references undefined `WLotBRolloutFlagsData` at `P:621-638`; T9 returns undefined `PosLotRecoveryResultData` at `P:1184-1196`.
- Source: strict generated-type authority is [CLAUDE.md:21-34](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:21). None of those missing DTO files is assigned in `P:573-601,1147-1157`.
- Failure scenario: T2 cannot insert its required SQLite header from the declared DTO, and T1/T9 cannot compile or generate a complete TypeScript contract.
- Minimum correction: reconcile `snapshot_id` across device schema, DTO and fingerprint; name exact files and full signatures for both missing DTOs; add their generation assertions and PHP/TS contract vectors.

A second executable omission exists in T9: `RetryPosLotObligationsCommand` is placed under Fiscal infrastructure, but T9 does not modify `FiscalServiceProvider`; module commands are explicitly registered there at [FiscalServiceProvider.php:47-76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:47). Add the provider file and a command-registration red assertion.

### NEW-B4 — The POS precision contract remains violated

- Plan: T5/T6 touch cart, availability and capture but assert that B “introduces no float surface” at `P:14`; capture accepts a string line quantity at `P:986-998`.
- Source: live `CartItem.quantity` is `number` at [cart.ts:36](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/cart.ts:36); pending/cart quantities are already JSON numbers converted after the fact at [availability.ts:32-34](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/stock/availability.ts:32), [availability.ts:157-167](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/stock/availability.ts:157), and quantity edits accept `number` at [cartIngress.ts:142-160](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/stock/cartIngress.ts:142). The standing rule forbids any float touching quantity at [CLAUDE.md:71-77](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:71).
- Failure scenario: a fractional cart quantity can be rounded before capture validation. Evidence then faithfully hashes the already-corrupted value and can disagree with the sealed line or aggregate delta.
- Minimum correction: migrate `CartItem.quantity`, cart-store actions, quantity inputs, `cartIngress`, offline receipt JSON and every caller touched by T5/T6 to decimal strings. Add exact fractional vectors through cart → sealed line → evidence → server projection.

The same cross-language defect affects `batch_id`, `entitlement_revision` and `snapshot_revision`: PostgreSQL uses `BIGINT` at `P:339-340,370,426`, while generated/handwritten TS signatures use `number` at `P:611,631-632,649,928,987`. Use canonical decimal strings on the wire/device or enforce a database-safe `<= 9007199254740991` ceiling everywhere.

### NEW-B5 — D7’s required append-only correction path is missing

- Plan: one submission per `(company,fiscal_event)` is enforced at `P:348-349`; differing content is permanently rejected at `P:470-472,1128-1129`.
- Source: the ruled sidecar is deliberately correctable at [owner rulings:39-45](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:39), and spec v4 requires append-only corrections at `S4:380`.
- Failure scenario: a cashier captures the wrong physical lot, the evidence validates and stock is attributed. The only later submission is a 409, so the unsealed evidence is effectively immutable without a supported correction.
- Minimum correction: add a permissioned, append-only evidence correction/supersession record with prior/new fingerprint, reason, actor and operation UUID. Define how it increments obligation generation and appends compensating lot effects without touching sealed fiscal bytes.

### NEW-B6 — Late evidence is not serialized with projection, recovery or dependent refunds

- Plan: ingress is declared inventory-lock-free at `P:534`, but it may increment an already-applied obligation and set it pending at `P:1130`. The concurrency test covers only live projection versus recovery at `P:555`; refund precedence is `P:1238-1242`.
- Source: current POS receipt work is one root transaction at [PosCoreReceiptProjection.php:253](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:253), with nested savepoint containment at [PosCoreReceiptProjection.php:2121-2191](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2121).
- Failure scenarios:
  - Evidence validates while the live projector is selecting fallback, causing a missed generation bump, estimate application despite validated evidence, or competing effect sets.
  - A refund restores estimated lot A, then late evidence corrects the original sale to lot B. B corrects only the sale obligation; the already-applied refund remains on A, leaving wrong per-lot stock despite every individual signed-sum invariant passing.
- Minimum correction: route validation consequences through the sole obligation service under a defined identity/product/obligation lock order. Add both race directions against live projection and recovery. Track dependent refund obligations and append compensating refund effects—or block resolution—when original-sale provenance changes after a refund.

### NEW-B7 — The database does not enforce the claimed state machines

- Plan: legal edges are declared at `P:458-487`, but S3/S4 provide only state-shape checks at `P:350-355,410-417`.
- Source: the existing fiscal `ProjectionStatus` treats applied/dead-lettered as terminal at [ApplyFiscalEventProjectionJob.php:37-52](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:37), while B reuses that enum for an obligation where `applied → pending` is legal. Its exact-case unit test currently expects four values at [FiscalEnumsTest.php:24-29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Unit/Fiscal/FiscalEnumsTest.php:24).
- Failure scenario: direct or buggy service updates can perform `applied → dead_lettered`, skip `running`, jump generation `0 → 99`, mutate evidence lines, or reopen an obligation without new validated evidence.
- Minimum correction: introduce dedicated evidence and lot-obligation enums; specify transition/CAS triggers or immutable transition rows; constrain generation increments and evidence-link causality; add PostgreSQL tests for every allowed and forbidden edge. Do not broaden the existing fiscal projection enum.

### NEW-B8 — The five-push manifest is still non-executable

Independent fatal defects:

- Common pre-push always names tests that land only in later pushes (`P:1363-1371`), so Pushes 1–4 fail on nonexistent files.
- Push 3 invokes `inventory:w-lot-b-preflight` at `P:1678-1685`, but the command lands only in Push 5 at `P:1274-1283,1779-1788`.
- No command updates Dokploy’s four W-LOT flags, `AUTO_MIGRATE`, or candidate-specific build SHA. Push 1’s new fail-closed entrypoint can therefore reject the deployment before those variables exist. Current shared Compose contains no such variables at [docker-compose.staging.yml:17-57](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:17).
- The web build currently receives only the existing arguments at [docker-compose.staging.yml:263-270](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:263); declaring `ARG VITE_APP_BUILD_SHA` without setting it cannot produce the asserted candidate fingerprint.
- Backup, migrate and preflight commands run `php artisan` on the SSH host at `P:1558-1562,1603-1624,1678-1685,1798-1808`. `/var/www/html` is the API container working directory at [Dockerfile:89-90](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/Dockerfile:89); these must run via `docker compose exec -T api`.
- With `set -o pipefail`, the backup validation at `P:1567-1574` fails when bad rows exist and also fails when `jq -e` selects no bad rows.
- Push 2 uses `CANDIDATE_SHA` for backup filenames before the Push-2 commit/common capture establishes it; `P:1555-1579`.
- The changed application is `apps/pos`, but the deterministic fingerprint and Playwright smoke cover `apps/web`. Push 4 says only “Install/build the candidate POS” at `P:1742-1744`, with no Tauri build/sign/install command, artifact ID, checksum or device fingerprint. Tauri builds are laptop-only at [WORKFLOW.md:32-44](/Users/houssamr/Projects/syneriva/apps/erp/docs/factory/WORKFLOW.md:32).
- The manifest requires and commits directly on `dev` at `P:1337,1385,1546`, contrary to the mandatory isolated-worktree/local-dev promotion rule at [CLAUDE.md:89-96](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:89).
- All five commit messages violate the repository’s required `Phase <major.minor.patch>: …` format at [AGENTS.md:15-16](/Users/houssamr/Projects/syneriva/apps/erp/AGENTS.md:15).

Minimum correction: rewrite every push as a self-contained command packet; move backup JSON, fail-closed entrypoint, fingerprint and preflight tooling before first use; select only tests present at that push; set and verify Dokploy runtime/build variables; execute Artisan inside `api`; replace the jq pipeline with one `all(...)` predicate; build/sign/install and fingerprint the POS artifact; capture every candidate/deployment/backup/POS artifact ID; follow the isolated-worktree and commit conventions.

### NEW-B9 — Convention 10 still fails round zero

- Plan: matrix at `P:48-67`.
- Source: convention 10 requires the verbatim columns and decision form at [Convention 10:37-56](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37), with round-zero enforcement at [Convention 10:73-79](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:73).
- Failure scenario: B uses `ID`, `Guarantee`, `Dolibarr/NV`, and `Decision with…`, not the required skeleton; user-visible `DIVERGE` rows name D7 but do not use the required owner-ruling qualification. The plan’s claim that the skeleton is exact is false.
- Minimum correction: copy the mandated skeleton verbatim, use `#`, `Guarantee the baseline gives the user`, `Dolibarr`, and `Decision`, and qualify user-visible divergence as `DIVERGE — owner ruling D7, OR:109`.

## NEW MAJOR findings

| Finding | Plan/source | Failure scenario | Minimum correction |
|---|---|---|---|
| Existing glossary concept is duplicated | `P:128-143`; [glossary:42](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:42), [glossary:58](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:58); [Convention 11:32-51](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:32) | `POS lot obligation`/`pos_receipt_lot_obligations` duplicates canonical `Projection lot obligation`/proposed `fiscal_projection_lot_obligations`; evidence storage also omits its device mirror from the vocabulary row. | Reconcile the existing glossary entries and spec before code; use one canonical noun/table/write path. Add the required `Concepts: …` line from Convention 11. |
| B duplicates rather than consumes W4 recovery | `P:1151-1212`; `S4:288-290,459` | A separate POS recovery service/command can diverge from the program’s shared fiscal-obligation recovery, policy snapshots and operator surface. | Make W4’s public recovery orchestration an explicit prerequisite/interface, or amend spec v4 and glossary with one justified owner. |
| Blocked status has no operator surface | `P:489,1230,1299`; current operator surface [DeadLetteredProjectionsController.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php:97) filters only fiscal dead letters | Blocked evidence/obligations exist only in preflight/DB; an operator cannot inspect reason, age, evidence or initiate recovery. | Name exact controller/resource/web files and add HTTP/UI red cases for blocked visibility and permitted recovery. |
| Cross-module signatures violate rule 6 | `P:1102-1112,1161-1182`; [CLAUDE.md:30-31](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:30) | Fiscal ingress accepts POS `Terminal` and Identity `User` models; POS obligation accepts Fiscal models and directly depends on BatchExpiry internals. | Pass shared contract DTOs/scalars or public service interfaces; do not import another module’s models. |
| Renderer census remains incomplete | `P:904-968`; `S4:372`; active mount [HomePage.tsx:1859-1867](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/pages/HomePage.tsx:1859) | Barcode scan collisions show candidates before cart insertion but no lot suggestion/module-gating coverage. | Include `BarcodeChooserModal.tsx`, its tests, and the legacy renderer disposition in T5. |
| Convention-09 tests are not mechanically dispatchable | `P:780,843-846,897-900,966,1035,1077,1138-1141,1267-1270,1317`; [Convention 09:79-89](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:79) | Bullets provide no exact test method, first assertion or exact command, so “same lane” cannot be verified before dispatch. | For every applicable task, name the second-company, second-location and rerun case, first assertion, exact command and lane. |
| Task/reviewer packets remain incomplete | `P:559-569,719-730,1247-1266` | Reviewer labels are not exact `.claude/agents/*-reviewer.md` paths/invocations; T1’s single red test does not exercise backup JSON, entrypoint flags, Compose inheritance or fingerprint; T9 says “exact file/filter” instead of giving commands. | Provide literal reviewer file paths/invocation and focused red packets for every production contract in each task. |
| Evidence schema is insufficiently immutable | `P:326-377` | Evidence lines can be updated/deleted directly; validation timestamps have no chronological relation; blocked reason vocabulary is unconstrained. | Add immutable line triggers, guarded header-state transitions, timestamp ordering checks and typed reason enums. |
| Actual returned-lot behavior is not specified | `P:1238-1244`; `S4:384` | A customer may return a different physical lot than the one estimated/captured on the original sale; blindly restoring original provenance mislabels sellable inventory. | Define refund-side capture/disposition validation, including expired/recalled returns and a returned-lot-differs test. |

## NEW MINOR findings

- `P:6` must distinguish the implementation-source base `a4044924…` from reviewed local HEAD `b3623823…`.
- T9’s first assertion at `P:1252-1257` literally performs PHP subtraction on decimal strings, despite `P:1259` saying to use `bcsub`. Replace the displayed assertion with the exact `bcsub(..., 4)` expression.

## Rejected false positives

- `tenants:migrate-rolling --force` is valid and returns nonzero after collected failures at [RollingTenantMigrationCommand.php:48-51](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48) and [RollingTenantMigrationCommand.php:158-174](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:158).
- `inventory_batch_movements.id` really is `BIGINT`, so `P:429` is type-compatible; [migration:14-25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php:14).
- SQLite v68/v69 are free: HEAD ends at v67 at [migrations.ts:2163-2183](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/migrations.ts:2163).
- Parent-plus-N effects solve cardinality, and signed reconciliation against `quantity_after - quantity_before` corrects R4’s impossible invariant.
- Omitting the fiscal-event FK and returning durable 202 correctly fixes the simple evidence-first arrival-order failure.
- D7 is preserved: evidence remains outside canonical bytes, hash chain and print output.
- `INTEGER` is correct for the six-digit canonical line index.
- API, worker, scheduler and websocket can inherit the same values through `x-api-env`; the defect is that the manifest never sets those values.
- `grep`, `sed`, `awk`, `openssl` and PostgreSQL client tools are available/appropriate replacements for container `rg`; [Dockerfile:38-67](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/Dockerfile:38).
- Both shift-open branches and the active cart renderer are genuinely named.
- Q11–Q13 are not encoded by W-LOT-B.
- The source citations remain materially stable between `a4044924…` and current HEAD because only documentation changed.

## Preserve

- D3 ordering: display → capture → consumption.
- D4 acknowledged refresh before both session-open branches.
- D7 separate authenticated evidence outside the fiscal hash and print payload.
- Canonical line-key grammar, shared PHP/TS vectors, `INTEGER` index and server recomputation.
- Receipt, fiscal event, evidence header/lines and evidence outbox in one SQLite transaction.
- Independent durable evidence delivery and evidence-first/event-first 200/202 convergence.
- One aggregate stock movement with N child batch effects.
- Signed reconciliation against `quantity_after - quantity_before`.
- Provenance values `operator_captured|system_fefo_estimate|unknown`.
- POS-core always active; only lot child work is entitlement/rollout gated.
- Module-off silence and retained historical evidence.
- Product-first lock order, short recovery lease and `InventoryGlPostingBuffer` ownership.
- No WAC/GL/aggregate replay for lot-only correction.
- Additive schemas, retained evidence/history and flag-first rollback.
- PostgreSQL lanes for constraints, locks and concurrency; Vitest for device SQLite.
- Explicit web deployment, captured deployment IDs, forced Laravel recreation and deterministic fingerprint intent—after the manifest is corrected.
- W-LOT-A server-core ownership boundaries; B must consume its public interfaces rather than reimplement them.

## Owner decisions still required — verbatim OPEN register

All four rows remain **OPEN**.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Q10 blocks the current W-LOT-A prerequisite schema/task/push and therefore blocks W-LOT-B dispatch. Q11–Q13 remain unencoded by B.

VERDICT: CHANGES-REQUIRED