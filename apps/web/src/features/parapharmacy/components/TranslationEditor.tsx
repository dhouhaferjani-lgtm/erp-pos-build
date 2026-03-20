import { useTranslation } from 'react-i18next'
import { Button } from '@/components/atoms/Button/Button'
import { Input } from '@/components/atoms/Input/Input'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Trash2, Plus } from 'lucide-react'

export interface Translation {
  id?: string;
  locale: string;
  name: string;
  description?: string | null;
  [key: string]: string | null | undefined;
}

interface TranslationEditorProps {
  translations: Translation[];
  onChange: (translations: Translation[]) => void;
  fields: Array<{
    name: string;
    label: string;
    required: boolean;
    multiline?: boolean;
  }>;
  availableLocales?: Array<{ code: string; name: string }>;
}

const DEFAULT_LOCALES = [
  { code: 'en', name: 'English' },
  { code: 'fr', name: 'Français' },
  { code: 'ar', name: 'العربية' },
];

export function TranslationEditor({
  translations,
  onChange,
  fields,
  availableLocales = DEFAULT_LOCALES,
}: TranslationEditorProps) {
  const { t } = useTranslation(['common', 'parapharmacy']);

  const addTranslation = () => {
    const usedLocales = translations.map((t) => t.locale);
    const availableLocale = availableLocales.find(
      (l) => !usedLocales.includes(l.code)
    );

    if (!availableLocale) {
      return;
    }

    const newTranslation: Translation = {
      locale: availableLocale.code,
      name: '',
      description: null,
    };

    onChange([...translations, newTranslation]);
  };

  const removeTranslation = (index: number) => {
    const updated = translations.filter((_, i) => i !== index);
    onChange(updated);
  };

  const updateTranslation = (
    index: number,
    field: string,
    value: string | null
  ) => {
    const updated = translations.map((translation, i) => {
      if (i === index) {
        return { ...translation, [field]: value };
      }
      return translation;
    });
    onChange(updated);
  };

  const usedLocales = translations.map((t) => t.locale);
  const canAddMore = usedLocales.length < availableLocales.length;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <label className="text-base font-semibold text-gray-900">
          {t('parapharmacy:translations')}
        </label>
        {canAddMore && (
          <Button type="button" variant="secondary" size="sm" onClick={addTranslation}>
            <Plus className="h-4 w-4 mr-2" />
            {t('parapharmacy:addTranslation')}
          </Button>
        )}
      </div>

      {translations.length === 0 && (
        <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
          <div className="px-6 py-8 text-center text-gray-500">
            {t('parapharmacy:noTranslations')}
            <br />
            <Button
              type="button"
              variant="secondary"
              size="sm"
              className="mt-4"
              onClick={addTranslation}
            >
              <Plus className="h-4 w-4 mr-2" />
              {t('parapharmacy:addFirstTranslation')}
            </Button>
          </div>
        </div>
      )}

      {translations.map((translation, index) => {
        const locale = availableLocales.find((l) => l.code === translation.locale)

        return (
          <div key={index} className="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div className="border-b border-gray-200 px-6 py-4">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-medium text-gray-900">
                  {locale?.name || translation.locale}
                  {translation.locale === 'en' && (
                    <span className="ml-2 text-xs text-gray-500">
                      ({t('parapharmacy:required')})
                    </span>
                  )}
                </h3>
                {translation.locale !== 'en' && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => { removeTranslation(index); }}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                )}
              </div>
            </div>
            <div className="px-6 py-4 space-y-3">
              <input type="hidden" value={translation.locale} />
              {translation.id && <input type="hidden" value={translation.id} />}

              {fields.map((field) => (
                <div key={field.name}>
                  <label
                    htmlFor={`translation-${index}-${field.name}`}
                    className="block text-sm font-medium text-gray-700 mb-1"
                  >
                    {field.label}
                    {field.required && translation.locale === 'en' && (
                      <span className="text-red-600 ml-1">*</span>
                    )}
                  </label>
                  {field.multiline ? (
                    <Textarea
                      id={`translation-${index}-${field.name}`}
                      value={(translation[field.name] as string) || ''}
                      onChange={(e) =>
                        { updateTranslation(index, field.name, e.target.value); }
                      }
                      required={field.required && translation.locale === 'en'}
                      rows={3}
                    />
                  ) : (
                    <Input
                      id={`translation-${index}-${field.name}`}
                      value={(translation[field.name] as string) || ''}
                      onChange={(e) =>
                        { updateTranslation(index, field.name, e.target.value); }
                      }
                      required={field.required && translation.locale === 'en'}
                    />
                  )}
                </div>
              ))}
            </div>
          </div>
        )
      })}
    </div>
  )
}
