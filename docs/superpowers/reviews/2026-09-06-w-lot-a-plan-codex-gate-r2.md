# Codex plan gate r2 — W-LOT-A rev 5 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 5 at local dev 6bb1519ec. Verbatim.

---
# W-LOT-A rev 5 adversarial gate

Reviewed read-only at local `dev` HEAD `0e3e63f12a96491d1d8d82ee449d22b2dc3dbaab`. No files were edited, no tests were run, and no Git state was changed.

Rev 5 is not dispatch-ready. Six blockers remain, including an explicit OPEN-Q10 schema branch and a Push 2 migration that would break deployed stock writers before their replacement ships.

## Previous-gate closure accounting

### Carried rows

| ID | Result | Current evidence |
|---|---|---|
| R1-1 Mechanical dispatch | **NOT CLOSED** | T3–T9 still use basenames, “rev-4 … unchanged,” or “PG by file” placeholders ([plan:849](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:849), [plan:997](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:997), [plan:1012](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1012)). |
| R1-2 Recall escalation/roles | **NOT CLOSED** | Q10 remains encoded in `BatchHoldStatus` and its CHECK constraint ([plan:301](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:301)). |
| R1-3 POS core/lot obligation | **REJECTED — W-LOT-B** | B owns device evidence and obligations ([B:89](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:89)). |
| R1-4 Lock census | **NOT CLOSED** | Current global recall and planned deactivation are absent from the census ([plan:602](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:602)). |
| R1-5 Deployment order | **NOT CLOSED** | Push 2 introduces a mandatory movement field before T6 writers/allocator deploy ([plan:463](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:463), [plan:1257](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1257)). |
| R1-6 Freeze before replacement | **CLOSED** | Identification remains ordered before used-lot freeze in T5 ([plan:997](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:997)). |
| R1-7 Float retirement | **CLOSED narrowly** | Scale-four migration/census and string DTO intent now exist ([plan:368](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:368)); task mechanics remain separately blocked. |
| R1-8 L9 correction/identification | **NOT CLOSED** | T5 imports paths and signatures from rev 4 rather than defining a dispatchable packet ([plan:999](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:999)). |
| R1-9 Flat-row count | **CLOSED** | Parent observations, effects and flat reconciliation are specified ([plan:472](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:472)). |
| R1-10 Provenance/health | **NOT CLOSED** | Absent allocation cannot produce the retained record required by S5; T9 is still incomplete ([plan:508](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:508), [plan:1121](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1121)). |
| R1-11 Refresh before POS open | **REJECTED — W-LOT-B** | Device/session refresh is B-owned ([B:91](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:91)). |
| R1-12 Durable outbox | **REJECTED — W-LOT-B** | Receipt/evidence outbox is B-owned ([B:94](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:94)). |
| R1-13 L8/L9 | **NOT CLOSED for A** | L8 is correctly B-owned; A’s T5 remains non-self-contained ([plan:997](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:997)). |
| R1-14 Citation hygiene | **NOT CLOSED** | Plan claims reviewed HEAD `4878c3e…`, not current HEAD ([plan:100](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:100)). |
| R1-15 Task size | **CLOSED narrowly** | Ten tasks exist; their independent dispatchability does not. |
| R2-B1 Owner register | **NOT CLOSED** | The register is copied verbatim, but Q10 is subsequently encoded ([owner:140](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:140), [plan:301](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:301)). |
| R2-B2 Aggregate movement link | **REJECTED — W-LOT-B** | A correctly uses signed effects; POS obligation effects remain B-owned ([B:96](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:96)). |
| R2-B3 Canonical line key | **REJECTED — W-LOT-B** | Evidence identity is B-owned ([B:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111)). |
| R2-B4 Writer/lock census | **NOT CLOSED** | See current BLOCKER-6. |
| R2-B5 Deployment preflight | **NOT CLOSED** | See current BLOCKER-4. |
| R2-B6 Dispatch packets | **NOT CLOSED** | See current BLOCKER-3. |
| R2-M1 L9 permission | **CLOSED** | Correction/identification remain admin-only ([plan:978](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:978)). |
| R2-M2 Reservation consumers | **CLOSED narrowly** | Consumers are enumerated in the lock census ([plan:607](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:607)); retry identity remains incomplete. |
| R2-M3 Provenance seams | **CLOSED narrowly** | All three producer families are named ([plan:617](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:617)). |
| R2-M4 POS renderer census | **REJECTED — W-LOT-B** | Renderer work is B-owned ([B:91](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:91)). |
| R2-M5 Health schedule | **CLOSED** | 03:20/180-minute behavior is retained in T9. |
| R2-M6 Vocabulary | **NOT CLOSED** | “Lot provenance record” overlaps existing “Lot evidence,” without a primary writer ([plan:161](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:161), [glossary:42](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:42)). |
| R2-m1 Reproducible baseline | **NOT CLOSED** | Current HEAD differs from the declared reviewed HEAD, though intervening tracked changes are documentation-only. |

