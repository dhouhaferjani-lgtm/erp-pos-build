/**
 * Currency and number formatting utilities
 * Supports country-specific formatting (Tunisia, France, etc.)
 */

import Big from 'big.js'
import { getDecimals, getLocale } from './currencyMeta'
import i18n from './i18n'

export interface CurrencyFormatOptions {
  currency?: string
  locale?: string
  minimumFractionDigits?: number
  maximumFractionDigits?: number
  includeCurrency?: boolean
}

function safeDecimal(value: string | number): Big {
  try {
    const stringValue = typeof value === 'number' ? String(value) : value
    return stringValue.trim() === '' ? new Big(0) : new Big(stringValue)
  } catch {
    return new Big(0)
  }
}

function getDecimalSeparator(locale: string): string {
  return new Intl.NumberFormat(locale).formatToParts(1.1).find((part) => part.type === 'decimal')?.value ?? '.'
}

function formatIntegerPart(integerPart: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    maximumFractionDigits: 0,
    useGrouping: true,
  }).format(BigInt(integerPart || '0'))
}

export function formatDecimalAmount(
  amount: string | number,
  locale: string,
  decimals: number
): string {
  const fixed = safeDecimal(amount).toFixed(decimals)
  const isNegative = fixed.startsWith('-')
  const unsignedFixed = isNegative ? fixed.slice(1) : fixed
  const [integerPart = '0', fractionPart = ''] = unsignedFixed.split('.')
  const formattedInteger = formatIntegerPart(integerPart, locale)
  const formattedNumber = fractionPart.length > 0
    ? `${formattedInteger}${getDecimalSeparator(locale)}${fractionPart}`
    : formattedInteger

  return isNegative ? `-${formattedNumber}` : formattedNumber
}

function currencyAppearsBeforeNumber(locale: string, currency: string): boolean {
  const parts = new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    currencyDisplay: 'code',
  }).formatToParts(0)
  const currencyIndex = parts.findIndex((part) => part.type === 'currency')
  const numberIndex = parts.findIndex((part) => part.type === 'integer')

  return currencyIndex !== -1 && numberIndex !== -1 && currencyIndex < numberIndex
}

/**
 * Format a number as currency
 * Defaults to EUR/fr-FR but can be customized per country.
 * When no fraction digit options are provided, uses per-currency defaults
 * (e.g. TND=3, EUR=2).
 */
export function formatCurrency(
  amount: string | number,
  options?: CurrencyFormatOptions
): string {
  const currency = options?.currency ?? 'EUR'
  const defaultDecimals = getDecimals(currency)
  const locale = options?.locale ?? getLocale(currency)
  const minimumFractionDigits = options?.minimumFractionDigits ?? defaultDecimals
  const maximumFractionDigits = options?.maximumFractionDigits ?? defaultDecimals
  const decimals = Math.max(minimumFractionDigits, maximumFractionDigits)
  const formattedAmount = formatDecimalAmount(amount, locale, decimals)

  if (options?.includeCurrency === false) {
    return formattedAmount
  }

  return currencyAppearsBeforeNumber(locale, currency)
    ? `${currency} ${formattedAmount}`
    : `${formattedAmount} ${currency}`
}

/**
 * Format Tunisia currency (TND)
 */
export function formatTND(amount: string | number): string {
  return formatCurrency(amount, {
    currency: 'TND',
  })
}

/**
 * Format French currency (EUR)
 */
export function formatEUR(amount: string | number): string {
  return formatCurrency(amount, {
    currency: 'EUR',
  })
}

/**
 * Format number without currency symbol
 */
export function formatNumber(
  value: string | number,
  decimals: number = 2,
  locale: string = 'en-US'
): string {
  return formatDecimalAmount(value, locale, decimals)
}

/**
 * Format a stock quantity for display.
 *
 * Inventory quantities are stored and returned at up to 4 decimal places
 * (decimal(15,4)). Quantities must NOT be formatted with currency precision
 * (formatCurrency / per-currency decimals) — that truncates 4-decimal
 * quantities for display. This shows up to `scale` decimal places and trims
 * insignificant trailing zeros (e.g. "10.0000" → "10", "7.1200" → "7.12",
 * "7.1234" → "7.1234").
 */
export function formatQuantity(
  value: string | number,
  scale: number = 4,
  locale: string = 'en-US'
): string {
  const fixed = safeDecimal(value).toFixed(scale)
  const trimmed = fixed.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')
  return formatDecimalAmount(trimmed, locale, trimmed.includes('.') ? trimmed.split('.')[1]?.length ?? 0 : 0)
}

/**
 * Format date based on country format
 * Tunisia & France: DD/MM/YYYY
 * US: MM/DD/YYYY
 */
export function formatDate(
  date: string | Date,
  format: 'DD/MM/YYYY' | 'MM/DD/YYYY' = 'DD/MM/YYYY',
  locale?: string
): string {
  const d = typeof date === 'string' ? new Date(date) : date

  if (isNaN(d.getTime())) {
    return ''
  }

  // Honor the active UI language (fr/en/ar) so dates render in the user's
  // locale (e.g. `02/07/2026` on the FR UI) instead of a hardcoded US format.
  const resolvedLocale = locale ?? i18n.language

  if (format === 'DD/MM/YYYY') {
    return new Intl.DateTimeFormat(resolvedLocale, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(d)
  }

  return new Intl.DateTimeFormat(resolvedLocale, {
    month: '2-digit',
    day: '2-digit',
    year: 'numeric',
  }).format(d)
}

/**
 * Format percentage
 */
export function formatPercentage(
  value: string | number,
  decimals: number = 2,
  locale: string = 'en-US'
): string {
  const num = typeof value === 'string' ? parseFloat(value) : value

  if (isNaN(num)) {
    return '0%'
  }

  return new Intl.NumberFormat(locale, {
    style: 'percent',
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(num / 100)
}

/**
 * Parse number from formatted string
 */
export function parseFormattedNumber(value: string): number {
  // Remove all non-numeric characters except . and -
  const cleaned = value.replace(/[^\d.-]/g, '')
  return parseFloat(cleaned) || 0
}
