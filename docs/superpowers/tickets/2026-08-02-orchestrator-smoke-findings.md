# Ticket: 4 findings from the orchestrator's live Playwright smoke (2026-08-02, post-fix-lane)

Independent interactive verification of the landed fix lanes against the running local stack
(demo-pharmacy-tn, owner). The two headline fixes verified working live: invoice edit form
pre-fills Date d'émission (2026-08-02) and an untouched save PRESERVES line tax_rate 19.00 with
stamp-inclusive totals (77.000 / 15.630 / 92.630); credit notes CN-2026-0018/0019 reached
`posted` through the repaired Confirm/Post path.

## 1 — INVESTIGATE (money-facing): amount-based credit note 50.000 posts at 50.174

CN-2026-0019 (and 0018): entered amount 50.000, posted total **50.174** — neither exact (50.000)
nor amount+stamp (50.600). Consistent with 49.574 + 0.600 stamp: the proportional line-allocation
of the amount appears to drift under truncation before the stamp lands on top. Customer is
credited MORE than requested and the figure is unexplainable to an auditor. Needs: root-cause in
the amount-based allocation path (CreditNoteService), a ruling on the contract (does "amount"
mean final total, or base-with-stamp-added → 50.600?), then fix + value-asserting tests.
Note MTP-DOC-19 asserts RELATIONALLY (balance reduction == posted total) so it stays green either
way — a value-asserting case must pin the ruling.

## 2 — P2: edit form's line-tax combobox does not preselect the line's current rate

Edit view of a draft with a 19% line shows "Sélectionner une taxe…" (placeholder) while totals
show the correct TVA. Save preserves the persisted rate (verified live), so display-only — but a
user reading the form sees no tax selected on a taxed line. `DocumentForm`/lines table tax cell.

## 3 — P2: edit form's totals preview omits document-level taxes

Form preview showed Total 91,630; the saved draft is 92.630 (stamp included per 18e61a554). The
FE preview computes lines-only. Displayed-vs-stored divergence on a fiscal figure — align the
preview with the API total (or annotate the stamp line separately in the preview).

## 4 — P1 i18n: sidebar renders raw key `navigation.stockByLocation`

Visible on EVERY page (fr locale): the Inventaire section shows the literal key instead of a
label — rule 11 violation (key exists in nav config but no fr/en translation entry). Check all 3
i18n touchpoints per the i18n context doc; sweep for sibling missing keys in the same namespace
while there.
