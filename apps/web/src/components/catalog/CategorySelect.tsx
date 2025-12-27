import { useTranslation } from 'react-i18next'
import { Select } from '@/components/atoms/Select/Select'
import { useCategoryTree } from '@/features/catalog/api/queries'
import type { CategoryTreeNode } from '@/features/catalog/types'

interface CategorySelectProps {
  value?: number | null
  onChange: (categoryId: number | null) => void
  placeholder?: string
  allowClear?: boolean
  error?: boolean
  disabled?: boolean
  className?: string
}

interface FlatCategory {
  id: number
  name: string
  depth: number
}

export function CategorySelect({
  value,
  onChange,
  placeholder,
  allowClear = true,
  error = false,
  disabled = false,
  className,
}: CategorySelectProps) {
  const { t } = useTranslation()
  const { data, isLoading } = useCategoryTree()

  const flattenCategories = (categories: CategoryTreeNode[], depth = 0): FlatCategory[] => {
    return categories.flatMap((cat) => [
      { id: cat.id, name: cat.name, depth },
      ...flattenCategories(cat.children || [], depth + 1),
    ])
  }

  const flatCategories = data ? flattenCategories(data) : []

  return (
    <Select
      value={value?.toString() ?? ''}
      onChange={(e) => {
        const val = e.target.value
        onChange(val ? Number(val) : null)
      }}
      disabled={isLoading || disabled}
      error={error}
      className={className}
    >
      <option value="">
        {placeholder || t('catalog.categories.selectCategory')}
      </option>
      {allowClear && value && (
        <option value="">{t('catalog.categories.noCategory')}</option>
      )}
      {flatCategories.map((cat) => (
        <option key={cat.id} value={cat.id}>
          {'\u00A0'.repeat(cat.depth * 4)}
          {cat.name}
        </option>
      ))}
    </Select>
  )
}
