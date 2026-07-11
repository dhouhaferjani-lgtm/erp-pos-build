import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { FileText, Calendar, User } from 'lucide-react'
import { getAdminAuditLogs } from '../api'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '@/components/molecules/PageHeader'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function AuditLogsPage() {
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
        return `${colorClasses.bgRed100} ${colorClasses.textRed800}`
      case 'activate_tenant':
        return `${colorClasses.bgGreen100} ${colorClasses.textGreen800}`
      case 'extend_trial':
        return `${colorClasses.bgBlue100} ${colorClasses.textBlue800}`
      case 'change_plan':
        return `${colorClasses.bgPurple100} ${colorClasses.textPurple800}`
      default:
        return `${colorClasses.bgGray100} ${colorClasses.textGray800}`
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className={`${colorClasses.textGray500}`}>Loading audit logs...</div>
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
        <PageHeader
          title="Audit Logs"
          subtitle="Complete trail of all administrative actions"
          breadcrumb={
            <Link
              to="/admin/dashboard"
              className={`text-sm ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}
            >
              &larr; Back to Dashboard
            </Link>
          }
        />

        <div className="overflow-hidden rounded-lg bg-white shadow">
          <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
            <thead className={`${colorClasses.bgGray50}`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Date
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Admin
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Action
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Tenant
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Notes
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
              {logs.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-6 py-12 text-center">
                    <FileText className={`mx-auto h-12 w-12 ${colorClasses.textGray400}`} />
                    <p className={`mt-4 ${colorClasses.textGray500}`}>No audit logs found</p>
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id} className={`${colorClasses.hoverBgGray50}`}>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                      <div className="flex items-center gap-2">
                        <Calendar className={`h-4 w-4 ${colorClasses.textGray400}`} />
                        {formatDate(log.created_at)}
                      </div>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                      <div className="flex items-center gap-2">
                        <User className={`h-4 w-4 ${colorClasses.textGray400}`} />
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
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                      {log.tenant_name ?? '-'}
                    </td>
                    <td className={`px-6 py-4 text-sm ${colorClasses.textGray500}`}>
                      {typeof log.details === 'object' && log.details !== null
                        ? JSON.stringify(log.details)
                        : '-'}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </DataTable>
        </div>
      </div>
    </div>
  )
}
