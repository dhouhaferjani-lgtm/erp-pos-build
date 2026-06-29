import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Users, UserPlus, ChevronLeft, Pencil, Check } from 'lucide-react';
import { useAuthStore } from '@/stores/authStore';
import { getDatabase } from '@/lib/db';
import {
  listCustomers,
  updateCustomerSkinProfile,
} from '@/lib/db/repositories/customerRepository';
import { enqueuePendingCustomer } from '@/lib/db/repositories/pendingCustomerRepository';
import { apiPatch } from '@/lib/api';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { cn } from '@/lib/utils';
import { tokens } from '@/lib/designTokens';
import { deterministicPendingCustomerUuid } from '@/components/customers/customerAttachUtils';

// ---------------------------------------------------------------------------
// Skin type constants (matches App\Shared\Domain\Enums\SkinType values and
// smart-prompts i18n namespace labels added in Tasks 1–3).
// ---------------------------------------------------------------------------
const SKIN_TYPES = ['normal', 'oily', 'dry', 'combination', 'sensitive'] as const;
type SkinTypeValue = (typeof SKIN_TYPES)[number];

function isSkinTypeValue(value: string): value is SkinTypeValue {
  return (SKIN_TYPES as readonly string[]).includes(value);
}

// ---------------------------------------------------------------------------
// Customer list item
// ---------------------------------------------------------------------------

interface CustomerRowProps {
  customer: CustomerMirrorRow;
  isSelected: boolean;
  onClick: () => void;
}

function CustomerRow({ customer, isSelected, onClick }: CustomerRowProps) {
  const { t } = useTranslation('pos');
  const contact = [customer.phone, customer.email].filter(Boolean).join(' · ');

  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex w-full flex-col gap-0.5 rounded-xl px-4 py-3 text-left transition-colors',
        isSelected
          ? 'bg-action text-ink-inverse'
          : 'bg-surface-raised hover:bg-surface-sunken',
      )}
    >
      <span
        className={cn(
          'text-sm font-semibold leading-tight',
          isSelected ? 'text-ink-inverse' : 'text-ink',
        )}
      >
        {customer.name}
      </span>
      {contact && (
        <span
          className={cn(
            'truncate text-xs',
            isSelected ? 'text-ink-inverse/70' : 'text-ink-faint',
          )}
        >
          {contact}
        </span>
      )}
      {customer.skin_type && (
        <span
          className={cn(
            'mt-0.5 text-xs',
            isSelected ? 'text-ink-inverse/80' : 'text-ink-muted',
          )}
        >
          {t(`skin_type.${customer.skin_type}`, {
            ns: 'smart-prompts',
            defaultValue: customer.skin_type,
          })}
        </span>
      )}
    </button>
  );
}

// ---------------------------------------------------------------------------
// Skin-profile edit form
// ---------------------------------------------------------------------------

interface SkinProfileFormProps {
  customer: CustomerMirrorRow;
  tenantId: string;
  companyId: string;
  onSaved: (updated: CustomerMirrorRow) => void;
  onCancel: () => void;
}