### Gate-r3 rows

| Finding | Result | Current evidence |
|---|---|---|
| Multi-lot cardinality | **REJECTED — W-LOT-B** | [B:89-108](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:89) |
| Canonical `SMALLINT` | **REJECTED — W-LOT-B** | [B:111](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:111) |
| Recall authority/bypass | **NOT CLOSED** | Q10 schema and unlocked global recall remain. |
| Recall retry | **NOT CLOSED** | The forbidden `requested|recalled` schema constrains every later lifecycle ([plan:329](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:329)). |
| Receipt evidence atomicity | **REJECTED — W-LOT-B** | [B:94](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:94) |
| Company entitlement boundary | **CLOSED** | Both race directions and the central-lock contract are now explicit ([plan:835](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:835)). |
| Five-push manifest | **NOT CLOSED** | Push 2 is incompatible and later commands contain prose/placeholders. |
| Dispatch and CI | **NOT CLOSED** | Task packets and reviewer invocations remain incomplete. |
| GL ownership/order | **CLOSED narrowly** | Product-first and buffered GL ordering are specified ([plan:584](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:584)). |
| Float retirement | **CLOSED narrowly** | Scale-four contract is explicit ([plan:368](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:368)). |
| Count as-of marker | **CLOSED** | Numeric company-scoped movement sequence is specified ([plan:459](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:459)). |
| Used-lot freeze | **NOT CLOSED** | T5 remains imported from rev 4, not dispatchable. |
| L1 authorization census | **NOT CLOSED** | Role matrix prose exists, but exact role cases and GM location implementation do not. |
| Active cart/pre-open | **REJECTED — W-LOT-B** | [B:91](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:91) |
| Generated enums/types | **NOT CLOSED** | Numerous new status/type columns lack PHP enum contracts, contrary to [CLAUDE.md:39](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:39). |
| Stale verified HEAD | **NOT CLOSED** | [plan:100](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:100) |

### Findings introduced by r4

| Finding | Result | Current evidence |
|---|---|---|
| NEW-B1 Q10 encoded | **NOT CLOSED** | [plan:301-337](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:301) |
| NEW-B2 Signed reconciliation | **REJECTED for A POS obligations** | B owns POS obligation effects; A’s count effects are signed. |
| NEW-B3 Entitlement fence | **CLOSED** | Both mutation directions are named and transactional ([plan:837](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:837)). |
| NEW-B4 Branch hold bypass | **CLOSED structurally** | Shared eligibility is assigned to FEFO/reservation/transfer ([plan:606](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:606)); the hold schema itself remains prohibited by Q10. |
| NEW-B5 Unordered watermark | **CLOSED** | Numeric sequence replaces UUID ordering. |
| NEW-B6 Evidence delivery order | **REJECTED — W-LOT-B** | [B:94-96](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md:94) |
| NEW-B7 Staging manifest | **NOT CLOSED** | See BLOCKER-4. |
| NEW-B8 Convention 10 | **NOT CLOSED** | Columns are still not verbatim ([plan:125](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:125), [C10:45](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:45)). |
| Deactivation disposition | **CLOSED for persistence** | Immutable transition schema now captures operation, actor, reason and snapshots ([plan:339](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:339)). |
| GM role exceeded ruling | **NOT CLOSED** | Permission delta is narrowed, but “no location restriction” has no implementation path ([plan:981](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:981)). |
| Convention 09 per task | **NOT CLOSED** | T3–T8 lack literal C09 commands; T9’s read-only exclusion is invalid under C09’s layer-based scope ([C09:30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:30)). |
| Convention 11 glossary | **NOT CLOSED** | Existing Lot evidence is duplicated conceptually and no single provenance writer is named. |
| Entitlement vocabulary | **CLOSED** | Only `entitled|not_entitled|entitlement_unresolved` are specified. |
| Delegated schema checks | **NOT CLOSED** | Enum, compatibility and reopened-actor contracts remain incomplete. |
| Undispatchable tasks | **NOT CLOSED** | See BLOCKER-3. |
| Wrong oversell citation | **CLOSED** | The plan correctly cites spec line 376. |
| Stale HEAD label | **NOT CLOSED** | Declared `4878c3e…`; reviewed `0e3e63f…`. |

