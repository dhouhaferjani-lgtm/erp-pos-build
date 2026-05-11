import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { issueGoodwill, voidVoucher, transferVoucher, extendExpiry } from '../api/voucherApi'
import { vouchersInvalidationPredicate } from './useVouchers'
import type {
  IssueGoodwillPayload,
  VoidVoucherPayload,
  TransferVoucherPayload,
  ExtendExpiryPayload,
} from '../types/voucher'

export function useIssueGoodwill() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: IssueGoodwillPayload) => issueGoodwill(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: vouchersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('vouchers:actions.issued'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useVoidVoucher() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: VoidVoucherPayload }) =>
      voidVoucher(id, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: vouchersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('vouchers:actions.voided'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useTransferVoucher() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: TransferVoucherPayload }) =>
      transferVoucher(id, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: vouchersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('vouchers:actions.transferred'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useExtendExpiry() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ExtendExpiryPayload }) =>
      extendExpiry(id, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: vouchersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('vouchers:actions.extended'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
