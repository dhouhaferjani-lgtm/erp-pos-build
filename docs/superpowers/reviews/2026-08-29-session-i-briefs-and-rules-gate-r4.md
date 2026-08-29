# Session I — adversarial gate r4: strict resolution check of the r3 findings (briefs I-1/I-2 r4 + docs `192be1a75`)

**Reviewer:** tenancy-authz-reviewer (Opus, adversarial, code-grounded)
**Date:** 2026-08-29
**Scope (deliberately narrow, per the dispatch):** resolution status of every r3 finding (`I1-R3-01..08`, `I2-R3-01..04`,
`DOC-R3-01..03`), a transcription diff of the pasted wire shape and the L4 row shapes against r3 §B and against the
code, and the G-3a / first-action question. **No new lines of attack were opened** beyond transcription errors and
contradictions introduced by the r4 edits.
**Prior rounds:** `…-gate-r1.md`, `…-gate-r2.md`, `…-gate-r3.md`.
**Artifacts reviewed:**
1. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I1-onboarding-campaign-BRIEF.md` (r4)
2. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I2-fresh-tenant-guards-BRIEF.md` (r4)
3. docs commit `192be1a7510b26f9efcdcaa428f6f24cddfb912e` in `.worktrees/i-docs` (branch `docs/session-i-process-hardening`,
   parent `cdc54f9f4`) — an amend of the r3-reviewed `02d4c67e3`; the r3→r4 delta is exactly **2 hunks / 2 files /
   1 insertion + 1 deletion each** (`git diff 02d4c67e3 192be1a75`), plus the OWED line in `HANDOVER.md:20`.

**Tree state at review time (changed materially since r3).** Main checkout `/Users/houssamr/Projects/syneriva/apps/erp`
is on `dev` at **`68c698f1a`**, **no merge in progress** (`git rev-parse MERGE_HEAD` → *unknown revision*),
working tree clean apart from the three untracked gate records. The r3 conflict on `.github/workflows/ci.yml` is
resolved and **G-3a is merged**:
```
$ ls apps/api/database/migrations/tenant/ | grep enforce_company_scoped
2026_08_28_100000_enforce_company_scoped_payment_method_codes.php
2026_08_30_100000_enforce_company_scoped_product_skus.php
2026_08_30_100100_enforce_company_scoped_variant_skus.php
2026_08_30_100200_enforce_company_scoped_partner_vat_numbers.php
```
All four are in `git ls-tree HEAD` (tracked, not stray files), and `git show HEAD:.github/workflows/ci.yml |
grep -c ProductSkuCompanyScopeMigrationTest` → `1`. G-12 is also on dev
(`2026_08_30_100300_ensure_units_visible_per_company.php`; `CompanyController.php:189`
`$this->unitsProvisioning->provisionForCompany($company)`).
Both lane worktrees are still at base `cdc54f9f4` with **zero own commits**, and
`git merge-base --is-ancestor cdc54f9f4 68c698f1a` → true, so each brief's `git merge dev` is a pure **fast-forward**
(no conflict is possible). Dependencies still provisioned: `.worktrees/i1-campaign/apps/web/node_modules` and
`.worktrees/i2-fresh-tenant-guards/apps/api/vendor/autoload.php` both exist.

---

## VERDICTS

| Artifact | Verdict |
|---|---|
| **I-1 brief r4** (onboarding campaign) | **DISPATCHABLE** — all 8 r3 findings RESOLVED; 3 MINOR transcription/consistency nits, none blocking |
| **I-2 brief r4** (fresh-tenant guards + ratchet) | **DISPATCHABLE** — all 4 r3 findings RESOLVED; first-action rule now satisfiable |
| **docs commit `192be1a75`** | **DISPATCHABLE** — both MINORs RESOLVED; 1 new MINOR (the annotation is already one merge stale) |

