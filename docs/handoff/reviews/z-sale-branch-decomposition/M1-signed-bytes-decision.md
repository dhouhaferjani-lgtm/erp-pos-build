# M1 — Signed-bytes decision memo: `event_version` bump vs. in-place semantic correction

Wave `z-sale-branch-decomposition` · milestone M1 · owner gate `z-signed-bytes-versioning` ·
base `9d14cb8e1afae9b9cd3e5afa9d8bfac8dd2aabd3`.

**This memo presents a decision. It does not take one.** Per brief R-1 and the ticket
(`docs/superpowers/tickets/2026-08-01-device-z-sale-branch-gross-as-net.md:16-22`), the A/B ruling
belongs to the parent-dispatched `fiscal-pos-reviewer` specialist gate. §6 states a
recommendation because the brief asks for one (M1 item 5); it is advisory only. Every line number
below was re-derived at `base_sha`.

---

## 0. Executive summary

| | |
|---|---|
| **What changes** | The **values** inside `vat_breakdown[].net_amount` and `.gross_amount` on signed `Z_REPORT`, `X_REPORT` **and `SESSION_CLOSE`** events, for any shift containing at least one taxed sale line. No key is added, removed or renamed. |
| **What does not change** | Payload key sets; `vat_amount`; `tax_rate`; every headline total; the hash-chain mechanics; grand totals (`perpetual_grand_total` et al.). |
| **Second chain also affected** | The **legacy `z_reports` fiscal-hash chain** — `computeZReportHash` hashes the whole `report_data`, which contains `vat_breakdown`. That chain has **no `event_version` at all**, so Option A cannot make it self-describing. (§1.4) |
| **Three findings the brief does not carry** | (F-1) `SESSION_CLOSE` is a third affected signed type. (F-2) **The brief's "headline totals are correct" premise is FALSE** — `net_sales` is independently wrong in the same signed bytes. (F-3) A fourth gross-as-net site exists in `zReportService.ts` itself (not signed). See §5. |
| **Signed-history evidence** | **Nothing in the repository demonstrates any signed Z/X history exists** — no seeder, no fixture, no migration, no golden vector. That is not the same as proving none exists; device-local unsynced chains are unreachable from here. (§3) |
| **Precedent** | The identical correction was already shipped **in place, at v1, with no discriminator**, on the refund branch — commit `77283d2a5` (2026-08-01). (§4) |

---

## 1. The exact byte-level consequence

### 1.1 The defective expressions

Three structurally separate consumers of `offline_receipts.lines[]` compute the per-rate
decomposition. All three sale branches do the same wrong thing:

| Site | Expression at `base_sha` |
|---|---|
| `apps/pos/src/lib/offline/zReportService.ts:907-914` | `lineVat = line.tax_amount`; **`lineNet = line.line_total`**; `lineGross = bcadd(lineNet, lineVat)` |
| `apps/pos/src/lib/offline/endOfDayPreview.ts:301-307` | semantically identical (brief cites `:297-304` — **+4 stale**, drift D-2) |
| `apps/pos/src/api/reportApi.ts:491-496` | semantically identical, **structurally not**: it binds no `lineGross` local, inlining `bcadd(lineNet, lineVat)` inside the accumulator at `:496`. **M2 note (R-2):** the third diff must be *normalised* to the two-local shape, not transcribed — R-2 requires a reviewer reading all three side by side to see one pattern, and today the third site does not have the same shape to begin with. |

`line_total` is **GROSS/TTC**: `cartStore.recalcLineTotal()` (`apps/pos/src/stores/cartStore.ts:179`)
computes `grossTotal = bcmul(unit_price, qty, decimals)` from a tax-INCLUSIVE `unit_price`, and
`computeTaxAmount()` (`:163-173`) **extracts** the tax out of that figure. `receiptService.ts:536-539`
copies both values verbatim onto the SQLite row.

So for a line of gross 12.00 at 20 % VAT (vat 2.00, true net 10.00), the sale branches book
**net 12.00 / vat 2.00 / gross 14.00** instead of **net 10.00 / vat 2.00 / gross 12.00**.
Per taxed line: **net overstated by exactly the VAT amount, gross overstated by the same.**
`vat_amount` is correct; untaxed lines (`tax_amount = 0`) are unaffected.

### 1.2 Which signed events carry these bytes

`aggregateReportData` returns `vat_breakdown` (`zReportService.ts:967-976`, `:995`). It is mapped
into the Z close input at `zReportService.ts:684-689` and reaches **three** signed payload
builders:

| Signed event type | Builder | Line |
|---|---|---|
| `Z_REPORT` | `buildZReportPayload()` | `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:498` |
| **`SESSION_CLOSE`** | `buildSessionClosePayload()` | `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:440` |
| `X_REPORT` | `buildXReportPayload()` | `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:392` |

The X path is fed independently by `reportApi.ts:520-527` → `:568-573` → `appendXReport`
(`zSessionAuthoring.ts:602-631`).

Each builder's output becomes `canonicalPayload.payload` in
`FiscalEventEngine.append()`, is JCS-encoded and SHA-256 hashed, and the hash chains into
`previous_hash`/`current_hash`. **`vat_breakdown` is inside the hash-chained bytes**, and is
required-present server-side (`ZReportPayload.php:38`, `XReportPayload.php:26`; every key is
mandatory because `validatePayloadKeySet` rejects both missing and extra keys,
`FiscalPayloadConstraintValidator.php:447-486`).

**F-1 (not in the brief): `SESSION_CLOSE` is a third affected signed event type.** The brief, the
ticket and LEDGER C-2 all name only `Z_REPORT` and `X_REPORT`. Whatever the gate rules must apply
to `SESSION_CLOSE` in lockstep, or a Z-session's two legs will disagree with each other inside one
sealed chain.

### 1.3 What a verifier comparing an old and a new event would see

- **Same key set, same ordering, same types.** Only two numeric strings per taxed rate row differ.
- `net_amount` differs by exactly `vat_amount`; `gross_amount` differs by exactly `vat_amount`;
  `vat_amount` and `tax_rate` are byte-identical.
- The internal identity flips from **`gross = net + vat` computed from a gross-valued `net`**
  (i.e. `gross_amount = 2 × true_gross − true_net`, which satisfies no meaningful invariant) to the
  correct **`net = gross − vat`**.
