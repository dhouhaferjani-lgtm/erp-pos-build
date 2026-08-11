# Owner decision sheet — event-sourcing remediation program (2026-08-11)

These 16 items unblock the critical path of the event-sourcing remediation program. Answer in place (edit this file) or verbally — either is fine. Where a recommendation is given below, it is the **orchestrator's recommendation, not a decision**: nothing here is ruled until the owner rules it. All wording is sourced from the three dossiers listed under each item; if a summary here drifts from the dossier text, **the dossier text is authoritative**.

Source dossiers:
- **Handover** = `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md`
- **SV** = `docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md`
- **OP** = `docs/handoff/FINDINGS-other-problems-2026-08-11.md`

---

## Section 1 — Blocks Lane A1 (the v3 blackout fix — answer these FIRST)

### D-1 (= Handover Q7) — ES-01 emission shape
`PosCoreReceiptProjection` deliberately calls loyalty directly to avoid double-earning, so emitting `ReceiptCompleted` from the projector is not available. Does the NF525 `TICKET` audit row come from a **new event class**, or from a **direct audit write inside the projector**? (Handover §8 Q7: *"It sets the precedent every other projector fix follows."*)
**Blocks:** Lane A1.
**Options:** new event class · direct audit write inside the projector.
**Orchestrator recommendation:** a NEW event class (not `ReceiptCompleted` — the loyalty double-earn exclusion stands), with explicitly scoped consumers. A direct audit write would reproduce the exact gap class (state changes with no event) this program exists to close.

### D-2 (= Handover Q8) — blackout-window backfill policy
The v3 era currently has zero `audit_events` `TICKET`/`OUVERTURE`/`FERMETURE`/`RAPPORT_Z` rows. Reconstruct them from `fiscal_events` (which hold the underlying data), or accept a documented hole with a stated start date? (Handover §8 Q8: *"Compliance-record decision, and it changes A1's shape substantially."*)
**Blocks:** Lane A1 exit.
**Options:** reconstruct from `fiscal_events` · accept a documented hole with a stated start date.
**Orchestrator recommendation:** reconstruct — the data exists. Route the compliance-acceptability half of this decision to the partner-accountant, on the same queue as the E-4 (TN legal) question.

---

## Section 2 — Blocks the shift-variance money stages (Stage 0 of dossier SV)

### D-3 (= Handover Q4 + SV Stage 0.3 / E-3 — same decision, stated once)
Do drawer DEPOSIT/PAYOUT get **their own justifying document** (document-per-action principle), or is a Treasury booking listener on the existing `CashDrawerOperationRecorded` event sufficient — and how are they typed for GL purposes (bank vs petty cash)? (Handover §8 Q4: *"The owner's own document-per-action principle is at stake; the archaeology deliberately left this open."* SV Stage 0.3 / `15-…:283`: *"E-3 — do DEPOSIT/PAYOUT post to the GL, and how are they typed (bank vs petty cash)?"*)
**Blocks:** SV Stage 0 → gates Stage 3 (SV-3 float/DEPOSIT/PAYOUT booking) and the GL-flag flip condition (item 1).
**Options:** own justifying document per drawer op · Treasury booking listener only, no new document.
**Orchestrator recommendation:** own justifying document — the standing document-per-action principle says every cash mutation needs its own document. Note SV-13's double-booking guard (`CashDrawerController::deposit`/`payout` has no v3 gate and is a competing server rail to the device `CASH_IN`/`CASH_OUT` rail) must reconcile either way this is decided.

### D-4 (= SV Stage 0.2 / E-2) — float modelling shape
Model the opening float as a **Safe→Till transfer document** (using the existing `RepositoryTransferService` + `createRepositoryTransferJournalEntry`) or as a **dedicated imprest entity**? (SV `15-…:282`, restated at SV §3 Stage 0.2: *"Determines SV-3's document shape."*)
**Blocks:** SV Stage 0 → determines SV-3's (float booking) document shape, which determines Stage 3 sequencing.
**Options:** Safe→Till transfer document · dedicated imprest entity.
*(No recommendation given — present options only, per instruction.)*