**One line before dispatch:** the briefs are clean — the remaining blocker is **orchestration, not text**: rule 22,
`docs/conventions/09/10/11` and `docs/glossary.md` exist **only on the unmerged `docs/session-i-process-hardening`
branch**, and both briefs order the implementer to read them after `git merge dev` (see NOTE-1) — promote
`192be1a75` to `dev` **before** the lanes run their first action.

---

# A. r3 finding resolution — strict

## I-1 (I1-R3-01..08)

| r3 finding | Status | Resolving line / evidence |
|---|---|---|
| **I1-R3-01** (BLOCKER) hash contract one level too shallow | **RESOLVED** | I-1 `:63` now reads *"`canonical_bytes` is the canonical encoding of a 15-key WRAPPER with the receipt body nested under `payload`"* and *"The golden's `expected_canonical_string` is the PAYLOAD only (30 keys v5 / 33 keys v4) — it proves the encoder, it is NOT what goes on the wire."* `:66-83` paste the full request shape; `:83` gives the encoder call with all 15 keys and the four regex pins. **Transcription verified byte-level — see §B.** |
| **I1-R3-02** (MAJOR) L4 missing `type` + `account_code` + GL-match | **RESOLVED** | I-1 `:55` — `{ type: "ACCOUNTING", name, cutover_date, source_system? }`, *"`account_code` is REQUIRED and must be the repository's linked GL account (read it from `GET /api/v1/payment-repositories` first)"*, the debit-only rule, the never-traded rule, one-repository-per-batch, and the automatic `OpeningBalanceEquity` leg. **Verified against code — see §C.** (One naming nit → I1-R4-03.) |
| **I1-R3-03** (MAJOR) L6 chain model + async projection | **RESOLVED** | I-1 `:57` — *"Chains are per `(tenant, company, terminal, chain_context)`: `SALE_RECEIPT` is `chain_context = operational`; `SESSION_OPEN` is `z_session` — a DIFFERENT chain, and NOT a prerequisite (`PosCoreReceiptProjection` never references a shift) — so L6 sends ONLY `SALE_RECEIPT` … at `sequence_number = 1`, `previous_hash = genesis_seed`"*, plus *"**Projection is ASYNCHRONOUS** (`ApplyFiscalEventProjectionJob` on queue `fiscal-projections`)"* and a mandated `pollUntil(fn, timeoutMs)` helper; `:94` puts the worker reminder in the wrapper script and defines the ledger row `FAIL(projection timeout — worker running?)`. Re-verified in code: `ApplyFiscalEventProjectionJob.php:172` `$this->onQueue('fiscal-projections')`; `config/queue.php:16` `'default' => env('QUEUE_CONNECTION', 'database')` (non-sync); `config/horizon.php:209` lists `fiscal-projections` in `defaults.*.queue`, so the precondition is satisfiable. (Wording nit → I1-R4-02.) |
| **I1-R3-04** (MAJOR) wrong terminal route + invented `/claim` | **RESOLVED (one wrong word)** | I-1 `:57` — *"`POST /api/v1/pos/terminals` (`TerminalController::store()`: returns an ACTIVE `fiscal_schema_version = 3` terminal with `v4_refund_authoring_enabled = true` … **no `/claim` step**; do NOT use `/pos/terminals/web`, which mints a schema-v2 Web terminal)"*. Verified: `TerminalController::store()` sets `'fiscal_schema_version' => 3, 'is_active' => true, 'activated_at' => now()`, `genesis_seed = bin2hex(random_bytes(32))`, then `$terminal->v4_refund_authoring_enabled = true` before `save()`, and returns `TerminalResource::make(...)` 201. The `pos_enabled` precondition is real (`if (! $this->locationHasPosEnabled(...)) return $this->posDisabledResponse();`). **But the permission name is wrong → I1-R4-01.** |
| **I1-R3-05** (MINOR) goldens have no `payload` key | **RESOLVED** | I-1 `:85` — *"(keys `_comment`, `event_type`, `event_version`, `expected_canonical_string`, `expected_sha256_hex` — the body is `JSON.parse(expected_canonical_string)`; there is no `payload` key)"*. Re-verified by parsing both fixtures: key sets are exactly those five; bodies are 30 keys (v5) and 33 keys (v4). |
| **I1-R3-06** (MINOR) redundant Node crypto | **RESOLVED** | I-1 `:85` — *"`FiscalEventCanonicalEncoder` already exposes `sha256Hex` — use it for `current_hash`; Node `crypto` only as a cross-check in L0a."* Verified: `HashChainIntegrityProvider.ts` `computeHash()` is literally `this.encoder.sha256Hex(canonicalBytes)`. |
| **I1-R3-07** (MINOR) refund `event_type` unnamed | **RESOLVED** | I-1 `:58` — *"`event_type = SALE_RECEIPT`, `event_version = 4`, `invoice_type_code = REFUND` (NOT `REFUND_RECEIPT`…)"*. The **new** `invoice_type_code` claim checks out: `FiscalPayloadConstraintValidator.php` — `if ($eventVersion === 4) { … if ($invoiceType !== 'REFUND') throw … 'payload_invoice_type_invalid:event_version=4 requires invoice_type_code=REFUND' }`, with `VOID` rejected outright above it. The v4 golden's own header is `"event_type": "SALE_RECEIPT", "event_version": 4`. |
| **I1-R3-08** (MINOR) EUR/scale-2 golden vs TND/scale-3 campaign | **RESOLVED** | I-1 `:85` — *"the v4 refund golden is **EUR at `currency_scale` 2** while the campaign is TND scale 3, so also change `currency_code`, `currency_scale`, every amount string to scale 3, and use a `method_code` that exists in the fresh tenant's seeded payment methods."* |

