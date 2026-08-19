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
    // FIXTURE: TWO sibling object literals at the SAME brace depth. The first
    // reverses the order (English last -> English wins that subtree); the second
    // is English-first and harmless. Depth-keyed classification let the second
    // sibling overwrite the record of the first and reported `english-spread`.
    beta: {
      ...enBeta,
      ...arBeta,
      reversed: { ...arBeta, ...enBeta },
      normal: { ...enBeta, ...arBeta },
    },
  },
}

void i18n.init({
  resources,
  fallbackLng: 'en',
  defaultNS: 'alpha',
  ns: ['alpha', 'beta'],
})
