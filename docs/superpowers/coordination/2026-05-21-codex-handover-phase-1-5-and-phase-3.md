# Codex Handover Brief — Phase 1.5.2 + 1.5.3 + Phase 3 (End-to-End)

**Created:** 2026-05-21
**Author:** Outgoing controller (transitioning to Codex-led continuation)
**Purpose:** Single comprehensive handover for the remaining fiscal-event-engine work — Phase 1.5.2 (per-country tax-number strict validation), Phase 1.5.3 (ParseFailureResolution operator UX pre-fill tool), and Phase 3 (Charge-to-Account). After these three workstreams ship, Tunisia + France are deployment-unblocked and the AR-creating side of customer-account flows is live.

This brief is **self-contained** — Codex starts cold with no prior session context. Read top-to-bottom, then execute §5 work in order. **All workflow decisions, regex patterns, scope, and standing patterns are pre-resolved.** The only owner-input ask is the D8-related question on B2B Tax Invoice authoring (surfaced in §5.3) — Codex should flag this in the Phase 3 spec review and the owner can decide then.

---

## 1. Mission summary

Three workstreams remain before Phase 4+ can start:

- **Phase 1.5.2** — per-country tax-number strict validation. **Pre-Tunisia-launch GATE.** Replaces Phase 2A.PHP.1's universal `^[A-Za-z0-9 \-/.]{4,40}$` regex with country-keyed validators. Patterns provided in §5.1 — no accountant input needed (publicly-documented formats).
- **Phase 1.5.3** — ParseFailureResolution operator UX pre-fill admin tool. Operator quality-of-life. Reads `fiscal_event_quarantine.raw_envelope`, attempts best-effort parse, pre-fills the structurally-valid 27 keys, operator amends only broken fields, submits via the existing resolve service.
- **Phase 3** — `ACCOUNT_CHARGE` event (B2C customer "buys now, pays later") + AR GL posting path (which does NOT exist today — `createPOSPaymentEntry` is cash→revenue only per codebase reality audit §2.2) + Treasury-module-integration bridge per D16. B2B charge-to-account routes through the existing web Facture flow (NOT POS ticket path) per roadmap v2 §Phase 3.

Workflow throughout: **Codex implements + Codex self-adversarial review + Opus second-pass adversarial review + fix + iterate until APPROVE.** Same pattern that worked for Phase 1 final continuation + Phase 2.

---

## 2. Worktree + branch state (CRITICAL)

- **Phase 2 worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.customer-accounts-phase2/` — branch `feat/pos-customer-accounts-phase2`, HEAD `2bcc3b6a2`. PR #125 MERGED to dev at `7e501cc20` on 2026-05-21.
- **Phase 1 worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/` — branch `feat/pos-fiscal-event-engine-phase1`, HEAD `8d014be69`. PR #124 MERGED.
- **Main worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/` — detached HEAD. NEVER cd here.
- **`origin/dev` HEAD:** `7e501cc20` (Phase 2 merge). Phase 1 + Phase 2 + Phase 1.5.1 all integrated.

**Create three new branches off latest `origin/dev`** — one per workstream:
- `feat/fiscal-phase-1-5-2-per-country-tax-validation` (small).
- `feat/fiscal-phase-1-5-3-parse-failure-resolution-ux` (small-medium).
- `feat/fiscal-phase-3-charge-to-account` (large — full phase cycle).

Sequencing recommendation (per roadmap §Phase 1.5 gate language): land Phase 1.5.2 first (unblocks Tunisia launch); then Phase 1.5.3 (operator-quality nice-to-have); then Phase 3 (full phase cycle). Phase 1.5.2 + 1.5.3 are independent and could run in parallel on separate branches if Codex prefers, but the test surface overlaps slightly (both touch FiscalPayloadConstraintValidator / ParseFailureResolutionService), so sequential is safer.

Optional: dedicated worktrees per branch following the Phase 1/Phase 2 pattern:
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git fetch origin
git worktree add /Users/houssamr/Projects/syneriva/apps/erp.phase-1-5-2 -b feat/fiscal-phase-1-5-2-per-country-tax-validation origin/dev
# Verify apps/api/vendor is real-copy not symlink per Phase 1/2 pattern
```
Repeat for 1.5.3 and Phase 3 when each starts.

**Stage explicit files only — NEVER `git add -A`. Use absolute paths in Bash.**