## I-2 (I2-R3-01..04)

| r3 finding | Status | Resolving line / evidence |
|---|---|---|
| **I2-R3-01** (BLOCKER) `tenants:run` discards the exit code; bare `--option=fail-on-drift` is invalid | **RESOLVED** | I-2 `:22` — *"the direct tenant-bound invocation `php artisan tenant:census-day-one --fail-on-drift` exits 1 on any multi-company tenant (under `tenants:run` the child exit code is DISCARDED by Stancl `Run.php` — the operator reads the printed verdict line, `RUNBOOK-orphaned-shift.md:130-132`; and boolean options must be passed as `--option='fail-on-drift=1'`, never bare)"*; `:37` repeats it and mandates the greppable verdict line `DAY-ONE CENSUS <tenant> <company>: CLEAN\|DRIFT(n)`; `:39` requires the RUNBOOK to carry **both** forms with the caveat; verification step `:56` uses the `key=value` form. Re-verified in vendor: `stancl/tenancy/src/Commands/Run.php` `handle()` calls `tenancy()->runForMultiple(…, fn => $this->call(…))` and returns nothing → always exit 0; the `[$key, $value] = explode('=', $argument, 2)` parser is unchanged. Mechanics of the prescribed form independently checked — see NOTE-2. |
| **I2-R3-02** (MAJOR) G-3a interlock under-specified | **RESOLVED, and now moot in the good direction** | I-2 `:4` — *"**I-2 lands AFTER G-3a** — if `git merge dev` does not bring `database/migrations/tenant/2026_08_30_100000_enforce_company_scoped_product_skus.php`, STOP and report (G-3a removes three of the indicative baseline entries: `products(tenant_id,sku)`, `product_variants(tenant_id,sku)`, `partners(tenant_id,vat_number)`; `product_variants(tenant_id,barcode)` survives by RUL-2)"*; `:46` adds *"generate the baseline only AFTER G-3a is in your tree (first-action rule); if G-3a somehow lands after your baseline, the merge that lands it must delete the three now-stale entries in the same commit (orchestrator's job — say it in your summary)."* **The first-action rule is now satisfiable** — see §D. The RUL-2 barcode claim is correct: `2026_08_30_100100_enforce_company_scoped_variant_skus.php` touches only `sku` (`COMPANY_COLUMNS = ['company_id','sku']`, `TENANT_COLUMNS = ['tenant_id','sku']`, partial index `ON product_variants (company_id, sku) WHERE deleted_at IS NULL`); no `barcode` index is dropped. |
| **I2-R3-03** (MINOR) pin the sole red channel | **RESOLVED** | I-2 `:22` — *"**`DayOneCensusCommandTest::test_second_company_without_repositories_fails_invariant_5_KNOWN_GAP_I2_F1` is the ONLY red channel; say so in its docblock so no later lane deletes/renames it**"*, and `(c)` explicitly demotes the `FreshTenantCensusInvariantsTest` incomplete to *"documentation, not the guard"*. |
| **I2-R3-04** (MINOR) cite the in-repo template | **RESOLVED** | I-2 `:45` — *"template to copy: `tests/Architecture/EnumCheckParityTest.php` — `RefreshDatabase` + PG-only self-skip + JSON baseline in that directory; **copy its structure, NOT its CI wiring, which is absent** — the allowlist instruction above is the wiring"*. Re-verified on dev: `EnumCheckParityTest.php:8` imports and `:98` `use RefreshDatabase;`; `apps/api/tests/Architecture/baselines/` holds 5 artifacts. |

