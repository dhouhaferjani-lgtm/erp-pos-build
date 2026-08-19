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
    // FIXTURE TAMPER: identical to prod-shaped except for this ONE trailing
    // comment. It mentions `arAlpha` in prose only — nothing is wired. A
    // classifier that reads comments flips ar.alpha from `en-aliased` to
    // `english-spread`, starts trusting locales/ar/alpha.json, and drops the
    // whole-namespace `aliased` entry into `stale` — which is reported as
    // burn-down PROGRESS, not as a failure.
    alpha: enAlpha, // TODO: swap to arAlpha once the bundle lands
    beta: { ...enBeta, ...arBeta },
  },
}

void i18n.init({
  resources,
  fallbackLng: 'en',
  defaultNS: 'alpha',
  ns: ['alpha', 'beta'],
})