### Prior r1 findings

| Previous finding | Result |
|---|---|
| BLOCKER-1 Q10 encoded | **NOT CLOSED** — current BLOCKER-1. |
| BLOCKER-2 incomplete task packets | **NOT CLOSED** — current BLOCKER-3. |
| BLOCKER-3 staging manifest | **NOT CLOSED** — current BLOCKER-4. |
| BLOCKER-4 scale-3/shadow types | **CLOSED/REJECTED narrowly** — scale-four is already covered by the historical widening migration and rev 5 adds live verification ([migration:72](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_29_100002_widen_quantity_columns_to_scale_4.php:72)); generated re-export intent is explicit ([plan:168](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:168)). |
| BLOCKER-5 unowned writers | **NOT CLOSED** — current BLOCKER-6. |
| BLOCKER-6 Convention 10 | **NOT CLOSED** — current BLOCKER-5. |
| MAJOR-1 deactivation persistence | **CLOSED** — [plan:339-365](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:339). |
| MAJOR-2 authorization matrix | **NOT CLOSED** — task packet and GM location gaps remain. |
| MAJOR-3 late-sync detector | **CLOSED for original omission** — detector and reopen scenarios are now assigned ([plan:1028](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1028)); actor/terminal-health regressions are new findings. |
| MAJOR-4 retention/resume | **CLOSED for original defects** — independent records and `resumes_run_id` now exist ([plan:508](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:508), [plan:527](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:527)). |
| MAJOR-5 C11/type ownership | **NOT CLOSED** — current MAJOR-5. |
| MINOR-1 UUID version | **CLOSED** — manifest now specifies `Str::uuid7()` ([plan:1184](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1184)). |
| MINOR-2 stale HEAD | **NOT CLOSED** — current MINOR-1. |

## Current findings

### BLOCKER-1 — OPEN Q10 remains encoded

- Plan: [301–337](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:301), [1263–1272](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1263).
- Authority: [owner Q10](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:140).
- Failure scenario: `status CHECK ('requested','recalled')`, a partial unique conditioned on `requested`, and an enum with only those values permanently reject the possible `released` or `rejected` owner outcomes. Push 2 deploys that choice even though no writer is active.
- Minimum correction: remove `batch_recall_requests`, `BatchHoldStatus`, the model, permission preparation, shared-eligibility hold read, and their Push 2 entries until Q10 is ruled. Q5’s local-hold requirement does not authorize guessing Q10’s terminal lifecycle.

### BLOCKER-2 — Push 2 is not additive-compatible with live writers

- Plan: [463–468](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:463), [1257–1289](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1257).
- Source: existing writers omit `movement_sequence`, including [StockAdjustmentService.php:2028](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:2028), [OpeningBalancePostingService.php:120](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:120), and [PosCoreReceiptProjection.php:2356](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2356).
- Failure scenario: Push 2 backfills and sets `stock_movements.movement_sequence NOT NULL`; the allocator and migrated writers do not ship until Push 4/T6. Every intervening stock-movement insert fails a NOT NULL constraint.
- Secondary failure: S5 denormalizes tenant/company into producer tables in Push 2 ([plan:497](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:497)), while current POS allocation creation supplies neither ([PosCoreReceiptProjection.php:2109](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2109)).
- Minimum correction: keep new columns nullable through the compatibility push or provide a database-side allocator/derivation mechanism usable by old code. Deploy all writers before setting NOT NULL, then enforce constraints in a later migration.

