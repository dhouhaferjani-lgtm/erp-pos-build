import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import { StickyFormFooter } from '../../../components/molecules/StickyFormFooter/StickyFormFooter'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { useMenu, useCreateMenu, useUpdateMenu, useCreateMenuCategory, useDeleteMenuCategory } from '../hooks/useMenus'
import { MenuCategoryItemManager } from '../components/MenuCategoryItemManager'
import { Input, Textarea, FormField, Button } from '@/components/atoms'
import { cn } from '@/lib/utils'
import { tokens, textColors, colors } from '@/lib/designTokens'
import type { CreateMenuData, UpdateMenuData, MenuCategoryData } from '../types/menu'

const DAY_OPTIONS = [
  { value: 1, label: 'Mon' },
  { value: 2, label: 'Tue' },
  { value: 3, label: 'Wed' },
  { value: 4, label: 'Thu' },
  { value: 5, label: 'Fri' },
  { value: 6, label: 'Sat' },
  { value: 7, label: 'Sun' },
]

export function MenuFormPage() {
  const { t } = useTranslation(['menu', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditing = !!id

  const { data: existingMenu, isLoading: isLoadingMenu } = useMenu(id ?? '')
  const createMutation = useCreateMenu()
  const updateMutation = useUpdateMenu()
  const createCategoryMutation = useCreateMenuCategory()
  const deleteCategoryMutation = useDeleteMenuCategory()

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [isDefault, setIsDefault] = useState(false)
  const [isActive, setIsActive] = useState(true)
  const [activeFrom, setActiveFrom] = useState('')
  const [activeUntil, setActiveUntil] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [availableDays, setAvailableDays] = useState<number[]>([])
  const [displayOrder, setDisplayOrder] = useState(0)
  const [newCategoryName, setNewCategoryName] = useState('')

  // Populate form when editing
  useEffect(() => {
    if (existingMenu) {
      const menu = existingMenu
      setName(menu.name)
      setDescription(menu.description ?? '')
      setIsDefault(menu.is_default)
      setIsActive(menu.is_active)
      setActiveFrom(menu.active_from ?? '')
      setActiveUntil(menu.active_until ?? '')
      setStartDate(menu.start_date ?? '')
      setEndDate(menu.end_date ?? '')
      setAvailableDays(menu.available_days ?? [])
      setDisplayOrder(menu.display_order)
    }
  }, [existingMenu])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    const data: CreateMenuData | UpdateMenuData = {
      name,
      description: description || null,
      is_default: isDefault,
      is_active: isActive,
      active_from: activeFrom || null,
      active_until: activeUntil || null,
      start_date: startDate || null,
      end_date: endDate || null,
      available_days: availableDays.length > 0 ? availableDays : null,
      display_order: displayOrder,
    }

    if (isEditing && id) {
      await updateMutation.mutateAsync({ id, data })
    } else {
      await createMutation.mutateAsync(data as CreateMenuData)
    }
    navigate('/catalog/menus')
  }

  const handleAddCategory = async () => {
    if (!newCategoryName.trim() || !id) return
    await createCategoryMutation.mutateAsync({
      menuId: id,
      data: { name: newCategoryName.trim() },
    })
    setNewCategoryName('')
  }

  const handleDeleteCategory = async (categoryId: string) => {
    if (window.confirm(t('menu:confirmDeleteCategory'))) {
      await deleteCategoryMutation.mutateAsync(categoryId)
    }
  }

  const toggleDay = (day: number) => {
    setAvailableDays((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day].sort()
    )
  }

  if (isEditing && isLoadingMenu) {
    return <div className={cn('text-center py-8', textColors.tertiary)}>{t('common:loading')}</div>
  }

  const categories: MenuCategoryData[] = existingMenu?.categories ?? []

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={isEditing ? t('menu:editMenu') : t('menu:createMenu')}
        breadcrumb={
          <button
            type="button"
            onClick={() => navigate('/catalog/menus')}
            className={cn(
              'inline-flex items-center gap-2 text-sm',
              textColors.tertiary,
              textColors.hoverPrimary,
            )}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:back')}
          </button>
        }
        className="mb-0"
      />

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Basic Info */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('menu:basicInfo')}</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label={t('menu:name')} htmlFor="menu-name" required className="sm:col-span-2">
              <Input
                id="menu-name"
                value={name}
                onChange={(e) => { setName(e.target.value); }}
                required
              />
            </FormField>
            <FormField label={t('menu:description')} htmlFor="menu-desc" className="sm:col-span-2">
              <Textarea
                id="menu-desc"
                value={description}
                onChange={(e) => { setDescription(e.target.value); }}
                rows={2}
              />
            </FormField>
            <FormField label={t('menu:displayOrder')} htmlFor="display-order">
              <Input
                id="display-order"
                type="number"
                value={displayOrder}
                onChange={(e) => { setDisplayOrder(parseInt(e.target.value, 10) || 0); }}
                min={0}
              />
            </FormField>
            <div className="flex items-center gap-6 pt-6">
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={isActive}
                  onChange={(e) => { setIsActive(e.target.checked); }}
                  className={tokens.checkbox.base}
                />
                <span className={cn('text-sm', textColors.secondary)}>{t('menu:isActive')}</span>
              </label>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={isDefault}
                  onChange={(e) => { setIsDefault(e.target.checked); }}
                  className={tokens.checkbox.base}
                />
                <span className={cn('text-sm', textColors.secondary)}>{t('menu:isDefault')}</span>
              </label>
            </div>
          </div>
        </div>

        {/* Schedule */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('menu:schedule')}</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label={t('menu:activeFrom')} htmlFor="active-from">
              <Input
                id="active-from"
                type="time"
                value={activeFrom}
                onChange={(e) => { setActiveFrom(e.target.value); }}
              />
            </FormField>
            <FormField label={t('menu:activeUntil')} htmlFor="active-until">
              <Input
                id="active-until"
                type="time"
                value={activeUntil}
                onChange={(e) => { setActiveUntil(e.target.value); }}
              />
            </FormField>
            <FormField label={t('menu:startDate')} htmlFor="start-date">
              <Input
                id="start-date"
                type="date"
                value={startDate}
                onChange={(e) => { setStartDate(e.target.value); }}
              />
            </FormField>
            <FormField label={t('menu:endDate')} htmlFor="end-date">
              <Input
                id="end-date"
                type="date"
                value={endDate}
                onChange={(e) => { setEndDate(e.target.value); }}
              />
            </FormField>
            <div className="sm:col-span-2">
              <label className={tokens.label.base + ' mb-2'}>{t('menu:availableDays')}</label>
              <div className="flex flex-wrap gap-2">
                {DAY_OPTIONS.map((day) => (
                  <button
                    key={day.value}
                    type="button"
                    onClick={() => { toggleDay(day.value); }}
                    className={cn(
                      'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                      availableDays.includes(day.value)
                        ? tokens.button.primary
                        : cn(colors.neutral[100], textColors.tertiary, colors.hover.gray50),
                    )}
                  >
                    {day.label}
                  </button>
                ))}
              </div>
              <p className={tokens.helperText.base}>{t('menu:availableDaysHint')}</p>
            </div>
          </div>
        </div>

        {/* Categories (edit mode only) */}
        {isEditing && (
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('menu:categories')}</h2>

            {categories.length > 0 && (
              <div className="space-y-3 mb-4">
                {categories.map((category) => (
                  <div key={category.id} className="space-y-1">
                    <div className="flex items-center justify-end">
                      <button
                        type="button"
                        onClick={() => handleDeleteCategory(category.id)}
                        className={cn('p-1', textColors.disabled, textColors.hoverError)}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                    <MenuCategoryItemManager category={category} />
                  </div>
                ))}
              </div>
            )}

            <div className="flex gap-2">
              <Input
                value={newCategoryName}
                onChange={(e) => { setNewCategoryName(e.target.value); }}
                placeholder={t('menu:newCategoryPlaceholder')}
                className="flex-1"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    handleAddCategory()
                  }
                }}
              />
              <Button
                type="button"
                variant="secondary"
                onClick={handleAddCategory}
                disabled={!newCategoryName.trim()}
              >
                <Plus className="mr-1 h-4 w-4" />
                {t('menu:addCategory')}
              </Button>
            </div>
          </div>
        )}

        <StickyFormFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/catalog/menus')}
          >
            {t('common:cancel')}
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={createMutation.isPending || updateMutation.isPending}
          >
            {isEditing ? t('common:save') : t('menu:createMenu')}
          </Button>
        </StickyFormFooter>
      </form>
    </div>
  )
}
