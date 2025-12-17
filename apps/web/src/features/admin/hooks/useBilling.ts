import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  getBillingDashboardStats,
  getPaymentProviders,
  getPlans,
  getSubscriptions,
  getSubscription,
  updateSubscription,
  getInvoices,
  getInvoice,
  createInvoice,
  getPayments,
  getPayment,
  recordPayment,
  refundPayment,
} from '../api'
import { getErrorMessage } from '@/lib/api'
import type {
  UpdateSubscriptionRequest,
  CreateInvoiceRequest,
  RecordPaymentRequest,
  RefundPaymentRequest,
} from '../types'

// Dashboard Stats
export function useBillingDashboard() {
  return useQuery({
    queryKey: ['admin', 'billing', 'dashboard'],
    queryFn: () => getBillingDashboardStats(),
  })
}

// Payment Providers
export function usePaymentProviders() {
  return useQuery({
    queryKey: ['admin', 'billing', 'providers'],
    queryFn: () => getPaymentProviders(),
  })
}

// Plans
export function usePlans() {
  return useQuery({
    queryKey: ['admin', 'billing', 'plans'],
    queryFn: () => getPlans(),
  })
}

// Subscriptions
export function useSubscriptions(params?: {
  status?: string
  plan_id?: string
  per_page?: number
}) {
  return useQuery({
    queryKey: ['admin', 'billing', 'subscriptions', params],
    queryFn: () => getSubscriptions(params),
  })
}

export function useSubscription(id: string) {
  return useQuery({
    queryKey: ['admin', 'billing', 'subscriptions', id],
    queryFn: () => getSubscription(id),
    enabled: !!id,
  })
}

export function useUpdateSubscription() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      id,
      data,
    }: {
      id: string
      data: UpdateSubscriptionRequest
    }) => updateSubscription(id, data),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'subscriptions'],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'subscriptions', variables.id],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'dashboard'],
      })
      toast.success('Subscription updated successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

// Invoices
export function useInvoices(params?: {
  status?: string
  tenant_id?: string
  per_page?: number
}) {
  return useQuery({
    queryKey: ['admin', 'billing', 'invoices', params],
    queryFn: () => getInvoices(params),
  })
}

export function useInvoice(id: string) {
  return useQuery({
    queryKey: ['admin', 'billing', 'invoices', id],
    queryFn: () => getInvoice(id),
    enabled: !!id,
  })
}

export function useCreateInvoice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateInvoiceRequest) => createInvoice(data),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'invoices'],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'dashboard'],
      })
      toast.success('Invoice created successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

// Payments
export function usePayments(params?: {
  status?: string
  provider?: string
  tenant_id?: string
  per_page?: number
}) {
  return useQuery({
    queryKey: ['admin', 'billing', 'payments', params],
    queryFn: () => getPayments(params),
  })
}

export function usePayment(id: string) {
  return useQuery({
    queryKey: ['admin', 'billing', 'payments', id],
    queryFn: () => getPayment(id),
    enabled: !!id,
  })
}

export function useRecordPayment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: RecordPaymentRequest) => recordPayment(data),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'payments'],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'invoices'],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'dashboard'],
      })
      toast.success('Payment recorded successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useRefundPayment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      id,
      data,
    }: {
      id: string
      data: RefundPaymentRequest
    }) => refundPayment(id, data),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'payments'],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'payments', variables.id],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'invoices'],
      })
      queryClient.invalidateQueries({
        queryKey: ['admin', 'billing', 'dashboard'],
      })
      toast.success('Payment refunded successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
