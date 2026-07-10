import { useState, type ComponentType, type ReactNode } from 'react'
import {
  Activity,
  Server,
  Database,
  Cpu,
  HardDrive,
  Clock,
  AlertTriangle,
  AlertCircle,
  CheckCircle,
  RefreshCw,
  Trash2,
  Play,
  XCircle,
  Zap,
  Users,
  DollarSign,
  TrendingUp,
} from 'lucide-react'
import { useMonitoringDashboard, useRetryFailedJob, useFlushFailedJobs, useRetryAllFailedJobs } from '../hooks/useMonitoring'
import { useCurrency } from '@/hooks/useCurrency'
import type { Alert, FailedJob } from '../api'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const monitoringStatusTones: Record<string, StatusTone> = {
  healthy: 'success',
  connected: 'success',
  available: 'success',
  degraded: 'warning',
  warning: 'warning',
  backlogged: 'warning',
  critical: 'danger',
  error: 'danger',
  unhealthy: 'danger',
  not_configured: 'neutral',
}

function monitoringStatusTone(status: string): StatusTone {
  return monitoringStatusTones[status] ?? 'neutral'
}

function duplicateAwareMonitoringKey(baseKey: string, seenCounts: Map<string, number>): string {
  const nextCount = (seenCounts.get(baseKey) ?? 0) + 1
  seenCounts.set(baseKey, nextCount)
  return `${baseKey}:${nextCount}`
}

const monitoringStatusIcons: Record<string, ComponentType<{ className?: string }>> = {
  healthy: CheckCircle,
  connected: CheckCircle,
  available: CheckCircle,
  degraded: AlertTriangle,
  warning: AlertTriangle,
  backlogged: AlertTriangle,
  critical: XCircle,
  error: XCircle,
  unhealthy: XCircle,
  not_configured: AlertCircle,
}

function MonitoringStatusBadge({ status, children }: { status: string; children?: ReactNode }) {
  const Icon = monitoringStatusIcons[status] ?? AlertCircle

  return (
    <StatusBadge tone={monitoringStatusTone(status)}>
      <Icon className="h-3 w-3" />
      {children ?? status}
    </StatusBadge>
  )
}

function MetricCard({
  title,
  value,
  subtitle,
  icon: Icon,
  trend,
}: {
  title: string
  value: string | number
  subtitle?: string
  icon: ComponentType<{ className?: string }>
  trend?: 'up' | 'down' | 'neutral'
}) {
  return (
    <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
      <div className="flex items-center justify-between">
        <div>
          <p className={`text-sm ${colorClasses.textGray500}`}>{title}</p>
          <p className={`text-[1.5rem] leading-8 font-semibold ${colorClasses.textGray900}`}>{value}</p>
          {subtitle && <p className={`text-xs ${colorClasses.textGray400} mt-1`}>{subtitle}</p>}
        </div>
        <div className={`p-3 rounded-lg ${trend === 'up' ? `${colorClasses.bgGreen100}` : trend === 'down' ? `${colorClasses.bgRed100}` : `${colorClasses.bgGray100}`}`}>
          <Icon className={`h-6 w-6 ${trend === 'up' ? `${colorClasses.textGreen600}` : trend === 'down' ? `${colorClasses.textRed600}` : `${colorClasses.textGray600}`}`} />
        </div>
      </div>
    </div>
  )
}