- **A verifier has nothing in the bytes to tell it which convention it is reading.** `event_version`
  is `1` on both (`FiscalEventPayloadRegistry.ts:245` returns a flat `1` for every implemented
  non-`SALE_RECEIPT` type; the server registry agrees at
  `FiscalEventPayloadRegistry.php:93-94`). The only usable discriminator today is the event's own
  timestamp against a build-rollout date — i.e. out-of-band knowledge.
- **The hash still verifies.** `Nf525DataProvider::isTrustedFiscalEvent()` and
  `VerifyEventChainCommand`'s `sealedCoordinateMismatches()` re-hash `canonical_bytes` and compare
  to `current_hash`. Both are version-agnostic and both PASS on old and new events alike — the
  wrongness is *semantic*, not integrity. **No existing verifier detects it, under either option.**
- **Nothing structural validates `vat_breakdown` at all.** `validateZReportFamilyPayload` — TS
  `FiscalEventEngine.ts:3748-3760` and PHP `FiscalPayloadConstraintValidator.php:764-771` — checks
  the top-level key set, four UUIDs, `business_date` and `training_flag`, and **never iterates
  `vat_breakdown`**. A Z can be sealed today with an empty or malformed breakdown. (Contrast
  `SALE_RECEIPT`, which enforces non-empty and per-row keys.)

#### 1.3a 🚨 The fix MOVES the payload's internal contradiction — it does not remove it (gate-r2 finding 2)

This is the consequence the gate most needs for ruling question 3, and it holds **under both
options**, because it is a property of the fix itself, not of how it is versioned.

`net_sales` is `Σ receipt.subtotal` (`zReportService.ts:900`) and `subtotal` is `Σ line_total` —
i.e. **gross** (F-2; `receiptService.ts:145-158`, stored `:547`). On a one-line shift of 12.00
gross / 2.00 VAT / 10.00 true net:

| | `gross_sales` | `net_sales` | `Σ gross_amount` | `Σ net_amount` | Which identity holds |
|---|---|---|---|---|---|
| **Today** | 12.00 | **12.00** (wrong — F-2) | **14.00** (wrong) | **12.00** (wrong) | `Σ net_amount == net_sales` ✓ · `Σ gross_amount == gross_sales` ✗ |
| **After this fix, F-2 unfixed** | 12.00 | **12.00** (still wrong) | 12.00 ✓ | 10.00 ✓ | `Σ gross_amount == gross_sales` ✓ · **`Σ net_amount == net_sales` ✗ — off by the shift's full VAT** |
| **After both fixes** | 12.00 | 10.00 | 12.00 | 10.00 | both ✓ |

So today the two wrong numbers agree with each other and the gross column is the visible
contradiction; after this lane's fix in isolation, the per-rate rows become correct and the
**headline `net_sales` becomes the visible contradiction**, disagreeing with `Σ net_amount` by the
entire VAT of the shift.

**This is operator- and server-visible, not theoretical.** `apps/pos/src/components/pos/ZReportModal.tsx:158-159`
renders `gross_sales`/`net_sales` directly above the per-rate table at `:176-193`, and
`ZReportProjection.php` copies both into one row (`:149` vs `:154`).

**And the codebase already treats the analogous identity as canonical.**
`Σ vat_breakdown[].net_amount == subtotal` is a **hard, server-enforced invariant for
`SALE_RECEIPT`** (`FiscalPayloadConstraintValidator.php:1097-1098`, enforced `:1139-1166`). The Z
family escapes it only because `validateZReportFamilyPayload` (`:764-771`) never iterates the
breakdown (F-4). If that validation is ever extended to the Z family — which F-4 suggests it should
be — a post-fix, F-2-unfixed Z would **fail** it.

**What this means for the ruling:** answering ruling question 3 with *"F-2 is out of scope, ticket
it"* is a legitimate call, but it must be made knowing that **M2 would then ship a signed Z whose
headline and per-rate rows disagree by the full VAT of the shift**. That is arguably more
defensible than today's state (two consistent wrong numbers) because the per-rate rows become
correct — but it is a **new** inconsistency in a sealed record, and it should be chosen, not
inherited.

### 1.4 A second, separate chain also changes — and it has no version field

`computeZReportHash()` (`apps/pos/src/lib/fiscal/zReportHashService.ts`) hashes
`JSON.stringify(normalizeForHash(report_data))`, and `report_data` **contains `vat_breakdown`**
(`zReportService.ts:995`, hashed at `:445-451`). `normalizeForHash` does not touch
`vat_breakdown`, so the values pass through verbatim into the legacy `z_reports` chain hash.

This matters for Option A specifically: **the legacy Z chain carries no `event_version`.** Its only
version discriminator is `report_data.schema_version`, and the device implementation carries an
explicit warning against bumping it — *"Deliberately NOT tied to a `schema_version` bump: bumping
would re-normalize `refunds_amount` through the server's schema ≥ 3 key list and break parity"*
(`zReportHashService.ts`, `cash_rounding_summary` comment block). So **an `event_version` bump makes
the fiscal-event chain self-describing and leaves the legacy Z chain exactly as ambiguous as
Option B leaves both.** Option A buys a discriminator on one of the two affected chains.

**Grand totals are NOT affected.** `zReportService.ts:459-465` advances
`cumulative_sales`/`cumulative_tax`/`cumulative_refunds`/`perpetual_grand_total` from
`gross_sales` / `tax_amount` / `refunds_amount` — headline scalars, never `vat_breakdown`. The
perpetual grand total is untouched by this fix under either option.

### 1.5 Blast radius

Every shift containing **at least one taxed sale line** — i.e. essentially every real shift for a
VAT-registered tenant (the launch target is a Tunisian pharmacy). This is not an edge case: it is
the ordinary path. The brief's phrase "ordinary sale-only shifts" is accurate.

---

## 2. The two options, costed

Both options produce **identical corrected values**. They differ only in whether the bytes announce
which convention they carry.

### 2.1 Option A — bump `event_version` for the Z/X (and `SESSION_CLOSE`) payloads