function SkinProfileForm({
  customer,
  tenantId,
  companyId,
  onSaved,
  onCancel,
}: SkinProfileFormProps) {
  const { t } = useTranslation('pos');
  const [skinType, setSkinType] = useState<string>(customer.skin_type ?? '');
  const [skinAdviceNote, setSkinAdviceNote] = useState<string>(
    customer.skin_advice_note ?? '',
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSave = async () => {
    setSaving(true);
    setError(null);

    const resolvedSkinType = skinType !== '' ? skinType : null;
    const resolvedNote = skinAdviceNote.trim() !== '' ? skinAdviceNote.trim() : null;

    try {
      const db = await getDatabase(companyId);
      await updateCustomerSkinProfile(
        db,
        tenantId,
        companyId,
        customer.id,
        resolvedSkinType,
        resolvedNote,
      );
      await apiPatch(`/partners/${customer.id}`, {
        skin_type: resolvedSkinType,
        skin_advice_note: resolvedNote,
      });
      onSaved({
        ...customer,
        skin_type: resolvedSkinType,
        skin_advice_note: resolvedNote,
      });
    } catch {
      setError(t('customers.updateSkinError'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <label
          htmlFor="skin-type-select"
          className="text-sm font-medium text-ink"
        >
          {t('customers.skinType')}
        </label>
        <select
          id="skin-type-select"
          aria-label={t('customers.skinType')}
          value={skinType}
          onChange={(e) => setSkinType(e.target.value)}
          className="rounded-xl border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
        >
          <option value="">{t('customers.skinTypeNone')}</option>
          {SKIN_TYPES.map((st) => (
            <option key={st} value={st}>
              {t(`skin_type.${st}`, { ns: 'smart-prompts', defaultValue: st })}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label
          htmlFor="skin-advice-note"
          className="text-sm font-medium text-ink"
        >
          {t('customers.skinAdviceNote')}
        </label>
        <textarea
          id="skin-advice-note"
          aria-label={t('customers.skinAdviceNote')}
          value={skinAdviceNote}
          onChange={(e) => setSkinAdviceNote(e.target.value)}
          placeholder={t('customers.skinAdviceNotePlaceholder')}
          rows={3}
          className="rounded-xl border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
        />
      </div>

      {error && (
        <p className="text-xs font-medium text-danger-strong">{error}</p>
      )}

      <div className="flex gap-2">
        <button
          type="button"
          onClick={onCancel}
          className={cn(tokens.button.secondary, 'flex-1 py-2 text-sm')}
        >
          {t('customers.close')}
        </button>
        <button
          type="button"
          aria-label={t('customers.saveSkinProfile')}
          onClick={() => void handleSave()}
          disabled={saving}
          className={cn(tokens.button.primary, 'flex-1 py-2 text-sm')}
        >
          <Check className="h-4 w-4" aria-hidden="true" />
          {saving ? t('customers.savingSkinProfile') : t('customers.saveSkinProfile')}
        </button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Customer detail panel
// ---------------------------------------------------------------------------

type DetailMode = 'view' | 'editSkin';

interface CustomerDetailProps {
  customer: CustomerMirrorRow;
  tenantId: string;
  companyId: string;
  onBack: () => void;
  onUpdated: (updated: CustomerMirrorRow) => void;
}

function CustomerDetail({
  customer,
  tenantId,
  companyId,
  onBack,
  onUpdated,
}: CustomerDetailProps) {
  const { t } = useTranslation('pos');
  const [mode, setMode] = useState<DetailMode>('view');
  const [current, setCurrent] = useState<CustomerMirrorRow>(customer);

  // Sync when parent changes the selected customer.
  useEffect(() => {
    setCurrent(customer);
    setMode('view');
  }, [customer.id]);

  const handleSkinSaved = (updated: CustomerMirrorRow) => {
    setCurrent(updated);
    onUpdated(updated);
    setMode('view');
  };

  const skinTypeLabel =
    current.skin_type && isSkinTypeValue(current.skin_type)
      ? t(`skin_type.${current.skin_type}`, {
          ns: 'smart-prompts',
          defaultValue: current.skin_type,
        })
      : null;

  return (
    <div className="flex h-full flex-col overflow-hidden rounded-2xl bg-surface-raised shadow-sm">
      {/* Header */}
      <div className="flex shrink-0 items-center gap-3 border-b border-border-subtle px-5 py-4">
        <button
          type="button"
          aria-label={t('customers.backToList')}
          onClick={onBack}
          className={cn(tokens.button.ghost, 'p-2 text-xs md:hidden')}
        >
          <ChevronLeft className="h-4 w-4" aria-hidden="true" />
        </button>
        <h2 className="text-lg font-bold text-ink">{current.name}</h2>
      </div>

      {/* Body */}
      <div className="min-h-0 flex-1 overflow-y-auto p-5">
        {mode === 'view' ? (
          <div className="flex flex-col gap-6">
            {/* Contact info */}
            <section aria-labelledby="customer-contact-heading">
              <dl className="flex flex-col gap-2">
                {current.phone && (
                  <div>
                    <dt className="text-xs text-ink-faint">{t('customers.phone')}</dt>
                    <dd className="text-sm text-ink">{current.phone}</dd>
                  </div>
                )}
                {current.email && (
                  <div>
                    <dt className="text-xs text-ink-faint">{t('customers.email')}</dt>
                    <dd className="text-sm text-ink">{current.email}</dd>
                  </div>
                )}
              </dl>
            </section>

            {/* Skin profile */}
            <section aria-labelledby="skin-profile-heading">
              <div className="mb-3 flex items-center justify-between gap-2">
                <h3
                  id="skin-profile-heading"
                  className="text-sm font-semibold text-ink"
                >
                  {t('customers.skinProfileTitle')}
                </h3>
                <button
                  type="button"
                  aria-label={t('customers.editSkinProfile')}
                  onClick={() => setMode('editSkin')}
                  className={cn(tokens.button.ghost, 'gap-1.5 px-2 py-1 text-xs')}
                >
                  <Pencil className="h-3.5 w-3.5" aria-hidden="true" />
                  {t('customers.editSkinProfile')}
                </button>
              </div>

              <dl className="flex flex-col gap-2 rounded-xl bg-surface-sunken p-4">
                <div>
                  <dt className="text-xs text-ink-faint">{t('customers.skinType')}</dt>
                  <dd className="text-sm font-medium text-ink">
                    {skinTypeLabel ?? (
                      <span className="text-ink-faint">
                        {t('customers.skinTypeNone')}
                      </span>
                    )}
                  </dd>
                </div>
                {current.skin_advice_note && (
                  <div>
                    <dt className="text-xs text-ink-faint">
                      {t('customers.skinAdviceNote')}
                    </dt>
                    <dd className="text-sm text-ink">{current.skin_advice_note}</dd>
                  </div>
                )}
              </dl>
            </section>
          </div>
        ) : (
          <div>
            <h3 className="mb-4 text-sm font-semibold text-ink">
              {t('customers.skinProfileTitle')}
            </h3>
            <SkinProfileForm
              customer={current}
              tenantId={tenantId}
              companyId={companyId}
              onSaved={handleSkinSaved}
              onCancel={() => setMode('view')}
            />
          </div>
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Add-customer form (existing pending customer write path)
// ---------------------------------------------------------------------------

interface AddCustomerFormProps {
  tenantId: string;
  companyId: string;
  onCreated: () => void;
  onCancel: () => void;
}

function AddCustomerForm({
  tenantId,
  companyId,
  onCreated,
  onCancel,
}: AddCustomerFormProps) {
  const { t } = useTranslation('pos');
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleCreate = async () => {
    const trimmedName = name.trim();
    const trimmedPhone = phone.trim();
    const trimmedEmail = email.trim();

    if (!trimmedName) {
      setError(t('customers.nameRequired'));
      return;
    }
    if (!trimmedPhone && !trimmedEmail) {
      setError(t('customers.contactRequired'));
      return;
    }

    setCreating(true);
    setError(null);

    try {
      const clientCustomerUuid = deterministicPendingCustomerUuid({
        tenantId,
        companyId,
        name: trimmedName,
        phone: trimmedPhone || null,
        email: trimmedEmail || null,
      });
      const db = await getDatabase(companyId);
      await enqueuePendingCustomer(db, {
        client_customer_uuid: clientCustomerUuid,
        tenant_id: tenantId,
        company_id: companyId,
        name: trimmedName,
        phone: trimmedPhone || null,
        email: trimmedEmail || null,
      });
      onCreated();
    } catch {
      setError(t('customers.createFailed'));
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="new-customer-name" className="text-sm font-medium text-ink">
          {t('customers.name')}
        </label>
        <input
          id="new-customer-name"
          type="text"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t('customers.name')}
          className="rounded-xl border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="new-customer-phone" className="text-sm font-medium text-ink">
          {t('customers.phone')}
        </label>
        <input
          id="new-customer-phone"
          type="tel"
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
          placeholder={t('customers.phone')}
          className="rounded-xl border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="new-customer-email" className="text-sm font-medium text-ink">
          {t('customers.email')}
        </label>
        <input
          id="new-customer-email"
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder={t('customers.email')}
          className="rounded-xl border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
        />
      </div>

      {error && (
        <p className="text-xs font-medium text-danger-strong">{error}</p>
      )}

      <div className="flex gap-2">
        <button
          type="button"
          onClick={onCancel}
          className={cn(tokens.button.secondary, 'flex-1 py-2 text-sm')}
        >
          {t('customers.close')}
        </button>
        <button
          type="button"
          onClick={() => void handleCreate()}
          disabled={creating}
          className={cn(tokens.button.primary, 'flex-1 py-2 text-sm')}
        >
          <UserPlus className="h-4 w-4" aria-hidden="true" />
          {creating ? t('customers.creating') : t('customers.createCustomer')}
        </button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

type PageMode = 'list' | 'add';

/**
 * CustomersPage — lists synced customers from the local SQLite mirror and
 * provides an add-customer form (existing pending-customer write path) plus a
 * detail/skin-profile edit view.
 *
 * Layout: split-panel (list | detail) on wider screens, stack (list → detail)
 * on narrower screens. Both themes and both density settings are supported via
 * design-token classes only.
 */
export function CustomersPage() {
  const { t } = useTranslation('pos');
  const tenantId = useAuthStore((s) => s.user?.tenantId ?? null);
  const companyId = useAuthStore((s) => s.companyId);

  const [customers, setCustomers] = useState<CustomerMirrorRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [pageMode, setPageMode] = useState<PageMode>('list');

  const loadCustomers = useCallback(async () => {
    if (!tenantId || !companyId) return;
    setLoading(true);
    setLoadError(null);
    try {
      const db = await getDatabase(companyId);
      const rows = await listCustomers(db, tenantId, companyId, 200);
      setCustomers(rows);
    } catch {
      // Store the i18n key; translate at render time so `t` isn't in deps
      // (adding `t` to deps would cause infinite re-renders in tests because
      // react-i18next does not guarantee a stable `t` reference on every call).
      setLoadError('customers.loadError');
    } finally {
      setLoading(false);
    }
  }, [tenantId, companyId]);

  useEffect(() => {
    void loadCustomers();
  }, [loadCustomers]);

  const filtered = search.trim()
    ? customers.filter(
        (c) =>
          c.name.toLowerCase().includes(search.toLowerCase()) ||
          (c.phone ?? '').includes(search) ||
          (c.email ?? '').toLowerCase().includes(search.toLowerCase()),
      )
    : customers;

  const selectedCustomer = customers.find((c) => c.id === selectedId) ?? null;

  const handleUpdated = (updated: CustomerMirrorRow) => {
    setCustomers((prev) => prev.map((c) => (c.id === updated.id ? updated : c)));
  };

  // Empty / loading states for the list column
  const listBody = () => {
    if (loading) {
      return (
        <div className="flex h-40 items-center justify-center">
          <span className="text-sm text-ink-faint">{t('common.loading', { defaultValue: '…' })}</span>
        </div>
      );
    }
    if (loadError) {
      return (
        <div className="flex h-40 items-center justify-center">
          <span className="text-sm text-danger-strong">{t(loadError)}</span>
        </div>
      );
    }
    if (filtered.length === 0) {
      return (
        <div className="flex h-40 flex-col items-center justify-center gap-2 text-center">
          <Users className="h-8 w-8 text-ink-faint" aria-hidden="true" />
          <span className="text-sm text-ink-faint">{t('customers.noCustomers')}</span>
        </div>
      );
    }
    return (
      <ul className="flex flex-col gap-1">
        {filtered.map((c) => (
          <li key={c.id}>
            <CustomerRow
              customer={c}
              isSelected={c.id === selectedId}
              onClick={() => {
                setSelectedId(c.id);
                setPageMode('list');
              }}
            />
          </li>
        ))}
      </ul>
    );
  };

  return (
    <div className="flex h-full flex-col bg-surface-canvas">
      {/* Page header */}
      <div className="flex shrink-0 items-center justify-between border-b border-border-subtle bg-surface-raised px-5 py-4">
        <h1 className="font-display text-xl font-bold text-ink-strong">
          {t('customers.pageTitle')}
        </h1>
        <button
          type="button"
          aria-label={t('customers.addCustomer')}
          onClick={() => {
            setPageMode('add');
            setSelectedId(null);
          }}
          className={cn(tokens.button.secondary, 'gap-2 px-3 py-2 text-sm')}
        >
          <UserPlus className="h-4 w-4" aria-hidden="true" />
          {t('customers.addCustomer')}
        </button>
      </div>

      {/* Body — split panel */}
      <div className="flex min-h-0 flex-1 overflow-hidden">
        {/* LEFT — customer list (always visible on md+, hidden on mobile when detail is open) */}
        <div
          className={cn(
            'flex flex-col border-r border-border-subtle bg-surface-canvas',
            // Mobile: hide list when a customer is selected or add form is open
            selectedId || pageMode === 'add'
              ? 'hidden md:flex md:w-80'
              : 'flex w-full md:w-80',
          )}
        >
          {/* Search */}
          <div className="shrink-0 border-b border-border-subtle p-3">
            <input
              type="search"
              aria-label={t('customers.search')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('customers.search')}
              className="w-full rounded-xl border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
            />
          </div>

          {/* List */}
          <div className="min-h-0 flex-1 overflow-y-auto p-3">
            {listBody()}
          </div>
        </div>

        {/* RIGHT — detail / add form */}
        <div
          className={cn(
            'flex min-h-0 flex-1 flex-col',
            // Mobile: hide right panel when no customer selected and not in add mode
            !selectedId && pageMode !== 'add' ? 'hidden md:flex' : 'flex',
          )}
        >
          {pageMode === 'add' ? (
            <div className="flex h-full flex-col">
              {/* Add form header */}
              <div className="flex shrink-0 items-center gap-3 border-b border-border-subtle px-5 py-4">
                <button
                  type="button"
                  aria-label={t('customers.backToList')}
                  onClick={() => setPageMode('list')}
                  className={cn(tokens.button.ghost, 'p-2 md:hidden')}
                >
                  <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                </button>
                <h2 className="text-lg font-bold text-ink">
                  {t('customers.addCustomer')}
                </h2>
              </div>
              <div className="min-h-0 flex-1 overflow-y-auto p-5">
                {tenantId && companyId ? (
                  <AddCustomerForm
                    tenantId={tenantId}
                    companyId={companyId}
                    onCreated={() => {
                      setPageMode('list');
                      void loadCustomers();
                    }}
                    onCancel={() => setPageMode('list')}
                  />
                ) : (
                  <p className="text-sm text-ink-faint">{t('customers.loadError')}</p>
                )}
              </div>
            </div>
          ) : selectedCustomer && tenantId && companyId ? (
            <div className="p-4 md:p-5 h-full">
              <CustomerDetail
                customer={selectedCustomer}
                tenantId={tenantId}
                companyId={companyId}
                onBack={() => setSelectedId(null)}
                onUpdated={handleUpdated}
              />
            </div>
          ) : (
            // Empty state — no customer selected, not in add mode
            <div className="hidden md:flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
              <span className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-sunken text-ink-faint">
                <Users className="h-8 w-8" aria-hidden="true" />
              </span>
              <p className="max-w-sm text-sm text-ink-faint">
                {t('customers.placeholder')}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
