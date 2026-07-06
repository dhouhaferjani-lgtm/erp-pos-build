import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Checkbox, FormField, Input } from '@/components/atoms'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { textColors } from '@/lib/designTokens'
import type { Zone } from './api'

const schema = z.object({
  name: z.string().min(1),
  code: z.string().min(1).max(50),
  sort_order: z.coerce.number().int().min(0),
  is_active: z.boolean(),
})

export type ZoneFormValues = z.infer<typeof schema>

interface ZoneFormDialogProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (data: ZoneFormValues) => void
  isPending: boolean
  editingZone: Zone | null
}

const defaultValues: ZoneFormValues = {
  name: '',
  code: '',
  sort_order: 0,
  is_active: true,
}

export function ZoneFormDialog({ isOpen, onClose, onSubmit, isPending, editingZone }: ZoneFormDialogProps) {
  const { t } = useTranslation(['inventory', 'common'])

  const form = useForm<ZoneFormValues>({
    resolver: zodResolver(schema) as Resolver<ZoneFormValues>,
    defaultValues,
  })

  useEffect(() => {
    if (!isOpen) {
      return
    }
    if (editingZone) {
      form.reset({
        name: editingZone.name,
        code: editingZone.code,
        sort_order: editingZone.sort_order,
        is_active: editingZone.is_active,
      })
    } else {
      form.reset(defaultValues)
    }
  }, [editingZone, form, isOpen])

  const handleFormSubmit = (values: ZoneFormValues) => {
    onSubmit(values)
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={editingZone ? t('inventory:zones.editZone') : t('inventory:zones.addZone')}
    >
      <form onSubmit={form.handleSubmit(handleFormSubmit)}>
        <ModalContent>
          <FormField
            label={t('inventory:zones.form.name')}
            htmlFor="zone-name"
            required
            error={form.formState.errors.name ? t('inventory:zones.form.nameRequired') : undefined}
          >
            <Input
              id="zone-name"
              {...form.register('name')}
              placeholder={t('inventory:zones.form.namePlaceholder')}
              error={!!form.formState.errors.name}
            />
          </FormField>

          <div className="grid grid-cols-2 gap-4">
            <FormField
              label={t('inventory:zones.form.code')}
              htmlFor="zone-code"
              required
              error={form.formState.errors.code ? t('inventory:zones.form.codeRequired') : undefined}
            >
              <Input
                id="zone-code"
                {...form.register('code')}
                placeholder={t('inventory:zones.form.codePlaceholder')}
                error={!!form.formState.errors.code}
              />
            </FormField>
            <FormField label={t('inventory:zones.form.sortOrder')} htmlFor="zone-sort-order">
              <Input id="zone-sort-order" type="number" min="0" {...form.register('sort_order')} />
            </FormField>
          </div>

          <div className="flex items-center gap-2">
            <Checkbox id="zone-is-active" {...form.register('is_active')} />
            <label htmlFor="zone-is-active" className={`text-sm ${textColors.secondary}`}>
              {t('inventory:zones.form.isActive')}
            </label>
          </div>
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isPending}>
            {isPending ? t('common:saving') : t('common:save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