Old events keep v1 semantics (gross-as-net); new events carry v2 semantics (correct); the version
is the discriminator a verifier reads.

**Note the unusual shape:** every existing version bump in this codebase marks a **key-set** change
(v2 added variant keys, v3 added cash-rounding keys, v4 added the refund authoring path). This
would be the first **semantics-only** bump — same keys, same types, different meaning. That is a
legitimate use of a version, but it is new to this system, and none of the existing per-version
machinery (`payloadKeysFor`, the V3/V4 key-set constants) does anything for it: the discriminator
would exist purely to be read by a future verifier that does not exist yet (§1.3).

**Files and symbols it touches** (each verified at `base_sha`):

| # | File:line | Change |
|---|---|---|
| A1 | `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:245` | The flat `return 1;` must gain a `Z_REPORT`/`X_REPORT`/`SESSION_CLOSE` branch (or become a map). Today `SALE_RECEIPT` is the only type with any fan-out (`:222-244`). |
| A2 | `apps/api/.../Fiscal/Application/Services/FiscalEventPayloadRegistry.php:92-94` | `PHASE_1_MAP` authoring version `1 → 2` for the three types. |
| A3 | **`…/FiscalEventPayloadRegistry.php:119-127`** | `SUPPORTED_VERSIONS` currently contains **only** `SALE_RECEIPT => [1,2,3,4]`. Z/X/`SESSION_CLOSE` must gain `[1, 2]`. **Omitting this quarantines 100 % of Z and X events** at `StrictCanonicalParser.php:628-634` (`envelope_event_version_mismatch`), because `supportedVersionsFor()` falls back to `[eventVersionFor()]` (`:152-155`). This is the single highest-risk line in Option A. |
| A4 | `apps/api/.../FiscalPayloadConstraintValidator.php:427-438` | `payloadKeysFor()` fans out only for `SALE_RECEIPT >= 4` / `>= 3`; everything else ignores `$eventVersion`. A v2 with an unchanged key set needs **no** change here — but if the gate wants the version to be *enforced* rather than merely stamped, this is where a `Z_REPORT_PAYLOAD_KEYS_V2` branch would go. |
| A5 | `apps/api/.../FiscalPayloadConstraintValidator.php:524-526` | `SESSION_CLOSE`/`X_REPORT`/`Z_REPORT` call `validateZReportFamilyPayload($payload)` with `$eventVersion` **dropped**. Threading it is required for any version-aware validation. |
| A6 | `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:911-916` | The TS mirror of A5 — same drop of `eventVersion` for the Z family. |
| A7 | `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1297-1317`, `:1319-1348`, `:1350-1383` | `X_REPORT_PAYLOAD_KEYS`, `SESSION_CLOSE_PAYLOAD_KEYS`, `Z_REPORT_PAYLOAD_KEYS` — flat `as const` lists with no version suffix (contrast `SALE_RECEIPT_PAYLOAD_KEYS_V3`/`_V4`). Only touched if A4 is taken. |
| A8 | `apps/api/.../POS/Application/Projections/ZReportProjection.php:174-179` | A load-bearing comment states the design premise being retired: *"it is derived server-side rather than read off the device payload because `Z_REPORT` **stays v1** — adding a key to the canonical payload would quarantine 100 % of Z events."* Must be rewritten. `rowFromPayload()` (`:53-121`) and `legacyReportData()` (`:127-164`, `vat_breakdown` passthrough at `:154`) read Z payload keys directly and **never branch on the Z's own version** — they would need to, if the two semantics are ever to be told apart server-side. |
| A9 | `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:1247-1281` | `mapCanonicalZReportGrandTotal()` reads `vat_breakdown` (`:1260`) straight off the signed payload into the NF525 GRANDTOTAL section. A v2 must be handled here or the archive silently mixes conventions. |
| A10 | `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:579`, `:598` | **A version bump is NOT regulator-visible on the verified path — corrected at M1 gate-r2, see below.** No code change; the entry is kept because the *absence* of archive visibility is itself a ruling input. |

**A10 in full — the NF525 correction (M1 gate-r2 finding 1).** An earlier revision of this memo
asserted that a v2 Z *"appears verbatim in the NF525 archive"* and offered archive
self-description as a flip-to-Option-A condition. **That was wrong, and it pointed the ruling the
wrong way.** Verified at `base_sha`:

- `<VersionEvenement>` is emitted at **exactly one site** — `Nf525XmlBuilder.php:598` — and that
  site is inside **`addQuarantineSection()`** (`:548`, element `EvenementsQuarantaine` at `:554`).
  `grep -rn "VersionEvenement" apps/api/` returns that one line and nothing else.
- Its only two feeds are the quarantine table and `fiscal_events` rows where
  `integrity_status <> 'verified' OR payload_parse_status = 'failed'`
  (`Nf525DataProvider.php:1553-1607`, `:1613-1660`).
- **A verified v2 `Z_REPORT` therefore never carries its `event_version` into the NF525 export at
  all.** No NF525 section emits `canonical_bytes` for verified events.

**The honest fact cuts against Option A, not for it.** The corrected (or uncorrected)
`vat_breakdown` **values** *do* reach the archive — `Nf525DataProvider::mapCanonicalZReportGrandTotal()`
(`:1247-1281`, `vat_breakdown` at `:1260`) feeds the NF525 GRANDTOTAL section — while the version
stamp does **not**. So Option A labels the canonical bytes and the `fiscal_events.event_version`
column, and leaves the **regulator-facing export exactly as undifferentiated as Option B does**.
Combined with §1.4 (the legacy `z_reports` chain has no version field either), the discriminator
Option A buys is visible on **one** of three surfaces: the fiscal-event bytes — not the legacy
chain, not the archive.
| A11 | `app/Shared/Domain/CashRoundingCutover.php:44,52-55` | `EVENT_VERSION = 3` and `applies()` is a bare `>= 3` with **no event-type guard**. Not reachable for Z/X today; if Z/X ever pass ≥ 3 it misclassifies them as rounding-era. A v1→v2 bump does not reach it, but it is a latent trap the ruling should note. |
| A12 | Tests that hardcode `1` | `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts:80-81` (`toBe(1)`); `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:86-87` (`assertSame(1, …)`); Z fixtures at v1 in `tests/Feature/Fiscal/ZReportProjectionTest.php:333,375,422`, `ZSessionLifecycleQuarantineVisibilityTest.php:509,539,561`, `tests/Feature/POS/ZReportImmutabilityTest.php:400`. |
| A13 | New test owed by the brief (M2 §4) | "Old events keep old semantics" needs its own test. **There is nothing to test it against**: no Z/X golden vector, byte-stability fixture or key-set test exists anywhere in the repo (§3.2), so the fixture would have to be authored from scratch. |
| A14 | **Deploy ordering — a rollout constraint, not a code change** | See below. Option A creates a **server-before-device** ordering obligation that does not exist today. |

