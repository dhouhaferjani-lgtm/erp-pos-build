# A4 Frontend Conventions Review — Round 2

- **Reviewer:** `frontend-conventions-reviewer`
- **Model:** Opus
- **Scope:** A4 after round-one fixes
- **Mode:** Read-only

## Verified fixed

Legacy-delta preview, replay UoM precision, block-reason visibility, horizontal overflow, finalized skipped-adjustment semantics, and finalized replay-audit coverage were fixed. Baselines were untouched and quantity arithmetic remained string-based.

## Remaining findings

- **MAJOR:** Older theoretical/final/count cells and the manual-override dialog still used storage-scale display, producing two precisions in one row (`ReconciliationTable.tsx:174,508,535`, `ManualOverrideDialog.tsx:68-85`).
- **MAJOR:** The exact legacy-delta frontend branch fixed from round one lacked a regression test (`ReconciliationTable.tsx:103-104`).
- Minor presentation points: move the legacy mode label out of the movements quantity cell, consume `will_auto_post`, normalize signed zero, avoid mixed translated fragments, and remove the lone inconsistent `scope="col"`.

## Verdict

**VERDICT: NEEDS REVISION**
