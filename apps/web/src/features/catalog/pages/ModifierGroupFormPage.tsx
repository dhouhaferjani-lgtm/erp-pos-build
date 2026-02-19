import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Trash2, Plus } from 'lucide-react'
import {
  useModifierGroup,
  useCreateModifierGroup,
  useUpdateModifierGroup,
  useCreateModifier,
  useUpdateModifier,
  useDeleteModifier,
} from '../hooks/useModifierGroups'
import type { SelectionType, ModifierData } from '../types/compositeItem'

export function ModifierGroupFormPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id && id !== 'new'

  const { data: group, isLoading } = useModifierGroup(isEdit ? id : '')
  const createMutation = useCreateModifierGroup()
  const updateMutation = useUpdateModifierGroup()
  const createModifierMutation = useCreateModifier()
  const updateModifierMutation = useUpdateModifier()
  const deleteModifierMutation = useDeleteModifier()

  const [form, setForm] = useState({
    code: '',
    name: '',
    selection_type: 'single' as SelectionType,
    min_selections: 0,
    max_selections: 1,
    is_required: false,
    is_active: true,
  })

  const [newModifier, setNewModifier] = useState({
    code: '',
    name: '',
    price_adjustment: '0',
  })

  useEffect(() => {
    if (group) {
      setForm({
        code: group.code,
        name: group.name,
        selection_type: group.selection_type,
        min_selections: group.min_selections,
        max_selections: group.max_selections,
        is_required: group.is_required,
        is_active: group.is_active,
      })
    }
  }, [group])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (isEdit) {
      updateMutation.mutate(
        { id, data: form },
        {
          onSuccess: () => toast.success(t('common:saved')),
          onError: () => toast.error(t('common:error')),
        }
      )
    } else {
      createMutation.mutate(form, {
        onSuccess: (created) => {
          toast.success(t('common:saved'))
          navigate(`/catalog/modifier-groups/${created.id}/edit`)
        },
        onError: () => toast.error(t('common:error')),
      })
    }
  }

  const handleAddModifier = () => {
    if (!newModifier.code || !newModifier.name || !isEdit) return
    createModifierMutation.mutate(
      {
        groupId: id,
        data: {
          code: newModifier.code,
          name: newModifier.name,
          price_adjustment: Number(newModifier.price_adjustment),
        },
      },
      {
        onSuccess: () => {
          setNewModifier({ code: '', name: '', price_adjustment: '0' })
          toast.success(t('common:saved'))
        },
      }
    )
  }

  const handleDeleteModifier = (modifierId: string) => {
    deleteModifierMutation.mutate(modifierId, {
      onSuccess: () => toast.success(t('common:deleted')),
    })
  }

  if (isEdit && isLoading) {
    return <div className="text-center py-8 text-gray-500">{t('common:loading')}</div>
  }

  const modifiers = group?.modifiers ?? []

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold text-gray-900">
        {isEdit ? t('catalog:editModifierGroup') : t('catalog:createModifierGroup')}
      </h1>

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <div>
            <label className="block text-sm font-medium text-gray-700">{t('catalog:code')}</label>
            <input type="text" required value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 text-sm" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">{t('catalog:name')}</label>
            <input type="text" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 text-sm" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">{t('catalog:selectionType')}</label>
            <select value={form.selection_type} onChange={(e) => setForm({ ...form, selection_type: e.target.value as SelectionType })} className="mt-1 block w-full rounded-md border-gray-300 text-sm">
              <option value="single">{t('catalog:single')}</option>
              <option value="multiple">{t('catalog:multiple')}</option>
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:minSelections')}</label>
              <input type="number" min="0" value={form.min_selections} onChange={(e) => setForm({ ...form, min_selections: Number(e.target.value) })} className="mt-1 block w-full rounded-md border-gray-300 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:maxSelections')}</label>
              <input type="number" min="1" value={form.max_selections} onChange={(e) => setForm({ ...form, max_selections: Number(e.target.value) })} className="mt-1 block w-full rounded-md border-gray-300 text-sm" />
            </div>
          </div>
          <div className="flex items-center gap-6">
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={form.is_required} onChange={(e) => setForm({ ...form, is_required: e.target.checked })} className="rounded border-gray-300" />
              <span className="text-sm text-gray-700">{t('catalog:isRequired')}</span>
            </label>
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} className="rounded border-gray-300" />
              <span className="text-sm text-gray-700">{t('catalog:isActive')}</span>
            </label>
          </div>
        </div>

        <div className="flex justify-end gap-3">
          <button type="button" onClick={() => navigate('/catalog/modifier-groups')} className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-50">{t('common:cancel')}</button>
          <button type="submit" disabled={createMutation.isPending || updateMutation.isPending} className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">{t('common:save')}</button>
        </div>
      </form>

      {/* Modifiers section (edit mode only) */}
      {isEdit && (
        <div className="border-t pt-6">
          <h3 className="text-lg font-medium text-gray-900 mb-4">{t('catalog:modifiers')}</h3>
          <table className="min-w-full divide-y divide-gray-300">
            <thead>
              <tr>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:code')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:name')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:priceAdjustment')}</th>
                <th className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">{t('catalog:isDefault')}</th>
                <th className="px-3 py-3.5"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {modifiers.map((mod: ModifierData) => (
                <tr key={mod.id}>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{mod.code}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{mod.name}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{mod.price_adjustment}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm">{mod.is_default ? t('common:yes') : t('common:no')}</td>
                  <td className="whitespace-nowrap px-3 py-4 text-sm">
                    <button onClick={() => handleDeleteModifier(mod.id)} className="text-red-600 hover:text-red-900">
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              ))}
              <tr className="bg-gray-50">
                <td className="px-3 py-4">
                  <input type="text" placeholder={t('catalog:code')} value={newModifier.code} onChange={(e) => setNewModifier({ ...newModifier, code: e.target.value })} className="w-24 rounded-md border-gray-300 text-sm" />
                </td>
                <td className="px-3 py-4">
                  <input type="text" placeholder={t('catalog:name')} value={newModifier.name} onChange={(e) => setNewModifier({ ...newModifier, name: e.target.value })} className="w-32 rounded-md border-gray-300 text-sm" />
                </td>
                <td className="px-3 py-4">
                  <input type="number" step="0.01" value={newModifier.price_adjustment} onChange={(e) => setNewModifier({ ...newModifier, price_adjustment: e.target.value })} className="w-24 rounded-md border-gray-300 text-sm" />
                </td>
                <td className="px-3 py-4">-</td>
                <td className="px-3 py-4">
                  <button onClick={handleAddModifier} disabled={!newModifier.code || !newModifier.name || createModifierMutation.isPending} className="text-indigo-600 hover:text-indigo-900 disabled:opacity-50">
                    <Plus className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
