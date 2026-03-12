import { useAuthStore } from '@/stores/authStore';

const currencyLocaleMap: Record<string, string> = {
  EUR: 'fr-FR',
  USD: 'en-US',
  GBP: 'en-GB',
  TND: 'fr-TN',
};

export function formatCurrency(
  amount: number | string,
  currency = 'EUR',
  decimals = 2,
): string {
  const num = typeof amount === 'string' ? parseFloat(amount) : amount;
  const value = isNaN(num) ? 0 : num;

  try {
    return new Intl.NumberFormat(currencyLocaleMap[currency] ?? 'en-US', {
      style: 'currency',
      currency,
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(value);
  } catch {
    return `${value.toFixed(decimals)} ${currency}`;
  }
}

export function useCurrency(): { currency: string; decimals: number; format: (amount: number | string) => string } {
  const companies = useAuthStore((s) => s.companies);
  const companyId = useAuthStore((s) => s.companyId);
  const company = companies.find((c) => c.id === companyId);
  const currency = company?.currency ?? 'EUR';
  const decimals = 2;

  return {
    currency,
    decimals,
    format: (amount: number | string) => formatCurrency(amount, currency, decimals),
  };
}
