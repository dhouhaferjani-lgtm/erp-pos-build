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