---

## 3. Authoritative artifacts (READ in order)

1. **Phase 2 handover brief** — `docs/superpowers/coordination/2026-05-20-codex-handover-brief.md` — workflow grounding (Codex implements + self-adversarial + Opus second-pass).
2. **Phase 2 session handoff §4** — `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` — 29+ standing patterns + recent Phase 2 closure log.
3. **Roadmap v2** — `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` — especially §Phase 1.5, §Phase 3, "Locked inputs every phase inherits," "Per-phase process."
4. **Source-of-truth v3** — `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md` — LOCKED architecture. Especially §13.6 + D16 (bounded-modules asymmetric seam — Treasury is a pluggable bridge), §9.x (reconciliation classification), §11.x (event types).
5. **Codebase reality audit** — `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md` — every codebase claim in your Phase 3 spec must trace to this. Especially §2.2 (`createPOSPaymentEntry` is cash→revenue only).
6. **Phase 1 synthesis v5** — `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md` — the 27-key SALE_RECEIPT canonical contract pattern. `ACCOUNT_CHARGE` payload should follow the same compliance-rich design pattern (multi-country superset, not minimum).
7. **Phase 1 spec v7** — `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` — §11.2-§11.4 SALE_RECEIPT contract, §11.0 server-authoring carve-out boundary. `ACCOUNT_CHARGE` is device-authored per §1 default.
8. **Phase 2 ACCOUNT_PAYMENT contract** — search the Phase 2 commits for the `AccountPaymentPayload` DTO + canonical contract. Phase 3 `ACCOUNT_CHARGE` mirrors its shape closely (same identity fields + customer attach + monetary fields + reconciliation classification).
9. **External multi-country research** — `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md` — NF525 + ZATCA + DE DSFinV-K + IT RT field citations. For `ACCOUNT_CHARGE`, do an analogous per-regime field analysis.
10. **Phase 1.5.1 audit** — `docs/superpowers/research/2026-05-21-phase1-5-mirror-column-audit.md` — for context on what's left in the mirror-column retention pile + safe-drop prerequisites.
11. **`/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md`** — project rules. Especially rule 5 (verification is law, end-to-end), rule 13 (constructor injection only).

---

## 4. Workflow (per roadmap v2 "Per-phase process" + Codex-led continuation)

For EACH workstream:

### For small implementations (Phase 1.5.2, Phase 1.5.3) — single-task pattern

1. **Codex implements** as a single atomic commit on the dedicated branch. Stage explicit files. Pre-commit verification (phpunit + phpstan L8 + pint + vitest + chokepoint gate + sentinels — ALL green).
2. **Codex self-adversarial review** → write structured review file at `docs/superpowers/reviews/2026-05-2X-<workstream>-codex-review.md`. Scrutinize the 29+ standing patterns from §6 below.
3. **If BLOCKER / REQUEST-CHANGES:** R2 fix in a follow-up commit; re-self-review. Carry the standing pattern: R2 fixes occasionally introduce new defects in the fix itself — re-self-review R2 too.
4. **Once self-APPROVE:** dispatch Opus second-pass adversarial review subagent. Brief Opus on: the relevant authoritative artifacts + the commit + your Codex review + what you want Opus to spot-check.
5. **If Opus finds anything:** fix + re-review (Codex + Opus).
6. **Once both APPROVE:** stage + commit review files (audit trail) + push.
7. **Open PR** to base `dev` with comprehensive description. Auto-merge if CI passes (`gh pr merge --auto --merge`).
8. **Update roadmap v2 §Phase 1.5** to mark the workstream complete.

### For Phase 3 — full phase cycle pattern

1. **Codex writes Phase 3 spec** at `docs/superpowers/specs/2026-05-2X-pos-charge-to-account-phase3-spec-v1.md`. Grounding: SoT v3 + codebase reality audit + Phase 1 contracts + roadmap v2 §Phase 3.
2. **Codex self-adversarial review** of the spec. Iterate to v2/v3 as needed.
3. **Opus second-pass adversarial review** of the spec. Iterate.
4. **Once both APPROVE:** commit spec + reviews.
5. **Codex writes Phase 3 implementation plan** via `writing-plans` skill at `docs/superpowers/plans/2026-05-2X-pos-charge-to-account-phase3.md`.
6. **Codex self-adversarial review** of the plan. Iterate.
7. **Opus second-pass adversarial review** of the plan. Iterate.
8. **Once both APPROVE:** commit plan + reviews.
9. **Codex executes the plan task-by-task,** same per-task pattern as Phase 2 (Codex implements → Codex self-adversarial → Opus second-pass → fix → push).
10. **Phase 3 closure:** full-flow verification test (analogous to Task 33 / Phase 2 Task 11). Refresh handoff §4 + memory + roadmap v2 §Phase 3 status. Open PR. Merge.