### BLOCKER-3 — The task packets are still not independently dispatchable

The required standard is red-first execution ([CLAUDE.md:18](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:18)), exact task scope ([CLAUDE.md:24](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:24)), and same-lane C09 proof ([C09:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37)).

| Task | Missing minimum contract |
|---|---|
| T1 | S1 assigns two migrations to T1, but neither appears in T1’s production files; T1 simultaneously says “no schema exists in P1” ([plan:660-760](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:660)). |
| T2 | Otherwise substantially complete, but no concrete reviewer invocation command. |
| T3 | Several DTOs/enums have no signatures; `MovementContextData` and `BatchEligibilityPurpose` lack files/contracts; C09 has no full path or literal command; reviewers are labels ([plan:851-925](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:851)). |
| T4 | Basenames are not paths; `RecallBatchRequest` appears in a signature but not its file contract; C09 and reviewer commands are placeholders ([plan:929-994](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:929)). |
| T5 | “rev-4 paths/signatures unchanged” and “command by exact file/filter” are external references, not a packet ([plan:997-1008](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:997)). |
| T6 | Uses descriptive basenames, rev-4 signatures, and “PG by file/filter” instead of literal paths/commands ([plan:1012-1024](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1012)). |
| T7 | Same path/command defects; `reopenForLateSync()` lacks the actor required by its schema ([plan:1028-1064](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1028)). |
| T8 | Basenames only, no primary provenance writer/service signature, and commands remain “PG command naming…” ([plan:1068-1117](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1068)). |
| T9 | CLI is imported from rev 4; its C09 exclusion is invalid because it edits BatchExpiry routes/list UI around a catalogue entity ([plan:1121-1133](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1121)). |
| T10 | “All five reviewers” supplies neither exact reviewer paths nor invocations ([plan:1137-1157](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1137)). |

Minimum correction: make each task self-contained with exact repository paths, all changed/new public and CLI signatures, complete owned schema, exact test file/class/case/first assertion/literal command/lane, the three applicable C09 proofs, exact reviewer files, and a runnable reviewer invocation.

### BLOCKER-4 — The staging push manifest remains non-executable

- Plan: [1186–1222](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1186), [1281–1289](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1281), [1327–1349](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1327).
- Source:
  - Current web image accepts no build-SHA argument ([apps/web/Dockerfile:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/Dockerfile:50)).
  - `.git` is excluded from its build context ([apps/web/.dockerignore:7](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/.dockerignore:7)).
  - Compose forwards only current Vite arguments ([docker-compose.staging.yml:263](/Users/houssamr/Projects/syneriva/apps/erp/docker-compose.staging.yml:263)).
- Failure scenarios:
  - Push 2’s literal migration block runs `php artisan` locally, not through the stated SSH/container pattern.
  - The web-deploy “contract” contains no executable HTTP request, polling command, JSON extraction, or invocation of the proposed release script.
  - No command forwards `CANDIDATE_SHA` into either build, so `/build-fingerprint.json.build_sha == CANDIDATE_SHA` cannot be proven.
  - Push 4 contains a literal `"... docker compose ..."` placeholder.
  - The permission loop lacks `pipefail`; Stancl prints `Tenant: <id>` before the actual seeder runs ([Seed.php:53](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Seed.php:53)), so a failed seeder can still satisfy the following `grep`.
  - Revision, cutover, provenance and census ID capture is mostly prose, not parseable commands.
  - Direct pushes omit the mandatory `git fetch origin dev` and fast-forward reconciliation ([CLAUDE.md:89](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:89)).
- Minimum correction: provide one literal, `set -euo pipefail` script implementing remote migrations, environment forwarding, deployment API calls, exact title/description correlation, SHA injection, asset hashing, ID extraction, per-tenant success validation, pre-push fetch/FF checks, and rollback. Invoke that script in every push.

### BLOCKER-5 — Convention 10 is still not exact

