// FIXTURE — edge cases for audit-i18n-completeness:
//   * `locales/en/orphan.json` exists on disk with NO entry in the `ns` array
//     (the escape `missingScannedSurface` cannot see, because a namespace with
//     no pinned finding has nothing to be missed);
//   * `step_one` / `tier_two` are ORDINARY keys that merely end in a CLDR
//     category name — they must NOT be treated as plural families;
//   * `items_one` / `items_other` and `files_one` ARE plural families (the
//     second proven by `{{count}}` alone, with a single authored form).

import enGamma from '../locales/en/gamma.json'
import frGamma from '../locales/fr/gamma.json'

const resources = {
  en: {
    gamma: enGamma,
  },
  fr: {
    gamma: frGamma,
  },
}

void i18n.init({
  resources,
  fallbackLng: 'en',
  defaultNS: 'gamma',
  ns: ['gamma'],
})