**A14 in full — the rollout hazard (M1 gate-r1 finding 1).** A3 says that *omitting* the
`SUPPORTED_VERSIONS` change quarantines all Z/X. The rollout corollary is separate and equally
sharp: devices author locally and sync opportunistically to **whatever API is currently deployed**.
If a device build authoring v2 reaches a terminal **before** the API carrying A3 is deployed, every
`Z_REPORT`/`X_REPORT`/`SESSION_CLOSE` that terminal syncs is rejected at
`StrictCanonicalParser.php:628-634` as `envelope_event_version_mismatch` and lands in
`fiscal_event_quarantine` — **100 % of that terminal's Z/X**, on an append-only device chain that
cannot be re-authored.

This is exactly the inverse of the ordering the device-side stack normally runs: R-6 / LEDGER **D-1**
describes device builds rolling out on their own cadence, and **D-3** pins a *device-before-server*
obligation for SV-11/SV-9. **Option A would introduce a server-before-device obligation pointing the
other way**, on the same fleet, in the same window. Two opposing ordering constraints on one rollout
is a real operational cost and belongs in the ruling.

**Option B has no ordering constraint at all**: three `bcsub` expressions, device-local, order-free
against any API version.

**Consumers that branch on `event_version` — the enumeration.**
- *Enforcing:* `StrictCanonicalParser.php:625-634` (the hard gate, via `supportedVersionsFor()` at
  `:194`), `FiscalPayloadConstraintValidator.php:427-438`, `BestEffortPayloadParser.php:62-69`
  (quarantine triage, defaults to `1`), `ParseFailureResolutionService.php:320,339` (re-parse on
  resume — the "re-check a signed payload" path for Z/X).
- *Cross-checking (version-agnostic, survive a bump):*
  `OutboxIngestor.php:396` (`validateSealedCoordinates` — the envelope's `event_version` must
  byte-match the value inside the sealed bytes), `VerifyEventChainCommand.php:797`,
  `fiscalEventRepository.ts:400-402`.
- *Carrying only:* `OutboxIngestor.php:803` (copies the device value verbatim; the server never
  re-derives it), `FiscalEvent.php:29,100,160`, `FiscalEventQuarantine.php:37,101,143`,
  `Nf525DataProvider.php:1575,1594,1632,1653`, `VerifyEventChainCommand.php:110,360`,
  device SQLite `migrations.ts:927` (`event_version INTEGER NOT NULL DEFAULT 1`) and the PG column
  (`2026_05_14_100001_create_fiscal_events_table.php:34`).
- *Immutability:* both the device trigger (`migrations.ts:1026`, `:1413`) and the PG trigger
  (`2026_05_14_100002_create_fiscal_events_immutability.php:79`) list `event_version` among the
  columns an UPDATE may not change. **A stored version can never be retro-patched** — which is
  precisely why the choice is one-way (see reversal cost).
- *Not affected:* `ZReportProjection.php:229,316` filters `event_version >= CashRoundingCutover`
  on **joined `SALE_RECEIPT` rows**, not on the Z itself. `ReportGenerationService` uses
  `fiscal_schema_version` / `report_data.schema_version` — **a different versioning axis entirely**.
  `VerifyPosChainCommand` verifies the legacy `pos_z_reports` chain and has **zero**
  `event_version` references.

**Cross-language drift risk.** `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts`
gates PHP↔TS key-set parity for `SALE_RECEIPT`, `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` **only**.
The Z/X key sets are **not** drift-gated, so an A4/A7 divergence would not be caught.

**Reversal cost (Option A): effectively irreversible.** Rule 8 means a published v2 stays
parseable forever; `SUPPORTED_VERSIONS` can never shrink and the stored column can never be
rewritten (immutability triggers). "Changing its mind" means authoring v1 again while carrying v2
support in perpetuity — strictly worse than never having bumped. The honest reading: **Option A is
a one-way door**; Option B leaves a door open in both directions.

### 2.2 Option B — in-place semantic correction at the current version

Correct the computation where it is; treat it as a bug fix to a derived field; no discriminator.

**Files and symbols it touches:**

| # | File:line | Change |
|---|---|---|
| B1 | `apps/pos/src/lib/offline/zReportService.ts:907-914` | `lineGross = line.line_total`; `lineNet = bcsub(lineGross, lineVat, decimals)`; accumulate with the explicit `decimals` scale (R-3). No `bcabs` — the sale branch is positive-signed and `bcabs` would swallow a legitimately negative row. |
| B2 | `apps/pos/src/lib/offline/endOfDayPreview.ts:301-307` | Same, with the file's own `scale` (`:172`). **Display-only** — `buildEndOfDayPreview` has exactly one consumer, `EndOfDayPreviewModal.tsx:161-162`, and its `vat_breakdown` is render-only (`:342-357`). This site changes **no signed bytes at all**. |
| B3 | `apps/pos/src/api/reportApi.ts:491-496` | Same, with `decimals`. |
| B4 | Fixture corrections (R-4) | `zReportService.test.ts:123` (`line_total '42.00'` = the subtotal on a 50.00/8.00 receipt), `endOfDayPreview.test.ts:37,50,144,193,247,323,390,450,488,523,555,622` — all author `line_total` as NET and mask the bug. |
| B5 | New real-writer E2E coverage (R-5) | Mirroring `refundReportingEndToEnd.test.ts`. Owed under **both** options. |

**Migration surface: zero.** No registry, parser, validator, projection, archive or verification
change. Nothing can quarantine.

