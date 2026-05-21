import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import enCommon from '@/locales/en/common.json';
import enFiscal from '@/locales/en/fiscal.json';
import enPos from '@/locales/en/pos.json';
import enSmartPrompts from '@/locales/en/smart-prompts.json';
import frCommon from '@/locales/fr/common.json';
import frFiscal from '@/locales/fr/fiscal.json';
import frPos from '@/locales/fr/pos.json';
import frSmartPrompts from '@/locales/fr/smart-prompts.json';

void i18n.use(initReactI18next).init({
  resources: {
    en: {
      common: enCommon,
      pos: enPos,
      'smart-prompts': enSmartPrompts,
      fiscal: enFiscal,
    },
    fr: {
      common: frCommon,
      pos: frPos,
      'smart-prompts': frSmartPrompts,
      fiscal: frFiscal,
    },
  },
  lng: 'en',
  fallbackLng: 'en',
  ns: ['common', 'pos', 'smart-prompts', 'fiscal'],
  defaultNS: 'pos',
  interpolation: {
    escapeValue: false,
  },
});

export default i18n;