- Plan: [line 125](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:125).
- Authority: [Convention 10 lines 37–57](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37).
- Failure: the plan uses `ID`, `Guarantee`, `Dolibarr/NV`, and `AutoERP today path:line`; the mandatory columns are `#`, `Guarantee the baseline gives the user`, `Dolibarr`, and `AutoERP today (path:line)`. Round-zero requires the skeleton verbatim.
- Minimum correction: copy the exact heading, column labels, separator, decision vocabulary block, and embedded C09 line from Convention 10.

### BLOCKER-6 — Lock/state-machine coverage still omits destructive lot writers

- Plan: [writer census](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:602).
- Source: current recall performs an unlocked update ([BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193), [Batch.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134)).
- Failure: global recall can race a sale/reservation after eligibility is checked; planned deactivation can race receipt/reservation between its zero snapshot and `is_active=false`. Neither current recall nor `BatchDeactivationService` is a census row with canonical lock ordering and a discriminating concurrency test.
- Minimum correction: assign both operations to exact files/tasks, route them through the product-first lock/eligibility spine, and add both race directions against sale, receipt, transfer and reservation.

### MAJOR-1 — New status/type columns lack required PHP enums

- Plan: [S1 state](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:275), [S3 effect kind](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:447), [S4 mode/finality](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:478), [S5 statuses](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:538), [S6 statuses](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:556).
- Authority: every status/type/code column requires a PHP enum ([CLAUDE.md:39](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:39)).
- Failure: implementers must invent enum names, cases, casts and generated TS ownership independently.
- Minimum correction: enumerate each PHP enum, exact file, cases, model cast, DTO field and generated TS export in its owning task.

### MAJOR-2 — `general_manager` cannot satisfy “no location restriction”

- Plan: [line 981](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:981).
- Authority: owner ruling [Q4](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:113).
- Source: location access is controlled by membership `allowed_location_ids`, independently of role permissions ([LocationContext.php:202](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:202)).
- Failure: seeding the role and permissions leaves an existing general manager restricted to their old membership locations.
- Minimum correction: define the exact membership/bypass contract, production files, existing-tenant migration behavior, and tests proving unrestricted company-wide access without broadening ordinary managers.

### MAJOR-3 — Count reopening cannot satisfy its actor/state contract

- Plan: `reopened_by` is required for reopened state ([plan:478-489](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:478)), but `reopenForLateSync()` has no actor argument ([plan:1049](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1049)).
- Regression source: pending receipt truth is mediated by [TerminalSyncHealthService.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/TerminalSyncHealthService.php:14), which T6/T7 do not own.
- Failure: an automatic late movement cannot populate required `reopened_by`, or code invents an implicit system user. A count may also be marked final while an existing terminal-health acknowledgement says pending/unknown.
- Minimum correction: define explicit human/system actor semantics and schema nullability, assign `TerminalSyncHealthService`, and test pending/unknown/stale terminal states through provisional, final and reopened transitions.

### MAJOR-4 — L5 absent-allocation semantics are unimplemented

- Spec: absent allocation must display `unknown` ([spec:401](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:401)).
- Plan: retained records require non-null producer row, parent, batch and positive quantity ([plan:512-525](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:512)).
- Source: current POS code creates no allocation at all when consumed lots are absent ([PosCoreReceiptProjection.php:2098](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2098)).
- Failure: there is no producer row from which to store or retrieve the promised `unknown` provenance.
- Minimum correction: either persist explicit unknown evidence at receipt-line level or specify exact read-time derivation across detail, trace and export surfaces, with an absent-allocation test.

### MAJOR-5 — Convention 11 ownership remains ambiguous

- Plan: introduces “Lot provenance record” ([plan:161](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:161)) while saying existing “Lot evidence” will merely be amended ([plan:166](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:166)).
- Source: existing glossary already defines Lot evidence and declares the same three producers ([glossary:42](/Users/houssamr/Projects/syneriva/apps/erp/docs/glossary.md:42)).
- Authority: Convention 11 requires one term, one primary writer and one operator surface ([C11:30](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:30)).
- Failure: implementation can create two canonical nouns and three direct retained-record writers.
- Minimum correction: provide the exact replacement glossary row, name one provenance write service used by all producers/backfill, and enumerate the one canonical surface plus permitted synonyms.

### MAJOR-6 — Reservation retry has no operation identity