**The dishonesty cost, stated plainly.** If signed history exists, Option B leaves **two different
meanings for one version, with nothing in the bytes to say which** — a fiscal record that cannot be
interpreted without out-of-band knowledge of the device build rollout date. That is a real defect in
the **bytes'** self-description.

**But it is NOT an archive defect, and Option A does not fix one.** Per A10, `<VersionEvenement>`
is emitted only for **quarantined/unverified** events (`Nf525XmlBuilder.php:598` inside
`addQuarantineSection()`), so the NF525 export of a *verified* Z carries no version stamp under
either option — while the `vat_breakdown` values themselves do reach the archive's GRANDTOTAL
section (`Nf525DataProvider.php:1247-1281`). Both options therefore leave the regulator-facing
export equally undifferentiated.

**The counterweight the gate must weigh against that:** Option A does not repair the old events
either. It only labels the new ones. Old v1 events remain wrong under both options — rule 8
forbids backfill, and the brief lists it as out of scope. The question is therefore **not** "wrong
history vs. correct history", it is **"wrong history that is labelled vs. wrong history that is
not"** — and, per §1.4, labelled on only one of the two affected chains.

**Reversal cost (Option B): trivial.** Revert three expressions. Nothing is published, nothing is
pinned, no consumer contract changes.

### 2.3 Side-by-side

| | **A — new `event_version`** | **B — in-place** |
|---|---|---|
| Corrected values | identical | identical |
| Files touched | ~13 sites across device + server + archive + 8 test sites (§2.1) | 3 expressions + fixtures (§2.2) |
| Quarantine risk | **high** — omitting A3 quarantines 100 % of Z/X | none |
| Deploy ordering | **server-before-device, mandatory** (A14). Inverted order quarantines 100 % of a terminal's Z/X on an append-only chain. Points **opposite** to D-3's device-before-server obligation on the same fleet | **none** — device-local, order-free |
| Discriminator on the fiscal-event chain | yes | no |
| Discriminator on the legacy `z_reports` chain | **no** (§1.4) | no |
| Repairs existing wrong events | **no** | no |
| Detected by any existing verifier | no (§1.3) | no |
| Reversal | one-way door | trivial |
| Precedent in this repo | none for a semantics-only bump | `77283d2a5` (§4) |

---

## 3. Evidence on existing signed history

### 3.1 What IS established from the repository

1. **No seeder creates any Z/X history — or any shift at all.** Exhaustive search of
   `apps/api/database/seeders/**` for `fiscal_events`, `Z_REPORT`, `X_REPORT`, `pos_z_reports`,
   `pos_shifts` returns **zero write sites**. `CoffeeShopSeeder.php:1204-1240` seeds one `Terminal`
   (`current_sequence => 1`) and its own docblock says a fresh tenant *"cannot open a shift"* until
   it exists; `DemoPharmacySeeder.php:751-780` seeds four terminals at `current_sequence => 0`.
   Corroborated in prose twice: `docs/qa/2026-08-01-money-test-plan.md:244-246` and
   `docs/qa/2026-08-02-full-e2e-campaign-plan.md:272-274` — *"**NO seeder creates `pos_shifts` /
   `pos_receipts`.**"* A freshly seeded environment is **provably** Z/X-empty.
2. **No golden or byte-stability fixture pins any Z/X canonical byte or event hash.** Every golden
   vector in the repo is `SALE_RECEIPT`-shaped (`tests/Fixtures/Fiscal/sale-receipt-golden/v4/`,
   `sale-receipt-v2-golden.json`, `v3-golden-hashes/`, `SaleReceiptV1V2ByteStability.test.ts`).
   Grep for `Z_REPORT`/`X_REPORT` across all `*.json`/`*.snap` fixtures: **zero hits.** The only
   Z-shaped byte pins (`tests/Feature/POS/HashGoldenByteTest.php:109-133`,
   `zReportHashService.legacyStability.test.ts`) pin the **legacy** `ZReportHashService`
   normalization over hand-authored `report_data`, where `vat_breakdown` is an *input* — so the fix
   would not move them. Consequence both ways: **no committed artifact this change would silently
   reinterpret, and no regression tripwire guarding it either.**
3. **No migration backfills, rewrites or reads Z/X fiscal events.**
   `2026_05_22_100000_extend_fiscal_event_type_check_for_phase4.php:59-60` adds the types to a
   CHECK constraint only. `2026_05_24_120000_add_fiscal_event_linkage_to_pos_z_reports.php:14-27`
   adds nullable linkage columns with **no UPDATE**. The append-only trigger
   `2026_06_11_120000_create_pos_z_reports_immutability_trigger.php:94-97` permits exactly one
   legacy→canonical adoption path — a **capability**, with nothing in the repo indicating it has
   ever been used.
4. **Z/X are device-authored only, and stored on-device first.** Server authoring is structurally
   refused for every `fiscal_schema_version >= 3` terminal
   (`ReportGenerationService.php:84-86,182`, marked `@deprecated … no shipped client` at `:514`,
   pinned by `ZReportServerAuthoringChokepointTest.php:21-26`), new terminals default to v3
   (`2026_07_31_000001_default_pos_terminals_fiscal_schema_version_3.php`), and both seeders write
   `fiscal_schema_version => 3`. Device SQLite `fiscal_events` (`migrations.ts:909-1050`) is
   append-only by trigger. **Any signed Z/X history exists first and irrevocably on a device, and
   reaches a server only if synced.**
5. **The device campaign that would have authored signed Z/X was never executed.**
   `docs/handoff/OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:62-66` D2 is **unticked**; its results
   file `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` is gitignored (`.gitignore:58`) and absent.
6. **Staging was observed device-data-empty on 2026-08-02.**
   `docs/qa/2026-08-02-full-e2e-campaign-plan.md:348` (C-15): *"**No device data**"*, with
   *"1 closed shift with sales"* listed as a fixture still to be **authored**; Z-report and
   shift-history coverage recorded as *"C (empty only)"* at `:278` and `:288`.
7. **Tenant #1 is not onboarded.** `OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:79` E3 unticked;
   `DISPATCH-PLAN-v3-first-tenant-2026-07-31.md:214` *"HARD RULE: tenant #1 CANNOT onboard until
   every applicable row below is [closed]."*