**Pre-declared finding I2-F1 re-verified on the CURRENT dev (it survived the G-3a/G-12 merges):** `CompanyController::store()`
seeds chart (`:170`), payment methods (`:176`), expense categories (`:184`), taxes (`:187`) and units (`:189`) — and
never `PaymentRepositorySeeder`. The only caller in the whole app is
`app/Modules/Tenant/Application/Services/TenantInitializationService.php:315` (`new PaymentRepositorySeeder`). So the
brief's pre-declared RED for invariant 5 on company 2 is still the true state of the tree.

## Docs (DOC-R3-01..03)

| r3 finding | Status | Resolving line / evidence |
|---|---|---|
| **DOC-R3-01** reciprocal glossary pointer neither committed nor marked owed | **RESOLVED** | `docs/sessions/session-I-process-hardening-2026-08-29/HANDOVER.md:20` — *"OWED (owner/parent repo): commit the reciprocal ERP-glossary pointer `claude/glossary.md` §"ERP domain terms" in the parent `syneriva` repo — left as an uncommitted working-tree change because that checkout sits on another session's branch `fix/automotive-country-scoping-semantics`."* That is the r3-required remedy verbatim, with the reason stated. |
| **DOC-R3-02** G-3a time-bombs in `09-SECOND-OF-EVERYTHING.md` / `glossary.md` | **RESOLVED (already one merge stale → DOC-R4-01)** | `09-SECOND-OF-EVERYTHING.md:19` gains *"; **G-3a (in flight 2026-08-29) removes `products` sku, `product_variants` sku and `partners` vat_number on merge**"*, and `glossary.md:36` gains *"(in flight 2026-08-29 — on merge the products/variant SKU and partner VAT-number keys become company-scoped; `product_variants` barcode stays tenant-wide by RUL-2)"*. Both are the exact annotations r3 asked for. |
| **DOC-R3-03** additivity (NOTE) | **HOLDS** | `git show --numstat 192be1a75`: `13 2` on `SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md` only; every other file is `N 0` (the two amended files were *created* in this commit, so the r3→r4 one-line edits do not introduce deletions). `CLAUDE.md` is still `9 0` — rule 22 appended, no renumbering. |

---

# B. Transcription diff — the pasted wire shape (I-1 `:66-83`) vs r3 §B(i)-3 vs the code

**No transcription error found.** Field-by-field:

