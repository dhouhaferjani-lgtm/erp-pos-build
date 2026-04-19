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
}