function AlertCard({ alert }: { alert: Alert }) {
  const getAlertConfig = () => {
    switch (alert.type) {
      case 'critical':
        return { bg: `${colorClasses.bgRed50} ${colorClasses.borderRed200}`, icon: XCircle, iconColor: `${colorClasses.textRed600}` }
      case 'warning':
        return { bg: `${colorClasses.bgYellow50} ${colorClasses.borderYellow200}`, icon: AlertTriangle, iconColor: `${colorClasses.textYellow600}` }
      default:
        return { bg: `${colorClasses.bgBlue50} ${colorClasses.borderBlue200}`, icon: AlertCircle, iconColor: `${colorClasses.textBlue600}` }
    }
  }

  const config = getAlertConfig()
  const Icon = config.icon

  return (
    <div className={`rounded-lg border p-4 ${config.bg}`}>
      <div className="flex items-start gap-3">
        <Icon className={`h-5 w-5 mt-0.5 ${config.iconColor}`} />
        <div className="flex-1">
          <p className={`font-medium ${colorClasses.textGray900}`}>{alert.message}</p>
          <p className={`text-sm ${colorClasses.textGray600} mt-1`}>{alert.action}</p>
          <span className="inline-block mt-2 text-xs bg-white px-2 py-1 rounded">{alert.category}</span>
        </div>
      </div>
    </div>
  )
}

function FailedJobRow({ job, onRetry, onDelete, isRetrying }: {
  job: FailedJob
  onRetry: () => void
  onDelete: () => void
  isRetrying: boolean
}) {
  return (
    <tr className={`border-b ${colorClasses.borderGray100}`}>
      <td className={`py-3 px-4 text-sm font-mono ${colorClasses.textGray600}`}>{job.id}</td>
      <td className="py-3 px-4 text-sm">{job.queue}</td>
      <td className={`py-3 px-4 text-sm ${colorClasses.textGray500}`}>
        {new Date(job.failed_at).toLocaleString()}
      </td>
      <td className={`py-3 px-4 text-sm ${colorClasses.textRed600} max-w-xs truncate`} title={job.exception}>
        {job.exception}
      </td>
      <td className="py-3 px-4">
        <div className="flex items-center gap-2">
          <button
            onClick={onRetry}
            disabled={isRetrying}
            className={`p-1 ${colorClasses.textBlue600} ${colorClasses.hoverBgBlue50} rounded disabled:opacity-50`}
            title="Retry"
          >
            <RefreshCw className={`h-4 w-4 ${isRetrying ? 'animate-spin' : ''}`} />
          </button>
          <button
            onClick={onDelete}
            className={`p-1 ${colorClasses.textRed600} ${colorClasses.hoverBgRed50} rounded`}
            title="Delete"
          >
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
      </td>
    </tr>
  )
}

