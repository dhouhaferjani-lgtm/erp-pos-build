import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, MapPin, Edit, Trash2, Star, Building2, Warehouse, Briefcase, Truck, Store } from 'lucide-react'
import { fetchLocations, createLocation, updateLocation, deleteLocation, setDefaultLocation } from '../location/api'
import type { LocationApiResponse, CreateLocationInput, UpdateLocationInput } from '../location/api'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { cn } from '../../lib/utils'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { Button, Checkbox, FormField, Input, Select, StatusBadge } from '../../components/atoms'
import { Modal, ModalContent, ModalFooter } from '../../components/organisms/Modal'
import { EmptyState } from '../../components/molecules'

type LocationType = 'shop' | 'warehouse' | 'office' | 'mobile'

const typeIcons: Record<LocationType, typeof Building2> = {
  shop: Store,
  warehouse: Warehouse,
  office: Briefcase,
  mobile: Truck,
}

const typeBadgeTones: Record<LocationType, string> = {
  shop: tokens.badge.blue,
  warehouse: tokens.badge.green,
  office: tokens.badge.purple,
  mobile: tokens.badge.yellow,
}

const BRANCH_TAX_REQUIRED_COUNTRIES = new Set(['FR', 'TN', 'MA'])

interface LocationFormData {
  name: string
  type: LocationType
  code: string
  phone: string
  email: string
  addressStreet: string
  addressCity: string
  addressPostalCode: string
  addressCountry: string
  taxId: string
  vatNumber: string
  posEnabled: boolean
}

