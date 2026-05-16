# POS Fiscal Event Engine — Session Handoff / Continuation Anchor

**Created:** 2026-05-14
**Last refreshed:** 2026-05-16 — Tasks 7–16 shipped + dual-reviewed; Task 5 deferred P2s closed; the schema-destructive gate is OPEN via owner attestation; branch HEAD `ecbe7cd84` on `feat/pos-fiscal-event-engine-phase1` (PR #124).
**Why this exists:** the originating session hit the context window; subsequent sessions refresh this in place. This is the durable anchor — read this first, then the artifacts it indexes.

---

## 1. Where we are in the arc

Goal: bring B2C customer-account flows (on-account payments, receipts, compliance) to the offline-first POS — which surfaced that the **POS fiscal layer itself** needs rebuilding on the correct (device-authority) architecture first.

The journey, compressed:
- Customer-accounts spec hit 4 Codex BLOCKs → revealed the architecture was mis-placed (server-authored instead of device-authored).
- Pivoted: codebase reality audit + receipt-chain trace + clean-rebuild scoping + 6 primary-source research passes.
- Consolidated into the **source-of-truth document**: v1 UNSOUND → v2 MAJOR-REVISION → **v3 SOUND-WITH-CORRECTIONS → corrections applied → LOCKED**.
- **SoT v3 amended 2026-05-14:** §13 guardrail 6 + D16 added — the **bounded-modules / composable-activation guardrail** (carried in roadmap v1, lost in the architecture pivot) restored, then **refined the same day** with the inbound-reference-data / outbound-operational-dependency distinction (see §2.2).
- **Roadmap v2** written → Codex MAJOR-REVISION → 6 corrections applied → annotated for the bounded-modules seam (Treasury touchpoints marked module-integration; web-POS parity added to out-of-scope).
- **Phase 1 spec**: v1 → Codex BLOCK (3B/3P1/3P2) → v2 → Codex BLOCK (2B/1P1/1P2) → v3 → Codex BLOCK (1B/3P1/2P2) → v4 (resolves all 6) → Opus review APPROVE-WITH-MINOR-EDITS (1P1/2P2/2P3) → v5 (applies all 5) → Codex BLOCK (1B/1P3) → v6 (resolves both) → Codex BLOCK (1B) → **v7 (current) resolves the v6 finding + adds a structural fix (the §14.3 two-chokepoint completeness rule)**.
- v7 **cleared Codex re-review — APPROVE, 0 findings** (the §14.3 chokepoint enumeration independently grep-verified as exhaustive; no regressions). Next: **`writing-plans` for the Phase 1 implementation plan → execute.**

---

## 2. The locked architecture model

### 2.1 Device-authority fiscal pattern (LOCKED — SoT v3 §1)
The offline-first POS **device** is the fiscal source of truth: it authors, canonically serializes (once), hashes, chains, and seals fiscal events locally. The **server** is a verify-only mirror — it re-hashes the device's exact `canonical_bytes`, verifies linkage, stores verbatim; it **never re-serializes, never recomputes-and-replaces**. One fiscal pattern everywhere; the existing receipt chain is rebuilt clean on it (no migration — gated by a preflight check).

### 2.2 Bounded modules, composable activation — the asymmetric seam (LOCKED — SoT v3 §13.6 + D16; amended + refined 2026-05-14)
**Not** a "base POS → upgrade tier" ladder. Bounded modules compose per use case, and **the dependency is asymmetric — direction is what matters:**

- **Inbound — setup/reference data is mirrored and may be referenced (PERMITTED).** Payment methods, tenders, payment repositories, products are defined once in the web during setup and synced to the POS local mirror; the POS already operates offline-first against that mirror. A POS record referencing this mirrored reference data — e.g. `ReceiptPayment.payment_method_id` FK to `payment_methods` — is a *permitted inbound setup dependency*, **not** a coupling to the Treasury operational module, and does not block a POS-only deployment.
- **Outbound — the fiscal engine depends on no other module's operations (FORBIDDEN to couple).** The `FiscalEventEngine`, the authoring/sealing/chain, and the server-side `OutboxIngestor` + ingestion path have **zero** dependency on the Treasury *operational* module (Treasury `Payment` rows, GL postings, `PaymentAllocationService`/FIFO allocation), accounting, or the B2B sales module.

**The mechanism:** the POS fiscal engine *publishes* fiscal events; each active module *consumes/projects* them via a **pluggable projector registry** (`FiscalEventProjectionRegistry`) resolved per `(tenant, company)` by a **`ModuleActivationResolver`**. The receipt business projection = a **POS-core projection that always runs** + **pluggable module bridges** (the **Treasury bridge** runs only when Treasury is active). The POS-core projector owns `pos_receipts`/lines/VAT, `ReceiptPayment` rows, vouchers, stock; the Treasury bridge owns Treasury `Payment` rows + GL.

POS-only is a **supported standalone deployment**; its transactions are viewable via the existing web shop-management (POS) section as a read projection over the synced mirror. **The actual web POS** (`TerminalType::Web`, a server-authoring browser path) is **out of scope** as a device-authority target — dispositioned (hidden/disabled or confirmed-unused) in the Phase 1 preflight gate; parity deferred.

This guardrail was in roadmap v1, lost in the architecture pivot, **re-locked + refined this session. Do not reopen it.**

---

## 3. Artifact index

| Artifact | Path | Status |
|---|---|---|
| **Source-of-truth v3** | `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md` | **LOCKED** — amended 2026-05-14 (§13.6 guardrail 6 + D16, the asymmetric bounded-modules seam). Do not reopen. |
| Codebase reality audit | `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md` | Current — the grounding reference for every codebase claim. Do not reopen. |
| Roadmap v2 (phased plan) | `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` | Current — annotated for the bounded-modules seam (Treasury touchpoints = module-integration; web-POS parity out of scope). Phase structure locked. |
| **Phase 1 spec v7** | `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` | **CURRENT DRAFT** — resolves the v6 Codex BLOCKER + adds the §14.3 two-chokepoint completeness rule. Self-reviewed + owner-reviewed. Awaiting Codex re-review. |
| Codex review of Phase 1 spec v3 | `docs/superpowers/reviews/2026-05-14-pos-phase1-foundation-spec-v3-codex-review.md` | The 6 findings v4 resolved — see §5. |
| Opus review of Phase 1 spec v4 | `docs/superpowers/reviews/2026-05-14-pos-phase1-foundation-spec-v4-opus-review.md` | APPROVE-WITH-MINOR-EDITS — the 5 findings v5 resolved — see §5. |
| Codex review of Phase 1 spec v5 | `docs/superpowers/reviews/2026-05-14-pos-phase1-foundation-spec-v5-codex-review.md` | BLOCK — the 2 findings v6 resolved — see §5. |
| Codex review of Phase 1 spec v6 | `docs/superpowers/reviews/2026-05-14-pos-phase1-foundation-spec-v6-codex-review.md` | BLOCK — the finding v7 resolved — see §5. |
| Codex review of Phase 1 spec v7 | `docs/superpowers/reviews/2026-05-14-pos-phase1-foundation-spec-v7-codex-review.md` | **APPROVE — 0 findings.** The spec gate is passed. |
| **Phase 1 implementation plan** | `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` | **CURRENT — v4, 2026-05-14.** 33 TDD tasks; preflight gate first. v1 → BLOCK (1B/3P1/3P2/1P3) → v2 → BLOCK (1P1) → v3 → BLOCK (1P1/1P3) → v4 resolves both. Awaiting v4 re-review. |
| Codex review of the Phase 1 plan (v1) | `docs/superpowers/reviews/2026-05-14-pos-phase1-fiscal-event-engine-plan-codex-review.md` | BLOCK — the 8 findings plan v2 resolved. |
| Codex review of the Phase 1 plan (v2) | `docs/superpowers/reviews/2026-05-14-pos-phase1-fiscal-event-engine-plan-v2-codex-review.md` | BLOCK — 1 P1 (deferred provider wiring); plan v3 resolved it. |
| Codex review of the Phase 1 plan (v3) | `docs/superpowers/reviews/2026-05-14-pos-phase1-fiscal-event-engine-plan-v3-codex-review.md` | BLOCK — 1 P1 (Task 19 needs projectors before 21/22) + 1 P3; plan v4 resolved both. |
| Phase 1 spec v1–v6 + their reviews; SoT v1/v2 + assessments | `docs/superpowers/specs/` + `research/` + `reviews/` | Superseded — audit history. |
| 6 research reports (NF525, German TSE, integrity/recovery, Tunisia, B2B/POS investigation) | dispersed in `research/` + agent outputs | Inputs, consolidated into SoT v3. |
| Owner strategy docs | `~/Downloads/fiscal_chain_architecture_strategy.md`, `~/Downloads/pos_printable_documents_architecture.md` | Owner-provided grounding inputs. |

---

## 4. Current open work

Phase 1 spec **v7 is APPROVED** — Codex re-review 2026-05-14, 0 findings. The spec passed through v1→v7 (Codex BLOCK ×4, Opus APPROVE-WITH-MINOR-EDITS ×1) and the §14.3 two-chokepoint completeness rule closed the recurring "missed server-authoring caller" class. **The Phase 1 spec gate is passed.**

**Plan v4 is re-reviewed and cleared.** Review file: `docs/superpowers/reviews/2026-05-14-pos-phase1-fiscal-event-engine-plan-v4-codex-review.md` (verdict APPROVE-WITH-MINOR-EDITS; only P3 wording cleanup applied).

**Phase 1 implementation is in progress on branch `feat/pos-fiscal-event-engine-phase1` (PR #124).** Branch HEAD `b888aa9e` as of 2026-05-16. Owner sign-off on the schema-destructive preflight gate is OPEN (`apps/api/docs/sessions/2026-05-14-fiscal-preflight-signoff.md` — attested 2026-05-15).

### 4.1 Tasks shipped (1–16 inclusive)

| # | Commit | Summary | Reviews |
|---|---|---|---|
| 1 | `fc69ee27` (promoted to `dev` via PR #123) | preflight + minimal Fiscal module provider | Opus APPROVE; sign-off attestation `d695e9cf` |
| 2 | `84ff133d` | FiscalEventType enum (Appendix A reserved values) | Opus APPROVE |
| 3 | `0f0e0271` | Supporting status enums (integrity/parse/projection/signature) | Opus APPROVE |
| 4 | `7e469e0a` | Canonical golden vectors + PHP hash-only golden test | Opus APPROVE |
| 5 | `296d9d61` | FiscalEventCanonicalEncoder TS (cross-language reproduction) | Opus APPROVE; **two deferred P2s** (vet hand-rolled SHA-256 OR add padding-boundary vectors at 55–57, 63–65, 119–120, 127–128 bytes; extract shared JCS core) — handle BEFORE Task 15 |
| 6 | `3ad6fe49` | FiscalIntegrityProvider + HashChainIntegrityProvider + SignatureProviderInterface | Opus APPROVE |
| 7 | `245f5e85` + `097c21a0` | `fiscal_events` table + Task 7 P3 un-skip | Opus + Codex both APPROVE-WITH-MINOR-EDITS-APPLIED |
| 8 | `2e036485` + `9577dc62` + `73066080` | `fiscal_events` immutability triggers + 2 BLOCKER closures | Codex round-2 BLOCKER (parse-failure resolution bypass) + round-3 BLOCKER (integrity_exception_class write-once) BOTH found by Codex, missed by Opus — both closed |
| 9 | `61444f56` + `223577ff` + `55027c0b` | `fiscal_event_projections` mutable table + reconciliation (P1 PG-gate, P2 $fillable narrowing, P2 PHPDoc) | Opus APPROVE-WITH-MINOR-EDITS; Codex APPROVE-WITH-MINOR-EDITS; consensus reconciliation applied |
| 10 | `2e8aec56` + `c3e448d2` + `9014019b` | `fiscal_event_quarantine` (§8 non-admissible partition) + reconciliation (P1 CI-gate, 4×P2 + index widen + Phase-2 trigger note) | Opus APPROVE-WITH-MINOR-EDITS; Codex REQUEST-CHANGES (file says APPROVE-WITH-MINOR-EDITS — wrapper-summary diverged); reconciliation applied |
| 11 | `ddc42d5c` + `00670adf` + `4aa7f9be` | `pos_receipts` gains `canonical_bytes` + `fiscal_event_id` UNIQUE FK; chain columns become mirrors. Receipt model `$fillable` extended; @property annotations added; prevent_receipt_modification trigger interaction documented; insert-based duplicate-rejection test (via explicit factory chain) added | Opus APPROVE-WITH-MINOR-EDITS; Codex APPROVE-WITH-MINOR-EDITS; 3 P2 reconciliation applied |
| 12 | `49fb63e3` + `a544d566` + `0d5da5b0` | Treasury `payments` gains `origin` + `fiscal_event_id`; `PaymentOrigin` enum (5 cases). FK direction `payments → fiscal_events` (asymmetric bounded-modules seam). Partial index on `fiscal_event_id WHERE NOT NULL` for projector replay. Payment model `$fillable` + cast + @property extended. No UNIQUE on `fiscal_event_id` (one event → N Payment rows; idempotency at projector-level per Task 22) | Opus APPROVE-WITH-MINOR-EDITS (3 P2); Codex APPROVE-WITH-MINOR-EDITS (1 P2 + 11 CLEAN); 4 P2 reconciliation applied (partial-index test, all-5-cases cast, runtime FK rejection, VARCHAR(32) length pin) |
| 13 | `442ea109` + `3fdb7a41` | **First Tauri/device-side task.** Device SQLite migration v37: `fiscal_events` table (spec §3.1 column set, NOT GLOB hash-format CHECK, sequence/enum/source-pair CHECKs), append-only triggers (BEFORE UPDATE OF all 33 non-sync columns; BEFORE DELETE), `terminal_state` +3 chain head columns (legacy mirrors retained), `offline_receipts.canonical_bytes` (nullable per Task 15 deferral). | Opus REQUEST-CHANGES (1 BLOCKER + 5 P2 + 1 P3); Codex BLOCK (1 BLOCKER + 1 P2 + 1 P3) — BOTH caught the same hash-format GLOB BLOCKER (`[0-9a-f]*` only validated first char). Round-2 commit fixes the CHECK with `NOT GLOB '*[^0-9a-f]*'` and grows the test from 12 → 49 cases (BLOCKER reproducer + sequence>0 + enum CHECKs + source-event pair CHECK + all-33-columns data-driven trigger coverage + post-failure atomicity + ALTER TABLE backfill on pre-v37 rows). |
| 5†| `b561bcd7` | Task 5 deferred P2s closed BEFORE Task 15: extracted `canonicalCore.ts` (shared JCS structural encoder parameterized by a `StringNormalizer`); receipt-V3 hash output remains byte-identical (47 v3 fixture tests stay green). SHA-256 padding-boundary vectors added — every block-edge length (55-57, 63-65, 119-121, 127-128) hashed against `node:crypto.createHash('sha256')`; 19 vectors total. | Closes the two Task-5 round-1 deferred P2s; gate to Task 15 lifted. |
| 14 | `57b8fda9` + `023f4a93` | `FiscalEventPayloadRegistry` + 5 payload DTOs (PHP) + TS device-side mirror. 4 implemented (`SaleReceiptPayload`, `ChainBreakDetectedPayload`, `ChainRestartPayload`, `TerminalRegistrySnapshotPayload`) + 1 reserved schema-only (`CompanyDayClosureManifestPayload`). Registry-vs-enum drift gate walks all 28 cases. TS `FISCAL_EVENT_TYPES` const tuple — 28-case mirror, verified by diff. | Opus APPROVE (0 BLOCKER / 0 P1 / 2 P2 / 2 P3); Codex BLOCK (2 BLOCKERs in `fromArray()`: silent key-missing data loss + float monetary field leak — both via the loose `(string) $data['key']` cast pattern). Round-2 commit introduces `FiscalPayloadArrayGuards` (requireString / requireInt / requireArray / optionalString with explicit `array_key_exists` + `is_*` checks); hardens all 4 implemented DTOs; aligns `CompanyDayClosureManifestPayload` to throw `FiscalEventTypeNotImplemented` (Opus P2-1). 11 → 20 tests (83 assertions). |
| 15 | `9a9a94b3` + `b888aa9e` | **First Opus-subagent delegation.** `FiscalEventEngine.append()` — the single device chain authoring path per spec §6.1. Runs inside caller's SQLite transaction; never commits. Resolves payload DTO + event_version via the registry; idempotency check on `(tenant_id, terminal_id, source_event_class, source_event_id)`; reads chain head; builds canonical_bytes; INSERTs `fiscal_events`; advances `terminal_state` chain head. Defends against the v37 empty-string seed sentinel from Task 13 Codex P3 (now hardened to require 64-char lowercase hex per Task 15 round-2 Codex P3). | Opus APPROVE-WITH-MINOR-EDITS (0 BLOCKER / 0 P1 / 2 P2 / 3 P3); Codex BLOCK (1 BLOCKER / 2 P1 / 2 P2 / 1 P3 — opaque `payload: unknown` lets non-string money into canonical bytes + envelope timestamp / date format not enforced + cross-terminal source-id pollution + raw UNIQUE on parallel chain advance + genesis-seed only rejected `''`). Round-2 commit adds: typed payload input interfaces (compile-time defense) + runtime `validateRequestEnvelope` + `validateRequestPayload` (rejects non-string monetary fields in SALE_RECEIPT); terminal-scoped `findBySource`; typed `ConcurrentChainAdvanceError` on chain UNIQUE; `FiscalEventPayloadValidationError`; hex-validated genesis seed. 11 → 22 tests. Opus P3-1/P3-2/P3-3 (defaultDb cleanup, union → single type, findBySource comment) deferred. |
| 16 | `7683ce666` + `ecbe7cd84` | `StrictCanonicalParser` (server PHP) — derives the structured `payload` JSONB from already-hash-verified `canonical_bytes` per spec §7.6. Hand-rolled JSON tokenizer (no whitespace tolerance — JCS canonical bytes) detects duplicate keys at every depth + rejects out-of-grammar numbers (fractional/exponent/leading-zero/`+`/`-0`/overflow) + rejects invalid UTF-8 + rejects unescaped control bytes + rejects U+2028/U+2029 line/paragraph separators. Asserts the §4 fourteen-field envelope shape (exact key set + canonical regex for hash / ISO 8601 / business_date + event_version cross-check against registry). Rejects payload extras per event type via `PAYLOAD_KEYS` map. Per-event constraints: sub-array shape (Task 14 P2-2 carry-forward), scale-aware bcformat money regex, 64-char lowercase hex on payload-level hash fields (analogue of Task 15 P1-1 on the server boundary). Never throws — returns `ParseResult { ok, payload, failureReason }` with stable `<snake_case>:<context>` failure prefixes so OutboxIngestor (Task 19) can route on the prefix. 2 files (parser + ParseResult). | Opus APPROVE-WITH-MINOR-EDITS (0 BLOCKER / 1 P1 / 4 P2 / 4 P3); Codex BLOCK (4 BLOCKER — envelope shape not asserted, payload extras accepted, whitespace tolerated, money fields not regex-validated; 2 P1 — list-vs-assoc container, hex hash format; 2 P2 — U+2028/U+2029, test coverage). **Codex BLOCKER-catch streak now 5 tasks** (Tasks 8 ×2, 14, 15, 16); Opus had blessed the same commit. Round-2 commit closes every BLOCKER + both P1s + U+2028/U+2029 + Opus P2-1/P2-2/P2-3/P3-1/P3-2. 34 → 82 tests (175 → 618 assertions). Opus P2-4 (envelope-empty-list error string), P3-3 (mb_chr false check), P3-4 (payload-list forensic prefix), Codex P2-7 NFC normalization deferred. |

### 4.2 Per-task ground rules (carried forward from Tasks 7–16)

1. **Per-task TDD + dual review is non-negotiable.** Codex has now caught BLOCKERs Opus missed on **five** tasks: Task 8 parse-failure resolution bypass, Task 8 integrity_exception_class write-once, Task 14 silent-key-missing + float-leak in `fromArray()`, Task 15 opaque-payload + envelope-format gaps, **and Task 16 four-BLOCKER cascade** (Opus APPROVE-WITH-MINOR-EDITS → Codex BLOCK: envelope shape not asserted, payload extras accepted, whitespace tolerated, money fields not regex-validated). On Task 13 both reviewers converged on the same hash-format GLOB BLOCKER independently. The Codex-finds-what-Opus-misses pattern is consistent enough that the dual gate is a hard requirement — never skip the Codex pass even when Opus is happy. **Patterns to treat as code smells:** `(type) $array['key']` PHP casts (Task 14 BLOCKER), `payload: unknown` TS seams without runtime validation (Task 15 BLOCKER), free-form string fields without regex format validation (Task 15 P1-1 + Task 16 P1 F6), **wider-than-canonical parse grammar** (Task 16 4-BLOCKER pattern — accepting whitespace, payload extras, off-spec money strings, missing envelope fields silently widens the boundary), **DTO::fromArray as a "complete schema check"** (Task 16 BLOCKER F2 — `fromArray` only validates required keys; the parser is the boundary that rejects extras).
2. **CI PG merge-gate filter must be extended at the same commit any new fiscal table-shape test ships — server-side PHPUnit only.** Tasks 10 and 11 reviewers both flagged this regression once already. Pattern: add the test class name to `.github/workflows/ci.yml` line ~346 + extend the comment block. **Device-side Vitest tests (Task 13+) are picked up automatically by `apps/pos pnpm test` in the existing pos-test job (`.github/workflows/ci.yml:461`); no filter change needed for device tests.**
3. **`$fillable` boundary discipline.** Lifecycle/state-machine columns belong out of `$fillable` (Task 9 lesson). Identity / insert-time FK columns are fine to include (Task 11 CLEAN-2 verified — both Receipt and FiscalEventProjectionRow follow this pattern).
4. **Codex wrapper-summary may diverge from the actual review file.** Trust the file (`docs/superpowers/reviews/...-codex-review.md`) as source-of-truth; the wrapper summary returned by the agent runtime is generated from a different prompt path and can mis-classify severity.
5. **Plan-vs-code wording drift on `hash_sequence` vs `chain_sequence`.** Plan says `hash_sequence`; actual `pos_receipts` column is `chain_sequence`. Migration / commit message correctly use `chain_sequence`; do not propagate the plan's wording. (Task 11 Codex CLEAN-6.)
6. **Schema-destructive preflight gate is OPEN.** No further human checkpoints in the remaining tasks unless a new schema-destructive surface surfaces.

### 4.3 Open items going into Task 17

- **Task 14 standing pattern:** the `FiscalPayloadArrayGuards` helper is the canonical pattern for DTO `fromArray()`-style construction (server PHP). The `(type) $data['key']` cast pattern is treated as a code smell going forward.
- **Task 15 standing pattern:** at the device-TS boundary, `payload: unknown` seams MUST be paired with per-event-type runtime validation. The new `FiscalEventEngine.validateRequestPayload` switch is the canonical pattern. Typed-input interfaces (`SaleReceiptPayloadInput` etc.) provide compile-time defense; the runtime validator is the second line. Free-form string fields (`event_time_device`, `business_date`) MUST be regex-validated at the boundary.
- **Task 16 standing patterns (3 new):**
  - **DTO::fromArray is a partial schema check, not a complete one.** It validates required keys + types; the parser at the canonical-bytes boundary additionally rejects EXTRA keys via a `PAYLOAD_KEYS` map. (Codex BLOCKER F2.) Any future parser that sits at a canonical boundary MUST mirror this.
  - **The accepted grammar of a strict parser cannot be wider than the canonical contract.** Whitespace tolerance, optional fields beyond the spec, weakly-typed numbers, etc., all widen the parser past what canonical bytes should contain — they turn what should be `canonical_parse_failure` quarantines into accepted parsed payloads. (Codex BLOCKER F1+F3+F4.)
  - **Scale-aware money regexes** are the canonical PHP-side enforcement of spec §4's "money as `CurrencyScale::bcformat()` decimal strings". The `moneyRegex($scale)` pattern is `^-?(0|[1-9]\d*)(\.\d{N})?$` (no fraction part when scale=0). `is_string()` alone is insufficient — `"not-money"` is a string.
- **Task 15 deferred Opus P3 follow-up:** unused `defaultDb` ctor param + `db` getter (Opus P3-1); `Database | SqlSurface` union → single structural `SqlSurface` (Opus P3-2); `findBySource` could use the same tenant-scope comment `readChainHead` already has (Opus P3-3). Group into a single cleanup commit when convenient (no urgency — none of them change behavior).
- **Task 16 deferred items (low urgency):** Opus P2-4 (forensically clearer error string on envelope-as-empty-list), Opus P3-3 (belt-and-braces mb_chr false check — phpstan stub says return is `string`, check would be unreachable on validated range), Opus P3-4 (forensic prefix on payload-as-list — already covered by `F1 payload_not_object:got=list`), Codex P2-7 NFC normalization (left as producer-side per spec §4 wording — "stripped at the producer"). Tail-end cleanup commit.
- **Task 17 next.** `ModuleActivationResolver` interface + `DefaultModuleActivationResolver` (server PHP) — the per-`(tenant, company)` activation seam for the bounded-modules guardrail (SoT §13.6 / D16). Canonical PascalCase token `'Treasury'` — `CompanyConfig::hasModule()` strict-compares. Plan §1230+. Inline implementation (small — interface + default + tests). Dual review as usual.
- **Subagent strategy:** Tasks 15/19/21/22/27/28/29/30 delegate to Opus subagents. Tasks 16-18 inline; 19 is the next delegation.

### 4.4 Worktree + CI status

- Working directory: `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1` (dedicated git worktree). Main `apps/erp/` is on detached HEAD; do NOT cd into it (parallel cleanup session may claim branches).
- The worktree has its own APFS-cloned `apps/api/vendor` (the original symlink resolved PSR-4 baseDir through the main worktree, masking new files; replaced with a real copy in this session). Phpstan/pint/phpunit work directly from the worktree.
- Per-commit verification: `phpunit` on the touched test, `phpstan analyse` (level 8), `pint --test` — all on the explicit file list.
- PG-only smoke runs only happen in CI (the local SQLite runner skips them with `markTestSkipped`). The CI filter at `.github/workflows/ci.yml:349` includes: `VoucherLedgerTest|VoucherLedgerAppendOnlyTest|VoucherSchemaTest|FiscalHardeningE2ETest|FiscalEventsTableTest|FiscalEventsImmutabilityTest|FiscalEventProjectionsTableTest|FiscalEventQuarantineTableTest|PosReceiptsCanonicalBytesTest|PaymentsOriginColumnsTest`.

---

## 5. Findings history (resolved — do not reopen)

- **v1** → Codex BLOCK: 3 BLOCKER / 3 P1 / 3 P2 → v2 resolved all 9.
- **v2** → Codex BLOCK: 2 BLOCKER (projection based on false source; projection failure semantics undefined), 1 P1 (`ON CONFLICT` treated non-identical conflicts as success), 1 P2 (`/pos/receipts/sync` retirement surface) → v3 resolved.
- **v3** → Codex BLOCK: 1 BLOCKER + 3 P1 + 2 P2 → **v4 resolved all 6**:
  - **BLOCKER** — bounded-modules seam not buildable on the current payment-method boundary. Resolved **by correcting the model, not removing the FK**: the asymmetric seam (§2.2) — inbound mirrored reference data is permitted; the `ModuleActivationResolver` gates the outbound Treasury operational dependency. `payment_methods` is deliberately **not** relocated.
  - **P1** — web-POS disposition named no concrete write paths → new §14.2 checklist.
  - **P1** — `sequence_conflict` quarantine not verbatim enough → `fiscal_event_quarantine` expanded with raw envelope + full metadata columns.
  - **P1** — parse-failure projection resume had no mechanism → §7.5 resolver contract + `fiscal:enqueue-resolved-event-projections` command.
  - **P2** — `/pos/receipts/sync` retirement omitted classes → §14.1 expanded with the concrete old-sync classes + integration tests.
  - **P2** — projection retry/dead-letter state underspecified → §7.5 names Laravel/Horizon as owner of transient state; `fiscal_event_projections` gains `running`/`dead_lettered` + operational timestamps.
- **v4** → Opus adversarial review APPROVE-WITH-MINOR-EDITS: 0 BLOCKER / 1 P1 / 2 P2 / 2 P3 → **v5 applies all 5**:
  - **P1** — `ModuleActivationResolver` / `requiresModule()` used lowercase `'treasury'`, but `Vertical::defaultModules()` uses PascalCase `'Treasury'` and `CompanyConfig::hasModule()` strict-compares → bridge would never run. v5 pins the canonical `'Treasury'` token + §17.3 test-locks it.
  - **P2** — §14.2 web-POS disposition omitted the `void` / `processReturn` server-authoring paths → v5 names them as **knowingly retained server-side for Phase 1** (their event types are Phase 2+ reserved), scopes the "does not coexist" claim to new-sale authoring.
  - **P2** — parse-failure resume transaction boundary undefined → v5 §7.5 specifies `payload` write + status flip + projection-row inserts in one transaction, enqueue after commit; `fiscal:enqueue-resolved-event-projections` named the recovery path.
  - **P3** — `ReceiptPaymentService` mis-attributed wholesale to the Treasury bridge (it also creates the POS-core `ReceiptPayment` row at `:297`) → v5 §7.4/§14 specify the **split**: `ReceiptPayment::create` → POS-core; Treasury `Payment` + GL → bridge.
  - **P3** — `/pos/receipts/sync` feature-suite scope was a glob → v5 §14.1 names the concrete suites.
- **v5** → Codex BLOCK: 1 BLOCKER / 1 P3 → **v6 resolves both**:
  - **BLOCKER** — the Tauri POS `executeCheckout()` has an `onlineCheckout()` branch that server-authors `SALE_RECEIPT` (`POST /pos/receipts` + `/pos/receipts/{id}/payments`) whenever connectivity is online — v5 dispositioned only the *browser* web-POS path, leaving the *physical* terminal server-authoring online (two-model coexistence, D8 violation). v6 makes device authority **connectivity-independent + terminal-type-independent** (§5.0), adds §14 disposition rows for `offlineCheckoutService.ts` (REWORK — `onlineCheckout` branch DISCARDED) + `apps/pos/src/api/receiptApi.ts`, reframes §14.2 to cover web *and* Tauri-online callers, adds §17.5 proving tests. Behavior change: online Tauri checkout → device-authored-then-sync.
  - **P3** — §14.1 feature-suite list still not exhaustive → v6 adds `OfflineV3CutoverSyncTest.php` + classifies `PosStabilizationTenantIsolationTest.php` as a broader stabilization suite (migrate its receipt-sync assertions only).
- **v6** → Codex BLOCK: 1 BLOCKER → **v7 resolves it + adds a structural fix**:
  - **BLOCKER** — `POST /pos/orders/{id}/close` → `OrderToReceiptService` → `ReceiptCreationService::createReceipt()` is *another* server-authoring `SALE_RECEIPT` path v6 missed. Verified `apps/web`-only (no Tauri caller) → dispositioned with the web POS (§14.2; order management → §18 deferred parity).
  - **STRUCTURAL FIX** — v5 and v6 were the same class of defect ("a missed server-authoring caller"). v7's new **§14.3** dispositions the **two chokepoints** — `ReceiptCreationService::createReceipt()` + `ReceiptFinalizationService::finalize()` — enumerates every production caller (grep-verified), and makes completeness a **CI grep** of the two methods. This breaks the whack-a-mole: a missed caller now fails CI rather than surfacing in the next review round.
- **v7** → Codex re-review **APPROVE — 0 findings**. Codex independently grep-verified the §14.3 chokepoint enumeration as exhaustive, ran a direct-`Receipt`-construction bypass scan (none found), and confirmed no regression. **Phase 1 spec gate passed.**

---

## 6. Process for the next session

Every adversarial-review prompt this effort uses (Opus or Codex): instruct the reviewer to **write the review to a file path** (not inline), **ground findings in SoT v3 + the two owner strategy docs + the codebase reality audit**, and **verify codebase claims against real code**.

**Do not reopen what is locked:** SoT v3's device-authority architecture; the bounded-modules asymmetric seam (§13.6 / D16); the codebase reality audit; roadmap v2's phase structure; and the v1–v6 findings (Codex v3/v5/v6 + Opus v4) already resolved in later revisions.

---

**End of handoff (refreshed 2026-05-16 after Task 16 round-2 — `StrictCanonicalParser` shipped + dual-reviewed; Codex BLOCKER-streak now 5; next: Task 17 — `ModuleActivationResolver` interface + `DefaultModuleActivationResolver`, the bounded-modules activation seam).**