- Plan: `reserve()` and `release()` accept only quantity/grain fields ([plan:889-896](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:889)), while T3 promises duplicate-retry idempotency ([plan:923](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:923)).
- Failure: identical calls cannot be distinguished as a retry versus a second legitimate reservation.
- Minimum correction: include stable operation/source identity and fingerprint in reserve/release contracts and persistence, with exact-retry and conflicting-reuse cases.

### MAJOR-7 — Broad staging adds can capture unrelated user files

- Plan: [Push 3 line 1312](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1312), [Push 5 lines 1360–1366](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1360).
- HEAD worktree currently contains unrelated untracked files under both `apps/api` and `docs/handoff`.
- Failure: `git add -- apps/api apps/web` stages the unrelated `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`.
- Minimum correction: stage an exact generated path manifest and fail if `git status --porcelain` contains any unclassified path.

### MINOR-1 — Reviewed HEAD is stale

- Plan declares `4878c3e6…` ([plan:100](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:100)); review HEAD is `0e3e63f1…`.
- The intervening tracked commits are documentation-only, so source citations remain materially stable.
- Minimum correction: distinguish immutable source base from current gate-review HEAD.

### MINOR-2 — Push commit messages violate repository format

- Plan messages: [P1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1247), [P3](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1313), [P5](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-w-lot-a-server-lot-core-execution-plan.md:1368).
- Authority: commits must use `Phase <major.minor.patch>: <imperative summary>` ([AGENTS.md:15](/Users/houssamr/Projects/syneriva/apps/erp/AGENTS.md:15)).
- Minimum correction: replace all five planned messages with compliant Phase-form messages.

## Rejected false positives

- `tenants:migrate-rolling --force` is a valid command and emits the claimed completion line ([RollingTenantMigrationCommand.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48), [line 163](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:163)). The defect is that the manifest does not invoke/capture it consistently through the remote wrapper.
- `tenants:seed --force --class=...` is a valid shape. The failure is the non-`pipefail` evidence pipeline.
- Compose inheritance across the four Laravel services is real; the remaining problem is complete value/SHA forwarding and executable verification.
- The original “POS allocation remains scale 3” claim is false for a fully migrated tenant: the existing migration widens it to `DECIMAL(15,4)` ([migration:79](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_29_100002_widen_quantity_columns_to_scale_4.php:79)). Rev 5’s live census remains prudent.
- Holding a central advisory lock across tenant commit is feasible because the named central connection is never tenancy-swapped ([database.php:105](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/database.php:105)).
- Multi-lot cardinality, canonical evidence-line identity, device rendering/capture, receipt evidence/outbox, obligation recovery and arrival order remain W-LOT-B scope and must not be replanned in A.
- Q11–Q13 are not encoded by W-LOT-A.
- The 03:20 schedule, 180-minute overlap, UUIDv7 intent, signed count delta and additive rollback principles remain acceptable.

## Preserve in the next revision

- Keep Q10–Q13 verbatim and OPEN, but remove all Q10-dependent schema/task/push content.
- Preserve `entitled|not_entitled|entitlement_unresolved`.
- Preserve the central advisory lock across tenant commit and both race-direction tests.
- Preserve one canonical mutation service, one eligibility service, product-first locking and GL buffering.
- Preserve exact scale-four strings and the live schema/value census.
- Preserve numeric movement ordering, explicit-zero observations and signed reconciliation effects.
- Preserve identification before used-lot freeze and the prohibition on automatic DEFAULT reconstruction.
- Preserve independent retained provenance and resume linkage, while adding absent-allocation semantics and one writer.
- Preserve the durable, read-only, entitlement-aware census and 03:20 schedule.
- Preserve the A/B boundary: A supplies server interfaces; B owns device display/capture/evidence/obligations.
- Preserve flag-first rollback, immutable revisions and captured-ID evidence after making the push script executable.
- Preserve the separate deferral of oversell policy.

## Owner decisions still required — VERBATIM OPEN register

All four remain **OPEN**.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Q10 blocks the current recall-request schema, enum, model, eligibility branch and Push 2. Q11–Q13 remain unencoded in W-LOT-A and do not independently block its non-recall server work.

VERDICT: CHANGES-REQUIRED