import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { fetchZReports } from '@/api/reportApi';
import { useCurrency } from '@/lib/currency';
import type { ZReportListItem } from '@/api/reportApi';

export function ZReportListPage() {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const navigate = useNavigate();

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
    <div className="flex h-full flex-col overflow-hidden bg-surface-canvas">
      <PageHeader
        title={t('reports.zList.title')}
        onBack={() => navigate('/')}
        backLabel={t('common:back')}
      />

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden p-6">
        {/* Loading */}
        {isLoading && (
          <div className="flex flex-1 items-center justify-center gap-3">
            <Loader2 className="h-6 w-6 animate-spin text-action" />
            <span className="text-sm text-ink-muted">{t('reports.loading')}</span>
          </div>
        )}

        {/* Error */}
        {!isLoading && error !== null && (
          <div className="flex flex-1 items-center justify-center">
            <p className="text-sm text-danger-strong">{error}</p>
          </div>
        )}

        {/* Empty */}
        {!isLoading && error === null && reports.length === 0 && (
          <div className="flex flex-1 items-center justify-center">
            <p className="text-sm text-ink-muted">{t('reports.zList.empty')}</p>
          </div>
        )}

        {/* Table */}
        {!isLoading && error === null && reports.length > 0 && (
          <div className="flex-1 overflow-auto rounded-card border border-border-subtle bg-surface-raised">
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-surface-sunken">
                <tr className="border-b border-border-subtle text-left text-xs font-semibold uppercase tracking-wide text-ink-muted">
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
                    className="border-b border-border-subtle last:border-0 hover:bg-surface-sunken"
                  >
                    <td className="px-4 py-3">
                      <span className="rounded-sm bg-action-subtle px-2 py-0.5 font-mono font-bold text-action">
                        {report.formatted_z_number}
                      </span>
                      {report.is_reprint && (
                        <span className="ml-2 rounded-sm bg-warning-surface px-1.5 py-0.5 text-xs font-medium text-warning-strong">
                          {t('reports.zList.duplicataBanner')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-ink-muted">
                      {new Date(report.generated_at).toLocaleString()}
                    </td>
                    <td className="px-4 py-3 text-right font-semibold text-ink">
                      {format(report.gross_sales)}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-ink-faint">
                      {report.fiscal_hash.slice(0, 12)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
