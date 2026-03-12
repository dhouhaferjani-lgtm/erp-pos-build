import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import enCommon from '@/locales/en/common.json';
import enPos from '@/locales/en/pos.json';
import frCommon from '@/locales/fr/common.json';
import frPos from '@/locales/fr/pos.json';

void i18n.use(initReactI18next).init({
  resources: {
    en: {
      common: enCommon,
      pos: enPos,
    },
    fr: {
      common: frCommon,
      pos: frPos,
    },
  },
  lng: 'en',
  fallbackLng: 'en',
  ns: ['common', 'pos'],
  defaultNS: 'pos',
  interpolation: {
    escapeValue: false,
  },
});

export default i18n;
