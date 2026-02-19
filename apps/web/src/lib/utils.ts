import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Format a number as money with 3 decimal places
 * Default currency is TND (Tunisian Dinar)
 */
export function formatMoney(amount: number, currency: string = 'TND'): string {
  return `${amount.toFixed(3)} ${currency}`
}
