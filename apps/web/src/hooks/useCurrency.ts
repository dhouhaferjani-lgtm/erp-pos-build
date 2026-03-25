import Big from 'big.js'
import { useCallback } from 'react'
import { useCompanyStore } from '../stores/companyStore'

const CURRENCY_DECIMALS: Record<string, number> = {
  TND: 3,
  EUR: 2,
  USD: 2,
  GBP: 2,
  MAD: 2,
  DZD: 2,
  LYD: 3,
  ITL: 2,
  BHD: 3,
  IQD: 3,
  JOD: 3,
  KWD: 3,
  OMR: 3,
}

const CURRENCY_LOCALES: Record<string, string> = {
  TND: 'fr-TN',
  EUR: 'fr-FR',
  USD: 'en-US',
  GBP: 'en-GB',
  MAD: 'fr-MA',
  DZD: 'fr-DZ',
  LYD: 'ar-LY',
  ITL: 'it-IT',
}

export function getDecimals(currency: string): number {
  return CURRENCY_DECIMALS[currency] ?? 2
}

export function getLocale(currency: string): string {
  return CURRENCY_LOCALES[currency] ?? 'en-US'
}

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
  const num = typeof value === 'string' ? parseFloat(value) : value
  if (isNaN(num)) return '0.00'

  const locale = options?.locale ?? getLocale(currency)
  const decimals = getDecimals(currency)
  const showSymbol = options?.symbol !== false

  if (!showSymbol) {
    return new Intl.NumberFormat(locale, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(num)
  }

  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(num)
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
