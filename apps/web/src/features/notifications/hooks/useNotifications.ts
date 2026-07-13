import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'

import {
  getNotifications,
  getUnreadNotificationCount,
  markAllNotificationsRead,
  markNotificationRead,
} from '../api/notificationsApi'

function useNotificationScope() {
  const userId = useAuthStore((state) => state.user?.id ?? null)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  return { userId, tenantId }
}

export function useUnreadNotificationCount() {
  const { userId, tenantId } = useNotificationScope()

  return useQuery({
    queryKey: tenantScopedKey(['notifications', userId, 'unread-count']),
    queryFn: getUnreadNotificationCount,
    enabled: Boolean(userId && tenantId),
    refetchInterval: 60_000,
  })
}

export function useNotificationsList(enabled: boolean) {
  const { userId, tenantId } = useNotificationScope()

  return useQuery({
    queryKey: tenantScopedKey(['notifications', userId, 'list']),
    queryFn: getNotifications,
    enabled: enabled && Boolean(userId && tenantId),
  })
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient()
  const { userId } = useNotificationScope()

  return useMutation({
    mutationFn: (id: string) => markNotificationRead(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['notifications', userId] })
    },
  })
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient()
  const { userId } = useNotificationScope()

  return useMutation({
    mutationFn: markAllNotificationsRead,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['notifications', userId] })
    },
  })
}
