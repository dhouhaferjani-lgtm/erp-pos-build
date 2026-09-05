# Handover to Codex — parapharmacy remediation spec, fix round 2 (v2 → v3)

Date: 2026-09-05. HEAD `f75aa5023` plus the committed v2 docs. Orchestrator: Claude Fable 5.1. Role split unchanged from round 1: Fable sequences, gates, commits, merges and promotes; Codex revises the spec in the working tree and stops. No code, tests, migrations, commits, pushes.

## Read first

1. `docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-gate-r2-synthesis.md` — the verdict, six deduplicated blockers B1–B6, the majors list, and the **correction to r1** (voucher/account legs DO reach the tender resolver; r1 F-A was wrong on that point and v2 inherited it).
2. The four lens files it links (fiscal, inventory, treasury, authz). Each finding there has `file:line` evidence at HEAD; treat them as verified unless you can disprove one with a citation.
3. Round-1 handover for R1–R4 (unchanged): `docs/handoff/HANDOVER-parapharmacy-remediation-codex-spec-fix-round-2026-09-05.md`.

## Required in v3

**Blockers (all six must close):**
- B1: add **D8 (OPEN)** for the sealed tender-binding transport; remove "recommended transport" language from W2's body; state the unresolvable-binding repair path; present D7 and D8 as one SALE_RECEIPT version roadmap (one cutover, not two). Include device-side validation of any sealed `repository_id` (today it is an optional string at `FiscalEventEngine.ts:2844`).
- B2: rewrite W2's classification so voucher, loyalty and customer-account legs have an explicit non-money-destination class; census every leg type from `PaymentMethodSeeder` and the bridge loop at `TreasuryReceiptBridge.php:480`; RD3 applies to electronic settlement only. Register the classification in the glossary or derive it from `is_cash_tender` / `has_maturity` / `instrument_kind`.
- B3: W1 gains a device-consumer census (`syncService.ts:1256`, `paymentStore.ts:950-962,1188`) and a device projection decision before any scoping of `/payment-repositories`.
- B4: W-LOT gains **L9 — DEFAULT/unknown lot identification and split**, sequenced before L4, with its own acceptance row (idempotent, no aggregate/GL change, history preserved).
- B5: R2 restated as a fix: worker/projection lot arms resolve company-level module entitlement explicitly (note `DefaultModuleActivationResolver.php:56` drops `companyId`, 24h cache at `CompanyConfigService.php:34`); product flag alone insufficient; `LotLedgerDriftCensus.php:81` filters by entitlement. Add **D9 (OPEN)**: BatchExpiry deactivatable for parapharmacy, or vertical-always-on.
- B6: L1 covers the web layer and a seeded-role delta table; RD2 stays OPEN.

**Majors:** every item in the synthesis "Majors" section, each closed with a spec change or an explicit, cited rejection in the notes. Key ones: fold `EnqueueResolvedEventProjectionsCommand` into W4; `NearExpirySlot.tsx` is the iteration-1 surface; W3 PG acceptance covers POS treasury and adjustment writers; tenant-wide repository `code` unique vs second-company fixture; L5 covers all three traceability surfaces; L3 lists the full float census; W5 reattribution is a `flat` movement pair with an explicit lock census; R3 cache uses the decimal-string stock surfaces and nets reservations/cart; G1 uses the router and `require.any.permission`, with a public-route class and the measured baseline; `treasury.manage_all_locations` seeding plan against real role names; location-scope baseline corrected to 15 files.

**Preserve** everything in the synthesis "Preserve" list verbatim.

## Deliverables

- Spec v3 in place with a v2 → v3 change log entry.
- Glossary updates as needed (classification, lot identification).
- `docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-codex-fix-round-2-notes.md`: per blocker and per major, what changed or why rejected; the full decisions register (D1–D9, RD2–RD4) all OPEN.
- Stop. Fable runs gate r3.
