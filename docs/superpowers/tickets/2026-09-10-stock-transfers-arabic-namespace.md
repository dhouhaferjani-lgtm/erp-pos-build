# Translate the stock-transfers Arabic namespace

Status: deferred beyond S1; owner: frontend/localization.
Authority: plan rev 10 §0AB, T-9(r2), F-12.

Translate the full 121-key namespace identified at the round-2 dispatch, plus keys added since that census (the preserved in-transit confirmation now makes 122 English keys). Include interpolation and Arabic plural variants, and obtain language review before wiring the namespace. The current Arabic UI deliberately serves the whole namespace through the English alias.

S1 restores `apps/web/src/lib/i18n.ts` byte-identical to `93b106461` and removes the three-key overlay. Do not regenerate or re-pin the completeness baseline to absorb missing translations. Validate the future complete bundle with the protected i18n audit and focused UI tests before any owner-controlled baseline update.

The ignored fix-round-1 draft is unreviewed reference material only; it is not an approved translation or shipped resource.