| Element in the brief | r3 §B(i)-3 | Code | Verdict |
|---|---|---|---|
| `envelopes` 1..100 | same | `IngestFiscalEventsRequest::rules()` `'envelopes' => ['required','array','min:1','max:100']` | ✅ |
| `envelope_id` / `type: "FISCAL_EVENT"` / `payload_version: 1` / `idempotency_key` | same | same rules: `envelope_id` required string, `type` `in:FISCAL_EVENT`, `payload_version` `integer,min:1`, `idempotency_key` required string, `payload` required array | ✅ |
| transport-only `id`, `current_hash`, `canonical_bytes`, `last_server_time_seen`, `source_event_class`, `source_event_id` | same | `FiscalEventEnvelope::fromArray()` — `requireString` for the first three, `optionalString` for the last three | ✅ |
| **14** sealed coordinates listed (`tenant_id, company_id, terminal_id, operator_id, event_type, event_version, signature_version, sequence_number, event_time_device, business_date, chain_context, reference_event_id, reference_document_id, previous_hash`) | same list | all present in `fromArray()`; count is exactly 14 | ✅ |
| canonical wrapper **15** keys, alphabetical: `business_date, chain_context, company_id, event_time_device, event_type, event_version, operator_id, payload, previous_hash, reference_document_id, reference_event_id, sequence_number, signature_version, tenant_id, terminal_id` | same | `StrictCanonicalParser::ENVELOPE_KEYS` (`:78-94`) is that exact 15-element list, enforced both ways (`envelope_field_missing:` on missing, `envelope_extra_field:` on extras); device side `FiscalEventEngine.ts` `canonicalPayload` literal is the same 15 keys in the same order | ✅ |
| `signature_version: "hash-chain-integrity-v1"` | same | `HashChainIntegrityProvider.ts` `version()` returns `'hash-chain-integrity-v1'` | ✅ |
| regex pins: UUID lowercase, `^[0-9a-f]{64}$`, `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$`, `^\d{4}-\d{2}-\d{2}$` | same | `FiscalEventEnvelope` consts `UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/'`, `HEX_64_REGEX`, `ISO_8601_UTC_SECONDS_REGEX`, `ISO_8601_DATE_REGEX`, applied in `assertWireShape()` | ✅ (the brief's `<uuid v4, lowercase>` is *stricter* than the code, which does not pin the version nibble — harmless) |
| `chain_context` one of operational / z_session / training_* | same | `assertWireShape()` `in_array($this->chainContext, self::CHAIN_CONTEXTS, true)` with `CHAIN_CONTEXTS` const | ✅ |
| vendoring facts (`canonicalCore.ts` 108 lines / 0 imports; `FiscalEventCanonicalEncoder.ts` 163 lines / only `./canonicalCore`) | same | `wc -l` → 108 / 163; `grep -c "^import\|require("` on `canonicalCore.ts` → 0; the encoder's single `import` is at `:23` | ✅ |

Two structural facts the brief conveys correctly but only implicitly: the receipt **body never travels as its own wire
field** (it exists on the wire solely inside the `canonical_bytes` string — `FiscalEventEnvelope` has no body
property, and `mergeOuterIntoPayload()` only folds `envelope_id`/`idempotency_key`/`payload_version` into the flat
array); and `last_server_time_seen`, if ever non-null, must itself match the UTC-seconds regex. Neither needs a text
change — the brief's `null` default is the safe form.

# C. Transcription diff — the L4 row shapes (I-1 `:55`)

| Claim in the brief | Code | Verdict |
|---|---|---|
| create body `{ type, name, cutover_date, source_system? }`, `type: "ACCOUNTING"` | `OpeningBalanceBatchController::store()` — `'type' => ['required','string','in:'.implode(',', array_column(OpeningBatchType::cases(),'value'))]`, `'name' => ['required','string','max:255']`, `'cutover_date' => ['required','date']`, `'source_system' => ['nullable','string','max:100']`; `OpeningBatchType::Accounting = 'ACCOUNTING'` | ✅ |
| `rows[].account_code` REQUIRED; `debit` string `"1000.000"`; `repository_code` optional | `import()` `OpeningBatchType::Accounting` branch — `'rows.*.account_code' => ['required','string']`, `'rows.*.debit'`/`'credit'` `['nullable','numeric','min:0','regex:/^-?\d+(\.\d{1,3})?$/']`, `'rows.*.repository_code' => ['nullable','string','max:50']` | ✅ (scale-3 regex ⇒ money-as-string is mandatory, consistent with the brief's `:89` string-comparison rule) |
| GL-account-match refusal wording | `AccountingOpeningService.php:356` — `"Payment repository '{$code}' is linked to GL account {$expected}; the opening float …"` | ✅ |
| unknown/inactive repository refusal | `:331` — `"Payment repository '{$code}' was not found or is inactive."` | ✅ |
| debit-only, never-traded, one-row-per-repository | `validateRepositoryColumn()` (`:311`) + `flagDuplicateRepositoryRows()` (`:390`) | ✅ |
| *"read it from `GET /api/v1/payment-repositories` first"* | `PaymentRepositoryController::index()` eager-loads `glAccount:id,code,name` and `formatRepository()` emits `'gl_account' => $repository->glAccount?->only(['id','code','name'])` | ✅ actionable — but the field is nested `gl_account.code` (→ I1-R4-03) |

# D. G-3a and I-2's first-action rule

**Satisfiable — yes, and now trivially so.** All four `enforce_company_scoped_*` migrations are tracked at `dev`
`68c698f1a`, including the exact file the brief names as the STOP trigger
(`apps/api/database/migrations/tenant/2026_08_30_100000_enforce_company_scoped_product_skus.php`). The i-2 worktree is
at `cdc54f9f4`, which is an ancestor of `68c698f1a` with no local commits, so `git merge dev` **fast-forwards** — the
brief's "if the merge conflicts, STOP" branch cannot fire, and the "if it does not bring the G-3a migration, STOP"
branch cannot fire either. The baseline can therefore be generated against a post-G-3a schema on the first attempt,
and `:46`'s stale-entry contingency stays a contingency. The same is true for i-1.

Corollary for I-2's indicative baseline: `products(tenant_id,sku)`, `product_variants(tenant_id,sku)` and
`partners(tenant_id,vat_number)` **will not be in the generated set** — 13 indicative entries become **10**, exactly as
`:4` predicts. `product_variants(tenant_id,barcode)` remains (the variant migration touches `sku` only).

---

# E. Findings (r4)

### MINOR

- **I1-R4-01** — `LANE-I1-onboarding-campaign-BRIEF.md:57` — the brief says `POST /api/v1/pos/terminals` *"needs
  `pos.operate_terminal` (admin has it)"*. The handler gates on a **different** permission:
  `TerminalController::store()` opens with `Gate::authorize('pos.manage_terminals');`. **Why it matters:** the campaign
  itself will not break — the registration path assigns `admin`
  (`TenantInitializationService.php:189` `$user->assignRole('admin')`) and the seeder gives `admin`
  `Permission::all()` (`RolesAndPermissionsSeeder.php:545`), and both permissions are seeded (`:363-364`) — but anyone
  debugging a 403 on this leg, or re-pointing the campaign at a non-admin operator, is sent to the wrong gate.
  **Fix:** one word — `pos.manage_terminals` (note `pos.operate_terminal` is still the correct permission for the
  ingest route `POST /api/v1/pos/sync/fiscal-events`, so keep both names distinct in the same sentence).
- **I1-R4-02** — `:57` says the queue-worker precondition is one *"the script checks by polling"*, but `:94` defines
  the wrapper script as only *printing a reminder*, with the polling done per-leg by `pollUntil` and surfaced as
  `FAIL(projection timeout — worker running?)`. Internal contradiction introduced by the I1-R3-03 edit. **Fix:** say
  "the legs poll; the script only warns", or make `:94` actually probe.
- **I1-R4-03** — `:55` — *"read it from `GET /api/v1/payment-repositories` first"* is correct but the response field is
  nested: `gl_account.code` (`PaymentRepositoryController::formatRepository()`), not a flat `account_code`. Naming it
  saves a lookup round.
- **DOC-R4-01** — `docs/conventions/09-SECOND-OF-EVERYTHING.md:19` and `docs/glossary.md:36` now say G-3a is
  *"in flight 2026-08-29 … on merge"*. As of `dev` `68c698f1a` G-3a **is merged**, so the annotation is already one
  merge stale and will land on `dev` describing a future that is past. **Fix (one line each, in the same amend):**
  change the tense — *"G-3a (merged 2026-08-30) removed `products` sku, `product_variants` sku and `partners`
  vat_number; `product_variants` barcode stays tenant-wide by RUL-2"* — and drop those three from the "live tenant-wide
  uniques **today**" list in `09:19`, since that row is the one the I-2 ratchet baseline is read against.

### NOTES (not findings — orchestration / advisory)

- **NOTE-1 (dispatch sequencing, applies to BOTH lanes).** `CLAUDE.md` rule 22 does **not** exist on `dev`
  (`git show dev:CLAUDE.md | grep "^### 2[0-9]"` → only 20 and 21), and `git ls-tree dev docs/conventions/` contains
  `01`–`08` + README only — no `09-SECOND-OF-EVERYTHING.md`, `10-BENCHMARK-FIRST-SPECS.md`,
  `11-ONE-SURFACE-PER-CONCEPT.md`, and no `docs/glossary.md`. `git merge-base --is-ancestor 192be1a75 dev` → **not
  merged**. Yet I-1 `:12` and I-2 `:12` both order *"Read `CLAUDE.md` rules … 22"*, and I-2 `:49` tells the implementer
  to reconcile against `docs/conventions/09-SECOND-OF-EVERYTHING.md`. After `git merge dev` those files are **absent
  from both worktrees** — the known "uncommitted docs are invisible in a fresh worktree" trap. This is not a brief-text
  defect (the text is right; the branch is behind). **Promote `docs/session-i-process-hardening` (`192be1a75`) to `dev`
  before either lane runs its first action**, or paste rule 22 + convention 09 into the worktrees.
- **NOTE-2 (the I2-R3-01 remedy mechanically works — with one implementation caveat).** I traced the prescribed form:
  `tenants:run … --option='fail-on-drift=1'` → `Run.php`'s reducer produces `['--fail-on-drift' => '1']` → `$this->call()`
  → Symfony `ArrayInput::addLongOption()`, which for a `VALUE_NONE` option with a non-null value simply stores it
  (`$this->options[$name] = $value;`) and does **not** throw. So `$this->option('fail-on-drift')` returns the string
  `'1'`. **Caveat for the implementer:** the command must treat it truthily —
  `if ($this->option('fail-on-drift'))` or `(bool) $this->option(...)`; a `=== true` comparison would silently ignore
  the flag under `tenants:run` while working for the direct invocation. Worth one clause in the command's docblock.
- **NOTE-3 (stale ~line, resolvable).** I-2 `:16` points at the `backend-test-pgsql --filter` line *"at ~`:1093`"* with
  a precedent comment block at `:1070-1092`. On today's `dev` the filter line is `.github/workflows/ci.yml:1109`
  (G-3a appended tokens), and there is now a **second** `--filter='/\(…)::/'` line at `:1233` belonging to the
  `t6-phase0b-pgsql` job (`:1138`) which already carries `TenantReferenceDataSeedingTest` — i.e. a plausible wrong
  target for `tests/Feature/Tenant/*` classes. The brief names the job (`backend-test-pgsql`) twice and `:3` disclaims
  line numbers, so this is resolvable as written; flagged only so the reviewer of the resulting diff checks *which*
  filter line grew.

---

## What to fix before dispatch

**Orchestrator (blocking-in-practice, not brief text):** merge `192be1a75` (`docs/session-i-process-hardening`) into
`dev` first, so rule 22 / conventions 09–11 / `docs/glossary.md` exist in both lane worktrees after their `git merge dev`;
while amending, retense the two G-3a annotations (DOC-R4-01).
**I-1 (optional, 3 one-liners):** `pos.manage_terminals` instead of `pos.operate_terminal` at `:57`; reconcile
"script checks by polling" (`:57`) with "script prints a reminder" (`:94`); say `gl_account.code` at `:55`.
**I-2:** nothing. Dispatch as-is.