### D-5 (= SV Stage 0.4 / E-4) — negative-till behaviour on an OUT variance
Should `insufficient_repository_balance` continue to **refuse** the booking when a shortfall would take the cash-register repository balance negative, or should it **record-and-alert** instead? SV notes this should be re-asked **after** D-4 is answered, since it becomes much less likely once the float is booked (SV §3 Stage 0.4: *"re-ask after 0.2"*; SV-18: *"asks whether an OUT variance that would take a till negative should refuse (today) or record-and-alert."*).
**Blocks:** SV-18 / the flip condition item 6.
**Options:** refuse (current behaviour) · record-and-alert.
*(No recommendation given — present options only, per instruction.)*

### D-6 (= SV Stage 0.5 / E-8) — TND variance thresholds + alert severity
Confirm or revise the TND thresholds (soft `1.0000` / hard `20.0000`, `CompanyFraudSettings.php:54-57`) and `cash_variance_email_severity` (currently `'none'`). SV notes: *"Alert volume changes on deploy regardless of the flag"* (SV `15-…:288`, §D.3-10).
**Blocks:** Stage 0 of the SV dossier; independent of the GL-flag flip but affects alert volume on deploy.
**Options:** keep current thresholds/severity · revise thresholds and/or set a non-`'none'` email severity.
*(No recommendation given — present options only, per instruction.)*

### D-7 (= SV §1 "Not ruled" / E-5) — Toast-style two-stage deposit reconciliation
Approve a Toast-style two-stage deposit reconciliation flow as a **follow-on lane**, or drop it from scope entirely? (SV §1: *"Not ruled (still owner-owed) ... Toast-style two-stage deposit reconciliation as a follow-on lane (R15 E-5, `15-…:285`)."*)
**Blocks:** nothing critical-path — it does not gate Stage 0-5 or the flip condition, but needs a disposition so it isn't silently absorbed into this program's scope.
**Options:** approve as a follow-on lane, scoped separately · drop.
**Orchestrator recommendation:** approve as a follow-on lane — not in this program's scope.

---

## Section 3 — Gates later lanes (answer before those lanes dispatch)

### D-8 (= Handover Q1) — ES-06 second-approver + correcting-event design
Should resolving a parse failure on a **sealed** fiscal event require a second approver, and should the correction be recorded as a **new correcting fiscal event** rather than an in-place payload rewrite? If the payload can never be re-derived from `canonical_bytes`, what is the accepted record of truth? (Handover §8 Q1: *"It is a compliance-posture decision with an expert-comptable dimension, not an implementation choice."*) Lane A0 builds the *detection* half regardless — only the *workflow* half waits on this ruling.
**Blocks:** A0's workflow half (detection ships either way).
**Options:** second approver + new correcting fiscal event · in-place payload rewrite (current, unsafe state) · other correction-of-record scheme.
**Orchestrator recommendation:** second approver + new correcting fiscal event, never an in-place rewrite; flag the expert-comptable dimension for the same review queue as D-2 and D-11.

### D-9 (= Handover Q2) — is Billing live for tenant #1?
If no, Lane G's Billing work (ES-34) is post-launch and the SUSPECTED finding can stay unverified longer. If yes, it needs a real audit before any event work. (Handover §8 Q2: *"Scope/launch decision."*)
**Blocks:** Lane G.
**Options:** not live for tenant #1 (defer) · live (audit now).
**Orchestrator recommendation:** no — cash-only first tenant → Lane G is post-launch.

### D-10 (= Handover Q3) — V1/V2 event disposition
Migrate the live consumers to V2 (also fixes channel-sync and fraud-audit variant blindness, ES-75/ES-28) — or retire the V2 dispatches? Rule 8 (events immutable forever) permits either disposition; they have opposite costs. (Handover §8 Q3: *"Architectural direction with a rule-8 interpretation in it."*)
**Blocks:** Lane F, and Lane E's ES-28.
**Options:** migrate live consumers to V2 · retire V2 dispatches.
**Orchestrator recommendation:** migrate to V2, given the channel lane launches with PrestaShop/Woo connected.

