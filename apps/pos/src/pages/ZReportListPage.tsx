import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Loader2, FileArchive } from 'lucide-react';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { fetchZReports } from '@/api/reportApi';
import { useCurrency } from '@/lib/currency';
import type { ZReportListItem } from '@/api/reportApi';

export function ZReportListPage() {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  const terminal = useTerminalStore((s) => s.terminal);
  const companyId = useAuthStore((s) => s.companyId);

  const [reports, setReports] = useState<ZReportListItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!terminal || !companyId) return;

    let cancelled = false;
    const load = async () => {
      setIsLoading(true);
      setError(null);
      try {
        const data = await fetchZReports(terminal.id, companyId);
        if (!cancelled) setReports(data);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : String(err));
        }
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    };

    void load();
    return () => { cancelled = true; };
  }, [terminal, companyId]);

  return (
    <div className="flex h-full flex-col overflow-hidden bg-gray-50 p-6">
      {/* Header */}
      <div className="mb-4 flex items-center gap-3">
        <FileArchive className="h-6 w-6 text-gray-500" />
        <h1 className="text-xl font-bold text-gray-900">{t('reports.zList.title')}</h1>
      </div>

      {/* Loading */}
      {isLoading && (
        <div className="flex flex-1 items-center justify-center gap-3">
          <Loader2 className="h-6 w-6 animate-spin text-blue-500" />
          <span className="text-sm text-gray-500">{t('reports.loading')}</span>
        </div>
      )}

      {/* Error */}
      {!isLoading && error !== null && (
        <div className="flex flex-1 items-center justify-center">
          <p className="text-sm text-red-600">{error}</p>
        </div>
      )}

      {/* Empty */}
      {!isLoading && error === null && reports.length === 0 && (
        <div className="flex flex-1 items-center justify-center">
          <p className="text-sm text-gray-500">{t('reports.zList.empty')}</p>
        </div>
      )}

      {/* Table */}
      {!isLoading && error === null && reports.length > 0 && (
        <div className="flex-1 overflow-auto rounded-xl border border-gray-200 bg-white">
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-gray-50">
              <tr className="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th className="px-4 py-3">{t('reports.zList.columns.zNumber')}</th>
                <th className="px-4 py-3">{t('reports.zList.columns.generatedAt')}</th>
                <th className="px-4 py-3 text-right">{t('reports.zList.columns.gross')}</th>
                <th className="px-4 py-3 font-mono">{t('reports.zList.columns.hash')}</th>
              </tr>
            </thead>
            <tbody>
              {reports.map((report) => (
                <tr
                  key={report.id}
                  className="border-b border-gray-100 last:border-0 hover:bg-gray-50"
                >
                  <td className="px-4 py-3">
                    <span className="rounded bg-blue-100 px-2 py-0.5 font-mono font-bold text-blue-800">
                      {report.formatted_z_number}
                    </span>
                    {report.is_reprint && (
                      <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700">
                        {t('reports.zList.duplicataBanner')}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {new Date(report.generated_at).toLocaleString()}
                  </td>
                  <td className="px-4 py-3 text-right font-semibold text-gray-900">
                    {format(report.gross_sales)}
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-gray-400">
                    {report.fiscal_hash.slice(0, 12)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
