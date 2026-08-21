# M1 ruling — `z-signed-bytes-versioning` (fiscal-pos specialist gate, parent-dispatched 2026-08-21)

> Ruling authority: the `fiscal-pos-reviewer` specialist gate, dispatched by the parent orchestrator
> per the brief's §M1 ruling channel, under the owner's standing delegation (P3-M1 R-4 precedent).
> Recorded by the parent (session 0578e8d8) as an owner-review item (owner sheet
> `docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md`). The gate verified the memo's
> load-bearing claims against code (14-item verification log, V-1..V-14, in the gate transcript;
> key decisive fact reproduced below) before ruling. Full gate output preserved in the session
> transcript; this file is the ruling of record.

## RULING: OPTION B — in-place semantic correction at the current `event_version`. UNCONDITIONAL on Q1/Q2/Q3.

**The decisive verified fact (V-14, not in the memo):** the corrected decomposition
(`net = gross − vat`) is ALREADY the enforced canonical convention of this system for
`SALE_RECEIPT` at v1–v4 — `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:121`
(`subtotalNet = gross − tax`), `:340,:348` (`vat_breakdown.net_amount` = Σ line_subtotal net,
`gross_amount = net + vat`), asserted device-side at `:147` and server-side at
`FiscalPayloadConstraintValidator.php:1139-1166`. The Z family escapes that invariant only because
`validateZReportFamilyPayload` (`FiscalPayloadConstraintValidator.php:764-771`) never iterates
`vat_breakdown` (F-4). The Z/X/SESSION_CLOSE values are therefore an ARITHMETIC DEFECT against a
convention the same sealed chain already declares — not "v1 semantics". Rule 8 makes EVENTS
immutable; it does not make a bug a contract version.

Why not A, in brief: a bump would mint a version to describe semantics the system never
intentionally had, on a discriminator with zero readers (no verifier; NF525 emits it only in the
quarantine section, `Nf525XmlBuilder.php:598`), covering only one of the two affected chains (the
legacy `z_reports` chain has no `event_version`), while creating a 100%-quarantine hazard
(`SUPPORTED_VERSIONS` holds only SALE_RECEIPT — `FiscalEventPayloadRegistry.php:126-128` →
`StrictCanonicalParser.php:627-633`) and a server-before-device rollout obligation pointing
opposite LEDGER D-3 on the same fleet. Precedent `77283d2a5` corrected the identical expression
class on the same signed field in place at v1, touching no registry.

## Conditions
1. M2 implements Option B EXACTLY — three sale-branch expressions + explicit currency scale (R-3).
   NO registry, payload, parser, validator, projection or archive change.
2. The ruling applies to **SESSION_CLOSE in lockstep** with Z_REPORT and X_REPORT (F-1) — no extra
   production code (all three builders read one `input.vatBreakdown`,
   `zSessionAuthoring.ts:392/:440/:498`); M2/M3 must add ONE assertion that the Z_REPORT and
   SESSION_CLOSE authored for the same close carry byte-identical `vat_breakdown`.
3. Q2 (any demo/pilot device ever closed a shift?) is ACCEPTED OPEN on the record — not closable by
   any server query. A positive Q2 on a demo/pilot/staging device is NOT a flip condition. Q1/Q3
   are NOT preconditions for M2 (hygiene only; any staging Zs found are disposable test data).
4. **RETURN-TO-GATE trigger (the only re-opener):** signed Z/X/SESSION_CLOSE events discovered on a
   REVENUE-BEARING, VAT-DECLARING tenant chain (not staging, not demo/pilot) before the device
   build carrying this fix rolls out → ruling suspended, gate re-runs with that data.
5. The reversal window closes at DEVICE BUILD ROLLOUT, not at merge: an owner override to Option A
   before any terminal receives the fixed build costs exactly Option A's own price (~zero
   incremental); after rollout, a v2 would separate "correct" from "ambiguous" rather than "wrong"
   from "correct" — strictly worse, permanently.

## Sub-item rulings (YAML blockers)
- **F-1 SESSION_CLOSE:** YES, in lockstep, mandatory (condition 2). Parent amends LEDGER C-2 + the
  ticket to name SESSION_CLOSE.
- **F-2 (`net_sales` headline):** ticketed, out of this lane's code scope; the brief's "Already
  correct" justification is FALSE and must be annotated; LEDGER C-2's "headline totals correct"
  sentence must be struck. Refinement: the headline is already contradicted by the sealed
  SALE_RECEIPT corpus today; M2-alone replaces one inconsistency with a narrower intra-Z one while
  making the per-rate rows consistent with the receipt corpus for the first time — a genuine
  absolute improvement.
- **If owner overrides to A:** stamped-but-unenforced v2 is NOT sufficient; A3 mandatory
  (SUPPORTED_VERSIONS must gain Z/X/SESSION_CLOSE `[1,2]` or 100% quarantine), A5/A6 required,
  A4/A7 optional; ordering owned by the device/deploy operator, three-phase rollout against D-3.
- **Q2:** accepted open by this gate, explicitly (condition 3).

## Sequencing recommendation to the parent (not a scope expansion)
Open the **F-2 sibling lane now** (headline `net_sales` fix — device-local, same three functions,
no server change; `ZReportProjection.php:149` is a passthrough). **Bind both fixes to ONE device
build** (the delivery vehicle is the build, not the merge — R-6/D-1). Prefer, do not block: C-2 may
ship alone if F-2 slips, but tenant #1 onboarding on a build with C-2-without-F-2 is an
owner-visible residual (internally inconsistent Zs by the full VAT + wrong NF525 `<VentesNettes>`
from event one). File **F-4 and F-5 with the F-2 lane; F-4 must land AFTER F-2** (a post-C-2,
pre-F-2 Z would fail the F-4 tripwire).