export function MonitoringPage() {
  const { data, isLoading, error, refetch } = useMonitoringDashboard({ refetchInterval: 30000 })
  const retryJob = useRetryFailedJob()
  const retryAll = useRetryAllFailedJobs()
  const flushAll = useFlushFailedJobs()
  const { format: formatRevenue } = useCurrency()
  const [activeTab, setActiveTab] = useState<'overview' | 'queues' | 'alerts'>('overview')

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-96 p-8">
        <RefreshCw className={`h-8 w-8 animate-spin ${colorClasses.textGray400}`} />
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-8">
        <div className="mx-auto max-w-7xl">
          <div className={`${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg p-6 text-center`}>
            <XCircle className={`h-12 w-12 ${colorClasses.textRed400} mx-auto mb-4`} />
            <h3 className={`text-lg font-medium ${colorClasses.textRed800}`}>Failed to load monitoring data</h3>
            <p className={`${colorClasses.textRed600} mt-2`}>{error instanceof Error ? error.message : 'Unknown error'}</p>
            <button
              onClick={() => refetch()}
              className={`mt-4 px-4 py-2 ${colorClasses.bgRed600} text-white rounded-lg ${colorClasses.hoverBgRed700}`}
            >
              Retry
            </button>
          </div>
        </div>
      </div>
    )
  }

  const { health, performance, critical, queues } = data!
  const alertKeyCounts = new Map<string, number>()
  const recentEventKeyCounts = new Map<string, number>()

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-[1.5rem] leading-8 font-bold ${colorClasses.textGray900}`}>System Monitoring</h1>
          <p className={`text-sm ${colorClasses.textGray500} mt-1`}>
            Last updated: {new Date(health.timestamp).toLocaleString()}
          </p>
        </div>
        <div className="flex items-center gap-4">
          <MonitoringStatusBadge status={health.status}>{health.status}</MonitoringStatusBadge>
          <button
            onClick={() => refetch()}
            className={`flex items-center gap-2 px-4 py-2 ${colorClasses.bgGray100} ${colorClasses.textGray700} rounded-lg ${colorClasses.hoverBgGray200}`}
          >
            <RefreshCw className="h-4 w-4" />
            Refresh
          </button>
        </div>
      </div>

      {/* Tabs */}
      <div className={`border-b ${colorClasses.borderGray200}`}>
        <nav className="-mb-px flex gap-6">
          {[
            { id: 'overview', label: 'Overview', icon: Activity },
            { id: 'queues', label: 'Queues', icon: Zap },
            { id: 'alerts', label: 'Alerts', icon: AlertTriangle, count: critical.alerts.length },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => { setActiveTab(tab.id as typeof activeTab); }}
              className={`flex items-center gap-2 py-3 px-1 border-b-2 text-sm font-medium ${
                activeTab === tab.id
                  ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                  : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
              }`}
            >
              <tab.icon className="h-4 w-4" />
              {tab.label}
              {tab.count !== undefined && tab.count > 0 && (
                <span className={`${colorClasses.bgRed100} ${colorClasses.textRed600} text-xs px-2 py-0.5 rounded-full`}>
                  {tab.count}
                </span>
              )}
            </button>
          ))}
        </nav>
      </div>

      {/* Overview Tab */}
      {activeTab === 'overview' && (
        <div className="space-y-6">
          {/* System Health */}
          <section>
            <h2 className={`text-lg font-semibold ${colorClasses.textGray900} mb-4`}>System Health</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <Database className={`h-5 w-5 ${colorClasses.textBlue600}`} />
                    <span className="font-medium">Database</span>
                  </div>
                  <MonitoringStatusBadge status={health.database.status}>{health.database.status}</MonitoringStatusBadge>
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Latency</span>
                    <span className="font-mono">{health.database.latency_ms}ms</span>
                  </div>
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Connections</span>
                    <span>{health.database.connections}/{health.database.max_connections}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Size</span>
                    <span>{health.database.size_human}</span>
                  </div>
                </div>
              </div>

              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <Zap className={`h-5 w-5 ${colorClasses.textPurple600}`} />
                    <span className="font-medium">Cache</span>
                  </div>
                  <MonitoringStatusBadge status={health.cache.status}>{health.cache.status}</MonitoringStatusBadge>
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Driver</span>
                    <span>{health.cache.driver}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Latency</span>
                    <span className="font-mono">{health.cache.latency_ms}ms</span>
                  </div>
                </div>
              </div>

              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <Clock className={`h-5 w-5 ${colorClasses.textOrange600}`} />
                    <span className="font-medium">Queue</span>
                  </div>
                  <MonitoringStatusBadge status={health.queue.status}>{health.queue.status}</MonitoringStatusBadge>
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Driver</span>
                    <span>{health.queue.driver}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Pending</span>
                    <span>{health.queue.pending_jobs}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Failed</span>
                    <span className={health.queue.failed_jobs > 0 ? `${colorClasses.textRed600} font-medium` : ''}>
                      {health.queue.failed_jobs}
                    </span>
                  </div>
                </div>
              </div>

              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <Server className={`h-5 w-5 ${colorClasses.textGreen600}`} />
                    <span className="font-medium">Server</span>
                  </div>
                  <span className={`text-xs ${colorClasses.textGray500}`}>PHP {health.server.php_version}</span>
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Memory</span>
                    <span>{health.server.memory.usage_percent}%</span>
                  </div>
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Load (1m)</span>
                    <span>{health.server.load_average['1min'].toFixed(2)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Uptime</span>
                    <span>{health.server.uptime}</span>
                  </div>
                </div>
              </div>
            </div>
          </section>

          {/* Resource Usage */}
          <section>
            <h2 className={`text-lg font-semibold ${colorClasses.textGray900} mb-4`}>Resource Usage</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <div className="flex items-center gap-2 mb-4">
                  <Cpu className={`h-5 w-5 ${colorClasses.textBlue600}`} />
                  <span className="font-medium">Memory Usage</span>
                </div>
                <div className={`relative h-4 ${colorClasses.bgGray200} rounded-full overflow-hidden`}>
                  <div
                    className={`absolute top-0 left-0 h-full rounded-full transition-all ${
                      health.server.memory.usage_percent > 80 ? `${colorClasses.bgRed500}` :
                      health.server.memory.usage_percent > 60 ? `${colorClasses.bgYellow500}` : `${colorClasses.bgGreen500}`
                    }`}
                    style={{ width: `${health.server.memory.usage_percent}%` }}
                  />
                </div>
                <p className={`text-sm ${colorClasses.textGray500} mt-2`}>
                  {health.server.memory.usage_percent}% used
                </p>
              </div>

              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <div className="flex items-center gap-2 mb-4">
                  <HardDrive className={`h-5 w-5 ${colorClasses.textPurple600}`} />
                  <span className="font-medium">Disk Usage</span>
                </div>
                <div className={`relative h-4 ${colorClasses.bgGray200} rounded-full overflow-hidden`}>
                  <div
                    className={`absolute top-0 left-0 h-full rounded-full transition-all ${
                      health.server.disk.usage_percent > 80 ? `${colorClasses.bgRed500}` :
                      health.server.disk.usage_percent > 60 ? `${colorClasses.bgYellow500}` : `${colorClasses.bgGreen500}`
                    }`}
                    style={{ width: `${health.server.disk.usage_percent}%` }}
                  />
                </div>
                <p className={`text-sm ${colorClasses.textGray500} mt-2`}>
                  {health.server.disk.used_human} / {health.server.disk.total_human} ({health.server.disk.usage_percent}%)
                </p>
              </div>
            </div>
          </section>

          {/* External Services */}
          <section>
            <h2 className={`text-lg font-semibold ${colorClasses.textGray900} mb-4`}>External Services</h2>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {Object.entries(health.services).map(([name, service]) => (
                <div key={name} className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                  <div className="flex items-center justify-between">
                    <span className="font-medium capitalize">{name}</span>
                    <MonitoringStatusBadge status={service.status}>{service.status}</MonitoringStatusBadge>
                  </div>
                  {service.driver && (
                    <p className={`text-sm ${colorClasses.textGray500} mt-1`}>Driver: {service.driver}</p>
                  )}
                </div>
              ))}
            </div>
          </section>

          {/* Business Metrics */}
          <section>
            <h2 className={`text-lg font-semibold ${colorClasses.textGray900} mb-4`}>Business Metrics</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <MetricCard
                title="Active Subscriptions"
                value={critical.billing.subscriptions.active}
                subtitle={`${critical.billing.subscriptions.trial} in trial`}
                icon={Users}
              />
              <MetricCard
                title="Revenue Today"
                value={formatRevenue(critical.billing.revenue.today)}
                subtitle={`${formatRevenue(critical.billing.revenue.this_month)} this month`}
                icon={DollarSign}
                trend="up"
              />
              <MetricCard
                title="Payments Today"
                value={critical.billing.payments.successful_today}
                subtitle={`${critical.billing.payments.failed_today} failed`}
                icon={TrendingUp}
              />
              <MetricCard
                title="Total Tenants"
                value={critical.tenants.total}
                subtitle={`${critical.tenants.new_this_month} new this month`}
                icon={Users}
              />
            </div>
          </section>

          {/* Performance Metrics */}
          <section>
            <h2 className={`text-lg font-semibold ${colorClasses.textGray900} mb-4`}>Performance</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <MetricCard
                title="Avg Response Time"
                value={`${performance.response_times.average_ms}ms`}
                subtitle={`P95: ${performance.response_times.p95_ms}ms`}
                icon={Activity}
              />
              <MetricCard
                title="Requests/min"
                value={performance.throughput.requests_per_minute}
                subtitle={`Peak: ${performance.throughput.peak_rpm}`}
                icon={Zap}
              />
              <MetricCard
                title="Error Rate"
                value={`${performance.error_rates.error_rate_percent}%`}
                subtitle={`${performance.error_rates.error_count_last_hour} errors/hour`}
                icon={AlertTriangle}
                trend={performance.error_rates.error_rate_percent > 1 ? 'down' : 'neutral'}
              />
              <MetricCard
                title="DB Performance"
                value={performance.database_performance.slow_queries}
                subtitle="Slow queries"
                icon={Database}
              />
            </div>
          </section>
        </div>
      )}

      {/* Queues Tab */}
      {activeTab === 'queues' && (
        <div className="space-y-6">
          {/* Queue Summary */}
          <section>
            <div className="flex items-center justify-between mb-4">
              <h2 className={`text-lg font-semibold ${colorClasses.textGray900}`}>Queue Status</h2>
              <div className="flex items-center gap-2">
                {queues.failed_jobs.length > 0 && (
                  <>
                    <button
                      onClick={() => { retryAll.mutate(); }}
                      disabled={retryAll.isPending}
                      className={`flex items-center gap-2 px-3 py-1.5 text-sm ${colorClasses.bgBlue600} text-white rounded-lg ${colorClasses.hoverBgBlue700} disabled:opacity-50`}
                    >
                      <Play className="h-4 w-4" />
                      Retry All
                    </button>
                    <button
                      onClick={() => {
                        if (confirm('Are you sure you want to delete all failed jobs?')) {
                          flushAll.mutate()
                        }
                      }}
                      disabled={flushAll.isPending}
                      className={`flex items-center gap-2 px-3 py-1.5 text-sm ${colorClasses.bgRed600} text-white rounded-lg ${colorClasses.hoverBgRed700} disabled:opacity-50`}
                    >
                      <Trash2 className="h-4 w-4" />
                      Flush All
                    </button>
                  </>
                )}
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <p className={`text-sm ${colorClasses.textGray500}`}>Pending Jobs</p>
                <p className={`text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>{queues.summary.pending_jobs}</p>
              </div>
              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <p className={`text-sm ${colorClasses.textGray500}`}>Failed Jobs</p>
                <p className={`text-[1.875rem] leading-9 font-bold ${queues.summary.failed_jobs > 0 ? `${colorClasses.textRed600}` : `${colorClasses.textGray900}`}`}>
                  {queues.summary.failed_jobs}
                </p>
              </div>
              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4`}>
                <p className={`text-sm ${colorClasses.textGray500}`}>Processing Rate</p>
                <p className={`text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>{queues.processing_rate.jobs_per_minute}/min</p>
              </div>
            </div>

            {/* Jobs by Queue */}
            <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} p-4 mb-6`}>
              <h3 className={`font-medium ${colorClasses.textGray900} mb-3`}>Jobs by Queue</h3>
              <div className="space-y-2">
                {Object.entries(queues.jobs_by_queue).map(([queue, count]) => (
                  <div key={queue} className="flex items-center justify-between">
                    <span className={`text-sm font-mono ${colorClasses.textGray600}`}>{queue}</span>
                    <span className="text-sm font-medium">{count}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Failed Jobs Table */}
            {queues.failed_jobs.length > 0 && (
              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} overflow-hidden`}>
                <div className={`px-4 py-3 ${colorClasses.bgGray50} border-b ${colorClasses.borderGray200}`}>
                  <h3 className={`font-medium ${colorClasses.textGray900}`}>Failed Jobs</h3>
                </div>
                <DataTable className="w-full">
                  <thead className={`${colorClasses.bgGray50}`}>
                    <tr>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>ID</th>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Queue</th>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Failed At</th>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Exception</th>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {queues.failed_jobs.map((job) => (
                      <FailedJobRow
                        key={job.id}
                        job={job}
                        onRetry={() => { retryJob.mutate(job.id); }}
                        onDelete={() => {/* delete logic */}}
                        isRetrying={retryJob.isPending}
                      />
                    ))}
                  </tbody>
                </DataTable>
              </div>
            )}

            {queues.failed_jobs.length === 0 && (
              <div className={`${colorClasses.bgGreen50} border ${colorClasses.borderGreen200} rounded-lg p-8 text-center`}>
                <CheckCircle className={`h-12 w-12 ${colorClasses.textGreen400} mx-auto mb-4`} />
                <h3 className={`text-lg font-medium ${colorClasses.textGreen800}`}>No Failed Jobs</h3>
                <p className={`${colorClasses.textGreen600} mt-1`}>All queued jobs are processing normally.</p>
              </div>
            )}
          </section>
        </div>
      )}

      {/* Alerts Tab */}
      {activeTab === 'alerts' && (
        <div className="space-y-6">
          <section>
            <h2 className={`text-lg font-semibold ${colorClasses.textGray900} mb-4`}>Active Alerts</h2>
            {critical.alerts.length > 0 ? (
              <div className="space-y-4">
                {critical.alerts.map((alert) => {
                  const baseKey = `${alert.type}:${alert.category}:${alert.message}`
                  return (
                    <AlertCard key={duplicateAwareMonitoringKey(baseKey, alertKeyCounts)} alert={alert} />
                  )
                })}
              </div>
            ) : (
              <div className={`${colorClasses.bgGreen50} border ${colorClasses.borderGreen200} rounded-lg p-8 text-center`}>
                <CheckCircle className={`h-12 w-12 ${colorClasses.textGreen400} mx-auto mb-4`} />
                <h3 className={`text-lg font-medium ${colorClasses.textGreen800}`}>No Active Alerts</h3>
                <p className={`${colorClasses.textGreen600} mt-1`}>All systems are operating normally.</p>
              </div>
            )}
          </section>

          <section>
            <h2 className={`text-lg font-semibold ${colorClasses.textGray900} mb-4`}>Recent Events</h2>
            {critical.recent_events.length > 0 ? (
              <div className={`bg-white rounded-lg border ${colorClasses.borderGray200} overflow-hidden`}>
                <DataTable className="w-full">
                  <thead className={`${colorClasses.bgGray50}`}>
                    <tr>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Time</th>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Type</th>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Message</th>
                      <th className={`py-2 px-4 text-left text-xs font-medium ${colorClasses.textGray500} uppercase`}>Severity</th>
                    </tr>
                  </thead>
                  <tbody>
                    {critical.recent_events.map((event) => {
                      const baseKey = `${event.timestamp}:${event.type}:${event.message}`
                      return (
                        <tr key={duplicateAwareMonitoringKey(baseKey, recentEventKeyCounts)} className={`border-b ${colorClasses.borderGray100}`}>
                          <td className={`py-3 px-4 text-sm ${colorClasses.textGray500}`}>
                            {new Date(event.timestamp).toLocaleString()}
                          </td>
                          <td className="py-3 px-4 text-sm font-medium">{event.type}</td>
                          <td className="py-3 px-4 text-sm">{event.message}</td>
                          <td className="py-3 px-4">
                            <MonitoringStatusBadge status={event.severity}>{event.severity}</MonitoringStatusBadge>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </DataTable>
              </div>
            ) : (
              <div className={`${colorClasses.bgGray50} border ${colorClasses.borderGray200} rounded-lg p-8 text-center`}>
                <Activity className={`h-12 w-12 ${colorClasses.textGray400} mx-auto mb-4`} />
                <h3 className={`text-lg font-medium ${colorClasses.textGray600}`}>No Recent Events</h3>
                <p className={`${colorClasses.textGray500} mt-1`}>No critical events in the last 24 hours.</p>
              </div>
            )}
          </section>
        </div>
      )}
      </div>
    </div>
  )
}
