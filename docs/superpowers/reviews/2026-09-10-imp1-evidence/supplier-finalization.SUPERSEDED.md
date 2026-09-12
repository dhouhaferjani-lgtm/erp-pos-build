# `supplier-finalization.json` is HISTORICAL — superseded by dev `477c877a3`

`supplier-finalization.json` records the **pre-fix** behaviour of `imp1-suppliers-balances-200.xlsx` (status `failed`, 200 committed rows, `error_message` naming `CurrencyScaleResolver::getScale()`). dev commit `477c877a341a8f228c01489c04243befc229d3e5` ("Phase 0.1.6: Fix queued supplier balance currency scaling") fixed that defect, so the same file now finalizes `completed` with 200 imported / 0 failed and no error message. Keep the file as the reproduction record; do not cite it as current behaviour.
