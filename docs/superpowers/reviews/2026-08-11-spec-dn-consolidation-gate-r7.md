# DN-consolidation spec — micro-gate round 7

## 1. Gate verdict: FAIL

R5-ND-1 is closed: §6.1 now supplies five named positive fixtures in a one-to-one row-level crosswalk with §2.3 Layer 2, requires every fixture to be reported, retains both existing negatives, and expressly disclaims per-method-variant fixture coverage. The gate still fails on one new bookkeeping defect: the live status banner remains the r6 pre-gate banner and contradicts the r7 revision state and log.

## 2. Verification

| Item | Verdict | Verification and assessment |
|---|---|---|
| **R5-ND-1 — positive fixture coverage and envelope** | **CLOSED; gate blocked by R7-ND-1** | The five covered-form rows map one-to-one to: (1) `DeliveryNoteBillingPropertyAssignFixture` using `Document::$payload['invoiced_at']` array-dimension assignment; (2) `DeliveryNoteBillingArrayWriteFixture` using `Document->update([...])` as the declared representative of the `update`/`create`/`fill`/`forceFill` family; (3) `DeliveryNoteBillingMarkerTableInsertFixture` using literal `DB::table('delivery_note_billing_marks')->insert([...])`; (4) `DeliveryNoteBillingMarkerModelWriteFixture` using fixture-local marker-model `::create([...])`; and (5) `DeliveryNoteBillingRawStatementFixture` using literal `DB::statement(...)`. §6.1 says each is **reported**, retains the service-internal and unrelated-model negative fixtures, and fixes the envelope at **one fixture per table row, never one per method variant**. No substantive edit outside §6.1 plus r7 revision/provenance bookkeeping was identified from the r7/GATE6-tagged sites. A byte-for-byte r6→r7 confinement check is unavailable because the spec and r6 gate are untracked and no r6 snapshot exists in Git. |

## 3. New defects

| ID | Defect | Required correction |
|---|---|---|
| **R7-ND-1** | The live status banner at line 5 still says the document was revised after **gate r5**, describes the r5 verdict, claims all four R5 defects are closed, and publishes `gate r6 → owner read → Codex build dispatch`. In r7, gate r6 is already the failed predecessor and R5-ND-1 is closed only by this revision. The nearby header summary also still says unqualifiedly that “r6 closes all four” (line 21). This contradicts the r7 header at line 3 and the append-only r7/r6 correction log at lines 1000–1020. | Update the live status/header summary to the post-gate-r6 state and next gate path while preserving the historical r6 log and its r7 correction note. |
