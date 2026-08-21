# ar-backfill — FR/EN divergence picks + out-of-scope debt

**Lane:** `fix/ar-locale-coverage` (worktree `.worktrees/ar-backfill`), branched from `dev` @ `c6d6308ae`.
**Scope:** Arabic translation backfill — 145 missing keys (3 red `arLocaleCoverage` tests) + 9 CLDR plural
completions, plus 7 enabling `finance overview.echeancier.*` keys.
**Translation rule applied:** FR bundle is the primary reference (audience = Tunisian business users),
EN as cross-check.

## Why this note exists

For five keys the FR and EN source strings did not say the same thing, so "translate from FR with EN as
cross-check" did not resolve on its own and I had to pick a side. Those picks are judgement calls, not
mechanical translation, so they are recorded here for owner review rather than being buried in the diff.
Recorded per gate finding P3-2.

## The five picks

| Key | EN source | FR source | Pick | Why |
|---|---|---|---|---|
| `common services.fields.defaultDuration` | `Default Duration (minutes)` | `Durée par défaut` (no unit) | **EN** — `المدّة الافتراضية (بالدقائق)` | The field is stored in minutes. Dropping the unit makes the input ambiguous at the point of entry; FR is the lossy side here. |
| `common partner.documentType.sales_order` | `Sales Order` | `Bon de commande` | **EN** — `أمر بيع` | FR is ambiguous: `bon de commande` commonly reads as a *purchase* order, and the sibling `purchase_order` is already `Commande fournisseur`. Following FR would collide the two document types in Arabic. |
| `common navigation.expiryWriteOff` | `Expiry Write-off` | `Radiation de lots périmés` | **FR** — `إعدام الدفعات منتهية الصلاحية` | FR is the more precise side: the feature writes off expiring *batches*, not products. Kept `الدفعات`. |
| `common locations.modal.codePlaceholder` | `WH-001` | `ENT-001` (localized prefix) | **EN** — `WH-001` | Location codes are ASCII identifiers stored as-is. A localized sample prefix would suggest the code itself should be localized. |
| `common locations.modal.emailPlaceholder` | `warehouse@company.com` | `entrepot@entreprise.com` | **EN** — `warehouse@company.com` | Same reasoning: an email sample is illustrative, and a French-localized local-part is not a better hint for an Arabic-reading user than the neutral English one. |

## Related observation (not a divergence, no action taken)

`common pos.inCart` — the key name reads "in cart" but **both** EN and FR say `Added` / `Ajouté`.
Translated the value (`تمت الإضافة`), not the key name. Flagging the key/value mismatch only; renaming a
live key is out of this lane's scope.

## Out-of-scope debt recorded, NOT touched

**P3-5 — pre-existing `Syneriva` brand misspelling in `apps/web/src/locales/ar/inventory.json`**
(canonical spelling is `Synerivia`). Verified present at exactly two sites:

- `:143` `"catalogFound": "بيانات من كتالوج Syneriva"`
- `:149` `"enrichmentDescription": "سيتم إثراء بيانات المنتج بواسطة Syneriva بعد الحفظ"`

This is Wave-4 brand-spelling debt (the same `UI-37` class that `appNamePlaceholder.test.ts` explicitly
scopes out of its own assertions). It predates this lane, sits in a namespace this lane does not touch,
and was left untouched deliberately.
