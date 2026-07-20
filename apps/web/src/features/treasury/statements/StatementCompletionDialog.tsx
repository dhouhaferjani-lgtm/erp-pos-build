import { useState } from 'react'
import Big from 'big.js'
import { useTranslation } from 'react-i18next'

import { Button, Checkbox } from '@/components/atoms'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { semanticColorTokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

interface StatementCompletionDialogProps {
  isOpen: boolean
  ignoredTotal: string
  currency: string
  pending?: boolean
  onClose: () => void
  onConfirm: (acknowledgeIgnoredTotal: boolean) => void
}

export function StatementCompletionDialog({ isOpen, ignoredTotal, currency, pending = false, onClose, onConfirm }: StatementCompletionDialogProps) {
  const { t } = useTranslation('treasury')
  const [acknowledged, setAcknowledged] = useState(false)
  const requiresAcknowledgment = !new Big(ignoredTotal).eq(0)

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('statements.workspace.complete.title')}>
      <ModalContent>
        <p>{t('statements.workspace.complete.message')}</p>
        {requiresAcknowledgment ? <label className={cn('flex items-start gap-3 rounded-lg border p-3 text-sm', semanticColorTokens.intent.caution.border, semanticColorTokens.intent.caution.bgSubtle)}><Checkbox checked={acknowledged} onChange={(event) => { setAcknowledged(event.target.checked) }} /><span>{t('statements.workspace.complete.acknowledge', { amount: formatCurrency(ignoredTotal, { currency }) })}</span></label> : null}
      </ModalContent>
      <ModalFooter><Button variant="secondary" onClick={onClose}>{t('statements.workspace.cancel')}</Button><Button disabled={pending || (requiresAcknowledgment && !acknowledged)} onClick={() => { onConfirm(requiresAcknowledgment) }}>{t('statements.workspace.complete.confirm')}</Button></ModalFooter>
    </Modal>
  )
}
