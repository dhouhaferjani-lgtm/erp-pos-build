import { getDecimals } from './currencyMeta'
import { formatCurrency as formatLocaleCurrency, formatDecimalAmount } from './format'

/**
 * Format currency based on locale and currency code
 *
 * This utility handles locale-specific currency formatting:
 * - For English: "TND 1,234.560"
 * - For French: "1 234,560 TND"
 * - For Arabic: "١٬٢٣٤٫٥٦٠ د.ت" (Arabic numerals + Arabic currency symbol)
 *
 * Decimal places are determined by the currency (TND=3, EUR=2, etc.).
 *
 * @param amount - The numeric amount to format
 * @param currencyCode - ISO 4217 currency code (e.g., "TND", "EUR", "USD")
 * @param locale - BCP 47 locale string (e.g., "en", "fr", "ar")
 * @returns Formatted currency string
 */
export function formatCurrency(
  amount: number | string,
  currencyCode: string,
  locale: string
): string {
  const decimals = getDecimals(currencyCode)
  return formatLocaleCurrency(amount, {
    currency: currencyCode,
    locale,
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
}

/**
 * Format currency for display in tables and lists (shorter format)
 *
 * @param amount - The numeric amount to format
 * @param currencyCode - ISO 4217 currency code
 * @param locale - BCP 47 locale string
 * @returns Formatted currency string without currency symbol for compact display
 */
export function formatCurrencyCompact(
  amount: number | string,
  currencyCode: string,
  locale: string
): string {
  const decimals = getDecimals(currencyCode)
  return formatDecimalAmount(amount, locale, decimals)
}
