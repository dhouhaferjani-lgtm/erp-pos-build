# POS device Arabic locale and RTL lane

At the reviewed content baseline `df85d43f4`, there is no `ar` tree under `apps/pos/src/locales/`; only `en/` and `fr/` exist. `apps/pos/src/lib/i18n.ts` registers exactly those two languages, sets `lng: 'en'` and `fallbackLng: 'en'`, and registers the four `common`, `pos`, `smart-prompts`, and `fiscal` namespaces.

SV-11 adds whole-drawer cash-count instructions and a six-line reconciliation reveal in those supported locales. Arabic is intentionally not fabricated inside that presentation-only change. The informational owner gate `sv11-arabic-device-locale` is the decision that opens or closes a separate device-Arabic lane; it does not block the English/French shipment.

## Scope

- Decide the supported Arabic locale tag and translation ownership.
- Add the Arabic POS namespace with reviewed translations for the complete cash-count flow, including the SV-11 instruction, float disclosure, and six reveal labels.
- Add right-to-left direction at the device application boundary and verify the POS touch surfaces: cash-count table, numpad, reveal summary, manager authorization, and variance-reason controls.
- Preserve locale-appropriate decimal and currency presentation without changing decimal-string money arithmetic.

## Acceptance evidence

- Translation review records the approved Arabic strings and locale tag.
- Rendered component tests cover the whole-drawer instruction interpolation and all six reveal lines in Arabic.
- RTL visual evidence covers narrow and standard device widths with no clipped labels or reversed numeric values.
- English and French regression tests remain green.

This follow-up does not block the current English/French SV-11 shipment.