8. **Staging is NOT fiscally empty, however.**
   `docs/superpowers/audits/2026-08-05-production-v1-readiness.md:157,188` records already-orphaned
   **sealed `DEPOSIT_RECEIPT` chain events** on staging/local. So "the staging fiscal chain has
   never been written to" is **false** — it simply has not been shown to contain Z/X.
9. **A stale packaged device with staging baked in exists.**
   `docs/handoff/HANDOVER-codex-pos-desktop-campaign-2026-08-02.md:17-20` — *"launches the INSTALLED
   production app (`/Applications/IziPOS.app`, ancient build, **staging API baked in**)"*.

### 3.2 What CANNOT be established from this worktree — say so plainly

I have no staging access and no device access from here. The following are **operator queries**,
not repository facts:

| # | Open question | How it would be answered |
|---|---|---|
| Q1 | Does any staging tenant's `fiscal_events` contain a `Z_REPORT`/`X_REPORT` row **today**? The emptiness observation (§3.1.6) is dated 2026-08-02 and scoped to the campaign tenant; ~3 weeks of unlogged staging activity sit between it and `base_sha`. | `SELECT event_type, count(*) FROM fiscal_events WHERE event_type IN ('Z_REPORT','X_REPORT','SESSION_CLOSE') GROUP BY 1` across all 8 staging tenant DBs (the set LEDGER S-16 records as live-queried on 2026-08-21). Plus `SELECT count(*) FROM pos_z_reports` and `… WHERE fiscal_event_id IS NULL`. |
| Q2 | **Has any demo/pilot device closed a shift?** — the sharpest gap. Device `fiscal_events` is append-only and syncs opportunistically, so a device may hold **unsynced** signed Z/X that no server query will ever reveal. | Per-terminal inventory. The repo states the constraint directly: `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:139` — the preflight *"cannot reach Tauri SQLite stores — the operator must inventory each deployed terminal."* **No server-side query can close Q2.** |
| Q3 | Did the §Z campaign close shifts before being abandoned? Its results doc is gitignored. | Inspect the campaign device DB (`~/Library/Application Support/com.syneriva.izipos/izipos-<companyId>.db`). |
| Q4 | Is "tenant #1 has no signed Z/X history" true? | Operational check. The repo rules this class of claim non-provable from here: `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md:1127` — *"v3-from-birth is a **deployment check, not a repository-provable fact**"*; `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:20` — *"an **operational fact, not a codebase-verified one**."* |

**The honest bottom line: the repository supports "no signed Z/X history is demonstrable from the
codebase, and none is seeded, fixtured or migrated." It does NOT support "no signed Z/X history
exists."** Q2 in particular is unclosable by any query the parent can run against a server.

---

## 4. Precedent — the identical correction already shipped in place

`git log -L 864,876:apps/pos/src/lib/offline/zReportService.ts` →
**`77283d2a5` (2026-08-01)** *"fix(pos): findings 1+4+14+16 — offline_receipts consumer math (v4
refunds)"*. That commit changed exactly the same class of expression on the **refund** branch:

```
-        const lineNet = bcabs(line.line_total ?? '0');
-        const lineGross = bcadd(lineNet, lineVat);
+        const lineGross = bcabs(line.line_total ?? '0', decimals);
+        const lineNet = bcsub(lineGross, lineVat, decimals);
```

It changed the **same `vat_breakdown` field of the same signed `Z_REPORT`/`X_REPORT`/`SESSION_CLOSE`
events**, for any shift containing a v4 refund. Its file list is
`reportApi.ts`, `endOfDayPreview.ts`, `zReportService.ts`, `refundReceiptService.ts`, plus two test
files — **no registry, no payload, no version file was touched.** It was reviewed, gated and merged.

**How much weight this carries — and how much it does not.** It is a real in-repo precedent for
Option B on identical bytes. But its blast radius was narrower: v4 refund authoring was itself a
brand-new capability at that moment, so the population of affected signed events was near-empty by
construction. This lane's blast radius is **every taxed sale shift**. The precedent establishes
that in-place correction of `vat_breakdown` is a shape this project has accepted; it does not
establish that it is acceptable at this scale. The gate should treat it as evidence, not as a
ruling.

Also relevant, in the other direction: owner directive **D4**
(`docs/superpowers/coordination/2026-05-22-codex-handover-phase-4-and-z-report.md:290`, marked
*"LOCKED — do not re-litigate"*) — *"New event types use `event_version: 1`. Z_REPORT + SESSION_OPEN
+ SESSION_CLOSE + X_REPORT + cash drawer event types all start at v1."* That directive fixes the
**starting** version and says nothing about bumping, but any Option-A ruling should note it is
moving a value an owner directive named.

Finally, the two cash-rounding plans
(`docs/superpowers/plans/2026-07-27-cash-rounding-server-phase1.md:5893`,
`…-device-phase2.md:20`) both list **"Z_REPORT v2 (a signed Z-level rounding key)"** as explicitly
**deferred** scope. If a Z v2 is going to happen anyway for that reason, the gate may prefer to
sequence this correction with it rather than spend a bump on semantics alone — or may prefer the
opposite, to avoid coupling an urgent correctness fix to deferred feature work. **That sequencing is
the gate's call, not mine.**

---

## 5. Findings this lane surfaced that the brief does not carry

Recorded, not acted on (rule 4). All three are material to the ruling.

### F-1 — `SESSION_CLOSE` is a third affected signed event type
`zSessionAuthoring.ts:440`. The brief, the ticket and LEDGER C-2 name only `Z_REPORT` and
`X_REPORT`. **The ruling must state whether it applies to `SESSION_CLOSE`**, and an Option-A
ruling must include it in A1/A2/A3 or a Z-session's two legs will carry different conventions
inside one sealed chain.

### F-2 🚨 — the brief's "headline totals are correct" premise is FALSE
The brief's §SCOPE asserts: *"`gross_sales` / `net_sales` / `tax_amount` come from `receipt.total` /
`receipt.subtotal` / `receipt.tax_amount` …, which the writer already stores correctly. **Do not
touch the headline aggregation.**"*

**The writer does not store `subtotal` correctly.** Verified end-to-end at `base_sha`:

