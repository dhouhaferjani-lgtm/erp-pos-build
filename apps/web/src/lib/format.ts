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
 *
 * @deprecated For product quantities use `formatQuantity` from `@/lib/decimal`
 * (pads to unit precision). This variant TRIMS trailing zeros and locale-groups —
 * wrong for unit-precision display. Guarded by tools/audit-quantity-display.mjs,
 * which anchors on the lib/decimal import.
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
const DATE_ONLY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/

export function formatDate(
  date: string | Date,
  format: 'DD/MM/YYYY' | 'MM/DD/YYYY' = 'DD/MM/YYYY',
  locale?: string
): string {
  // A date-ONLY string (`YYYY-MM-DD`) carries no time or zone. `new Date(str)`
  // would parse it as UTC midnight, which then shifts back a calendar day when
  // formatted in a timezone behind UTC. Build a LOCAL date from its parts so the
  // rendered calendar date matches the input in every timezone. Datetime strings
  // (with a time or offset) keep their instant-based, zone-converting behavior.
  const dateOnlyMatch = typeof date === 'string' ? DATE_ONLY_PATTERN.exec(date) : null
  const d = dateOnlyMatch
    ? new Date(Number(dateOnlyMatch[1]), Number(dateOnlyMatch[2]) - 1, Number(dateOnlyMatch[3]))
    : typeof date === 'string'
      ? new Date(date)
      : date

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
export interface PercentFormatOptions {
  maximumFractionDigits?: number
}

function incrementDecimalDigits(digits: string): string {
  const chars = digits.split('')

  for (let index = chars.length - 1; index >= 0; index -= 1) {
    if (chars[index] !== '9') {
      chars[index] = String.fromCharCode(chars[index].charCodeAt(0) + 1)
      return chars.join('')
    }

    chars[index] = '0'
  }

  return `1${chars.join('')}`
}

function roundDecimalString(value: string, scale: number): string {
  const trimmed = value.trim()
  const decimalPattern = /^([+-])?(\d*)(?:\.(\d*))?$/
  const match = decimalPattern.exec(trimmed)

  if (!match || (match[2] === '' && match[3] === '')) {
    return '0'
  }

  const sign = match[1] === '-' ? '-' : ''
  const integerPart = match[2] || '0'
  const fractionPart = match[3] || ''
  const paddedFraction = fractionPart.padEnd(scale + 1, '0')
  const roundingDigit = paddedFraction.charAt(scale)
  const keptFraction = paddedFraction.slice(0, scale)
  const combined = `${integerPart}${keptFraction}` || '0'
  const roundedCombined = roundingDigit >= '5'
    ? incrementDecimalDigits(combined)
    : combined
  const integerLength = roundedCombined.length - scale
  const roundedInteger = (integerLength > 0 ? roundedCombined.slice(0, integerLength) : '0')
    .replace(/^0+(?=\d)/, '')
  const roundedFraction = scale > 0
    ? roundedCombined.slice(Math.max(integerLength, 0)).padStart(scale, '0')
    : ''
  const unsigned = roundedFraction
    ? `${roundedInteger}.${roundedFraction}`.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')
    : roundedInteger

  return unsigned === '0' ? '0' : `${sign}${unsigned}`
}

export function formatPercent(
  value: string | number | null | undefined,
  options?: PercentFormatOptions
): string {
  const scale = Math.max(0, options?.maximumFractionDigits ?? 2)
  const rawValue = value === null || value === undefined ? '' : String(value)
  const rounded = roundDecimalString(rawValue, scale)

  return `${rounded}%`
}

export function formatPercentage(
  value: string | number | null | undefined,
  decimals: number = 2,
  _locale: string = 'en-US'
): string {
  return formatPercent(value, { maximumFractionDigits: decimals })
}

/**
 * Parse number from formatted string
 */
export function parseFormattedNumber(value: string): number {
  // Remove all non-numeric characters except . and -
  const cleaned = value.replace(/[^\d.-]/g, '')
  return parseFloat(cleaned) || 0
}
