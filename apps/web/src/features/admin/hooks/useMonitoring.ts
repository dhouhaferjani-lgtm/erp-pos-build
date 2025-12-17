import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getMonitoringDashboard,
  getSystemHealth,
  getPerformanceMetrics,
  getCriticalMetrics,
  getQueueMonitoring,
  retryFailedJob,
  deleteFailedJob,
  retryAllFailedJobs,
  flushFailedJobs,
} from '../api'

// Full monitoring dashboard with all metrics
export function useMonitoringDashboard(options?: { refetchInterval?: number }) {
  return useQuery({
    queryKey: ['admin', 'monitoring', 'dashboard'],
    queryFn: () => getMonitoringDashboard(),
    refetchInterval: options?.refetchInterval ?? 30000, // Default: 30 seconds
  })
}

// System health metrics
export function useSystemHealth(options?: { refetchInterval?: number }) {
  return useQuery({
    queryKey: ['admin', 'monitoring', 'system'],
    queryFn: () => getSystemHealth(),
    refetchInterval: options?.refetchInterval ?? 30000,
  })
}

// Performance metrics
export function usePerformanceMetrics(options?: { refetchInterval?: number }) {
  return useQuery({
    queryKey: ['admin', 'monitoring', 'performance'],
    queryFn: () => getPerformanceMetrics(),
    refetchInterval: options?.refetchInterval ?? 30000,
  })
}

// Critical business metrics
export function useCriticalMetrics(options?: { refetchInterval?: number }) {
  return useQuery({
    queryKey: ['admin', 'monitoring', 'critical'],
    queryFn: () => getCriticalMetrics(),
    refetchInterval: options?.refetchInterval ?? 60000, // 1 minute
  })
}

// Queue monitoring
export function useQueueMonitoring(options?: { refetchInterval?: number }) {
  return useQuery({
    queryKey: ['admin', 'monitoring', 'queues'],
    queryFn: () => getQueueMonitoring(),
    refetchInterval: options?.refetchInterval ?? 15000, // 15 seconds
  })
}

// Retry a failed job
export function useRetryFailedJob() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string | number) => retryFailedJob(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'monitoring'] })
    },
  })
}

// Delete a failed job
export function useDeleteFailedJob() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string | number) => deleteFailedJob(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'monitoring'] })
    },
  })
}

// Retry all failed jobs
export function useRetryAllFailedJobs() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => retryAllFailedJobs(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'monitoring'] })
    },
  })
}

// Flush all failed jobs
export function useFlushFailedJobs() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => flushFailedJobs(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'monitoring'] })
    },
  })
}
