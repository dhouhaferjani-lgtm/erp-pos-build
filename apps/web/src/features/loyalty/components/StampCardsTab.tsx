import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2 } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import { useStampCards, useCreateStampCard, useUpdateStampCard, useDeleteStampCard } from '../hooks/useStampCards'
import { StampCardFormModal } from './StampCardFormModal'
import type { StampCard, CreateStampCardData } from '../types/loyalty'

interface StampCardsTabProps {
  programId: string
}

export function StampCardsTab({ programId }: StampCardsTabProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const { data: cards, isLoading } = useStampCards(programId)
  const createMutation = useCreateStampCard(programId)
  const updateMutation = useUpdateStampCard(programId)
  const deleteMutation = useDeleteStampCard(programId)

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingCard, setEditingCard] = useState<StampCard | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<StampCard | null>(null)

  const handleOpenCreate = () => {
    setEditingCard(null)
    setIsModalOpen(true)
  }

  const handleOpenEdit = (card: StampCard) => {
    setEditingCard(card)
    setIsModalOpen(true)
  }

  const handleSubmit = (data: CreateStampCardData) => {
    if (editingCard) {
      updateMutation.mutate(
        { id: editingCard.id, data },
        { onSuccess: () => { setIsModalOpen(false); } },
      )
    } else {
      createMutation.mutate(data, { onSuccess: () => { setIsModalOpen(false); } })
    }
  }

  if (isLoading) {
    return (
      <div className="flex justify-center py-8">
        <Spinner />
      </div>
    )
  }

  const cardsList = cards ?? []

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-900">{t('loyalty:stampCards.title')}</h3>
        <Button size="sm" onClick={handleOpenCreate}>
          <Plus className="w-4 h-4 mr-1" />
          {t('loyalty:stampCards.create')}
        </Button>
      </div>

      {cardsList.length === 0 ? (
        <div className="text-center py-8">
          <p className="text-gray-500">{t('loyalty:stampCards.noCards')}</p>
          <p className="text-gray-400 text-sm mt-1">{t('loyalty:stampCards.noCardsDescription')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.name')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.stampsRequired')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.stampsPerItem')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.expiryDays')}</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">{t('common:table.actions')}</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {cardsList.map((card) => (
                <tr key={card.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">{card.name}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{card.stamps_required}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{card.stamps_per_item}</td>
                  <td className="px-4 py-3 text-sm text-gray-700">{card.expiry_days ?? '-'}</td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => { handleOpenEdit(card); }}
                        className="p-1.5 rounded hover:bg-gray-100 text-gray-600"
                      >
                        <Pencil className="w-4 h-4" />
                      </button>
                      <button
                        onClick={() => { setDeleteTarget(card); }}
                        className="p-1.5 rounded hover:bg-red-50 text-red-500"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <StampCardFormModal
        isOpen={isModalOpen}
        onClose={() => { setIsModalOpen(false); }}
        onSubmit={handleSubmit}
        isPending={createMutation.isPending || updateMutation.isPending}
        editingCard={editingCard}
      />

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        onClose={() => { setDeleteTarget(null); }}
        onConfirm={() => {
          if (deleteTarget) {
            deleteMutation.mutate(deleteTarget.id, { onSettled: () => { setDeleteTarget(null); } })
          }
        }}
        isLoading={deleteMutation.isPending}
        title={t('loyalty:actions.delete')}
        message={t('loyalty:stampCards.deleteConfirm')}
        variant="danger"
      />
    </div>
  )
}
