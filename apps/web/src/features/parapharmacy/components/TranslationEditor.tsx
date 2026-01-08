import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Trash2, Plus } from 'lucide-react';

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
        <Label className="text-base font-semibold">
          {t('parapharmacy:translations')}
        </Label>
        {canAddMore && (
          <Button type="button" variant="outline" size="sm" onClick={addTranslation}>
            <Plus className="h-4 w-4 mr-2" />
            {t('parapharmacy:addTranslation')}
          </Button>
        )}
      </div>

      {translations.length === 0 && (
        <Card>
          <CardContent className="py-8 text-center text-muted-foreground">
            {t('parapharmacy:noTranslations')}
            <br />
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="mt-4"
              onClick={addTranslation}
            >
              <Plus className="h-4 w-4 mr-2" />
              {t('parapharmacy:addFirstTranslation')}
            </Button>
          </CardContent>
        </Card>
      )}

      {translations.map((translation, index) => {
        const locale = availableLocales.find((l) => l.code === translation.locale);

        return (
          <Card key={index}>
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm font-medium">
                  {locale?.name || translation.locale}
                  {translation.locale === 'en' && (
                    <span className="ml-2 text-xs text-muted-foreground">
                      ({t('parapharmacy:required')})
                    </span>
                  )}
                </CardTitle>
                {translation.locale !== 'en' && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => removeTranslation(index)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                )}
              </div>
            </CardHeader>
            <CardContent className="space-y-3">
              <input type="hidden" value={translation.locale} />
              {translation.id && <input type="hidden" value={translation.id} />}

              {fields.map((field) => (
                <div key={field.name}>
                  <Label htmlFor={`translation-${index}-${field.name}`}>
                    {field.label}
                    {field.required && translation.locale === 'en' && (
                      <span className="text-destructive ml-1">*</span>
                    )}
                  </Label>
                  {field.multiline ? (
                    <Textarea
                      id={`translation-${index}-${field.name}`}
                      value={(translation[field.name] as string) || ''}
                      onChange={(e) =>
                        updateTranslation(index, field.name, e.target.value)
                      }
                      required={field.required && translation.locale === 'en'}
                      rows={3}
                    />
                  ) : (
                    <Input
                      id={`translation-${index}-${field.name}`}
                      value={(translation[field.name] as string) || ''}
                      onChange={(e) =>
                        updateTranslation(index, field.name, e.target.value)
                      }
                      required={field.required && translation.locale === 'en'}
                    />
                  )}
                </div>
              ))}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
