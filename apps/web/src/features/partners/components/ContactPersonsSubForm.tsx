import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, Star, StarOff, Mail, Truck } from 'lucide-react'

interface ContactPerson {
  id?: string
  first_name: string
  last_name: string
  email: string
  phone: string
  job_title: string
  department: string
  is_primary: boolean
  is_invoice_contact: boolean
  is_delivery_contact: boolean
}

interface ContactPersonsSubFormProps {
  contacts: ContactPerson[]
  onChange: (contacts: ContactPerson[]) => void
  disabled?: boolean
}

function createEmptyContact(): ContactPerson {
  return {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    job_title: '',
    department: '',
    is_primary: false,
    is_invoice_contact: false,
    is_delivery_contact: false,
  }
}

export function ContactPersonsSubForm({
  contacts,
  onChange,
  disabled = false,
}: ContactPersonsSubFormProps) {
  const { t } = useTranslation('sales')
  const [expandedIndex, setExpandedIndex] = useState<number | null>(null)

  const handleAdd = () => {
    const newContact = createEmptyContact()
    if (contacts.length === 0) {
      newContact.is_primary = true
    }
    const updated = [...contacts, newContact]
    onChange(updated)
    setExpandedIndex(updated.length - 1)
  }

  const handleRemove = (index: number) => {
    const updated = contacts.filter((_, i) => i !== index)
    if (contacts[index].is_primary && updated.length > 0) {
      updated[0] = { ...updated[0], is_primary: true }
    }
    onChange(updated)
    setExpandedIndex(null)
  }

  const handleFieldChange = (
    index: number,
    field: keyof ContactPerson,
    value: string | boolean,
  ) => {
    const updated = contacts.map((contact, i) => {
      if (i !== index) {
        if (field === 'is_primary' && value === true) {
          return { ...contact, is_primary: false }
        }
        return contact
      }
      return { ...contact, [field]: value }
    })
    onChange(updated)
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-900">
          {t('partners.contacts.title')}
        </h3>
        <button
          type="button"
          onClick={handleAdd}
          disabled={disabled}
          className="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          <Plus className="h-4 w-4" />
          {t('partners.contacts.add')}
        </button>
      </div>

      {contacts.length === 0 ? (
        <p className="py-4 text-center text-sm text-gray-500">
          {t('partners.contacts.empty')}
        </p>
      ) : (
        <div className="space-y-3">
          {contacts.map((contact, index) => (
            <div
              key={contact.id ?? `new-${String(index)}`}
              className="rounded-lg border border-gray-200 bg-gray-50 p-4"
            >
              {/* Summary row */}
              <div className="flex items-center justify-between">
                <button
                  type="button"
                  onClick={() =>
                    { setExpandedIndex(expandedIndex === index ? null : index); }
                  }
                  className="flex-1 text-start"
                >
                  <div className="flex items-center gap-2">
                    {contact.is_primary && (
                      <Star className="h-4 w-4 fill-yellow-400 text-yellow-400" />
                    )}
                    <span className="font-medium text-gray-900">
                      {contact.first_name || contact.last_name
                        ? `${contact.first_name} ${contact.last_name}`.trim()
                        : t('partners.contacts.newContact')}
                    </span>
                    {contact.job_title && (
                      <span className="text-sm text-gray-500">
                        - {contact.job_title}
                      </span>
                    )}
                  </div>
                  <div className="mt-1 flex gap-3 text-xs text-gray-500">
                    {contact.is_invoice_contact && (
                      <span className="flex items-center gap-1">
                        <Mail className="h-3 w-3" />
                        {t('partners.contacts.invoiceContact')}
                      </span>
                    )}
                    {contact.is_delivery_contact && (
                      <span className="flex items-center gap-1">
                        <Truck className="h-3 w-3" />
                        {t('partners.contacts.deliveryContact')}
                      </span>
                    )}
                  </div>
                </button>
                <button
                  type="button"
                  onClick={() => { handleRemove(index); }}
                  disabled={disabled}
                  className="rounded p-1 text-gray-400 hover:text-red-600 disabled:opacity-50"
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>

              {/* Expanded form */}
              {expandedIndex === index && (
                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">
                      {t('partners.contacts.firstName')}
                    </label>
                    <input
                      type="text"
                      value={contact.first_name}
                      onChange={(e) =>
                        { handleFieldChange(index, 'first_name', e.target.value); }
                      }
                      disabled={disabled}
                      className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">
                      {t('partners.contacts.lastName')}
                    </label>
                    <input
                      type="text"
                      value={contact.last_name}
                      onChange={(e) =>
                        { handleFieldChange(index, 'last_name', e.target.value); }
                      }
                      disabled={disabled}
                      className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">
                      {t('partners.email')}
                    </label>
                    <input
                      type="email"
                      value={contact.email}
                      onChange={(e) =>
                        { handleFieldChange(index, 'email', e.target.value); }
                      }
                      disabled={disabled}
                      className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">
                      {t('partners.phone')}
                    </label>
                    <input
                      type="tel"
                      value={contact.phone}
                      onChange={(e) =>
                        { handleFieldChange(index, 'phone', e.target.value); }
                      }
                      disabled={disabled}
                      className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">
                      {t('partners.contacts.jobTitle')}
                    </label>
                    <input
                      type="text"
                      value={contact.job_title}
                      onChange={(e) =>
                        { handleFieldChange(index, 'job_title', e.target.value); }
                      }
                      disabled={disabled}
                      className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">
                      {t('partners.contacts.department')}
                    </label>
                    <input
                      type="text"
                      value={contact.department}
                      onChange={(e) =>
                        { handleFieldChange(index, 'department', e.target.value); }
                      }
                      disabled={disabled}
                      className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                    />
                  </div>

                  {/* Role toggles */}
                  <div className="flex flex-wrap gap-4 sm:col-span-2">
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={contact.is_primary}
                        onChange={(e) =>
                          { handleFieldChange(
                            index,
                            'is_primary',
                            e.target.checked,
                          ); }
                        }
                        disabled={disabled}
                        className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span className="flex items-center gap-1">
                        {contact.is_primary ? (
                          <Star className="h-3.5 w-3.5 fill-yellow-400 text-yellow-400" />
                        ) : (
                          <StarOff className="h-3.5 w-3.5 text-gray-400" />
                        )}
                        {t('partners.contacts.primaryContact')}
                      </span>
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={contact.is_invoice_contact}
                        onChange={(e) =>
                          { handleFieldChange(
                            index,
                            'is_invoice_contact',
                            e.target.checked,
                          ); }
                        }
                        disabled={disabled}
                        className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span className="flex items-center gap-1">
                        <Mail className="h-3.5 w-3.5 text-gray-400" />
                        {t('partners.contacts.invoiceContact')}
                      </span>
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={contact.is_delivery_contact}
                        onChange={(e) =>
                          { handleFieldChange(
                            index,
                            'is_delivery_contact',
                            e.target.checked,
                          ); }
                        }
                        disabled={disabled}
                        className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span className="flex items-center gap-1">
                        <Truck className="h-3.5 w-3.5 text-gray-400" />
                        {t('partners.contacts.deliveryContact')}
                      </span>
                    </label>
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
