import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { fetchContact, createContact, updateContact, contactKeys } from '../api/contactApi'
import type { CreateContactData } from '../api/contactApi'

export function ContactFormPage() {
  const { t } = useTranslation(['crm', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditing = Boolean(id)
  const queryClient = useQueryClient()

  const { data: existingContact } = useQuery({
    queryKey: contactKeys.detail(id!),
    queryFn: () => fetchContact(id!),
    enabled: isEditing,
  })

  const [formData, setFormData] = useState<CreateContactData>({
    first_name: '',
    last_name: '',
    phone: '',
    email: '',
    mobile: '',
    date_of_birth: '',
    gender: '',
    national_id: '',
    notes: '',
  })

  // Sync form when editing data loads
  const [initialized, setInitialized] = useState(false)
  if (isEditing && existingContact && !initialized) {
    setFormData({
      first_name: existingContact.first_name,
      last_name: existingContact.last_name ?? '',
      phone: existingContact.phone ?? '',
      email: existingContact.email ?? '',
      mobile: existingContact.mobile ?? '',
      date_of_birth: existingContact.date_of_birth ?? '',
      gender: existingContact.gender ?? '',
      national_id: existingContact.national_id ?? '',
      notes: existingContact.notes ?? '',
    })
    setInitialized(true)
  }

  const [error, setError] = useState<string | null>(null)

  const createMutation = useMutation({
    mutationFn: (data: CreateContactData) => createContact(data),
    onSuccess: (contact) => {
      void queryClient.invalidateQueries({ queryKey: contactKeys.all })
      navigate(`/crm/contacts/${contact.id}`)
    },
    onError: (err: Error) => { setError(err.message) },
  })

  const updateMutation = useMutation({
    mutationFn: (data: Partial<CreateContactData>) => updateContact(id!, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: contactKeys.all })
      navigate(`/crm/contacts/${id}`)
    },
    onError: (err: Error) => { setError(err.message) },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    const data: CreateContactData = { first_name: formData.first_name }
    if (formData.last_name) data.last_name = formData.last_name
    if (formData.phone) data.phone = formData.phone
    if (formData.email) data.email = formData.email
    if (formData.mobile) data.mobile = formData.mobile
    if (formData.date_of_birth) data.date_of_birth = formData.date_of_birth
    if (formData.gender) data.gender = formData.gender
    if (formData.national_id) data.national_id = formData.national_id
    if (formData.notes) data.notes = formData.notes
    if (isEditing) {
      updateMutation.mutate(data)
    } else {
      createMutation.mutate(data)
    }
  }

  const isSubmitting = createMutation.isPending || updateMutation.isPending

  const updateField = (field: keyof CreateContactData, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <h1 className="text-2xl font-bold text-gray-900">
        {isEditing ? t('crm:contacts.editContact') : t('crm:contacts.newContact')}
      </h1>

      <form onSubmit={handleSubmit} className="space-y-6 rounded-lg border border-gray-200 bg-white p-6">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label htmlFor="first_name" className="block text-sm font-medium text-gray-700 mb-1">
              {t('crm:contacts.firstName')} *
            </label>
            <input
              id="first_name"
              type="text"
              required
              value={formData.first_name}
              onChange={(e) => { updateField('first_name', e.target.value) }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <div>
            <label htmlFor="last_name" className="block text-sm font-medium text-gray-700 mb-1">
              {t('crm:contacts.lastName')}
            </label>
            <input
              id="last_name"
              type="text"
              value={formData.last_name}
              onChange={(e) => { updateField('last_name', e.target.value) }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label htmlFor="phone" className="block text-sm font-medium text-gray-700 mb-1">
              {t('crm:contacts.phone')}
            </label>
            <input
              id="phone"
              type="tel"
              value={formData.phone}
              onChange={(e) => { updateField('phone', e.target.value) }}
              placeholder={t('crm:contacts.phone')}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <div>
            <label htmlFor="mobile" className="block text-sm font-medium text-gray-700 mb-1">
              {t('crm:contacts.mobile')}
            </label>
            <input
              id="mobile"
              type="tel"
              value={formData.mobile}
              onChange={(e) => { updateField('mobile', e.target.value) }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
        </div>

        <div>
          <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
            {t('crm:contacts.email')}
          </label>
          <input
            id="email"
            type="email"
            value={formData.email}
            onChange={(e) => { updateField('email', e.target.value) }}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label htmlFor="date_of_birth" className="block text-sm font-medium text-gray-700 mb-1">
              {t('crm:contacts.dateOfBirth')}
            </label>
            <input
              id="date_of_birth"
              type="date"
              value={formData.date_of_birth}
              onChange={(e) => { updateField('date_of_birth', e.target.value) }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <div>
            <label htmlFor="gender" className="block text-sm font-medium text-gray-700 mb-1">
              {t('crm:contacts.gender')}
            </label>
            <select
              id="gender"
              value={formData.gender}
              onChange={(e) => { updateField('gender', e.target.value) }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="">{t('common:select')}</option>
              <option value="male">{t('crm:contacts.genders.male')}</option>
              <option value="female">{t('crm:contacts.genders.female')}</option>
              <option value="other">{t('crm:contacts.genders.other')}</option>
            </select>
          </div>
        </div>

        <div>
          <label htmlFor="national_id" className="block text-sm font-medium text-gray-700 mb-1">
            {t('crm:contacts.nationalId')}
          </label>
          <input
            id="national_id"
            type="text"
            value={formData.national_id}
            onChange={(e) => { updateField('national_id', e.target.value) }}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        <div>
          <label htmlFor="notes" className="block text-sm font-medium text-gray-700 mb-1">
            {t('crm:contacts.notes')}
          </label>
          <textarea
            id="notes"
            rows={3}
            value={formData.notes}
            onChange={(e) => { updateField('notes', e.target.value) }}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex gap-3 justify-end">
          <button
            type="button"
            onClick={() => { navigate(-1) }}
            className="px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            {t('common:actions.cancel')}
          </button>
          <button
            type="submit"
            disabled={isSubmitting || !formData.first_name.trim()}
            className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {isSubmitting ? t('common:status.saving') : (isEditing ? t('common:actions.update') : t('common:actions.create'))}
          </button>
        </div>
      </form>
    </div>
  )
}
