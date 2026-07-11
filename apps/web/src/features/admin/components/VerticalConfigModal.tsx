import { Fragment, useState } from 'react'
import { Dialog, DialogPanel, DialogTitle, Transition, TransitionChild } from '@headlessui/react'
import { X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUpdateVerticalConfig } from '../hooks/useVerticals'
import { getVerticalConfigErrorMessage } from '../api'
import type { AdminVerticalConfig } from '../types'
import { Button } from '@/components/atoms/Button/Button'
import { Checkbox } from '@/components/atoms/Checkbox/Checkbox'
import { tokens, textColors, borderColors } from '@/lib/designTokens'

interface VerticalConfigModalProps {
  vertical: AdminVerticalConfig
  availableModules: string[]
  onClose: () => void
}

interface ModuleChecklistProps {
  legend: string
  availableModules: string[]
  selected: string[]
  onToggle: (module: string) => void
  disabled: boolean
}

function ModuleChecklist({
  legend,
  availableModules,
  selected,
  onToggle,
  disabled,
}: ModuleChecklistProps) {
  return (
    <fieldset className={`rounded-lg border ${borderColors.light} p-4`}>
      <legend className={`px-1 text-sm font-semibold ${textColors.primary}`}>
        {legend}
      </legend>
      <div className="grid grid-cols-2 gap-2">
        {availableModules.map((module) => (
          <label
            key={module}
            className={`flex items-center gap-2 text-sm ${textColors.secondary}`}
          >
            <Checkbox
              checked={selected.includes(module)}
              disabled={disabled}
              onChange={() => {
                onToggle(module)
              }}
            />
            {module}
          </label>
        ))}
      </div>
    </fieldset>
  )
}

function toggleModule(list: string[], module: string): string[] {
  return list.includes(module)
    ? list.filter((m) => m !== module)
    : [...list, module]
}

export function VerticalConfigModal({
  vertical,
  availableModules,
  onClose,
}: VerticalConfigModalProps) {
  const { t } = useTranslation('admin')
  const updateConfig = useUpdateVerticalConfig()

  const [defaultModules, setDefaultModules] = useState<string[]>(
    vertical.default_modules
  )
  const [compatibleExtras, setCompatibleExtras] = useState<string[]>(
    vertical.compatible_extras
  )

  const serverError = getVerticalConfigErrorMessage(updateConfig.error)

  const handleSave = () => {
    updateConfig.mutate(
      {
        vertical: vertical.vertical,
        payload: {
          default_modules: defaultModules,
          compatible_extras: compatibleExtras,
        },
      },
      {
        onSuccess: () => {
          onClose()
        },
      }
    )
  }

  return (
    <Transition appear show as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <TransitionChild
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/25" />
        </TransitionChild>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <TransitionChild
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 scale-95"
              enterTo="opacity-100 scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 scale-100"
              leaveTo="opacity-0 scale-95"
            >
              {/* Fixed dimensions: explicit width + fixed height with internal
                  scroll so the modal never resizes on interaction. */}
              <DialogPanel
                className="flex h-[560px] w-[640px] max-w-full transform flex-col overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all"
              >
                <div className="flex shrink-0 items-center justify-between">
                  <DialogTitle
                    as="h3"
                    className={`text-lg font-semibold leading-6 ${textColors.primary}`}
                  >
                    {t('verticals.modal.title', { label: vertical.label })}
                  </DialogTitle>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="p-1"
                    aria-label={t('verticals.modal.close')}
                    onClick={onClose}
                  >
                    <X className="h-5 w-5" />
                  </Button>
                </div>

                <div className="mt-4 flex-1 space-y-6 overflow-y-auto pr-1">
                  <ModuleChecklist
                    legend={t('verticals.modal.defaultModules')}
                    availableModules={availableModules}
                    selected={defaultModules}
                    onToggle={(module) => {
                      setDefaultModules((prev) => toggleModule(prev, module))
                    }}
                    disabled={updateConfig.isPending}
                  />
                  <ModuleChecklist
                    legend={t('verticals.modal.compatibleExtras')}
                    availableModules={availableModules}
                    selected={compatibleExtras}
                    onToggle={(module) => {
                      setCompatibleExtras((prev) => toggleModule(prev, module))
                    }}
                    disabled={updateConfig.isPending}
                  />

                  {serverError !== null && (
                    <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
                      {serverError}
                    </div>
                  )}
                </div>

                <div className="mt-4 flex shrink-0 justify-end gap-3">
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={onClose}
                  >
                    {t('verticals.modal.cancel')}
                  </Button>
                  <Button
                    type="button"
                    disabled={updateConfig.isPending}
                    onClick={handleSave}
                  >
                    {updateConfig.isPending
                      ? t('verticals.modal.saving')
                      : t('verticals.modal.save')}
                  </Button>
                </div>
              </DialogPanel>
            </TransitionChild>
          </div>
        </div>
      </Dialog>
    </Transition>
  )
}
