import { getStoredValue, setStoredValue } from '@/lib/storage';
import type { CompanyConfig } from '@/types/companyConfig';

const PREFIX = 'company_config';

export function companyConfigCacheKey(companyId: string): string {
  return `${PREFIX}:${companyId}`;
}

export async function persistCompanyConfig(companyId: string, config: CompanyConfig): Promise<void> {
  await setStoredValue(companyConfigCacheKey(companyId), config);
}

export async function loadCachedCompanyConfig(companyId: string): Promise<CompanyConfig | null> {
  return getStoredValue<CompanyConfig>(companyConfigCacheKey(companyId));
}
