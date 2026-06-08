/**
 * Currency and number formatting utilities
 * Supports country-specific formatting (Tunisia, France, etc.)
 */

import { getDecimals, getLocale } from '../hooks/useCurrency'

export interface CurrencyFormatOptions {
  currency?: string
  locale?: string
  minimumFractionDigits?: number
  maximumFractionDigits?: number
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
  const num = typeof amount === 'string' ? parseFloat(amount) : amount

  if (isNaN(num)) {
    return '0.00'
  }

  const currency = options?.currency ?? 'EUR'
  const defaultDecimals = getDecimals(currency)
  const locale = options?.locale ?? getLocale(currency)
  const minimumFractionDigits = options?.minimumFractionDigits ?? defaultDecimals
  const maximumFractionDigits = options?.maximumFractionDigits ?? defaultDecimals

  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    minimumFractionDigits,
    maximumFractionDigits,
  }).format(num)
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
  const num = typeof value === 'string' ? parseFloat(value) : value

  if (isNaN(num)) {
    return '0.00'
  }

  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(num)
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
  const num = typeof value === 'string' ? parseFloat(value) : value

  if (isNaN(num)) {
    return '0'
  }

  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: 0,
    maximumFractionDigits: scale,
  }).format(num)
}

/**
 * Format date based on country format
 * Tunisia & France: DD/MM/YYYY
 * US: MM/DD/YYYY
 */
export function formatDate(
  date: string | Date,
  format: 'DD/MM/YYYY' | 'MM/DD/YYYY' = 'DD/MM/YYYY',
  locale: string = 'en-US'
): string {
  const d = typeof date === 'string' ? new Date(date) : date

  if (isNaN(d.getTime())) {
    return ''
  }

  if (format === 'DD/MM/YYYY') {
    return new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(d)
  }

  return new Intl.DateTimeFormat(locale, {
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
