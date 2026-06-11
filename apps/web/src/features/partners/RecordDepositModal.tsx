import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../../components/organisms/Modal'
import { FormField } from '../../components/atoms/FormField'
import { Input } from '../../components/atoms/Input'
import { Select } from '../../components/atoms/Select'
import { Textarea } from '../../components/atoms/Textarea'
import { Button } from '../../components/atoms/Button'
import { api, apiPost, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useActivePaymentRepositories } from '../treasury/hooks/usePaymentRepositories'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { toast } from 'sonner'

interface DepositPaymentMethod {
  id: string
  code: string
  name: string
  is_active: boolean
}

interface DepositResult {
  deposit_receipt_uuid: string
  amount: string
  currency_code: string
  settled_amount: string
  credited_amount: string
}

export interface RecordDepositModalProps {
  isOpen: boolean
  onClose: () => void
  partnerId: string
  /** Called after a deposit is recorded, so the page can refresh derived views. */
  onRecorded?: () => void
}

/**
 * Fixed-size modal for recording a back-office payment toward a customer account.
 * The money settles open invoices FIFO with any remainder credited as advance —
 * the server returns that split, which we surface in the success toast.
 */
export function RecordDepositModal({ isOpen, onClose, partnerId, onRecorded }: RecordDepositModalProps) {
  const { t } = useTranslation(['deposits', 'common'])
  const queryClient = useQueryClient()

  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currency = useCompanyStore(
    (state) => state.companies.find((c) => c.id === state.currentCompanyId)?.currency ?? 'EUR',
  )

  const [amount, setAmount] = useState('')
  const [paymentMethodCode, setPaymentMethodCode] = useState('')
  const [repositoryId, setRepositoryId] = useState('')
  const [note, setNote] = useState('')

  const { data: paymentMethods = [] } = useQuery({
    queryKey: tenantScopedKey(['payment-methods']),
    queryFn: async () => {
      const response = await api.get<{ data: DepositPaymentMethod[] }>('/payment-methods')
      return response.data.data
    },
    enabled: isOpen && tenantId !== null && companyId !== null,
  })
  const activeMethods = useMemo(() => paymentMethods.filter((m) => m.is_active), [paymentMethods])

  const { data: repositories = [] } = useActivePaymentRepositories()

  const resetForm = () => {
    setAmount('')
    setPaymentMethodCode('')
    setRepositoryId('')
    setNote('')
  }

  const handleClose = () => {
    resetForm()
    onClose()
  }

  const mutation = useMutation({
    mutationFn: async () => {
      return await apiPost<DepositResult>(`/partners/${partnerId}/deposits`, {
        amount: amount.trim(),
        payment_method_code: paymentMethodCode,
        repository_id: repositoryId,
        currency,
        note: note.trim() === '' ? null : note.trim(),
      })
    },
    onSuccess: (result) => {
      toast.success(
        t('deposits:recordedToast', {
          settled: formatCurrency(result.settled_amount, { currency: result.currency_code }),
          credited: formatCurrency(result.credited_amount, { currency: result.currency_code }),
        }),
      )
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['partner-deposits', partnerId]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['partner-account-balance', partnerId]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['partner', partnerId]) })
      onRecorded?.()
      resetForm()
      onClose()
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Positive decimal check on the canonical string — no float coercion.
  const trimmedAmount = amount.trim()
  const isPositiveAmount =
    /^\d+(\.\d+)?$/.test(trimmedAmount) && !/^0*\.?0*$/.test(trimmedAmount)
  const isValid = isPositiveAmount && paymentMethodCode !== '' && repositoryId !== ''

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (!isValid || mutation.isPending) return
    mutation.mutate()
  }

  return (
    <Modal isOpen={isOpen} onClose={handleClose} size="md">
      <ModalHeader title={t('deposits:recordTitle')} onClose={handleClose} />
      <form onSubmit={handleSubmit}>
        <ModalContent>
          <div className="space-y-4">
            <FormField label={t('deposits:form.amount')} required htmlFor="deposit-amount">
              <Input
                id="deposit-amount"
                type="text"
                inputMode="decimal"
                placeholder="0.00"
                value={amount}
                onChange={(e) => { setAmount(e.target.value); }}
                aria-label={t('deposits:form.amount')}
              />
            </FormField>

            <FormField label={t('deposits:form.paymentMethod')} required htmlFor="deposit-method">
              <Select
                id="deposit-method"
                value={paymentMethodCode}
                onChange={(e) => { setPaymentMethodCode(e.target.value); }}
                aria-label={t('deposits:form.paymentMethod')}
              >
                <option value="">{t('deposits:form.selectPaymentMethod')}</option>
                {activeMethods.map((method) => (
                  <option key={method.id} value={method.code}>
                    {method.name}
                  </option>
                ))}
              </Select>
            </FormField>

            <FormField label={t('deposits:form.repository')} required htmlFor="deposit-repository">
              <Select
                id="deposit-repository"
                value={repositoryId}
                onChange={(e) => { setRepositoryId(e.target.value); }}
                aria-label={t('deposits:form.repository')}
              >
                <option value="">{t('deposits:form.selectRepository')}</option>
                {repositories.map((repo) => (
                  <option key={repo.id} value={repo.id}>
                    {repo.name}
                  </option>
                ))}
              </Select>
            </FormField>

            <FormField label={t('deposits:form.note')} htmlFor="deposit-note">
              <Textarea
                id="deposit-note"
                rows={2}
                value={note}
                onChange={(e) => { setNote(e.target.value); }}
                aria-label={t('deposits:form.note')}
              />
            </FormField>
          </div>
        </ModalContent>
        <ModalFooter>
          <Button type="button" variant="secondary" onClick={handleClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={!isValid || mutation.isPending}>
            {mutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t('deposits:form.submit')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
