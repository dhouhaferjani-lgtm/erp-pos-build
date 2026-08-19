# Remove D-e's inventory-counting exclusion with T21

**Severity:** HIGH — mandatory part of M5/T21.
**Owner:** Inventory + Accounting implementation owner.

Wave 3C excludes `reference_type = inventory_counting` from detector D-e because
count corrections are movement-only until T21 wires their GL entry at the real
counting root. Leaving that exclusion after T21 would hide precisely the failed
or declined count-correction postings D-e must report.

In the same M5 commit that wires `MovementGlKind::CountCorrection`, delete the
temporary D-e exclusion and flip its live-shaped `CountCorrection` fixture from
the M3 negative into a positive missing-entry case. Then add the covered
negative: the same movement with its movement-keyed `inventory_shrinkage`
entry is silent. The T21 change is incomplete while this ticket remains open.
