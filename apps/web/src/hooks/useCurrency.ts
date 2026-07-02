import Big from 'big.js'
import { useCallback } from 'react'
import { useCompanyStore } from '../stores/companyStore'
import { formatCurrency } from '../lib/format'
import { getDecimals, getLocale } from '../lib/currencyMeta'
export { getDecimals, getLocale } from '../lib/currencyMeta'

/**
 * Get the narrowest currency symbol for display (e.g. "€" for EUR, "$" for USD).
 * Falls back to the ISO code (e.g. "TND") when no symbol exists.
 */
export function getCurrencySymbol(currency: string, locale?: string): string {
  const resolvedLocale = locale ?? getLocale(currency)
  const parts = new Intl.NumberFormat(resolvedLocale, {
    style: 'currency',
    currency,
    currencyDisplay: 'narrowSymbol',
  }).formatToParts(0)

  return parts.find((p) => p.type === 'currency')?.value ?? currency
}

/**
 * Format a numeric amount with the given currency using Intl.NumberFormat.
 * Can be used outside of React (no hooks).
 */
export function formatAmount(
  value: string | number,
  currency: string,
  options?: { locale?: string; symbol?: boolean }
): string {
  const locale = options?.locale ?? getLocale(currency)
  return formatCurrency(value, {
    currency,
    locale,
    includeCurrency: options?.symbol !== false,
  })
}

/**
 * Hook that provides currency formatting tied to the current company.
 */
export function useCurrency() {
  const getCurrentCompany = useCompanyStore((state) => state.getCurrentCompany)
  const company = getCurrentCompany()

  const currency = company?.currency ?? 'EUR'
  const decimals = getDecimals(currency)
  const locale = getLocale(currency)
  const symbol = getCurrencySymbol(currency, locale)

  const format = useCallback(
    (value: string | number, options?: { symbol?: boolean }) => {
      return formatAmount(value, currency, { locale, ...options })
    },
    [currency, locale]
  )

  /**
   * Format a number to fixed decimal string for calculations/display.
   * Replaces hardcoded .toFixed(3).
   */
  const toFixed = useCallback(
    (value: number): string => {
      return new Big(value).toFixed(decimals)
    },
    [decimals]
  )

  return {
    currency,
    symbol,
    locale,
    decimals,
    format,
    toFixed,
  }
}