- `apps/pos/src/lib/payment/cartTotals.ts:36` — `const subtotal = bcsum(cartItems.map(item => item.line_total), decimals)`,
  documented at `:7` as *"Σ `line_total` at the currency scale"*. `line_total` is **GROSS**.
- `:66` — `total = bcsub(subtotal, discount, decimals)`. With no transaction discount the function
  returns `total: subtotal` verbatim (`:40`, `:52`), so **`total === subtotal` exactly**.
- `apps/pos/src/lib/offline/receiptService.ts:145-158` — `computeLineTotals()` independently sums
  `item.line_total` into `subtotal`; stored at `:547` as the row's `subtotal`.
- `apps/pos/src/lib/offline/zReportService.ts:899-901` — `grossSales += receipt.total`,
  **`netSales += receipt.subtotal`**, `taxAmount += receipt.tax_amount`.

⇒ **On a signed `Z_REPORT`/`X_REPORT`/`SESSION_CLOSE`, `net_sales` equals `gross_sales`** for any
taxed shift without a transaction discount. The identity `subtotal + tax_amount == total` — which
`refundReportingEndToEnd.test.ts:292` asserts and the real writer satisfies on a **refund** row —
**fails on every sale row**. The existing fixture masks it exactly as it masks the per-rate defect:
`zReportService.test.ts:123` authors `total 50.00 / subtotal 42.00 / tax 8.00`, which the real
writer would have written as `total 50.00 / subtotal 50.00 / tax 8.00`.

This is independently recorded as part of finding **R-01** in
`docs/superpowers/audits/2026-08-05-production-v1-readiness.md:185` (status **open**), which names
it *"an unnamed 5th defect"* and concludes *"Z `net_sales` == gross sales"*.

**Why the ruling needs this.** The memo cannot honestly describe "which fields of which signed
events change" while a second, larger error sits in the same sealed payload untouched. Concretely:
if Option A is ruled, a v2 that corrects `vat_breakdown` but leaves `net_sales` wrong would need a
**v3** when `net_sales` is fixed — two one-way doors for one defect class. If the gate wants one
bump, the two fixes must be sequenced together, which is a **scope expansion this lane's brief
forbids** (rule 4; the brief calls the headline "a second, unrelated defect"). **This is a
parent/owner sequencing decision, not one I may take.**

### F-3 — a fourth gross-as-net site, in `zReportService.ts` itself
`zReportService.ts:1011-1024`, inside `buildReceiptSnapshots()`'s `taxByRate` loop (map declared at
`:1010`): the defect is at **`:1015-1017`** — `lineNet = line.line_total ?? '0'`;
`lineGross = bcadd(lineNet, lineVat)` — with the accumulators at `:1020-1022` also missing the scale
argument. Its output feeds `receipt_snapshots` (`:436`, `:507`).

**It is NOT in the signed bytes and NOT in the Z hash** — `buildZReportPayload` does not carry
`receipt_snapshots`, and `computeZReportHash` (`:445-451`) hashes only `report_data`, of which
`receipt_snapshots` is a **sibling** (`report_data: reportData` at `:504`; `receipt_snapshots` at
`:507`). It lands on the device-local `z_reports`
row and on whatever consumes that mirror. Lower severity, outside the brief's three declared sites,
**recorded per rule 4 and not fixed.** The audit names it as part of R-01
(`production-v1-readiness.md:185`, cited there as `:1015-1017`).

### F-4 — `vat_breakdown` has no structural validation on either side
`FiscalEventEngine.ts:3748-3760` (TS) and `FiscalPayloadConstraintValidator.php:764-771` (PHP)
never iterate `vat_breakdown`. A Z can be sealed today with an empty or malformed breakdown, and
the Z/X row key set (`{gross_amount, net_amount, tax_rate, vat_amount}`, `tax_rate` a **number**)
differs from `SALE_RECEIPT`'s (`{gross_amount, net_amount, rate, tax_category_code, vat_amount}`,
`rate` a **string**) — and the PHP↔TS drift gate (`FiscalPayloadKeyDrift.test.ts`) does not cover
Z/X. Downstream finding; a separate lane.

---

## 6. Recommendation (advisory — the gate rules)

**I recommend Option B — in-place semantic correction — conditional on Q1 and Q3 (§3.2) coming back
empty, and with F-1 folded in so `SESSION_CLOSE` moves with Z and X.**

**The Q2 default, stated in advance (M1 gate-r1 finding 2).** Q2 — *has any demo/pilot device
closed a shift?* — is closable only by a per-terminal operator inventory and by **no** server query
(§3.2, `2026-05-14-pos-phase1-fiscal-event-engine.md:139`). "Q2 unresolved" is therefore the
**expected** state, not an exceptional one, and a recommendation that goes silent there would be
useless at exactly the moment it is read. So:

> **If Q2 cannot be discharged, the recommendation still stands at Option B** — because Option A
> does not repair a pre-fix device's sealed events either (§2.2), does not shorten the window in
> which more of them accrue (§7 item 3), and adds a rollout hazard aimed at that same fleet (A14).
> An undischargeable Q2 makes Option A *less* attractive, not more: the fleet whose contents are
> unknown is precisely the fleet the server-before-device ordering constraint would endanger.
>
> **Who may decide to proceed on an open Q2:** the `fiscal-pos-reviewer` ruling gate, on the record,
> naming Q2 as accepted-open. It is not mine to accept, and it is not a thing to leave unstated.
> Only a **positive** Q2 answer — an actual device found with closed shifts — is a flip condition.

The reasoning, weighted:

1. **Option A's discriminator is worth less here than it looks.** It labels one of the two affected
   chains (§1.4); no existing verifier reads it for this purpose (§1.3); it repairs nothing; and
   this would be the first semantics-only bump in a system whose entire per-version machinery is
   built for key-set changes. Against that it carries a real quarantine hazard (A3) and is a
   **one-way door** (rule 8).
2. **The cost asymmetry is large and the correctness benefit is identical.** ~13 sites across three
   layers plus an archive format, versus three expressions — for the same corrected numbers.
3. **The precedent is real and recent** (§4): the same field, the same event types, corrected in
   place at v1, gated and merged three weeks ago.
4. **The evidence, as far as the repository can carry it, is that there is nothing to reinterpret**
   (§3.1) — no seeded, fixtured or migrated Z/X history anywhere.

