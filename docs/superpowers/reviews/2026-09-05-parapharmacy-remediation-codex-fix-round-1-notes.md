# Parapharmacy remediation — Codex spec fix round 1 notes

Date: 2026-09-05. **Documentation complete; awaiting Fable gate r2.** This is a handback of proposed spec changes, not a reviewer verdict or implementation approval.

Read the [handover](../../handoff/HANDOVER-parapharmacy-remediation-codex-spec-fix-round-2026-09-05.md) in full before changes, then its four inputs in order: audit, spec v1, Fable r1, root-cause archaeology. Source citations remain anchored at `b9a5565aa`. Working HEAD was `f75aa5023` when checked; all 48 explicitly named source files in v2 were compared with their `b9a5565aa` contents and were unchanged. No checkout or branch mutation was performed.

## Changed documents

- [Spec v2, revised in place](../specs/2026-09-05-parapharmacy-readiness-remediation-design.md): top change log, extended benchmark, W0, consolidated W-LOT, corrected financial packages, dependencies and stop condition.
- [Glossary](../../glossary.md): added **Lot evidence** and **Session reconciliation**, with proposed versus shipped storage/write paths and canonical operator surfaces distinguished.
- This fix-round note. The original r1 review, archaeology and tickets remain unchanged; no ticket is declared implemented or closed.

## Fable findings addressed

| Finding | v2 correction |
|---|---|
| F-A | W2 retires the first-active device repository picker, requires one authored binding, evaluates a new sealed SALE_RECEIPT repository reference using the ACCOUNT_PAYMENT precedent, reduces classification to cash/maturity/settlement and names the H-3 reversal behind OPEN RD3. Existing projected-leg idempotency and frozen replay policy are preserved |
| F-B | D7 compares sealed line fields with a separate evidence stream, including atomicity, ordering and append-only corrections; W6 iteration 2 is behind D3/D7. No lot evidence stream or sealed lot change is required by R3 iteration 1 |
| F-C | W3 removes the prescribed lock order, requires a complete writer/lock census and PostgreSQL concurrency proof, cites unlocked max+1 and the chain index, and puts the proposed Document partial source index behind duplicate/schema/discriminator checks |
| F-D | W7 reuses and extends ShiftExpectedCashService for device-versus-fiscal comparison; repository comparison is separate and unavailable until RD4 coverage. It preserves existing membership checks and distinguishes fiscal matching, lot evidence and custody. Glossary noun registered |
| F-E | W1 is treasury-only and includes repository adjustments in the scope census/acceptance. W-LOT narrows new batch enforcement to recall/destroy/read routes, preserves existing FormRequest checks, and leaves manager recall OPEN as RD2 |
| F-F | W4 explicitly preserves transactional row seeding and fixes only registry exception exclusion; scheduled recovery covers lost enqueue at attempts=0 and abandoned running, with worker/effect idempotency and quarantine containment |

**No F-A..F-F finding was rejected.** Review suggestions were adapted to owner rulings: immediate captured checkout is deferred by R3, D7 stays unselected, and W5/W6 are internal sections of one W-LOT package. The review's broad “single derivation” wording is qualified by ShiftExpectedCashService's own legacy-consumer comments; reuse is required without claiming every historical consumer already shares it.

## Owner rulings and archaeology

R1 is represented by ordered gaps L1–L8 and one W-LOT acceptance matrix: permissions, used-lot correction, exact issue/transfer availability, real-lot counting and DEFAULT inflation (superseding T22/OQ-7 in design), provenance labels, scheduled drift detection, POS guidance and gated capture. W-LOT requires two companies, two locations, two real lots and re-run idempotency, plus two terminals where relevant.

R2 is explicit in W-LOT **and W6** for device, web, sync data and worker legs. One source qualification is flagged for r2: `PosCoreReceiptProjection.php:2026` calls the product-flag check at `FEFOInventoryService.php:930–934`; this alone does not prove module entitlement. The required no-module/no-lot behavior stands, with upstream/return-path census and acceptance rather than an unsupported claim that every path already enforces it.

R3 iteration 1 is read-only FEFO suggestion on tile/cart, branch cache and D4 freshness, no selection/captured evidence and unchanged sealed lot contract. B9/B10 benchmark that visibility, clearly marking unverified competitor placement/freshness. Odoo POS display uses an explicit version-18 primary reference because the version-19 page could not be fetched reliably; ERPNext POS Invoice editing is not asserted to prove register-tile behavior. R4 keeps targeted architectural corrections.

W0 includes all seven archaeology guards and the mechanism each addresses: route permission ratchet, location coverage census, full resolver matrix, lot-evidence vocabulary/completeness, PostgreSQL concurrency, standing lost-work recovery rows and `_pins_limitation_` markers. These are proposed later deliverables, not tests implemented in this round.

## Still open and stopping point

The **single authoritative decisions table** is spec §2.3. All ten entries remain OPEN: D1 legal-company structure; D2 surfaces/tenders; D3 required retail lot evidence; D4 offline freshness; D5 custody reach; D6 cross-branch returns; D7 iteration-2 evidence transport; RD2 manager recall; RD3 H-3 fallback reversal; RD4 Treasury float/drop coverage before repository comparison. Recommendations are not owner rulings.

Checked document structure, source references against the required snapshot and the scope of changed files. **No product code, migrations, test edits/runs, runtime configuration, commits, merges, rebases, pushes or reviewer calls.** Fable 5.1 alone commits path-scoped, runs gate r2 and sequences any subsequent work. Codex stops here.
