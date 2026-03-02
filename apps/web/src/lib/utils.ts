import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

import { getDecimals } from '../hooks/useCurrency'

/**
 * Format a number as money with currency-appropriate decimal places.
 * Default currency is EUR.
 */
export function formatMoney(amount: number, currency: string = 'EUR'): string {
  return `${amount.toFixed(getDecimals(currency))} ${currency}`
}