**What would flip me to Option A**, stated in advance so the gate can test it:
- **Q2 returns a device with closed shifts** (§3.2) — a real sealed corpus at ordinary-shift scale
  makes the "two meanings, one version" objection concrete rather than theoretical; or
- the gate rules that the **`net_sales` defect (F-2) will be fixed in the same device build**, in
  which case one bump can cover both corrections and the marginal cost of A collapses; or
- the gate judges that **the canonical bytes and the `fiscal_events.event_version` column must be
  self-describing as a matter of principle**, independent of whether any tool reads the
  discriminator today (§1.3) — i.e. it is buying option value for a future verifier.

**A flip condition that an earlier revision of this memo listed and that is now WITHDRAWN as
factually wrong (M1 gate-r2 finding 1):** *"NF525 archive self-description … `<VersionEvenement>`
is the field a French auditor reads."* It is not — that element is emitted only for quarantined
events (A10). A verified v2 Z shows no version in the NF525 export, so **archive
self-description cannot be a reason to prefer Option A.** The condition is struck rather than
silently edited, because it was offered to a gate whose ruling is a one-way door.

**What I am explicitly NOT deciding, and why:** whether the residual staging/demo-device exposure
is acceptable; whether tenant #1's cheap window (§7) outweighs it; whether F-2 changes this lane's
scope. Those are the gate's and the owner's.

---

## 7. The tenant-#1 cheap-window argument, with its limits

**The argument.** Tenant #1 has not been onboarded (§3.1.7) and therefore has no signed Z/X history.
If this correction ships in the device build **before that tenant's first shift**, there is nothing
to backfill and nothing to reinterpret *for them*: every Z they ever seal carries the correct
decomposition from event one, and the "two meanings, one version" objection never arises **within
that tenant's chain**. On that reading Option B costs that tenant nothing.

**What the argument does NOT cover** (brief R-1, verbatim scope, each checked against §3):

1. **Existing staging history.** Unknown — Q1 is open. Staging is demonstrably **not** fiscally
   empty (§3.1.8: sealed `DEPOSIT_RECEIPT` events), so "staging has never been written to" is
   already false; it simply has not been shown to contain Z/X.
2. **Any demo/pilot device that has already closed shifts.** Unknown — **Q2, and unclosable by any
   server query** (§3.2). A packaged device with staging baked in is documented to exist
   (§3.1.9); device chains are append-only and sync opportunistically.
3. **Any tenant onboarded on a pre-fix build.** A device-side fix only reaches a terminal via a
   device build (R-6). Any terminal that closes a shift between now and its build upgrade seals
   more wrong bytes **under either option** — Option A does not shorten that window, it only labels
   what lands after it.
4. **The premise itself is operational, not repo-provable** — Q4, and the repo says so in its own
   words (§3.2).

**The honest framing for the gate:** the cheap window is a real and material argument **about
tenant #1 only**. It says nothing about items 1–3, and item 2 cannot be closed by anything the
parent can run. Weighing that residual is the gate's job, not this memo's.

---

## 8. Resume condition

Per brief §M1 and the harness: once the memo's own bridge review is ACCEPT, the milestone is
`blocked_review` and the run ends. **M2 implements exactly the ruled option** and may not start
until `owner_gates.z-signed-bytes-versioning.chosen_option` is recorded in
`docs/handoff/progress/z-sale-branch-decomposition.progress.yaml`, with `ruled_by` and `record`.

The ruling should answer, explicitly:

1. **Option A or Option B.**
2. **Does it apply to `SESSION_CLOSE`** as well as `Z_REPORT` and `X_REPORT`? (F-1)
3. **What happens to F-2** — the `net_sales` headline defect in the same signed bytes: out of scope
   for this lane and ticketed, or folded in (a scope expansion the brief currently forbids)?
4. If **Option A**: does the version bump also require the **key-set enforcement** work (A4–A7), or
   is a stamped-but-unenforced v2 sufficient?
5. If **Option A**: who owns the **server-before-device deploy ordering** obligation (A14), and how
   is it reconciled with LEDGER **D-3**'s opposite device-before-server obligation on the same
   fleet? An inverted rollout quarantines 100 % of a terminal's Z/X irrecoverably.
6. **Q2 (§3.2) is expected to remain open.** If the ruling proceeds on an undischarged Q2, say so
   on the record — the memo's §6 default is that an open Q2 does not change the recommendation, but
   accepting it is the gate's call, not the executor's.

---

## 9. Review history

| Round | Lens | Verdict | Disposition |
|---|---|---|---|
| 1 | fiscal-pos | **ACCEPT** — 0 P1, 1 P2, 4 P3 (`M1-round1.md`) | All five folded in before the ruling, because the artifact this memo feeds is a one-way door: P2 finding 1 → **A14** + the §2.3 row + ruling question 5; P3 finding 2 → the **Q2 default** in §6; P3 finding 3 → the `:504` citation correction in F-3; P3 finding 4 → progress-YAML `updated:`/`commit:` bookkeeping; P3 finding 5 → the third site's structural non-identity, flagged as an M2/R-2 note in §1.1. |
| 2 | fiscal-pos | **CHANGES-REQUIRED** — 1 P1, 1 P2, 3 P3 (`M1-round2.md`) | **P1** → the NF525 argument for Option A was **factually wrong**: `<VersionEvenement>` (`Nf525XmlBuilder.php:598`) is emitted only inside `addQuarantineSection()`, so a *verified* v2 Z never carries its version into the archive. A10 rewritten, the §2.2 sentence corrected, and **flip condition 3 struck and replaced** — the corrected fact cuts *against* Option A. **P2** → new **§1.3a**: the fix *moves* the payload's internal contradiction from the gross column to the net column (`Σ net_amount` vs `net_sales`, off by the shift's full VAT) unless F-2 moves with it. **P3** → YAML `recommendation:` de-staled to Q1/Q3; YAML stop-state bookkeeping; three citation corrections (A9 path, `ZReportProjection.php:154`, `StrictCanonicalParser.php:628-634`). |
| 3 | fiscal-pos | see `M1-round3.md` | Re-gate of the round-2 fixes. |
