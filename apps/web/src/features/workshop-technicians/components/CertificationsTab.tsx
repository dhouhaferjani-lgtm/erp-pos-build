import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import type { TechnicianCertification } from '../api/authoringTypes'
import {
  useDeleteCertification,
  useTechnicianCertifications,
} from '../hooks/useAuthoring'
import { CertificationFormModal } from './CertificationFormModal'

interface CertificationsTabProps {
  technicianId: string
}

export function CertificationsTab({ technicianId }: CertificationsTabProps) {
  const { t } = useTranslation('workshop-technicians')
  const { data, isLoading } = useTechnicianCertifications(technicianId)
  const deleteMut = useDeleteCertification(technicianId)
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<TechnicianCertification | undefined>(undefined)

  function handleAdd(): void {
    setEditing(undefined)
    setModalOpen(true)
  }

  function handleEdit(row: TechnicianCertification): void {
    setEditing(row)
    setModalOpen(true)
  }

  function handleDelete(row: TechnicianCertification): void {
    if (window.confirm(t('authoring.certifications.confirmDelete'))) {
      deleteMut.mutate(row.id)
    }
  }

  const rows = data ?? []

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h3 className={`text-sm font-semibold ${textColors.primary}`}>
          {t('authoring.certifications.title')}
        </h3>
        <button
          type="button"
          onClick={handleAdd}
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm} inline-flex items-center gap-1`}
        >
          <Plus className="h-4 w-4" />
          {t('authoring.certifications.add')}
        </button>
      </div>

      {isLoading ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('team.loading')}</p>
      ) : rows.length === 0 ? (
        <p className={`rounded-lg border ${borderColors.light} bg-white p-4 text-sm ${textColors.tertiary}`}>
          {t('authoring.certifications.empty')}
        </p>
      ) : (
        <ul className={`divide-y ${borderColors.divideLight} overflow-hidden rounded-lg border ${borderColors.light} bg-white`}>
          {rows.map((row) => (
            <li key={row.id} className="flex items-center justify-between gap-3 p-3">
              <div>
                <p className={`text-sm font-medium ${textColors.primary}`}>
                  {row.certification_name}
                </p>
                <p className={`text-xs ${textColors.tertiary}`}>
                  {row.issuing_body ?? '—'}
                  {row.expires_at !== null ? ` · ${row.expires_at}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  onClick={() => {
                    handleEdit(row)
                  }}
                  className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm}`}
                  aria-label={t('authoring.certifications.modal.editTitle')}
                >
                  <Pencil className="h-4 w-4" />
                </button>
                <button
                  type="button"
                  onClick={() => {
                    handleDelete(row)
                  }}
                  className={`${tokens.button.base} ${tokens.button.dangerOutline} ${tokens.button.sizes.sm}`}
                  aria-label={t('authoring.certifications.confirmDelete')}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {modalOpen ? (
        <CertificationFormModal
          technicianId={technicianId}
          certification={editing}
          onClose={() => {
            setModalOpen(false)
          }}
          onSaved={() => {
            setModalOpen(false)
          }}
        />
      ) : null}
    </div>
  )
}
