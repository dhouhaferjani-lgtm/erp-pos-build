import { useAuthStore } from '@/stores/authStore';

const currencyLocaleMap: Record<string, string> = {
  EUR: 'fr-FR',
  USD: 'en-US',
  GBP: 'en-GB',
  TND: 'fr-TN',
};

const CURRENCY_DECIMALS: Record<string, number> = {
  BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
  BIF: 0, CLP: 0, DJF: 0, GNF: 0, ISK: 0, JPY: 0, KMF: 0,
  KRW: 0, PYG: 0, RWF: 0, UGX: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0,
};

export function getCurrencyDecimals(currency: string): number {
  return CURRENCY_DECIMALS[currency] ?? 2;
}

export function formatCurrency(
  amount: number | string,
  currency = 'EUR',
  decimals?: number,
): string {
  const num = typeof amount === 'string' ? parseFloat(amount) : amount;
  const value = isNaN(num) ? 0 : num;
  const d = decimals ?? getCurrencyDecimals(currency);

  try {
    return new Intl.NumberFormat(currencyLocaleMap[currency] ?? 'en-US', {
      style: 'currency',
      currency,
      minimumFractionDigits: d,
      maximumFractionDigits: d,
    }).format(value);
  } catch {
    return `${value.toFixed(d)} ${currency}`;
  }
}

export function useCurrency(): { currency: string; decimals: number; format: (amount: number | string) => string } {
  const companies = useAuthStore((s) => s.companies);
  const companyId = useAuthStore((s) => s.companyId);
  const company = companies.find((c) => c.id === companyId);
  const currency = company?.currency ?? 'EUR';
  const decimals = getCurrencyDecimals(currency);

  return {
    currency,
    decimals,
    format: (amount: number | string) => formatCurrency(amount, currency, decimals),
  };
}