const emptyForm: LocationFormData = {
  name: '',
  type: 'shop',
  code: '',
  phone: '',
  email: '',
  addressStreet: '',
  addressCity: '',
  addressPostalCode: '',
  addressCountry: '',
  taxId: '',
  vatNumber: '',
  posEnabled: false,
}

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function LocationsPage() {
  const { t } = useTranslation(['common', 'settings'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingLocation, setEditingLocation] = useState<LocationApiResponse | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<LocationApiResponse | null>(null)
  const [formData, setFormData] = useState<LocationFormData>(emptyForm)

  const { data: locations, isLoading } = useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: fetchLocations,
    enabled: tenantId !== null && companyId !== null,
  })

  const createMutation = useMutation({
    mutationFn: (data: CreateLocationInput) => createLocation(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('locations', tenantId, companyId),
      })
      closeModal()
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateLocationInput }) => updateLocation(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('locations', tenantId, companyId),
      })
      closeModal()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteLocation(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('locations', tenantId, companyId),
      })
      setDeleteTarget(null)
    },
  })

  const setDefaultMutation = useMutation({
    mutationFn: (id: string) => setDefaultLocation(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('locations', tenantId, companyId),
      })
    },
  })

  const openCreateModal = () => {
    setEditingLocation(null)
    setFormData(emptyForm)
    setIsModalOpen(true)
  }

  const openEditModal = (location: LocationApiResponse) => {
    setEditingLocation(location)
    setFormData({
      name: location.name,
      type: location.type as LocationType,
      code: location.code,
      phone: location.phone ?? '',
      email: location.email ?? '',
      addressStreet: location.address_street ?? '',
      addressCity: location.address_city ?? '',
      addressPostalCode: location.address_postal_code ?? '',
      addressCountry: location.address_country ?? '',
      taxId: location.tax_id ?? '',
      vatNumber: location.vat_number ?? '',
      posEnabled: location.pos_enabled,
    })
    setIsModalOpen(true)
  }

  const closeModal = () => {
    setIsModalOpen(false)
    setEditingLocation(null)
    setFormData(emptyForm)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (editingLocation) {
      const updateData: UpdateLocationInput = {
        name: formData.name,
        type: formData.type,
        posEnabled: formData.posEnabled,
      }
      if (formData.code) updateData.code = formData.code
      if (formData.phone) updateData.phone = formData.phone
      if (formData.email) updateData.email = formData.email
      if (formData.addressStreet) updateData.addressStreet = formData.addressStreet
      if (formData.addressCity) updateData.addressCity = formData.addressCity
      if (formData.addressPostalCode) updateData.addressPostalCode = formData.addressPostalCode
      if (formData.addressCountry) updateData.addressCountry = formData.addressCountry
      // Always send tax fields on update so an emptied field clears the branch
      // override back to inheriting the company value (null = inherit). The API
      // blocks clearing for a sellable shop in a country that requires it.
      updateData.taxId = formData.taxId.trim() === '' ? null : formData.taxId
      updateData.vatNumber = formData.vatNumber.trim() === '' ? null : formData.vatNumber

      updateMutation.mutate({
        id: editingLocation.id,
        data: updateData,
      })
    } else {
      const createData: CreateLocationInput = {
        name: formData.name,
        type: formData.type,
        posEnabled: formData.posEnabled,
      }
      if (formData.code) createData.code = formData.code
      if (formData.phone) createData.phone = formData.phone
      if (formData.email) createData.email = formData.email
      if (formData.addressStreet) createData.addressStreet = formData.addressStreet
      if (formData.addressCity) createData.addressCity = formData.addressCity
      if (formData.addressPostalCode) createData.addressPostalCode = formData.addressPostalCode
      if (formData.addressCountry) createData.addressCountry = formData.addressCountry
      if (formData.taxId) createData.taxId = formData.taxId
      if (formData.vatNumber) createData.vatNumber = formData.vatNumber

      createMutation.mutate(createData)
    }
  }

  const isMutating = createMutation.isPending || updateMutation.isPending
  const isTaxIdRequiredHint = formData.type === 'shop' && BRANCH_TAX_REQUIRED_COUNTRIES.has(
    formData.addressCountry.trim().toUpperCase(),
  )

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <p className={textColors.tertiary}>{t('status.loading')}</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className={cn('text-2xl font-bold', textColors.primary)}>{t('locations.title')}</h1>
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>{t('locations.subtitle')}</p>
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>{t('settings:locations.scopeHint')}</p>
        </div>
        <Button className="gap-2" onClick={openCreateModal}>
          <Plus className="h-4 w-4" />
          {t('locations.addLocation')}
        </Button>
      </div>

      {/* Locations List */}
      {(!locations || locations.length === 0) ? (
        <div className="py-6">
          <EmptyState
            icon={<MapPin className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
            title={t('locations.empty.title')}
            description={t('locations.empty.description')}
          />
          <div className="mt-6 flex justify-center">
            <Button className="gap-2" onClick={openCreateModal}>
              <Plus className="h-4 w-4" />
              {t('locations.addLocation')}
            </Button>
          </div>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {locations.map((location) => {
            const TypeIcon = typeIcons[location.type as LocationType] ?? Building2

            return (
              <div
                key={location.id}
                className={cn(tokens.card.base, borderColors.hover, 'transition-colors')}
              >
                <div className="flex items-start justify-between">
                  <div className="flex items-start gap-3">
                    <div className={cn('rounded-lg p-2', tokens.badge.base, typeBadgeTones[location.type as LocationType])}>
                      <TypeIcon className="h-5 w-5" />
                    </div>
                    <div>
                      <h3 className={cn('font-medium', textColors.primary)}>{location.name}</h3>
                      <p className={cn('text-sm', textColors.tertiary)}>{location.code}</p>
                    </div>
                  </div>
                  {location.is_default && (
                    <StatusBadge tone="warning" className="gap-1">
                      <Star className="h-3 w-3" />
                      {t('locations.default')}
                    </StatusBadge>
                  )}
                </div>

                <div className="mt-4 space-y-2 text-sm">
                  <div className={cn('flex items-center gap-2', textColors.tertiary)}>
                    <span className={cn(tokens.badge.base, typeBadgeTones[location.type as LocationType])}>
                      {t(`locations.types.${location.type}`)}
                    </span>
                    {location.pos_enabled && (
                      <StatusBadge tone="success">
                        {t('locations.posEnabled')}
                      </StatusBadge>
                    )}
                  </div>
                  {(location.address_city ?? location.address_country) && (
                    <p className={textColors.tertiary}>
                      {[location.address_city, location.address_country].filter(Boolean).join(', ')}
                    </p>
                  )}
                  {location.phone && (
                    <p className={textColors.tertiary}>{location.phone}</p>
                  )}
                </div>

                {/* Actions */}
                <div className={cn('mt-4 flex items-center gap-2 border-t pt-4', borderColors.light)}>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="gap-1"
                    onClick={() => { openEditModal(location) }}
                  >
                    <Edit className="h-3.5 w-3.5" />
                    {t('actions.edit')}
                  </Button>
                  {!location.is_default && (
                    <>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1"
                        onClick={() => { setDefaultMutation.mutate(location.id) }}
                        disabled={setDefaultMutation.isPending}
                      >
                        <Star className="h-3.5 w-3.5" />
                        {t('locations.setDefault')}
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1"
                        onClick={() => { setDeleteTarget(location) }}
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        {t('actions.delete')}
                      </Button>
                    </>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Create/Edit Modal */}
      <Modal
        isOpen={isModalOpen}
        onClose={closeModal}
        title={editingLocation ? t('locations.editLocation') : t('locations.addLocation')}
      >
        <form onSubmit={handleSubmit}>
          <ModalContent>
            {/* Name */}
            <FormField label={t('locations.form.name')} htmlFor="name" required>
              <Input
                type="text"
                id="name"
                required
                value={formData.name}
                onChange={(e) => { setFormData({ ...formData, name: e.target.value }) }}
                placeholder={t('locations.form.namePlaceholder')}
              />
            </FormField>

            {/* Type & Code */}
            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('locations.form.type')} htmlFor="type" required>
                <Select
                  id="type"
                  required
                  value={formData.type}
                  onChange={(e) => { setFormData({ ...formData, type: e.target.value as LocationType }) }}
                >
                  <option value="shop">{t('locations.types.shop')}</option>
                  <option value="warehouse">{t('locations.types.warehouse')}</option>
                  <option value="office">{t('locations.types.office')}</option>
                  <option value="mobile">{t('locations.types.mobile')}</option>
                </Select>
              </FormField>
              <FormField label={t('locations.form.code')} htmlFor="code">
                <Input
                  type="text"
                  id="code"
                  value={formData.code}
                  onChange={(e) => { setFormData({ ...formData, code: e.target.value }) }}
                  placeholder={t('locations.form.codePlaceholder')}
                />
              </FormField>
            </div>

            {/* Contact */}
            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('locations.form.phone')} htmlFor="phone">
                <Input
                  type="tel"
                  id="phone"
                  value={formData.phone}
                  onChange={(e) => { setFormData({ ...formData, phone: e.target.value }) }}
                />
              </FormField>
              <FormField label={t('locations.form.email')} htmlFor="email">
                <Input
                  type="email"
                  id="email"
                  value={formData.email}
                  onChange={(e) => { setFormData({ ...formData, email: e.target.value }) }}
                />
              </FormField>
            </div>

            {/* Address */}
            <FormField label={t('locations.form.address')} htmlFor="address">
              <Input
                type="text"
                id="address"
                value={formData.addressStreet}
                onChange={(e) => { setFormData({ ...formData, addressStreet: e.target.value }) }}
              />
            </FormField>

            {/* City, Postal, Country */}
            <div className="grid grid-cols-3 gap-4">
              <FormField label={t('locations.form.city')} htmlFor="city">
                <Input
                  type="text"
                  id="city"
                  value={formData.addressCity}
                  onChange={(e) => { setFormData({ ...formData, addressCity: e.target.value }) }}
                />
              </FormField>
              <FormField label={t('locations.form.postalCode')} htmlFor="postal">
                <Input
                  type="text"
                  id="postal"
                  value={formData.addressPostalCode}
                  onChange={(e) => { setFormData({ ...formData, addressPostalCode: e.target.value }) }}
                />
              </FormField>
              <FormField label={t('locations.form.country')} htmlFor="country">
                <Input
                  type="text"
                  id="country"
                  value={formData.addressCountry}
                  onChange={(e) => { setFormData({ ...formData, addressCountry: e.target.value }) }}
                />
              </FormField>
            </div>

            {/* Tax Identity */}
            <div className="grid grid-cols-2 gap-4">
              <FormField
                label={isTaxIdRequiredHint ? `${t('locations.form.taxId')} *` : t('locations.form.taxId')}
                htmlFor="taxId"
                helperText={isTaxIdRequiredHint ? t('locations.form.taxIdRequiredHint') : undefined}
              >
                <Input
                  type="text"
                  id="taxId"
                  value={formData.taxId}
                  onChange={(e) => { setFormData({ ...formData, taxId: e.target.value }) }}
                  aria-required={isTaxIdRequiredHint}
                  aria-describedby={isTaxIdRequiredHint ? 'taxId-required-hint' : undefined}
                />
              </FormField>
              <FormField label={t('locations.form.vatNumber')} htmlFor="vatNumber">
                <Input
                  type="text"
                  id="vatNumber"
                  value={formData.vatNumber}
                  onChange={(e) => { setFormData({ ...formData, vatNumber: e.target.value }) }}
                />
              </FormField>
            </div>

            {/* POS Enabled */}
            <div className="flex items-center gap-2">
              <Checkbox
                id="posEnabled"
                checked={formData.posEnabled}
                onChange={(e) => { setFormData({ ...formData, posEnabled: e.target.checked }) }}
              />
              <label htmlFor="posEnabled" className={cn('text-sm', textColors.secondary)}>
                {t('locations.form.posEnabled')}
              </label>
            </div>
          </ModalContent>

          {/* Actions */}
          <ModalFooter>
            <Button type="button" variant="secondary" onClick={closeModal}>
              {t('cancel')}
            </Button>
            <Button type="submit" disabled={isMutating}>
              {isMutating ? t('saving') : t('save')}
            </Button>
          </ModalFooter>
        </form>
      </Modal>

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={deleteTarget !== null}
        onClose={() => { setDeleteTarget(null) }}
        onConfirm={() => {
          if (deleteTarget) {
            deleteMutation.mutate(deleteTarget.id)
          }
        }}
        title={t('locations.deleteConfirm.title')}
        message={t('locations.deleteConfirm.message', { name: deleteTarget?.name ?? '' })}
        confirmText={t('actions.delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
