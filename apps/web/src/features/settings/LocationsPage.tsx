import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, MapPin, Edit, Trash2, Star, Building2, Warehouse, Briefcase, Truck, Store } from 'lucide-react'
import { fetchLocations, createLocation, updateLocation, deleteLocation, setDefaultLocation } from '../location/api'
import type { LocationApiResponse, CreateLocationInput, UpdateLocationInput } from '../location/api'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { tokens } from '../../lib/designTokens'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

type LocationType = 'shop' | 'warehouse' | 'office' | 'mobile'

const typeIcons: Record<LocationType, typeof Building2> = {
  shop: Store,
  warehouse: Warehouse,
  office: Briefcase,
  mobile: Truck,
}

const typeColors: Record<LocationType, string> = {
  shop: 'bg-blue-100 text-blue-800',
  warehouse: 'bg-green-100 text-green-800',
  office: 'bg-purple-100 text-purple-800',
  mobile: 'bg-orange-100 text-orange-800',
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
  const { t } = useTranslation('common')
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
        <p className="text-gray-500">{t('status.loading')}</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('locations.title')}</h1>
          <p className="mt-1 text-sm text-gray-500">{t('locations.subtitle')}</p>
        </div>
        <button
          onClick={openCreateModal}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          <Plus className="h-4 w-4" />
          {t('locations.addLocation')}
        </button>
      </div>

      {/* Locations List */}
      {(!locations || locations.length === 0) ? (
        <div className="rounded-lg border border-gray-200 bg-white p-12 text-center">
          <MapPin className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-4 text-lg font-medium text-gray-900">{t('locations.empty.title')}</h3>
          <p className="mt-2 text-sm text-gray-500">{t('locations.empty.description')}</p>
          <button
            onClick={openCreateModal}
            className="mt-4 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
          >
            <Plus className="h-4 w-4" />
            {t('locations.addLocation')}
          </button>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {locations.map((location) => {
            const TypeIcon = typeIcons[location.type as LocationType] ?? Building2

            return (
              <div
                key={location.id}
                className="rounded-lg border border-gray-200 bg-white p-6 hover:border-gray-300 transition-colors"
              >
                <div className="flex items-start justify-between">
                  <div className="flex items-start gap-3">
                    <div className={`rounded-lg p-2 ${typeColors[location.type as LocationType]}`}>
                      <TypeIcon className="h-5 w-5" />
                    </div>
                    <div>
                      <h3 className="font-medium text-gray-900">{location.name}</h3>
                      <p className="text-sm text-gray-500">{location.code}</p>
                    </div>
                  </div>
                  {location.is_default && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">
                      <Star className="h-3 w-3" />
                      {t('locations.default')}
                    </span>
                  )}
                </div>

                <div className="mt-4 space-y-2 text-sm">
                  <div className="flex items-center gap-2 text-gray-600">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${typeColors[location.type as LocationType]}`}>
                      {t(`locations.types.${location.type}`)}
                    </span>
                    {location.pos_enabled && (
                      <span className="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                        {t('locations.posEnabled')}
                      </span>
                    )}
                  </div>
                  {(location.address_city || location.address_country) && (
                    <p className="text-gray-500">
                      {[location.address_city, location.address_country].filter(Boolean).join(', ')}
                    </p>
                  )}
                  {location.phone && (
                    <p className="text-gray-500">{location.phone}</p>
                  )}
                </div>

                {/* Actions */}
                <div className="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4">
                  <button
                    onClick={() => { openEditModal(location) }}
                    className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100"
                  >
                    <Edit className="h-3.5 w-3.5" />
                    {t('actions.edit')}
                  </button>
                  {!location.is_default && (
                    <>
                      <button
                        onClick={() => { setDefaultMutation.mutate(location.id) }}
                        disabled={setDefaultMutation.isPending}
                        className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-yellow-700 hover:bg-yellow-50"
                      >
                        <Star className="h-3.5 w-3.5" />
                        {t('locations.setDefault')}
                      </button>
                      <button
                        onClick={() => { setDeleteTarget(location) }}
                        className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        {t('actions.delete')}
                      </button>
                    </>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Create/Edit Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
            <h2 className="text-lg font-semibold text-gray-900">
              {editingLocation ? t('locations.editLocation') : t('locations.addLocation')}
            </h2>

            <form onSubmit={handleSubmit} className="mt-4 space-y-4">
              {/* Name */}
              <div>
                <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                  {t('locations.form.name')} *
                </label>
                <input
                  type="text"
                  id="name"
                  required
                  value={formData.name}
                  onChange={(e) => { setFormData({ ...formData, name: e.target.value }) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  placeholder={t('locations.form.namePlaceholder')}
                />
              </div>

              {/* Type & Code */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="type" className="block text-sm font-medium text-gray-700">
                    {t('locations.form.type')} *
                  </label>
                  <select
                    id="type"
                    required
                    value={formData.type}
                    onChange={(e) => { setFormData({ ...formData, type: e.target.value as LocationType }) }}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  >
                    <option value="shop">{t('locations.types.shop')}</option>
                    <option value="warehouse">{t('locations.types.warehouse')}</option>
                    <option value="office">{t('locations.types.office')}</option>
                    <option value="mobile">{t('locations.types.mobile')}</option>
                  </select>
                </div>
                <div>
                  <label htmlFor="code" className="block text-sm font-medium text-gray-700">
                    {t('locations.form.code')}
                  </label>
                  <input
                    type="text"
                    id="code"
                    value={formData.code}
                    onChange={(e) => { setFormData({ ...formData, code: e.target.value }) }}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    placeholder={t('locations.form.codePlaceholder')}
                  />
                </div>
              </div>

              {/* Contact */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="phone" className="block text-sm font-medium text-gray-700">
                    {t('locations.form.phone')}
                  </label>
                  <input
                    type="tel"
                    id="phone"
                    value={formData.phone}
                    onChange={(e) => { setFormData({ ...formData, phone: e.target.value }) }}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                    {t('locations.form.email')}
                  </label>
                  <input
                    type="email"
                    id="email"
                    value={formData.email}
                    onChange={(e) => { setFormData({ ...formData, email: e.target.value }) }}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

              {/* Address */}
              <div>
                <label htmlFor="address" className="block text-sm font-medium text-gray-700">
                  {t('locations.form.address')}
                </label>
                <input
                  type="text"
                  id="address"
                  value={formData.addressStreet}
                  onChange={(e) => { setFormData({ ...formData, addressStreet: e.target.value }) }}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>

              {/* City, Postal, Country */}
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label htmlFor="city" className="block text-sm font-medium text-gray-700">
                    {t('locations.form.city')}
                  </label>
                  <input
                    type="text"
                    id="city"
                    value={formData.addressCity}
                    onChange={(e) => { setFormData({ ...formData, addressCity: e.target.value }) }}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label htmlFor="postal" className="block text-sm font-medium text-gray-700">
                    {t('locations.form.postalCode')}
                  </label>
                  <input
                    type="text"
                    id="postal"
                    value={formData.addressPostalCode}
                    onChange={(e) => { setFormData({ ...formData, addressPostalCode: e.target.value }) }}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label htmlFor="country" className="block text-sm font-medium text-gray-700">
                    {t('locations.form.country')}
                  </label>
                  <input
                    type="text"
                    id="country"
                    value={formData.addressCountry}
                    onChange={(e) => { setFormData({ ...formData, addressCountry: e.target.value }) }}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

              {/* Tax Identity */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="taxId" className={tokens.label.base}>
                    {t('locations.form.taxId')}
                    {isTaxIdRequiredHint && ' *'}
                  </label>
                  <input
                    type="text"
                    id="taxId"
                    value={formData.taxId}
                    onChange={(e) => { setFormData({ ...formData, taxId: e.target.value }) }}
                    aria-required={isTaxIdRequiredHint}
                    aria-describedby={isTaxIdRequiredHint ? 'taxId-required-hint' : undefined}
                    className={tokens.input.base}
                  />
                  {isTaxIdRequiredHint && (
                    <p id="taxId-required-hint" className={tokens.helperText.base}>
                      {t('locations.form.taxIdRequiredHint')}
                    </p>
                  )}
                </div>
                <div>
                  <label htmlFor="vatNumber" className={tokens.label.base}>
                    {t('locations.form.vatNumber')}
                  </label>
                  <input
                    type="text"
                    id="vatNumber"
                    value={formData.vatNumber}
                    onChange={(e) => { setFormData({ ...formData, vatNumber: e.target.value }) }}
                    className={tokens.input.base}
                  />
                </div>
              </div>

              {/* POS Enabled */}
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="posEnabled"
                  checked={formData.posEnabled}
                  onChange={(e) => { setFormData({ ...formData, posEnabled: e.target.checked }) }}
                  className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <label htmlFor="posEnabled" className="text-sm text-gray-700">
                  {t('locations.form.posEnabled')}
                </label>
              </div>

              {/* Actions */}
              <div className="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button
                  type="button"
                  onClick={closeModal}
                  className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  {t('cancel')}
                </button>
                <button
                  type="submit"
                  disabled={isMutating}
                  className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  {isMutating ? t('saving') : t('save')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

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
