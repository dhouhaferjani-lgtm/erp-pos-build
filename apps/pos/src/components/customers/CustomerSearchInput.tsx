import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';
import { getDatabase } from '@/lib/db';
import { searchCustomers } from '@/lib/db/repositories/customerRepository';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';

export interface CustomerSearchInputProps {
  tenantId: string | null | undefined;
  companyId: string | null | undefined;
  onSelect: (customer: CustomerMirrorRow) => void;
  limit?: number;
}

export function CustomerSearchInput({
  tenantId,
  companyId,
  onSelect,
  limit = 8,
}: CustomerSearchInputProps) {
  const { t } = useTranslation('pos');
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<CustomerMirrorRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const scopeMissing = query.trim() !== '' && (!tenantId || !companyId);

  useEffect(() => {
    let cancelled = false;
    const trimmed = query.trim();

    if (trimmed === '' || !tenantId || !companyId) {
      return () => {
        cancelled = true;
      };
    }

    void (async () => {
      try {
        const db = await getDatabase(companyId);
        const rows = await searchCustomers(db, {
          tenant_id: tenantId,
          company_id: companyId,
          query: trimmed,
          limit,
        });
        if (!cancelled) {
          setResults(rows);
          setLoading(false);
        }
      } catch (searchError) {
        if (!cancelled) {
          setResults([]);
          setError(searchError instanceof Error ? searchError.message : t('customerAttach.errorSearchFailed'));
          setLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [companyId, limit, query, tenantId]);

  const handleQueryChange = (value: string) => {
    setQuery(value);
    if (value.trim() === '') {
      setResults([]);
      setError(null);
      setLoading(false);
      return;
    }
    if (!tenantId || !companyId) {
      setResults([]);
      setError(null);
      setLoading(false);
      return;
    }
    setResults([]);
    setError(null);
    setLoading(true);
  };

  const displayError = scopeMissing ? t('customerAttach.scopeError') : error;
  const displayResults = scopeMissing ? [] : results;

  return (
    <div className="space-y-2">
      <label className="block text-xs font-semibold uppercase tracking-wide text-gray-500" htmlFor="customer-search">
        {t('customerSearch.label')}
      </label>
      <div className="relative">
        <Search className="pointer-events-none absolute left-2 top-2.5 h-4 w-4 text-gray-400" aria-hidden="true" />
        <input
          id="customer-search"
          value={query}
          onChange={(event) => handleQueryChange(event.target.value)}
          placeholder={t('customerSearch.placeholder')}
          className="w-full rounded-md border border-gray-300 bg-white py-2 pl-8 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        />
      </div>
      {loading && !scopeMissing && <div className="text-xs text-gray-500">{t('customerSearch.searching')}</div>}
      {displayError && <div className="text-xs font-medium text-red-700">{displayError}</div>}
      {displayResults.length > 0 && (
        <div className="max-h-40 overflow-y-auto rounded-md border border-gray-200 bg-white">
          {displayResults.map((customer) => (
            <button
              key={`${customer.tenant_id}:${customer.company_id}:${customer.id}`}
              type="button"
              onClick={() => onSelect(customer)}
              className="block w-full border-b border-gray-100 px-3 py-2 text-left last:border-b-0 hover:bg-blue-50 focus:bg-blue-50 focus:outline-none"
            >
              <span className="block text-sm font-semibold text-gray-900">{customer.name}</span>
              <span className="block truncate text-xs text-gray-500">
                {[customer.phone, customer.email, customer.tax_number].filter(Boolean).join(' | ')}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
