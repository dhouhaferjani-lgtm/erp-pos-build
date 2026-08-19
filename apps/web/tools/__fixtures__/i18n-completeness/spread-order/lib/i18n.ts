// FIXTURE — production-shaped i18n wiring for audit-i18n-completeness tests.
// Deliberately reproduces the two mechanisms that make the MERGED `resources`
// object lie about Arabic coverage in the real src/lib/i18n.ts:
//   1. whole-namespace English aliasing   (real: i18n.ts `auth: enAuth` under ar)
//   2. `{ ...en, ...partialAr }` spreads  (real: i18n.ts `sales`, `treasury`, …)
// A scanner reading `resources` sees vacuous full parity; the audit must read
// the AUTHORED locale source files instead (gate-r1 H-5).

import enAlpha from '../locales/en/alpha.json'
import enBeta from '../locales/en/beta.json'
import frAlpha from '../locales/fr/alpha.json'
import frBeta from '../locales/fr/beta.json'
import arBeta from '../locales/ar/beta.json'

const resources = {
  en: {
    alpha: enAlpha,
    beta: enBeta,
  },
  fr: {
    alpha: frAlpha,
    beta: frBeta,
  },
  ar: {
    // ar has no alpha bundle: fall back to English for the whole namespace.
    alpha: enAlpha,
    // FIXTURE TAMPER: the spread order is REVERSED relative to prod-shaped.
    // `...enBeta` is applied LAST, so English wins every key and nothing
    // `locales/ar/beta.json` authors is ever served — even though both
    // identifiers appear, exactly as in the `english-spread` shape.
    beta: { ...arBeta, ...enBeta },
  },
}

void i18n.init({
  resources,
  fallbackLng: 'en',
  defaultNS: 'alpha',
  ns: ['alpha', 'beta'],
})