---

## 5. Workstream specifications

### 5.1 — Phase 1.5.2: Per-country tax-number strict validation

**Branch:** `feat/fiscal-phase-1-5-2-per-country-tax-validation` off `origin/dev`.

**Scope:** replace `FiscalPayloadConstraintValidator`'s universal `^[A-Za-z0-9 \-/.]{4,40}$` regex (shipped in Phase 2A.PHP.1 R2) with a country-keyed validator branch on `seller.tax_jurisdiction_country_code`.

**Per-country regex patterns (publicly-documented formats — NO accountant input needed):**

| `country_code` | `seller.tax_number` regex | Format |
|---|---|---|
| **FR** | `^([0-9]{9}\|[0-9]{14})$` | SIREN (9-digit company root) OR SIRET (14-digit SIREN + 5-digit NIC establishment). NF525 v2.1 requires SIRET on the receipt; SIREN acceptable for single-establishment businesses. INSEE definitions + Article 88 LF 2016. |
| **TN** | `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$` | Matricule Fiscal compact form: 7–8 digits (taxpayer #) + 2 letters (category code A/M/N/P + tax-type code M/N/T) + 3-digit establishment number (000 = head office). Slash-separated form `1234567/A/M/000` MUST be normalized to compact at input time. Direction Générale des Impôts Tunisie. |
| **SA** | `^3[0-9]{12}03$` | ZATCA VAT registration: 15 digits, starts with `3` (taxpayer marker), ends with `03` (VAT marker). ZATCA E-Invoicing Detailed Technical Guidelines v2. |
| **DE** | `^DE[0-9]{9}$` | USt-IdNr: `DE` prefix + 9 digits. Bundeszentralamt für Steuern. |
| **IT** | `^[0-9]{11}$` | Partita IVA: 11 digits. Agenzia delle Entrate. |

**For `buyer.tax_number` (when B2B buyer attached — cross-border or domestic):**
- FR: same as seller PLUS TVA Intracommunautaire `^FR[0-9]{11}$` (FR + 2-digit checksum + 9-digit SIREN).
- IT individual case: use the existing separate `buyer.codice_fiscale` field with `^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$` (16-char). Do NOT validate as tax_number.
- Others: same regex as seller (company-level).

**Implementation:**

1. **Validator branch.** In `FiscalPayloadConstraintValidator.php` (the file Phase 2A.PHP.1 + R2 landed), add a private `validateTaxNumber(string $taxNumber, string $countryCode, string $fieldPath): void` helper. Look up the country's regex from a constant table; if country not in table, fall back to the universal pattern (forward-compat for future countries).
2. **TN normalization.** Before applying the TN regex, strip slashes if present (`str_replace('/', '', $taxNumber)`) so both `1234567/A/M/000` and `1234567AM000` inputs are validated correctly. The CANONICAL stored value should be the compact form — the payload assembler at the device side must already produce compact (verify via the device-side validator counterpart in TypeScript).
3. **TS-side mirror.** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` `validateSaleReceiptPayload` + `validateAccountPaymentPayload` must enforce the same country-keyed patterns at the device boundary. Same TN normalization.
4. **Per-country positive/negative test fixtures.** New tests in `FiscalPayloadConstraintValidatorTest.php` — 5 positive fixtures (one per country, valid format) + 5 negative fixtures (wrong format per country) + 1 fallback-to-universal test (unknown country code).
5. **`buyer.codice_fiscale` regex** added to validator with the 16-char pattern; mutual-exclusion test with `buyer.tax_number` (if both present in IT case, owner-prefer the tax_number for VAT-registered companies).
6. **Update synthesis v5 §7** — replace the placeholder TN pattern (`^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$`) with the correct `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$`. Mark the per-country table as LANDED (no longer Phase 1.5 deferred).
7. **Update roadmap v2 §Phase 1.5** — mark Phase 1.5.2 complete with commit SHA.

**Forensic prefixes** for failed validation:
- `payload_tax_number_format_mismatch:field=seller.tax_number:country=FR:value=<actual>`
- `payload_buyer_codice_fiscale_format_mismatch:field=buyer.codice_fiscale:value=<actual>`

**Pre-commit verification:**
```bash
cd <worktree>/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php
./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/        # full Fiscal suite regression
./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/
./vendor/bin/pint --test app/Modules/Fiscal/

cd ../apps/pos
pnpm test src/lib/fiscal/__tests__/FiscalEventEngine.test.ts
pnpm test                                                            # full POS regression
pnpm typecheck
pnpm lint

bash <worktree>/apps/api/scripts/check-saleReceipt-chokepoints.sh
```

**Commit message template:**
```
feat(fiscal): Phase 1.5.2 — per-country tax-number strict validation

Replaces Phase 2A.PHP.1 R2's universal seller.tax_number regex
(^[A-Za-z0-9 \-/.]{4,40}$) with country-keyed validators on
seller.tax_jurisdiction_country_code:

- FR: SIREN (9) or SIRET (14). NF525 v2.1 + INSEE.
- TN: Matricule Fiscal compact ^[0-9]{7,8}[A-Z]{2}[0-9]{3}$. DGI Tunisie.
- SA: ZATCA VAT ^3[0-9]{12}03$. ZATCA Detailed Technical Guidelines v2.
- DE: USt-IdNr ^DE[0-9]{9}$. BZSt.
- IT: Partita IVA ^[0-9]{11}$. Agenzia delle Entrate.

buyer.tax_number adds FR TVA-IC ^FR[0-9]{11}$ when present.
buyer.codice_fiscale (IT individual) validated as
^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$.

TN slash-separated form normalized to compact at validation.
TS-side mirror updated; cross-language drift gate extended.
Forensic prefixes: payload_tax_number_format_mismatch:field=...:country=...:value=...

Unblocks Tunisia + France customer-facing deployment per roadmap v2 §Phase 1.5
deployment gate language. Closes Phase 1.5.2.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

**Expected scope:** ~300-500 LOC. 1-2 review rounds.

---

### 5.2 — Phase 1.5.3: ParseFailureResolution operator UX pre-fill

**Branch:** `feat/fiscal-phase-1-5-3-parse-failure-resolution-ux` off `origin/dev`.

**Scope per roadmap v2 §Phase 1.5:** build an admin tool that reads `fiscal_event_quarantine.raw_envelope`, attempts best-effort parse, pre-fills the structurally-valid 27 keys (for SALE_RECEIPT) or the ACCOUNT_PAYMENT payload keys, lets the operator amend only the broken fields, and submits via the existing `ParseFailureResolutionService::resolve()` (Task 24 R2 service).

**Design:**

1. **Server endpoint.** `POST /api/v1/fiscal/quarantine/{id}/best-effort-parse` — Permission: `fiscal.events.resolve_quarantine` (existing per Task 24 R2). Loads the quarantined `fiscal_event_quarantine` row by id; runs a best-effort parser over `raw_envelope`; returns a JSON response with two sections:
   - `parsed`: the keys that DID parse successfully (with their parsed values).
   - `defects`: structured list of parse failures (forensic prefix + field path + offending value).
2. **Best-effort parser.** A wrapper around `StrictCanonicalParser` that catches per-field failures and aggregates them rather than failing fast. Returns a `BestEffortParseResult` DTO with the partial-payload + the defect-list.
3. **Admin UI surface.** Web admin (NOT POS — this is an operator-facing recovery tool). Page renders the parsed-fields read-only + the defects-list as editable inputs. Operator amends the broken fields; submits via existing resolve endpoint.
4. **Validation.** Submitted corrected payload still validates through `FiscalPayloadConstraintValidator` per Task 24 R2. The pre-fill tool just reduces operator typing — it does NOT bypass validation.
5. **Audit trail.** Pre-fill responses are logged via `Log::info('fiscal.quarantine.best_effort_parse_invoked', [...])` for forensic reconstruction.
6. **Authorization.** Same `fiscal.events.resolve_quarantine` permission as the resolve service. Spatie team-scoped per Task 24 R2 pattern.

**Implementation:**

1. **NEW PHP service:** `apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php`. Constructor injection of `StrictCanonicalParser` (existing).
2. **NEW DTO:** `apps/api/app/Modules/Fiscal/Domain/DTOs/BestEffortParseResult.php`. Fields: `parsed: array<string, mixed>`, `defects: array<ParseDefect>`.
3. **NEW controller:** `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineBestEffortParseController.php`. Endpoint: `POST /api/v1/fiscal/quarantine/{id}/best-effort-parse`. Resource: `BestEffortParseResource`.
4. **Permission:** verify `fiscal.events.resolve_quarantine` exists in `RolesAndPermissionsSeeder` (added in Task 24 R2). Reuse.
5. **Frontend (web admin):** new page at `apps/web/src/pages/admin/fiscal/QuarantineResolveAssistPage.tsx` (or similar — match existing admin page conventions). Calls the new endpoint; renders the form; submits via the existing resolve endpoint.
6. **Tests:**
   - `BestEffortPayloadParserTest` — covers partial-parse cases (one field invalid → that field surfaces as defect; other fields parse successfully).
   - `QuarantineBestEffortParseControllerTest` — endpoint happy path + auth + missing permission + invalid id.
   - Frontend component test (Vitest) — renders parsed fields + defects list; submit handler.

**Out of scope:**
- Auto-submit-on-resolve. Operator MUST review the pre-fill + amend defects + click submit.
- Automatic best-effort across all quarantine rows. Per-row only.
- Bypass of the existing validator. The corrected payload still must pass `FiscalPayloadConstraintValidator`.

**Pre-commit verification:**
```bash
cd <worktree>/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php tests/Unit/Fiscal/BestEffortPayloadParserTest.php
./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/
./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/
./vendor/bin/pint --test app/Modules/Fiscal/

cd ../apps/web
pnpm test src/pages/admin/fiscal/
pnpm test
pnpm typecheck
pnpm lint
```

**Commit message template:**
```
feat(fiscal): Phase 1.5.3 — ParseFailureResolution operator UX pre-fill tool

Reduces operator typing burden on the 27-key SALE_RECEIPT (and ACCOUNT_PAYMENT)
corrected-payload workflow per roadmap v2 §Phase 1.5.

New BestEffortPayloadParser service wraps StrictCanonicalParser to aggregate
per-field defects rather than fail fast. New endpoint POST /api/v1/fiscal/
quarantine/{id}/best-effort-parse returns parsed + defects sections. New
admin page renders the pre-fill form; operator amends defects; submits via
existing ParseFailureResolutionService::resolve() (Task 24 R2). Validation
unchanged — corrected payload still must pass FiscalPayloadConstraintValidator.

Authorization: existing fiscal.events.resolve_quarantine permission per
Task 24 R2 Spatie team-scoped pattern.

Closes Phase 1.5.3.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

**Expected scope:** ~600-900 LOC (PHP service + DTO + controller + frontend page + tests). 1-2 review rounds.

---

### 5.3 — Phase 3: Charge-to-Account (full phase cycle)

**Branch:** `feat/fiscal-phase-3-charge-to-account` off `origin/dev` AFTER Phase 1.5.2 + Phase 1.5.3 merge.

**Scope per roadmap v2 §Phase 3:** the AR-creating side of customer-account flows. POS-core authors `ACCOUNT_CHARGE` events (customer leaves owing); Treasury bridge projects to a Treasury `Payment` row + creates the AR GL posting path (which does NOT exist today per codebase reality audit §2.2 — `createPOSPaymentEntry` is cash→revenue only).

**Sub-scopes:**

1. **`ACCOUNT_CHARGE` event type** — POS-core, device-authored via the Phase 1 engine. Payload shape follows the Phase 2 `ACCOUNT_PAYMENT` precedent (compliance-rich, multi-country future-proof). Same reconciliation classification model.
2. **Settlement-vs-payment-line split** — `ACCOUNT_CHARGE` carries the charge total + the customer attach but does NOT carry payment lines (the customer is NOT paying right now — they're leaving owing). Distinct from `SALE_RECEIPT` which carries payment lines for immediate settlement.
3. **AR GL posting path** — NEW. Currently `createPOSPaymentEntry` posts cash→revenue (DR cash, CR revenue). The new AR path posts DR accounts-receivable + CR revenue. Implemented as a new method on `GeneralLedgerService` (Treasury module) OR a new service on the Treasury bridge depending on the projector seam design.
4. **Treasury bridge for `ACCOUNT_CHARGE`** — per D16 + Phase 1 `TreasuryReceiptBridge` precedent. Pluggable per-`(tenant, company)` via `ModuleActivationResolver`. POS-only deployment still authors `ACCOUNT_CHARGE` events but no AR GL projection.
5. **B2B `Facture` routing** — identified B2B charge-to-account routes through the existing web Facture flow per roadmap v2 §Phase 3 wording: *"identified B2B charge-to-account routes through the invoice flow, not the POS ticket path — B2B-sales-module integration."* Phase 3 sub-task: synced `ACCOUNT_CHARGE` events for B2B-classified customers trigger a Facture draft in the B2B module (when active). For B2C-classified customers (the para-pharmacy use case), no Facture — just the AR posting.
6. **Rules engine** — credit limits per customer (refuse charge if balance + new charge > limit); payment terms (net-30 / net-60 / etc. — affects when the AR row goes overdue).
7. **`ACCOUNT_CHARGE_RECEIPT` printable** — operator hands customer a printed acknowledgment with previous balance + new charge + new projected balance + payment terms.

**Per-phase process (per roadmap v2):**

**Stage A — Phase 3 Spec.** Codex writes spec at `docs/superpowers/specs/2026-05-2X-pos-charge-to-account-phase3-spec-v1.md`. Cover:
- `ACCOUNT_CHARGE` payload shape (cite NF525 + Tunisia + multi-country research).
- Customer classification (B2C vs B2B) — how the POS knows which one to apply. Existing `Partner` model already carries `category` field (CustomerCategory enum); use it.
- AR GL posting path design — extend `GeneralLedgerService::createPOSPaymentEntry` OR new `createPOSChargeEntry`. Decision required: which?
- B2B Facture routing — when synced `ACCOUNT_CHARGE` arrives for a B2B-classified customer, what triggers the Facture draft? Per the roadmap, this is "B2B-sales-module integration" — bridge it via the existing projector seam.
- Rules engine — credit limit enforcement at the POS (offline-safe — the local customer mirror carries the credit_limit field; POS rejects charge if balance + new_charge > limit). Server-side reconciliation if mirror is stale.
- Reconciliation classification: `ACCOUNT_CHARGE` is `offline_authoritative` (the device is source of truth for "this customer was charged this amount"); FIFO allocation + GL posting are `server_reconciles`.

**OWNER-INPUT QUESTION TO SURFACE IN PHASE 3 SPEC REVIEW:**
> Per D8 (Phase 1 owner directive 2026-05-20): "B2B / ZATCA Tax Invoice path TBD later — either Tauri authors Standard Invoice with sequential cbc:ID, OR web B2B flow aggregates POS receipts into proper invoices."
>
> For Phase 3 B2B charge-to-account specifically: the roadmap says "B2B charge-to-account routes through the invoice flow, not the POS ticket path." This implies the web B2B Facture flow consumes the synced `ACCOUNT_CHARGE` event + generates the Facture. The POS does NOT author a Tax Invoice.
>
> **Confirm with owner before Phase 3 spec v1 LOCKED:** does Phase 3 lock this design (POS authors `ACCOUNT_CHARGE`; web B2B consumes it + generates Facture)? OR does Phase 3 keep the D8 question open (Tauri-authored Tax Invoice path remains a future option)?
>
> Recommendation: LOCK the web-B2B-aggregates model for Phase 3. The Tauri POS stays B2C-only per D7. ZATCA Standard Invoice authoring becomes a Phase 4+ ZATCA-specific workstream when SA is implemented.

**Stage B — Phase 3 Plan.** Codex writes plan via `writing-plans` skill. Expected ~15-25 tasks given the scope (event type + payload + device authoring + server projection + AR GL + B2B Facture bridge + rules engine + printable + customer-mirror extension for credit_limit + sync + tests).

**Stage C — Phase 3 Execution.** Task-by-task per the plan. Each task uses the Phase 2 per-task workflow.

**Stage D — Phase 3 Closure.** Full-flow verification test analogous to Phase 2 Task 11:
- Device authors `ACCOUNT_CHARGE` for a B2C customer with sufficient credit → seals → syncs → server ingests + parses + projects (POS-core printable + Treasury bridge AR GL posting).
- Device rejects `ACCOUNT_CHARGE` for a B2C customer with insufficient credit (offline credit-limit enforcement).
- Server-side: B2B `ACCOUNT_CHARGE` event triggers Facture draft in B2B module when active; POS-only deployment skips Facture step.
- Multi-deployment regression test: POS-only (Treasury inactive) → device records `ACCOUNT_CHARGE` + prints receipt + skips Treasury bridge.
- Update roadmap v2 §Phase 3 status. Refresh handoff §4 + memory.

**Expected scope:** ~5-8K LOC across spec + plan + multi-task implementation. ~15-25 plan tasks. ~3-5 review rounds per task on average.

---

## 6. Standing patterns (29+ from Phase 1; carry unchanged)

Read `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md` §4.2 in full. HOT for the remaining work:

- **Dead-path rebuild (Tasks 30 + 32 + PHP.1/PHP.2):** every new code path needs a live caller. Audit with grep AFTER refactors. **Triggered MANY times on this branch — Phase 3 introducing the new AR GL posting path is HIGH risk for this pattern.**
- **Cross-tenant FK safety (Task 21 R2 + Pass 2A.PHP.2 BLOCKER-1):** ALWAYS scope FK lookups by tenant_id. The product FK gap in PHP.2 R1 was caught only by Codex. **Phase 3's customer charge-to-account lookups MUST be tenant-scoped.**
- **Fail-loud over silent-downgrade (Task 22 + PHP.2 R1 BLOCKER-2):** projection failures throw typed exceptions, not silently fall through.
- **Discriminated-union test matrix in round-1 (Task 20):** test every variant. **Phase 3's rules-engine has multiple rejection variants (over-credit-limit, expired-customer, etc.) — exhaustive in round-1.**
- **DB primitives load-bearing (Task 19):** don't substitute "functionally equivalent" alternatives.
- **R2 introduces new defects (Tasks 23/24/25/30/PHP.2):** ALWAYS re-self-review R2 commits.
- **Per-method `markTestSkipped` ONLY (Task 29):** never class-level.
- **Skip-citation accuracy (Task 29):** grep-verify the surviving owner.
- **"Not 410" is not "happy path" (Task 29):** test presence-of-correct-behavior, not absence-of-symptom.
- **CI gate dependency setup in same commit (Task 30):** when extending a CI gate, update job deps in same commit.
- **D16 grep guard pattern (Pass 2A.PHP.2):** projector files MUST NOT directly import Treasury/Customer/B2B/Accounting modules. Use `App\Shared\Contracts\<X>\<Resolver>` interface pattern (PaymentMethodResolver precedent). **Phase 3's AR GL posting path WILL touch Treasury — design it via a Shared\Contracts interface (e.g. `App\Shared\Contracts\Treasury\GlPostingService`), NOT direct Treasury import from POS projector.**
- **Bounded-modules asymmetric seam (D16):** inbound mirror data permitted; outbound operational dependency forbidden. **Phase 3's customer mirror sync for credit_limit is inbound — fine. AR GL posting is outbound — must flow through the projector seam + ModuleActivationResolver.**
- **`Log::spy + shouldHaveReceived` over Mockery (Task 30):** Laravel-native.
- **`Carbon::setTestNow` in try/finally (Task 23 R3):** for time-sensitive tests.

---

## 7. Owner directives D1-D16 (LOCKED — do not re-litigate)

- **D1**: Compliance-rich canonical payload — Phase 3 `ACCOUNT_CHARGE` follows the same multi-country superset pattern as SALE_RECEIPT (Phase 1) + ACCOUNT_PAYMENT (Phase 2).
- **D2**: NO DUAL CHAIN — `ACCOUNT_CHARGE` rides the same `fiscal_events` chain.
- **D3**: NF525 + Tunisia FIRST. ZATCA + DE + IT deferred. Phase 1.5.2 patterns above are designed for this priority.
- **D4**: New event types use `event_version: 1`. `ACCOUNT_CHARGE` starts at v1.
- **D5**: Post-phase audit + drop unused mirror code. Any new mirror columns Phase 3 introduces get a Phase 3-cleanup analog.
- **D6**: No feature flags on dev for cleanly-sequenceable work. Use atomic commits + sentinel files (`.PASS_*_PENDING` pattern) if a multi-commit refactor needs sequencing.
- **D7**: B2C only via Tauri POS. The existing web B2B flow is UNTOUCHED. `ACCOUNT_CHARGE` is B2C-centric in Tauri; B2B Facture generation happens in the web B2B module.
- **D8** (RELATED to Phase 3): ZATCA Tax Invoice path TBD later. For Phase 3, the related question is: who authors the B2B Tax Invoice — Tauri POS OR web B2B module? Recommended LOCK: web B2B (POS just authors `ACCOUNT_CHARGE` event; B2B module generates Facture). See §5.3 Stage A spec-question.
- **D9**: Tunisia priority. NF525-certifiable canonical satisfies Tunisia.
- **D16** (from SoT v3 §13.6): asymmetric bounded-modules seam. Inbound reference data permitted; outbound operational dependency forbidden at the engine layer. **Phase 3's AR GL posting MUST flow through `App\Shared\Contracts\Treasury\<GlPostingService>` interface, NOT direct Treasury import.**

---

## 8. Pre-commit verification (carry from Phase 1/Phase 2)

For EVERY commit:
```bash
cd <worktree>/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/   # full Fiscal + POS feature suites
./vendor/bin/phpstan analyse --level=8 [touched paths]
./vendor/bin/pint --test [touched files]

cd ../apps/pos
pnpm test
pnpm typecheck
pnpm lint

# §14.3 chokepoint gate must still PASS
bash <worktree>/apps/api/scripts/check-saleReceipt-chokepoints.sh

# Pass 2B sentinel — should exit 0 (marker absent post-Phase-2; if still present, something's broken)
bash <worktree>/apps/pos/scripts/check-pass-2b-pending.sh
```

CI PG-merge-gate filter at `.github/workflows/ci.yml:~404` extended in same commit for any new PG-specific fiscal test class.

---

## 9. Status reporting + autonomy

- **Brief the owner once per major milestone:** Phase 1.5.2 landed → Phase 1.5.3 landed → Phase 3 spec landed → Phase 3 plan landed → each Phase 3 task shipped → Phase 3 closure. Concise: 1 paragraph + DONE / files touched / test counts / standing patterns verified.
- **Do NOT ask for confirmation between tasks.** Execute autonomously.
- **Only stop and surface if:**
  - You hit a BLOCKED you cannot resolve (architectural ambiguity, ownership question, scope expansion beyond roadmap v2 §Phase 3).
  - The D8-related Phase 3 spec question (§5.3 Stage A) needs owner sign-off.
  - Context fills to ~70% — write a fresh handover brief mirroring this one and the user starts a new session.

- **Open PRs to base `dev`** for each workstream when ready. Use `gh pr create` with comprehensive descriptions matching the Phase 1 + Phase 2 patterns. Auto-merge via `gh pr merge --auto --merge` once CI passes.

---

## 10. Codex sandbox workaround (carried from Phase 1)

Codex's `apply_patch` is sandboxed to a configured root. If sandbox blocks worktree writes:
- (a) Pre-create stub files via Write tool, then Codex modifies them.
- (b) Return review content INLINE in the agent response; controller (or you) transcribes to disk.

For Codex-acting-as-implementer (full Write tool access via Claude Code CLI), this is less of an issue.

---

## 11. Final notes

- **Push after each task** (after dual review APPROVE).
- **Use Write tool for new files; Edit for modifications** — these always land in the worktree regardless of sandbox config.
- **Per-method skip discipline** — never class-level. Each skip cites the surviving owner + the follow-up task that un-skips.
- **Verify the premise of every deferral** before deferring — grep-confirm the upstream claim.
- **Trust the file** when Codex's wrapper summary diverges from the review file (project memory standing pattern).

You inherit a clean state: Phase 1 + 2 merged to dev; Phase 1.5.1 audit done (RETAIN columns + safe-drop deferred); §14.3 chokepoint gate PASS; Pass 2B sentinel exit 0. Phase 1.5.2 + 1.5.3 are small + bounded. Phase 3 is the next full phase cycle.

End state after this brief executes: Tunisia + France deployment-unblocked; AR side of customer-accounts live; B2C charge-to-account flowing through the engine + projection + GL; B2B charge-to-account routing into the existing web Facture flow per the locked architectural seam. Roadmap v2 §Phase 1.5 + §Phase 3 marked complete. Branch `dev` ready for go-live work after these merge.

Good luck.

— Outgoing controller, 2026-05-21