### D-11 (= Handover Q5) — unkeyed SHA-256 vs NF525 signature requirement
`compliance.md` lists RSA-2048/ECDSA-256 as an NF525 requirement; the chain is currently unkeyed SHA-256 and the `signature_*` columns are unexercised (ES-43). Acceptable for the TN first tenant, deferred to the FR lane, or launch-blocking? (Handover §8 Q5: *"Regulatory acceptance decision."*)
**Blocks:** A0 exit scope.
**Options:** acceptable for TN tenant #1 as-is · defer to the FR lane · launch-blocking (fix now).
**Orchestrator recommendation:** defer to the FR lane with documented TN acceptance; confirm with the partner-accountant (same E-4 queue as D-2).

### D-12 (= Handover Q6) — ES-26 batch inter-location transfer
Give it its own document type, or route it through `StockTransferService` and retire `BatchStockService::transferBatchStock`? (Handover §8 Q6: *"Domain-model decision with a UI consequence."*) This also gates Lane E's entry criteria per Handover §3 ("Per-lane entry criteria" table).
**Blocks:** Lane E.
**Options:** own document type · route through `StockTransferService`, retire `BatchStockService::transferBatchStock`.
*(No recommendation given — present options only, per instruction.)*

### D-13 (= Handover Q9) — RETOUR representation shift expert ratification
Expert-comptable ratification of the RETOUR representation shift (`01/O-4`) is recorded as still **OWED**. Does it gate anything in this program? (Handover §8 Q9: *"Pre-existing owed item; this session should not silently assume it is closed."*)
**Blocks:** Lane C sequencing (potentially — needs the owner to confirm whether it gates or not).
**Options:** gates Lane C until ratified · does not gate, proceed and ratify in parallel.
*(No recommendation given — present options only, per instruction. Relay to the expert-comptable via the existing question queue regardless of the ruling.)*

### D-14 (= Handover Q10) — ES-64 GR-IR backfill intent
`BackfillGoodsReceiptsCommand` creates receipts with no `GoodsReceived` event, so no GR-IR liability is posted. Is this intended (needs a documentation note) or a permanent GL hole (needs a corrective run)? (Handover §8 Q10: *"Requires knowing whether the backfilled population is live in any tenant."*)
**Blocks:** Lane E.
**Options:** intended, document it · unintended GL hole, run a corrective backfill.
**Note:** the orchestrator will run a data probe — is the backfilled population live in any tenant? — and attach the result here before this item needs an owner answer.

---

## Section 4 — Small/standalone

### D-15 (= OP-09 / A-2) — accountant POS grant scope
What permission scope does the accountant role get on POS data? (OP §B, OP-09: *"A-2 — accountant POS-permission grant scope open: `pos.view_receipts` only, `+pos.view_reports`, or `+dashboard.owner`? | P2 | OPEN OWNER QUESTION | Owner ruling, then seeder grant + cache-reset."*)
**Blocks:** nothing critical-path; standalone permission grant.
**Options:** `pos.view_receipts` only · `+ pos.view_reports` · `+ dashboard.owner`.
*(No recommendation given — present options only, per instruction.)*

### D-16 (= OP-06 / E-7) — standing reminder, not a question
**Not a decision to make here.** The E-7 launch-gate Status cell is still blank — Lane C merged but is not yet human-signed-off, and this is a human-only action (no code fix closes it). (OP §A, OP-06: *"E-7 launch gate Status cell still blank — Lane C merged but not human-signed-off. | P1 | RULED-AWAITING-EXECUTION (human) | Owner/runbook: mark E-7 explicitly."*)
**Action needed:** the owner (or whoever runs the launch runbook) marks E-7's Status cell in `OWNER-manual-launch-gates-2026-07-31.md:45`.

---

## Answer log

| ID | Answer | Date |
|---|---|---|
| | | |
