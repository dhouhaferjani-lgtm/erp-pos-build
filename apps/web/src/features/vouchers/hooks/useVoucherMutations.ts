import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { issueGoodwill, voidVoucher, transferVoucher, extendExpiry } from '../api/voucherApi'
import { VOUCHERS_KEY } from './useVouchers'
import type {
  IssueGoodwillPayload,
  VoidVoucherPayload,
  TransferVoucherPayload,
  ExtendExpiryPayload,
} from '../types/voucher'

export function useIssueGoodwill() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: IssueGoodwillPayload) => issueGoodwill(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: VOUCHERS_KEY })
      toast.success(i18n.t('vouchers:actions.issued'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useVoidVoucher() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: VoidVoucherPayload }) =>
      voidVoucher(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: VOUCHERS_KEY })
      toast.success(i18n.t('vouchers:actions.voided'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useTransferVoucher() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: TransferVoucherPayload }) =>
      transferVoucher(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: VOUCHERS_KEY })
      toast.success(i18n.t('vouchers:actions.transferred'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useExtendExpiry() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ExtendExpiryPayload }) =>
      extendExpiry(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: VOUCHERS_KEY })
      toast.success(i18n.t('vouchers:actions.extended'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
