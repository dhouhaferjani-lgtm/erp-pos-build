import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Button, Checkbox, Input, Select } from '@/components/atoms'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import type { LocationNode, LocationNodeType, NodeInput } from '../api'

const nodeTypes: LocationNodeType[] = ['zone', 'aisle', 'rack', 'shelf', 'bin', 'section']
const codePattern = /^[A-Za-z0-9][A-Za-z0-9.-]{0,49}$/

interface NodeFormDialogProps {
  isOpen: boolean
  locationId: string
  parentId: string | null
  node?: LocationNode | null
  isPending: boolean
  onClose: () => void
  onSubmit: (input: NodeInput) => void
}

interface NodeFormValues {
  name: string
  code: string
  node_type: LocationNodeType
  sort_order: number
  is_active: boolean
}

export function NodeFormDialog({
  isOpen,
  locationId,
  parentId,
  node = null,
  isPending,
  onClose,
  onSubmit,
}: NodeFormDialogProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const form = useForm<NodeFormValues>({
    defaultValues: {
      name: '',
      code: '',
      node_type: 'zone',
      sort_order: 0,
      is_active: true,
    },
  })

  useEffect(() => {
    if (!isOpen) return
    form.reset({
      name: node?.name ?? '',
      code: node?.code ?? '',
      node_type: node?.node_type ?? 'zone',
      sort_order: node?.sort_order ?? 0,
      is_active: node?.is_active ?? true,
    })
  }, [form, isOpen, node])

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={node === null ? t('inventory:placement.form.addTitle') : t('inventory:placement.form.editTitle')}
      size="md"
    >
      <form onSubmit={(event) => { void form.handleSubmit((values) => {
        onSubmit({
          location_id: locationId,
          parent_id: node?.parent_id ?? parentId,
          node_type: values.node_type,
          name: values.name.trim(),
          code: values.code.trim(),
          sort_order: values.sort_order,
          is_active: values.is_active,
        })
      })(event) }}>
        <ModalContent className="space-y-4">
          <FormField label={t('inventory:placement.form.name')} htmlFor="placement-node-name" error={form.formState.errors.name?.message}>
            <Input id="placement-node-name" {...form.register('name', { required: t('inventory:placement.form.nameRequired') })} />
          </FormField>
          <FormField label={t('inventory:placement.form.code')} htmlFor="placement-node-code" error={form.formState.errors.code?.message}>
            <Input id="placement-node-code" {...form.register('code', { pattern: { value: codePattern, message: t('inventory:placement.form.codeInvalid') } })} />
          </FormField>
          <FormField label={t('inventory:placement.form.type')} htmlFor="placement-node-type">
            <Select id="placement-node-type" {...form.register('node_type')}>
              {nodeTypes.map((type) => <option key={type} value={type}>{t(`inventory:placement.types.${type}`)}</option>)}
            </Select>
          </FormField>
          <FormField label={t('inventory:placement.form.sortOrder')} htmlFor="placement-sort-order">
            <Input id="placement-sort-order" type="number" {...form.register('sort_order', { valueAsNumber: true })} />
          </FormField>
          <label className="flex items-center gap-2 text-sm">
            <Checkbox {...form.register('is_active')} />
            {t('inventory:placement.form.active')}
          </label>
        </ModalContent>
        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose}>{t('common:cancel')}</Button>
          <Button type="submit" disabled={isPending}>{isPending ? t('common:saving') : t('common:save')}</Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
