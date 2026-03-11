import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Select, FormField } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import { useActivePrograms } from '../hooks/usePrograms'

interface EnrollMemberModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (programId: string) => void
  isPending: boolean
}

export function EnrollMemberModal({ isOpen, onClose, onSubmit, isPending }: EnrollMemberModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const { data: programs } = useActivePrograms()
  const [selectedProgramId, setSelectedProgramId] = useState('')

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (selectedProgramId) {
      onSubmit(selectedProgramId)
    }
  }

  const activePrograms = programs ?? []

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm">
      <Modal.Header title={t('loyalty:enroll.title')} onClose={onClose} />
      <form onSubmit={handleSubmit}>
        <Modal.Content>
          {activePrograms.length === 0 ? (
            <p className="text-sm text-gray-500">{t('loyalty:enroll.noActivePrograms')}</p>
          ) : (
            <FormField label={t('loyalty:enroll.selectProgram')}>
              <Select
                value={selectedProgramId}
                onChange={(e) => setSelectedProgramId(e.target.value)}
              >
                <option value="">{t('loyalty:enroll.selectProgram')}</option>
                {activePrograms.map((program) => (
                  <option key={program.id} value={program.id}>
                    {program.name}
                  </option>
                ))}
              </Select>
            </FormField>
          )}
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isPending || !selectedProgramId || activePrograms.length === 0}>
            {isPending ? t('common:saving') : t('loyalty:actions.enroll')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
