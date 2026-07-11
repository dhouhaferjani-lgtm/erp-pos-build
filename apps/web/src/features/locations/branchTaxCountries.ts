/**
 * Countries where a SHOP location must carry its own tax ID (branch-level
 * fiscal identity). Mirrors the backend `config/tax_identity.php` list.
 *
 * Single source of truth for the frontend — import this instead of
 * duplicating the country list per component.
 */
export const BRANCH_TAX_ID_REQUIRED_COUNTRIES = ['FR', 'TN', 'MA'] as const

export type BranchTaxIdRequiredCountry = (typeof BRANCH_TAX_ID_REQUIRED_COUNTRIES)[number]

/**
 * Whether the given ISO country code requires a branch tax ID for shops.
 * Tolerates untrimmed / lowercase input from form state.
 */
export function isBranchTaxIdRequiredCountry(countryCode: string): boolean {
  return (BRANCH_TAX_ID_REQUIRED_COUNTRIES as readonly string[]).includes(
    countryCode.trim().toUpperCase(),
  )
}
