import { useTranslation } from 'react-i18next'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Pencil, Trash2, Building2, User } from 'lucide-react'
import { fetchContact, deleteContact, contactKeys } from '../api/contactApi'

export function ContactDetailPage() {
  const { t } = useTranslation(['crm', 'common'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: contact, isLoading } = useQuery({
    queryKey: contactKeys.detail(id!),
    queryFn: () => fetchContact(id!),
    enabled: Boolean(id),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteContact(id!),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: contactKeys.all })
      navigate('/crm/contacts')
    },
  })

  if (isLoading) {
    return <div className="flex items-center justify-center py-12 text-gray-500">{t('common:common.loading')}</div>
  }

  if (!contact) {
    return <div className="flex items-center justify-center py-12 text-gray-500">{t('crm:contacts.noContacts')}</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="flex h-12 w-12 items-center justify-center rounded-full bg-blue-100">
            <User className="h-6 w-6 text-blue-600" />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{contact.full_name}</h1>
            <p className="text-sm text-gray-500">{contact.phone ?? contact.email ?? ''}</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Link
            to={`/crm/contacts/${id}/edit`}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <Pencil className="h-4 w-4" />
            {t('common:actions.edit')}
          </Link>
          <button
            type="button"
            onClick={() => {
              if (window.confirm(t('crm:contacts.deleteConfirm'))) {
                deleteMutation.mutate()
              }
            }}
            className="inline-flex items-center gap-2 rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
          >
            <Trash2 className="h-4 w-4" />
            {t('common:actions.delete')}
          </button>
        </div>
      </div>

      {/* Personal Information */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-gray-900">{t('crm:contacts.personalInfo')}</h2>
        <dl className="grid grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.firstName')}</dt>
            <dd className="text-sm text-gray-900">{contact.first_name}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.lastName')}</dt>
            <dd className="text-sm text-gray-900">{contact.last_name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.phone')}</dt>
            <dd className="text-sm text-gray-900">{contact.phone ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.mobile')}</dt>
            <dd className="text-sm text-gray-900">{contact.mobile ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.email')}</dt>
            <dd className="text-sm text-gray-900">{contact.email ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.dateOfBirth')}</dt>
            <dd className="text-sm text-gray-900">{contact.date_of_birth ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.gender')}</dt>
            <dd className="text-sm text-gray-900">{contact.gender ? t(`crm:contacts.genders.${contact.gender}`) : '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.nationalId')}</dt>
            <dd className="text-sm text-gray-900">{contact.national_id ?? '—'}</dd>
          </div>
        </dl>
        {contact.notes && (
          <div className="mt-4">
            <dt className="text-sm font-medium text-gray-500">{t('crm:contacts.notes')}</dt>
            <dd className="mt-1 text-sm text-gray-900">{contact.notes}</dd>
          </div>
        )}
      </div>

      {/* Company Associations */}
      {contact.parties && contact.parties.length > 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">{t('crm:contacts.companyAssociations')}</h2>
          <div className="space-y-3">
            {contact.parties.map((party) => (
              <div key={party.id} className="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div className="flex items-center gap-3">
                  <Building2 className="h-5 w-5 text-gray-400" />
                  <div>
                    <p className="text-sm font-medium text-gray-900">{party.name}</p>
                    {party.job_title && <p className="text-xs text-gray-500">{party.job_title}</p>}
                  </div>
                </div>
                {party.is_primary && (
                  <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                    {t('crm:contacts.isPrimary')}
                  </span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
