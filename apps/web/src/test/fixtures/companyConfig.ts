import type { CompanyConfig } from '@/contexts/CompanyConfigContext'

/**
 * Seed value for CompanyConfigProvider in tests.
 *
 * The real provider fetches `/company/config` via TanStack Query (gated on
 * auth). `renderWithProviders` pre-populates the query cache with this value
 * so the provider resolves synchronously without network and without any
 * changes to the production fetch path.
 *
 * We alias the real `CompanyConfig` interface rather than re-declaring a
 * narrower shape: this keeps fixtures type-safe against the contract the
 * provider exposes, and means tests reading `config.currency` or any other
 * field pick up signal from the real type.
 */
export type TestCompanyConfig = CompanyConfig

export const defaultCompanyConfig: TestCompanyConfig = {
  vertical: 'generic',
  default_modules: ['Identity', 'POS', 'Inventory'],
  enabled_extras: [],
  all_enabled_modules: ['Identity', 'POS', 'Inventory'],
  currency: 'EUR',
  locale: 'en',
  country_code: null,
  smart_prompts_enabled: false,
  smart_prompts_variant: 'off',
  line_designation_override_enabled: false,
}

export const pharmacyCompanyConfig: TestCompanyConfig = {
  vertical: 'pharmacy',
  default_modules: ['Identity', 'Menu', 'Tables', 'POS'],
  enabled_extras: ['loyalty'],
  all_enabled_modules: ['Identity', 'Menu', 'Tables', 'POS', 'loyalty'],
  currency: 'EUR',
  locale: 'en',
  country_code: null,
  smart_prompts_enabled: false,
  smart_prompts_variant: 'off',
  line_designation_override_enabled: false,
}

/**
 * Parapharmacy vertical seed. Mirrors the backend `parapharmacy` vertical whose
 * `default_modules` include the `Parapharmacy` module, so
 * `hasModule('Parapharmacy')` is true. Used by gating tests that verify
 * parapharmacy-flavored UI only renders for parapharmacy tenants.
 */
export const parapharmacyCompanyConfig: TestCompanyConfig = {
  vertical: 'parapharmacy',
  default_modules: ['Identity', 'POS', 'Inventory', 'Parapharmacy'],
  enabled_extras: [],
  all_enabled_modules: ['Identity', 'POS', 'Inventory', 'Parapharmacy'],
  currency: 'EUR',
  locale: 'en',
  country_code: null,
  smart_prompts_enabled: false,
  smart_prompts_variant: 'off',
  line_designation_override_enabled: false,
}

/**
 * Non-parapharmacy vertical that has the Parapharmacy module enabled as an
 * opt-in extra. Exercises the "robust to extras" property: module-based gating
 * (hasModule('Parapharmacy')) must show parapharmacy UI here, whereas the old
 * `vertical === 'parapharmacy'` string check would (incorrectly) hide it.
 */
export const genericWithParapharmacyExtraCompanyConfig: TestCompanyConfig = {
  vertical: 'generic',
  default_modules: ['Identity', 'POS', 'Inventory'],
  enabled_extras: ['Parapharmacy'],
  all_enabled_modules: ['Identity', 'POS', 'Inventory', 'Parapharmacy'],
  currency: 'EUR',
  locale: 'en',
  country_code: null,
  smart_prompts_enabled: false,
  smart_prompts_variant: 'off',
  line_designation_override_enabled: false,
}

/**
 * Mechanic vertical seed with automotive modules (Vehicle, Workshop).
 * Used by guard + sidebar tests that cover module visibility per vertical.
 */
export const mechanicCompanyConfig: TestCompanyConfig = {
  vertical: 'mechanic',
  default_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Inventory'],
  enabled_extras: [],
  all_enabled_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Inventory'],
  currency: 'TND',
  locale: 'fr_TN',
  country_code: 'TN',
  smart_prompts_enabled: false,
  smart_prompts_variant: 'off',
  line_designation_override_enabled: false,
}

/**
 * Mechanic vertical with enabled extras (Fleet, Appointments).
 * Used to verify guards allow access to opt-in extras.
 */
export const mechanicWithExtrasCompanyConfig: TestCompanyConfig = {
  vertical: 'mechanic',
  default_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales'],
  enabled_extras: ['Fleet', 'Appointments'],
  all_enabled_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Fleet', 'Appointments'],
  currency: 'TND',
  locale: 'fr_TN',
  country_code: 'TN',
  smart_prompts_enabled: false,
  smart_prompts_variant: 'off',
  line_designation_override_enabled: false,
}
