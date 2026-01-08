/**
 * Format currency based on locale and currency code
 *
 * This utility handles locale-specific currency formatting:
 * - For English: "TND 1,234.56"
 * - For French: "1 234,56 TND"
 * - For Arabic: "١٬٢٣٤٫٥٦ د.ت" (Arabic numerals + Arabic currency symbol)
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
  const numericAmount = typeof amount === 'string' ? parseFloat(amount) : amount

  if (isNaN(numericAmount)) {
    return '0.00'
  }

  try {
    // Use Intl.NumberFormat for locale-aware formatting
    const formatter = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: currencyCode,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })

    return formatter.format(numericAmount)
  } catch (error) {
    // Fallback if currency code is not recognized
    console.warn(`Failed to format currency ${currencyCode} with locale ${locale}:`, error)

    // Simple fallback formatting
    const formatted = numericAmount.toFixed(2)
    return `${currencyCode} ${formatted}`
  }
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
  const numericAmount = typeof amount === 'string' ? parseFloat(amount) : amount

  if (isNaN(numericAmount)) {
    return '0.00'
  }

  try {
    const formatter = new Intl.NumberFormat(locale, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })

    return formatter.format(numericAmount)
  } catch (error) {
    return numericAmount.toFixed(2)
  }
}
