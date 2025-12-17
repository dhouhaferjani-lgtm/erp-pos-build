import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { FileText, Calendar, User } from 'lucide-react'
import { getAdminAuditLogs } from '../api'
import { QueryError } from '@/components/QueryError'

export function AuditLogsPage() {
  const { t } = useTranslation()
  const { data: logsData, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'audit-logs'],
    queryFn: () => getAdminAuditLogs(),
  })

  const logs = logsData?.data ?? []

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString()
  }

  const getActionColor = (action: string) => {
    switch (action) {
      case 'suspend_tenant':
        return 'bg-red-100 text-red-800'
      case 'activate_tenant':
        return 'bg-green-100 text-green-800'
      case 'extend_trial':
        return 'bg-blue-100 text-blue-800'
      case 'change_plan':
        return 'bg-purple-100 text-purple-800'
      default:
        return 'bg-gray-100 text-gray-800'
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-gray-500">{t('auditLogs.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-8">
        <QueryError
          error={error}
          onRetry={refetch}
          title="Failed to load audit logs"
        />
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8">
          <h1 className="text-2xl font-bold text-gray-900">
            {t('auditLogs.title')}
          </h1>
          <p className="text-gray-500">{t('auditLogs.description')}</p>
        </div>

        <div className="overflow-hidden rounded-lg bg-white shadow">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('auditLogs.table.date')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('auditLogs.table.admin')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('auditLogs.table.action')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('auditLogs.table.tenant')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('auditLogs.table.notes')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {logs.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-6 py-12 text-center">
                    <FileText className="mx-auto h-12 w-12 text-gray-400" />
                    <p className="mt-4 text-gray-500">{t('auditLogs.empty')}</p>
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                      <div className="flex items-center gap-2">
                        <Calendar className="h-4 w-4 text-gray-400" />
                        {formatDate(log.created_at)}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                      <div className="flex items-center gap-2">
                        <User className="h-4 w-4 text-gray-400" />
                        {log.admin_name}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex rounded-full px-2 text-xs font-semibold leading-5 ${getActionColor(log.action)}`}
                      >
                        {log.action.replace(/_/g, ' ')}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                      {log.tenant_name ?? '-'}
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500">
                      {typeof log.details === 'object' && log.details !== null
                        ? JSON.stringify(log.details)
                        : '-'}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
